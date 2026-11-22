<?php
/**
 * Network commands: the IP filter report, the quarantine lift and the IPv4 pool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Pve\Clients;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Network commands: the IP filter report, the quarantine lift and the IPv4 pool.
 */
final class NetworkCommand {
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
	 * [--yes]
	 * : Confirms the server named in the output (without it nothing is changed).
	 *
	 * @subcommand quarantine-lift
	 */
	public function quarantine_lift( array $args, array $assoc_args ): void {
		$resource = Resource::for_license( (int) ( $args[0] ?? 0 ) );
		if ( ! $resource ) { \WP_CLI::error( 'The license has no live resource.' ); }
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( sprintf( 'This lifts the network quarantine of server "%s" (resource %d). Add --yes to confirm.', (string) $resource->get( 'server_name' ), $resource->id() ) );
		}
		if ( ! Quarantine::request_lift( $resource, 'wp-cli' ) ) { \WP_CLI::error( 'This server has no network quarantine.' ); }
		Jobs::act( $resource->id(), 'fw_quarantine_lift' );
		$fresh = Resource::find( $resource->id() );
		if ( $fresh && Quarantine::active( $fresh ) ) {
			\WP_CLI::error( 'The quarantine is still on; the lift will be tried again. See the license history.' );
		}
		\WP_CLI::success( 'Network quarantine lifted.' );
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
		$result = \BeltoftServices\Proxmox\Network\IpPoolScreen::apply(
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
	 * Registers each subcommand under `beltoft-services` when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services ipfilter-report', array( self::class, 'ipfilter_report' ) );
			\WP_CLI::add_command( 'beltoft-services quarantine-lift', array( self::class, 'quarantine_lift' ) );
			\WP_CLI::add_command( 'beltoft-services ip-pool-add', array( self::class, 'ip_pool_add' ) );
			\WP_CLI::add_command( 'beltoft-services ip-pool-block', array( self::class, 'ip_pool_block' ) );
			\WP_CLI::add_command( 'beltoft-services ip-pool-release', array( self::class, 'ip_pool_release' ) );
			\WP_CLI::add_command( 'beltoft-services ip-pool-list', array( self::class, 'ip_pool_list' ) );
		}
	}
}
