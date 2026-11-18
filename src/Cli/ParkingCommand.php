<?php
/**
 * WP-CLI: domain parking.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Domains\Parking;
use BeltoftServices\Hosting\ParkedEdge;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the parked domains and parks the existing ones that qualify.
 */
final class ParkingCommand {

	/**
	 * Lists the parked domains.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv. Default table.
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$items = array();

		foreach ( Parking::all() as $row ) {
			$items[] = array(
				'domain' => $row['zone'],
				'since'  => gmdate( 'Y-m-d H:i', (int) ( $row['at'] ?? 0 ) ),
				'sets'   => implode( ' ', array_keys( $row['sets'] ) ),
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, array( 'domain', 'since', 'sets' ) );
	}

	/**
	 * Parks the domains on our name servers that have no address at the root and at www. A dry run unless --apply is given.
	 * Needs a parking address in the settings. Writes DNS through the same writer as everything else (the live DNS server only
	 * when the registrar mode and BELTOFT_SERVICES_DOMAINS_LIVE say so).
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the parking records. Without it nothing is written.
	 *
	 * [--format=<format>]
	 * : table, json, csv. Default table.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function add( array $args, array $assoc_args ): void {
		if ( ! Parking::enabled() ) {
			\WP_CLI::error( 'Parking is off: set a parking IPv4 or IPv6 address in the domain settings first.' );
		}

		$apply = ! empty( $assoc_args['apply'] );
		$rows  = Parking::sweep( $apply );

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'zone', 'result' ) );
		\WP_CLI::log( $apply ? 'Done.' : 'Dry run: nothing was written. Add --apply to park the zones marked would_park.' );
	}

	/**
	 * Sends the list of parked names to the edge of every enabled hosting node now (the same job that runs after a change).
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function push( array $args, array $assoc_args ): void {
		$names = ParkedEdge::hosts();
		$rows  = array();

		foreach ( ParkedEdge::run() as $node => $state ) {
			$rows[] = array( 'node' => $node, 'result' => $state );
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'node', 'result' ) );
		\WP_CLI::log( count( $names ) . ' names sent to ' . count( $rows ) . ' node(s).' );
	}
}
