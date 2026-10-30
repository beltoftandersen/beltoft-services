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
use BeltoftServices\Domains\Health as DomainHealth;
use BeltoftServices\Jobs;
use BeltoftServices\Plugin;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\IpfilterReport;
use BeltoftServices\Proxmox\IpWho;
use BeltoftServices\Proxmox\LinkVm;
use BeltoftServices\Proxmox\Metrics;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\DiskDriver;
use BeltoftServices\Proxmox\DiskMeta;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\Clients;
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Proxmox\Quarantine;
use BeltoftServices\Proxmox\Provision;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Beltoft Services from the command line.
 */
final class Commands {
	/**
	 * Link an existing disk without changing its VM. Dry run makes no Proxmox call.
	 *
	 * ## OPTIONS
	 * <license-id> : The disk license.
	 * <server-license-id> : Its owner's server license.
	 * <slot> : The existing scsi1 through scsi30 slot.
	 * [--dry-run] : Print the intended read and bookkeeping change.
	 * [--confirm=<text>] : LINK-DISK:<slot> on a live cluster.
	 *
	 * @subcommand link-disk
	 */
	public function link_disk( array $args, array $assoc_args ): void {
		$disk_license = Licenses::find( (int) ( $args[0] ?? 0 ) );
		$server_license = Licenses::find( (int) ( $args[1] ?? 0 ) );
		$slot = (string) ( $args[2] ?? '' );
		if ( ! $disk_license || ! $server_license || ! preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/D', $slot ) ) {
			\WP_CLI::error( 'Give a disk license, a server license and scsi1 through scsi30.' );
		}
		$driver = Product::driver_for( (int) $disk_license['product_id'] );
		$server = Resource::for_license( (int) $server_license['id'] );
		if ( ! $driver || DiskDriver::ID !== $driver->id() || ! $server || 'proxmox' !== $server->get( 'driver' ) || $server->get( 'linked' ) || 'ready' !== $server->get( 'state' ) || Licenses::STATUS_ACTIVE !== Licenses::effective_status( $disk_license ) || (int) $disk_license['user_id'] !== (int) $server_license['user_id'] || Resource::for_license( (int) $disk_license['id'] ) ) {
			\WP_CLI::error( 'The disk license must be active, both licenses must belong to one customer, the server must be ready and managed, and the disk must have no resource.' );
		}
		$record = VmRecords::for_resource( $server->id() );
		$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
		if ( ! $record || ! $cluster ) {
			\WP_CLI::error( 'The server VM record or cluster is unavailable.' );
		}
		foreach ( DiskMeta::for_server( $server ) as $other ) {
			if ( $other->get( 'slot' ) === $slot ) {
				\WP_CLI::error( 'Another disk resource already claims that slot.' );
			}
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::log( sprintf( 'Would read VM %d in cluster %s, verify ownership and slot %s, then create a protected disk resource. No remote write.', (int) $record['vmid'], $record['cluster'], $slot ) );
			return;
		}
		if ( 'live' === ( $cluster['client'] ?? 'fake' ) && ( $assoc_args['confirm'] ?? '' ) !== 'LINK-DISK:' . $slot ) {
			\WP_CLI::error( 'Live read requires --confirm=LINK-DISK:' . $slot . ' and per-step authorization.' );
		}
		$gate = Clients::gate( $record['cluster'], $cluster, Clients::live_allowed() );
		if ( $gate ) {
			\WP_CLI::error( $gate );
		}
		$client = Clients::for( $cluster );
		$vm = Ownership::verify( $client, $record, $server );
		if ( is_wp_error( $vm ) ) {
			\WP_CLI::error( 'VM ownership could not be verified: ' . $vm->get_error_message() );
		}
		$value = (string) ( $vm['config'][ $slot ] ?? '' );
		$volid = explode( ',', $value )[0];
		$prefix = (string) $cluster['storage'] . ':' . (int) $record['vmid'] . '/';
		$name = str_starts_with( $volid, $prefix ) ? substr( $volid, strlen( $prefix ) ) : '';
		$size = Provision::gigabytes( $value );
		$variation_id = (int) ( $disk_license['variation_id'] ?? 0 );
		$plan = Product::plan( (int) $disk_license['product_id'], $variation_id > 0 ? $variation_id : null );
		if ( ! preg_match( '/^vm-' . (int) $record['vmid'] . '-[A-Za-z0-9._-]+\.(?:raw|qcow2)$/', $name ) || null === $size || (int) $size !== (int) ( $plan['disk_gb'] ?? 0 ) ) {
			\WP_CLI::error( 'The slot must hold a raw or qcow2 volume on the server storage with exactly the licensed size.' );
		}
		$resource = Resource::create( $disk_license, $driver, $plan, array( 'server_license' => (int) $server_license['id'], 'slot' => $slot, 'volname' => $name, 'size_gb' => (int) $size, 'state' => 'ready', 'desired' => 'ready', 'linked' => true, 'protected' => true, 'steps' => array( 'server', 'slot', 'allocate', 'attach', 'verify' ), 'notified' => 'ready' ) );
		if ( is_wp_error( $resource ) ) {
			\WP_CLI::error( $resource->get_error_message() );
		}
		Events::add( (int) $disk_license['id'], 'resource_linked', sprintf( 'Linked existing disk in %s by WP-CLI without changing the server.', $slot ) );
		\WP_CLI::success( sprintf( 'Linked disk license %d to server license %d in %s.', (int) $disk_license['id'], (int) $server_license['id'], $slot ) );
	}
	/**
	 * Read shaped VPS metrics for one license; --disk explicitly asks its guest agent.
	 *
	 * @subcommand vm-metrics
	 */
	public function vm_metrics( array $args, array $assoc_args ): void {
		$license_id = (int) ( $args[0] ?? 0 );
		$resource = $license_id > 0 ? Resource::for_license( $license_id ) : null;
		if ( ! $resource || 'proxmox' !== $resource->get( 'driver' ) ) { \WP_CLI::error( 'Give a VPS license with a live resource.' ); }
		$range = (string) ( $assoc_args['range'] ?? 'hour' );
		if ( ! isset( Metrics::RANGES[ $range ] ) ) { \WP_CLI::error( 'Use --range=hour, day or month.' ); }
		$rows = ( new Operations( $resource ) )->series( $range );
		if ( null === $rows ) { \WP_CLI::error( 'VM usage could not be read.' ); }
		$series = Metrics::shape( $rows, $range );
		\WP_CLI::log( sprintf( 'Range: %s; points: %d; latest: %s', $range, count( $series['points'] ), wp_json_encode( $series['points'] ? end( $series['points'] ) : null ) ) );
		if ( isset( $assoc_args['disk'] ) ) {
			$disk = GuestDisk::read( $resource );
			\WP_CLI::log( 'Disk: ' . wp_json_encode( $disk ) );
		}
	}

	/**
	 * Read-only report for turning the IP filter (fw_ipfilter) on: per VM of a cluster, the ipfilter sets it should have
	 * (from the stored address and the private network), what Proxmox holds, and whether ipfilter is on. Changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <cluster>
	 * : The cluster id.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @subcommand ipfilter-report
	 */
	public function ipfilter_report( array $args, array $assoc_args ): void {
		$cluster = Clusters::get( (string) ( $args[0] ?? '' ) );
		if ( null === $cluster ) { \WP_CLI::error( 'Unknown cluster.' ); }
		try {
			$client = Clients::for( $cluster );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'The cluster has no client: ' . $e->getMessage() );
		}
		$rows = IpfilterReport::rows( (string) $args[0], $client );
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'vmid', 'license', 'expected', 'actual', 'ipfilter', 'status' ) );
	}

	/**
	 * Lifts the network quarantine of a server: outgoing traffic is allowed again (policy_out ACCEPT), the quarantine
	 * rules are removed, the flag is cleared. Never automatic. Runs the firewall job now and reports what Proxmox shows.
	 *
	 * ## OPTIONS
	 *
	 * <license>
	 * : The license id of the server.
	 *
	 * @subcommand quarantine-lift
	 */
	public function quarantine_lift( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$resource = Resource::for_license( (int) ( $args[0] ?? 0 ) );
		if ( ! $resource ) { \WP_CLI::error( 'The license has no live resource.' ); }
		if ( ! Quarantine::request_lift( $resource, 'wp-cli' ) ) { \WP_CLI::error( 'This server has no network quarantine.' ); }
		Jobs::act( $resource->id(), 'fw_quarantine_lift' );
		$fresh = Resource::find( $resource->id() );
		if ( $fresh && Quarantine::active( $fresh ) ) {
			\WP_CLI::error( 'The quarantine is still on; the lift will be tried again. See the license history.' );
		}
		\WP_CLI::success( 'Network quarantine lifted.' );
	}

	/**
	 * Runs the plugin's due jobs every few seconds for a while, so a step that is a few seconds away does not wait
	 * for the once-a-minute cron tick. Only this plugin's reconcile and customer-action jobs are run; Action Scheduler's
	 * own runner keeps everything else. It honours the kill switch, the resource locks and the run budget.
	 *
	 * Put it in cron next to the normal WordPress cron line. `flock -n` is required: it keeps a slow minute from stacking a second runner:
	 * `* * * * * <user> flock -n /run/lock/beltoft-services-runner.lock wp beltoft-services run-due --seconds=55 --path=<wordpress path> --quiet`
	 * Without it everything still works, only slower.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : How long to keep starting jobs. Default 55 (0 runs at most one job). No job is started after that, so it ends within the seconds plus one run budget (45 s). At most 3600.
	 *
	 * [--interval=<seconds>]
	 * : Seconds between two passes. Default 3 (1 to 30).
	 *
	 * @subcommand run-due
	 */
	public function run_due( array $args, array $assoc_args ): void {
		if ( ! Plugin::requirements_met() ) {
			\WP_CLI::warning( 'Beltoft Services is not running: a requirement is missing.' );
			return;
		}

		$seconds  = max( 0, min( 3600, (int) ( $assoc_args['seconds'] ?? 55 ) ) );
		$interval = max( 1, min( 30, (int) ( $assoc_args['interval'] ?? 3 ) ) );
		$ran      = Jobs::run_due( $seconds, $interval );

		\WP_CLI::log( sprintf( 'Ran %d due job(s) in %d second(s).', $ran, $seconds ) );
	}

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
		if ( ! $dns_only ) {
			// The id must be this domain's: a wrong id would manage another registration.
			$found = DomainClients::registrar()->request( 'GET', '/domains/' . $remote_id );
			if ( is_wp_error( $found ) ) { \WP_CLI::error( 'The registrar could not confirm the registrar id.' ); }
			$named = isset( $found['full_name'] ) ? (string) $found['full_name'] : trim( (string) ( $found['domain']['name'] ?? '' ) . '.' . (string) ( $found['domain']['extension'] ?? '' ), '.' );
			if ( strtolower( rtrim( $named, '.' ) ) !== $domain ) { \WP_CLI::error( 'The registrar id belongs to a different domain.' ); }
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

	/** Check configured authoritative servers and report zone problems.
	 * @subcommand dns-check
	 */
	public function dns_check( array $args, array $assoc_args ): void {
		$issues = DomainHealth::dns_check();
		if ( is_wp_error( $issues ) ) { \WP_CLI::error( $issues->get_error_message() ); }
		foreach ( $issues as $issue ) { \WP_CLI::warning( $issue ); }
		if ( $issues ) { \WP_CLI::error( count( $issues ) . ' DNS issue(s) found.' ); }
		\WP_CLI::success( 'DNS check passed.' );
	}

	/**
	 * Registers the commands when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services', self::class );
			\WP_CLI::add_command( 'beltoft-services dns-claims', DnsClaimsCommand::class );
			\WP_CLI::add_command( 'beltoft-services private-net', PrivateNetCommand::class );
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
	 * Shows who holds an IPv4 address, or held it at a time (for abuse reports). Read-only.
	 *
	 * Looks in the IP pool, the private networks and the holding log: resource, license, customer
	 * (id, name, email), product and resource state. Without --at every holding is listed, the
	 * current one with an empty "until". With --at only the holding that covered that time.
	 * Holdings are logged from the day this was added; an address held before then is shown from
	 * its resource's activation, with a note.
	 *
	 * ## OPTIONS
	 *
	 * <ip>
	 * : The IPv4 address, public or private.
	 *
	 * [--at=<datetime>]
	 * : Who held it then. UTC unless the text names a zone, e.g. "2026-10-29 14:30" or "2026-10-29T14:30:00+02:00".
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
	 *     wp beltoft-services who 192.0.2.10
	 *     wp beltoft-services who 192.0.2.10 --at="2026-10-29 14:30" --format=json
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function who( array $args, array $assoc_args ): void {
		$ip = trim( (string) ( $args[0] ?? '' ) );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			\WP_CLI::error( 'Give an IPv4 address.' );
		}

		$at = null;

		if ( isset( $assoc_args['at'] ) ) {
			try {
				$at = ( new \DateTimeImmutable( (string) $assoc_args['at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
			} catch ( \Exception $e ) {
				\WP_CLI::error( 'Cannot read --at as a date and time.' );
			}
		}

		$items  = IpWho::lookup( $ip, $at );
		$format = 'json' === ( $assoc_args['format'] ?? 'table' ) ? 'json' : 'table';

		if ( array() === $items ) {
			if ( 'json' === $format ) {
				\WP_CLI::log( '[]' );
			} else {
				\WP_CLI::warning( null === $at ? 'No resource holds or held ' . $ip . '.' : 'No resource held ' . $ip . ' at that time.' );
			}
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'ip', 'kind', 'resource_id', 'license_id', 'customer_id', 'name', 'email', 'product', 'state', 'from', 'until', 'note' ) );
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
