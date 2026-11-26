<?php
/**
 * WP-CLI commands.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Jobs;
use BeltoftServices\Plugin;
use BeltoftServices\Proxmox\Network\IpWho;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Beltoft Services from the command line.
 */
final class Commands {
	/**
	 * Runs the plugin's due jobs every few seconds for a while, so a step that is a few seconds away does not wait
	 * for the once-a-minute cron tick. Only this plugin's reconcile and customer-action jobs are run; Action Scheduler's
	 * own runner keeps everything else. It honours the kill switch, the resource locks and the run budget.
	 *
	 * Best as always-on services (systemd), several side by side: each claims one job at a time and the resource lock keeps
	 * two runners off the same service. A stop signal ends a runner between two jobs, so stop the runners before files of
	 * the site are replaced and start them after. Example ExecStart, with Restart=always:
	 * `wp beltoft-services run-due --seconds=600 --interval=1 --path=<wordpress path> --quiet`
	 * Or in cron next to the normal WordPress cron line, under `flock -n` (one runner, a slow minute can skip the next one):
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
	 * Registers the commands when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services', self::class );
		}
	}

	/**
	 * Shows who holds an IPv4 or IPv6 address, or held it at a time (for abuse reports). Read-only.
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
	 * : The address: IPv4 (public or private) or IPv6. An IPv6 address inside a server's own /80 is looked up as that server's.
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

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) || false !== strpos( $ip, '%' ) ) {
			\WP_CLI::error( 'Give an IPv4 or IPv6 address.' );
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

		foreach ( array( 'uuid', 'driver', 'state', 'desired', 'remote_id', 'protected', 'plan_dirty', 'attempts', 'next_attempt_at', 'paused_at', 'warned_at', 'deletes_at', 'last_error', 'plan' ) as $key ) {
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
