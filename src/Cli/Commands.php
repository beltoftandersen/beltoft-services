<?php
/**
 * WP-CLI commands.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Http\Connections;
use BeltoftServices\Domains\Clients as DomainClients;
use BeltoftServices\Domains\Prices as DomainPrices;
use BeltoftServices\Domains\Search as DomainSearch;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\LinkVm;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Beltoft Services from the command line.
 */
final class Commands {

	/**
	 * Fetches the registrar TLD table and prints the number saved.
	 *
	 * @subcommand domain-prices
	 */
	public function domain_prices( array $args, array $assoc_args ): void {
		$count = DomainPrices::sync();
		if ( is_wp_error( $count ) ) { \WP_CLI::error( $count->get_error_message() ); }
		\WP_CLI::success( sprintf( '%d TLD prices saved.', $count ) );
	}

	/**
	 * Prints the account balance in the registrar's currency.
	 *
	 * @subcommand domain-balance
	 */
	public function domain_balance( array $args, array $assoc_args ): void {
		$result = DomainClients::registrar()->request( 'GET', '/resellers' );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		if ( ! is_numeric( $result['balance'] ?? null ) ) { \WP_CLI::error( 'Registrar did not return a balance.' ); }
		\WP_CLI::log( sprintf( 'Balance: %s %s', (string) $result['balance'], (string) ( $result['currency'] ?? 'EUR' ) ) );
	}

	/**
	 * Link an existing registration or DNS zone to an active license, without ordering anything.
	 *
	 * @subcommand link-domain
	 */
	public function link_domain( array $args, array $assoc_args ): void {
		$license = Licenses::find( (int) ( $args[0] ?? 0 ) );
		$domain = DomainSearch::normalise( (string) ( $args[1] ?? '' ) );
		$dns_only = isset( $assoc_args['dns-only'] );
		$remote_id = (string) ( $assoc_args['registrar-id'] ?? '' );
		if ( ! $license || is_wp_error( $domain ) ) { \WP_CLI::error( 'Give an existing license id and a valid domain.' ); }
		$driver = Product::driver_for( (int) $license['product_id'] );
		if ( ! $driver || $driver->id() !== ( $dns_only ? 'dns' : 'domain' ) ) { \WP_CLI::error( 'The license product has the wrong domain driver.' ); }
		if ( ! $dns_only && ! ctype_digit( $remote_id ) ) { \WP_CLI::error( '--registrar-id must be a numeric domain id.' ); }
		if ( Resource::for_license( (int) $license['id'] ) ) { \WP_CLI::error( 'The license already has a resource.' ); }
		if ( Licenses::STATUS_ACTIVE !== Licenses::effective_status( $license ) ) { \WP_CLI::error( 'The license must be active.' ); }
		foreach ( array( 'domain', 'dns' ) as $kind ) {
			foreach ( Resource::live_ids( $kind ) as $id ) {
				$other = Resource::find( $id );
				if ( $other && $other->get( 'domain' ) === $domain && (int) ( $other->license()['user_id'] ?? 0 ) !== (int) ( $license['user_id'] ?? 0 ) ) { \WP_CLI::error( 'This domain belongs to another customer resource.' ); }
				if ( $other && $other->get( 'domain' ) === $domain && $kind === $driver->kind() ) { \WP_CLI::error( 'This domain already has a resource of this kind.' ); }
			}
		}
		if ( $dns_only ) {
			$zone = DomainClients::dns()->zone( $domain );
			if ( is_wp_error( $zone ) || null === $zone ) { \WP_CLI::error( 'The DNS zone must exist before linking.' ); }
		}
		$meta = array( 'state' => 'ready', 'desired' => 'ready', 'domain' => $domain, 'mode' => $dns_only ? 'dns' : 'register', 'protected' => true, 'notified' => 'ready', 'steps' => array( 'claim', 'zone', 'delegation' ), 'zone_claimed' => true );
		if ( ! $dns_only ) { $meta['remote_id'] = $remote_id; $meta['steps'] = array( 'contact', 'zone_prepared', 'order', 'active', 'zone', 'dates' ); }
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$r = Resource::create( $license, $driver, Product::plan( (int) $license['product_id'], $variation_id > 0 ? $variation_id : null ), $meta );
		if ( is_wp_error( $r ) ) { \WP_CLI::error( $r->get_error_message() ); }
		Events::add( (int) $license['id'], 'resource_linked', sprintf( 'Linked existing domain %s by WP-CLI.', $domain ) );
		\WP_CLI::success( sprintf( 'Linked %s to license %d.', $domain, (int) $license['id'] ) );
	}

	/**
	 * Registers the commands when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services', self::class );
		}
	}

	/**
	 * Links a license to a tenant that already exists on an HTTP service.
	 *
	 * Records the resource as ready and protected with the given remote id. It calls the
	 * service for nothing and provisions nothing. Right after linking, the plan is pushed to
	 * the service once (update_plan), so the tenant matches the product.
	 *
	 * ## OPTIONS
	 *
	 * <license-id>
	 * : The license id.
	 *
	 * --remote-id=<id>
	 * : The tenant's id at the service.
	 *
	 * [--dry-run]
	 * : Say what would be recorded, without writing or queuing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services link-http 12 --remote-id=tenant-42 --dry-run
	 *     wp beltoft-services link-http 12 --remote-id=tenant-42
	 *
	 * @subcommand link-http
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function link_http( array $args, array $assoc_args ): void {
		$license = Licenses::find( (int) ( $args[0] ?? 0 ) );

		if ( ! $license ) {
			\WP_CLI::error( 'License not found.' );
		}

		$remote_id = trim( (string) ( $assoc_args['remote-id'] ?? '' ) );

		if ( '' === $remote_id ) {
			\WP_CLI::error( '--remote-id must not be empty.' );
		}

		$product_id = (int) $license['product_id'];
		$driver     = Product::driver_for( $product_id );

		if ( ! $driver || 'http' !== $driver->id() ) {
			\WP_CLI::error( "The license's product is not an HTTP service." );
		}

		$connection = Product::connection( $product_id );

		if ( '' === $connection || ! isset( Connections::all()[ $connection ] ) ) {
			\WP_CLI::error( '' === $connection ? "The license's product has no connection." : sprintf( 'The connection "%s" of the license\'s product does not exist.', $connection ) );
		}

		if ( Resource::for_license( (int) $license['id'] ) ) {
			\WP_CLI::error( 'The license already has a live resource.' );
		}

		if ( \BeltoftServices\Breach::marked( (int) $license['id'] ) ) {
			\WP_CLI::error( 'The license is marked for a breach of the terms; clear it on the license screen first.' );
		}

		$other = $this->linked_license( $driver->kind(), $connection, $remote_id );

		if ( $other > 0 ) {
			\WP_CLI::error( sprintf( 'Remote id %1$s is already linked to license %2$d.', $remote_id, $other ) );
		}

		$status = Licenses::effective_status( $license );

		// beltoft-licenses refuses to add a resource to a license that is not active, so linking
		// one is not possible; refuse early with the reason instead of a half-made link.
		if ( Licenses::STATUS_ACTIVE !== $status ) {
			\WP_CLI::error( sprintf( 'The license is %s; a resource can only be linked to an active license.', $status ) );
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::log( sprintf( 'Would record license %d as ready and protected with remote id %s, call the service for nothing, then one update_plan.', (int) $license['id'], $remote_id ) );
			return;
		}

		$variation_id = (int) ( $license['variation_id'] ?? 0 );

		$resource = Resource::create(
			$license,
			$driver,
			Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ),
			array(
				'state'      => 'ready',
				'desired'    => 'ready',
				'remote_id'  => $remote_id,
				'protected'  => true,
				'plan_dirty' => true,
				'steps'      => array( 'provision' ),
				// The customer already has this service: later paused/deleted emails apply.
				'notified'   => 'ready',
			)
		);

		if ( is_wp_error( $resource ) ) {
			\WP_CLI::error( $resource->get_error_message() );
		}

		// A resource made by another request between the check and create() is returned as it is.
		if ( $resource->get( 'remote_id' ) !== $remote_id ) {
			\WP_CLI::error( 'The license got a resource in the meantime; nothing was linked.' );
		}

		Events::add( (int) $license['id'], 'resource_linked', sprintf( 'Linked to existing tenant %s by WP-CLI; the plan will be pushed once.', $remote_id ) );
		Jobs::enqueue( $resource->id() );

		\WP_CLI::success( sprintf( 'Linked license %d to remote id %s. The plan will be pushed once.', (int) $license['id'], $remote_id ) );
	}

	/**
	 * Adds an address or a network to the IP pool as free (IPv4 only).
	 *
	 * ## OPTIONS
	 *
	 * <ip|cidr>
	 * : An address, or a network of /20 or smaller such as 192.0.2.8/29.
	 *
	 * [--gateway=<ip>]
	 * : Gateway stored with each address (it must lie inside the network).
	 *
	 * @subcommand ip-pool-add
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function ip_pool_add( array $args, array $assoc_args ): void {
		try {
			$added = IpPool::add( (string) ( $args[0] ?? '' ), isset( $assoc_args['gateway'] ) ? (string) $assoc_args['gateway'] : null );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'The pool is busy; nothing more was added. Try again.' );
		}

		if ( 0 === $added ) {
			\WP_CLI::error( 'Nothing was added: give an IPv4 address or a network of /20 or smaller; addresses already in the pool are skipped.' );
		}

		\WP_CLI::success( sprintf( '%d address(es) added.', $added ) );
	}

	/**
	 * Blocks a free address, so it is never handed out.
	 *
	 * ## OPTIONS
	 *
	 * <ip>
	 * : The address.
	 *
	 * [--unblock]
	 * : Put a blocked address back into the pool instead.
	 *
	 * @subcommand ip-pool-block
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function ip_pool_block( array $args, array $assoc_args ): void {
		$ip = (string) ( $args[0] ?? '' );

		try {
			$ok = ! empty( $assoc_args['unblock'] ) ? IpPool::unblock( $ip ) : IpPool::block( $ip );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'The pool is busy; try again.' );
		}

		if ( ! $ok ) {
			\WP_CLI::error( ! empty( $assoc_args['unblock'] ) ? sprintf( '%s is not a blocked address.', $ip ) : sprintf( '%s is not a free address of the pool.', $ip ) );
		}

		\WP_CLI::success( sprintf( '%1$s %2$s.', $ip, ! empty( $assoc_args['unblock'] ) ? 'unblocked' : 'blocked' ) );
	}

	/**
	 * Releases the address of a failed or closed service back to the pool.
	 *
	 * Only a leftover can be released: held by a failed service, or assigned to a closed one (for
	 * instance after "Mark deleted"). When a machine may still exist (a closed service, or a failed
	 * one with a VM record) type the confirmation: --confirm="RELEASE <ip>".
	 *
	 * ## OPTIONS
	 *
	 * <ip>
	 * : The address.
	 *
	 * [--confirm=<text>]
	 * : RELEASE <ip>.
	 *
	 * @subcommand ip-pool-release
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function ip_pool_release( array $args, array $assoc_args ): void {
		$result = \BeltoftServices\Proxmox\IpPoolScreen::apply(
			'release',
			array(
				'address' => (string) ( $args[0] ?? '' ),
				'confirm' => (string) ( $assoc_args['confirm'] ?? '' ),
			)
		);

		if ( 'success' !== $result[0] ) {
			\WP_CLI::error( $result[1] );
		}

		\WP_CLI::success( $result[1] );
	}

	/**
	 * Lists the IP pool.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @subcommand ip-pool-list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function ip_pool_list( array $args, array $assoc_args ): void {
		unset( $args );

		$items = array();

		foreach ( IpPool::rows() as $row ) {
			$items[] = array(
				'address'  => $row['address'],
				'prefix'   => (string) $row['prefix'],
				'gateway'  => (string) $row['gateway'],
				'state'    => $row['state'],
				'resource' => null === $row['resource_id'] ? '' : (string) $row['resource_id'],
				'leftover' => $row['leftover'],
			);
		}

		\WP_CLI\Utils\format_items( 'json' === ( $assoc_args['format'] ?? 'table' ) ? 'json' : 'table', $items, array( 'address', 'prefix', 'gateway', 'state', 'resource', 'leftover' ) );
	}

	/**
	 * Links a license to a virtual machine that already exists on a Proxmox cluster.
	 *
	 * Bookkeeping and the ownership tag only: it records the license as ready and protected, adds a
	 * tag line to the machine's description and, when the cluster has a pool and the machine is in
	 * none, adds it to that pool. It starts, stops, resizes and reconfigures nothing, and the plan is
	 * not pushed. Calls: two reads, one or two writes. A dry run makes no call at all.
	 *
	 * A machine on another network (another bridge, an internal address) that is not in the pool
	 * is adopted with --outside-pool: nothing about its network is touched. IPv4 only.
	 *
	 * ## OPTIONS
	 *
	 * <license-id>
	 * : The license id.
	 *
	 * --cluster=<id>
	 * : Cluster id (Settings, Proxmox clusters).
	 *
	 * --node=<node>
	 * : The node the machine runs on.
	 *
	 * --vmid=<id>
	 * : The machine's VMID (inside the cluster's customer range).
	 *
	 * --ip=<ip>
	 * : The machine's IPv4 address: a free address of the pool, or with --outside-pool one that is not in it.
	 *
	 * [--outside-pool]
	 * : The address is not in the pool (another network).
	 *
	 * [--dry-run]
	 * : Print the calls that would be made. Nothing is read, written or recorded.
	 *
	 * [--confirm=<text>]
	 * : LINK:<vmid>. Required to change a machine on a live cluster.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services link-vm 12 --cluster=main --node=pve1 --vmid=200 --ip=192.0.2.10 --dry-run
	 *     wp beltoft-services link-vm 12 --cluster=main --node=pve1 --vmid=200 --ip=192.0.2.10 --confirm=LINK:200
	 *
	 * @subcommand link-vm
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function link_vm( array $args, array $assoc_args ): void {
		$result = LinkVm::run(
			(int) ( $args[0] ?? 0 ),
			array(
				'cluster'      => (string) ( $assoc_args['cluster'] ?? '' ),
				'node'         => (string) ( $assoc_args['node'] ?? '' ),
				'vmid'         => (int) ( $assoc_args['vmid'] ?? 0 ),
				'ip'           => (string) ( $assoc_args['ip'] ?? '' ),
				'outside_pool' => ! empty( $assoc_args['outside-pool'] ),
				'dry_run'      => ! empty( $assoc_args['dry-run'] ),
				'confirm'      => (string) ( $assoc_args['confirm'] ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		foreach ( $result['lines'] as $line ) {
			\WP_CLI::log( $line );
		}

		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}

		if ( $result['linked'] ) {
			\WP_CLI::success( 'Done.' );
		}
	}

	/**
	 * The license whose live resource already has this remote id on this connection, or 0.
	 *
	 * @param string $kind       Activation kind.
	 * @param string $connection Connection id.
	 * @param string $remote_id  Remote id.
	 */
	private function linked_license( string $kind, string $connection, string $remote_id ): int {
		foreach ( Resource::live_ids( $kind ) as $id ) {
			$resource = Resource::find( $id );

			if ( ! $resource || $resource->get( 'remote_id' ) !== $remote_id ) {
				continue;
			}

			$license = $resource->license();
			$used    = (string) $resource->get( 'connection' );
			$used    = '' !== $used ? $used : Product::connection( (int) ( $license['product_id'] ?? 0 ) );

			if ( $used === $connection ) {
				return (int) ( $license['id'] ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Shows a license's resource, without secrets.
	 *
	 * ## OPTIONS
	 *
	 * <license-id>
	 * : The license id.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services status 12 --format=json
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		$resource = Resource::for_license( (int) ( $args[0] ?? 0 ) );

		if ( ! $resource ) {
			\WP_CLI::error( 'The license has no live resource.' );
		}

		$meta = array( 'activation_id' => $resource->id() );

		foreach ( array( 'uuid', 'driver', 'state', 'desired', 'remote_id', 'protected', 'plan_dirty', 'attempts', 'next_attempt_at', 'paused_at', 'warned_at', 'last_error', 'plan' ) as $key ) {
			$meta[ $key ] = $resource->get( $key );
		}

		$items = array();
		foreach ( $meta as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => self::show( $value ),
			);
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $meta ) );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * A value as text for the table.
	 *
	 * @param mixed $value Value.
	 */
	private static function show( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}
}
