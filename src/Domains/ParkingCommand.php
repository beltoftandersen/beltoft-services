<?php
/**
 * WP-CLI: domain parking.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

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
		/**
		 * The names the edge would serve as parked, or null when nothing answers (no hosting feature).
		 *
		 * @param array<int, string>|null $names Null until something answers.
		 */
		$names = apply_filters( 'beltoft_services_parked_edge_hosts', null );

		/**
		 * Sends the parked names to the edge now. Answer: node id => done, running or the reason it failed; null when nothing answers.
		 *
		 * @param array<string, string>|null $result Null until something answers.
		 */
		$result = apply_filters( 'beltoft_services_parked_edge_push', null );

		if ( ! is_array( $names ) || ! is_array( $result ) ) {
			\WP_CLI::log( 'Nothing pushes parked names on this site.' );

			return;
		}

		$rows = array();

		foreach ( $result as $node => $state ) {
			$rows[] = array( 'node' => $node, 'result' => $state );
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'node', 'result' ) );
		\WP_CLI::log( count( $names ) . ' names sent to ' . count( $rows ) . ' node(s).' );
	}

	/**
	 * Registers the command when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services parking', self::class );
		}
	}
}
