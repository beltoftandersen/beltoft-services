<?php
/**
 * WP-CLI: the pool of customer private networks.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Proxmox\PrivateNets;

defined( 'ABSPATH' ) || exit;

/**
 * Operator seeding of the private /28 networks. Nothing here calls Proxmox: the matching VLAN must already
 * be allowed on the cluster's private bridge.
 */
final class PrivateNetCommand {

	/**
	 * Adds one private /28 and its VLAN to the pool, free.
	 *
	 * ## OPTIONS
	 *
	 * <vlan>
	 * : The VLAN id (1 to 4094), not used by the public network.
	 *
	 * <cidr>
	 * : A private /28 aligned to 16 addresses, such as 10.64.0.0/28.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services private-net add 1000 10.64.0.0/28
	 *
	 * @subcommand add
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function add( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		if ( ! ctype_digit( (string) ( $args[0] ?? '' ) ) ) {
			\WP_CLI::error( 'Give the VLAN id as a number from 1 to 4094.' );
		}

		try {
			$added = PrivateNets::add( (int) $args[0], (string) ( $args[1] ?? '' ) );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'The pool is busy. Try again.' );
		}

		if ( ! $added ) {
			\WP_CLI::error( 'Nothing was added: give a VLAN from 1 to 4094 that is not in use and an aligned private /28 that is not in the pool yet.' );
		}

		\WP_CLI::success( 'Private network added.' );
	}

	/**
	 * Lists the private networks.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );

		$items = array();

		foreach ( PrivateNets::rows() as $row ) {
			$items[] = array(
				'vlan'      => (string) $row['vlan'],
				'cidr'      => $row['cidr'],
				'state'     => $row['state'],
				'user'      => null === $row['user_id'] ? '' : (string) $row['user_id'],
				'addresses' => (string) $row['addresses'],
			);
		}

		\WP_CLI\Utils\format_items( 'json' === ( $assoc_args['format'] ?? 'table' ) ? 'json' : 'table', $items, array( 'vlan', 'cidr', 'state', 'user', 'addresses' ) );
	}
}
