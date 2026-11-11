<?php
/**
 * Admin alerts about the hosting nodes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An hourly Action Scheduler job (plan 15.2). For every enabled node it asks the agent for `agent.health` and mails
 * the admin about what is wrong, each problem once a day at most:
 *
 * - the agent cannot be reached, or the health job fails;
 * - the agent's own monitor alerts (its `alerts` list, for example the database monitor of plan 7.3);
 * - isolation problems: a container whose port isolation is not on (the optional `isolation` map vmid => state, the
 *   agent's loop re-asserts it every 30 seconds, so a state other than `ok` that stays is a real problem);
 * - loader problems: sites whose loader file is refused or failed, counted over all accounts of the node (the
 *   customer sees their own in the account);
 * and then the capacity alerts: HostingCapacity::alert() (committed memory or disk over the alert share, once a day) and
 * HostingCapacity::overage_alerts() (over the budget, at 100 percent and each further 10 percent). The agent's health
 * answer may also carry a `host` member (mem_total_mb, mem_free_mb, disk_total_gb, disk_free_gb: what the node really has);
 * when it does, HostingCapacity::cross_check() compares it with what the plugin counts as committed, once a day.
 *
 * The mails carry node ids, container ids and states only: no secret, no customer data beyond ids.
 */
final class NodeHealth {

	public const HOOK = 'beltoft_services_hosting_health';
	public const CRON = '17 * * * *';

	/**
	 * Hooks the job and its schedule.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the hourly run once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON );
	}

	/**
	 * The job.
	 *
	 * @return array<string, string[]> Problems found, by node id (for tests and the CLI).
	 */
	public static function run(): array {
		if ( Settings::get( 'pause_all' ) ) {
			return array();
		}

		$found = array();

		foreach ( array_keys( Nodes::all() ) as $id ) {
			$problems = self::check( (string) $id );

			if ( array() !== $problems ) {
				$found[ (string) $id ] = $problems;
			}
		}

		Alerts::quietly( static fn() => HostingCapacity::alert() );
		Alerts::quietly( static fn() => HostingCapacity::overage_alerts() ); // A failing alert never fails the job.

		return $found;
	}

	/**
	 * One node: the problems found, already mailed.
	 *
	 * @param string $id Node id.
	 * @return string[] Problem texts.
	 */
	public static function check( string $id ): array {
		$node = Nodes::get( $id );

		if ( null === $node || ! $node['enabled'] ) {
			return array();
		}

		$problems = array();

		try {
			$agent = Clients::agent_for( $node );
		} catch ( \Throwable $e ) {
			unset( $e );

			return array( self::notify( $id, 'client', 'The agent client could not be built for this node.' ) );
		}

		$job = $agent->submit( 'agent.health', array(), 'health-' . $id . '-' . gmdate( 'YmdH', Jobs::now() ) );

		if ( is_wp_error( $job ) || 'failed' === ( $job['status'] ?? '' ) ) {
			$why = is_wp_error( $job ) ? $job->get_error_code() : 'the health job failed';

			return array( self::notify( $id, 'unreachable', 'The node agent did not answer: ' . $why . '.' ) );
		}

		if ( 'done' !== ( $job['status'] ?? '' ) ) {
			return array(); // Still running: the next hour reads it.
		}

		$result = (array) ( $job['result'] ?? array() );

		if ( is_array( $result['host'] ?? null ) ) {
			HostingCapacity::cross_check( $id, $result['host'] );
		}

		foreach ( array_slice( (array) ( $result['alerts'] ?? array() ), 0, 10 ) as $alert ) {
			$text       = is_array( $alert ) ? (string) ( $alert['message'] ?? $alert['kind'] ?? 'alert' ) : (string) $alert;
			$problems[] = self::notify( $id, 'alert:' . md5( $text ), 'The agent reports: ' . mb_substr( sanitize_text_field( $text ), 0, 200 ) );
		}

		$bad = array();

		foreach ( (array) ( $result['isolation'] ?? array() ) as $vmid => $state ) {
			if ( 'ok' !== (string) $state && 1 === preg_match( '/^\d{3,9}$/D', (string) $vmid ) ) {
				$bad[] = (string) $vmid . ' (' . mb_substr( sanitize_text_field( (string) $state ), 0, 40 ) . ')';
			}
		}

		if ( array() !== $bad ) {
			$problems[] = self::notify( $id, 'isolation', 'Port isolation is not on for container(s): ' . implode( ', ', array_slice( $bad, 0, 20 ) ) . '.' );
		}

		$loaders = array();

		foreach ( (array) ( $result['loaders'] ?? array() ) as $key => $loader ) {
			$state = is_array( $loader ) ? (string) ( $loader['state'] ?? '' ) : '';

			if ( 'error' === $state || 0 === strpos( $state, 'refused' ) ) {
				$loaders[] = (string) $key . ' (' . mb_substr( sanitize_text_field( $state ), 0, 40 ) . ')';
			}
		}

		if ( array() !== $loaders ) {
			$problems[] = self::notify( $id, 'loaders', count( $loaders ) . ' site(s) have a refused or failed loader file: ' . implode( ', ', array_slice( $loaders, 0, 10 ) ) . '.' );
		}

		return $problems;
	}

	/**
	 * Mails the admin about one problem, once a day per node and kind.
	 *
	 * @param string $id   Node id.
	 * @param string $kind Problem kind (part of the once-a-day key).
	 * @param string $text What is wrong.
	 * @return string The text.
	 */
	private static function notify( string $id, string $kind, string $text ): string {
		Alerts::notify( sprintf( 'Hosting node %s: attention', $id ), array( $text ), null, 'beltoft_services_hosting_health_' . md5( $id . '|' . $kind ) );

		return $text;
	}
}
