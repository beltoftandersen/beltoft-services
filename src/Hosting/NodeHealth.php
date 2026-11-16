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
 * - the container bridge missing (`bridge` false) and a background loop of the agent that failed (`loops`, each with `ok`); these
 *   are what the agent's health answer carries today;
 * - little free space: `storage.node` (container storage) and `storage.db` (data disk of the shared database server), under 10
 *   percent or 5 GB; and `db_guard.state` "tripped" (the agent set accounts read only, they are named);
 * - loader problems: sites whose loader file is refused or failed, counted over all accounts of the node (the
 *   customer sees their own in the account);
 * and then the capacity alerts: HostingCapacity::alert() (committed memory or disk over the alert share, once a day) and
 * HostingCapacity::overage_alerts() (over the budget, at 100 percent and each further 10 percent). The agent never returned
 * the `host` and `isolation` members this job once read, so they are not read; the isolation loop shows in `loops`.
 *
 * The mails carry node ids, container ids and states only: no secret, no customer data beyond ids.
 */
final class NodeHealth {

	public const HOOK = 'beltoft_services_hosting_health';
	public const READ = 'beltoft_services_hosting_health_read';

	/** Looks at a health job that was still running, a minute apart. */
	public const TRIES = 10;
	public const CRON = '17 * * * *';

	/** Free space under 10 percent of the disk, or under 5 GB, is a problem (the agent's own guard floor is its setting). */
	public const FREE_PERCENT   = 10;
	public const FREE_MIN_BYTES = 5368709120;

	/**
	 * Hooks the job and its schedule.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::READ, array( __CLASS__, 'read' ), 10, 3 );
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

		$job_id = 'health-' . $id . '-' . gmdate( 'YmdH', Jobs::now() );
		$job    = $agent->submit( 'agent.health', array(), $job_id );

		if ( is_wp_error( $job ) || 'failed' === ( $job['status'] ?? '' ) ) {
			$why = is_wp_error( $job ) ? $job->get_error_code() : 'the health job failed';

			return array( self::notify( $id, 'unreachable', 'The node agent did not answer: ' . $why . '.' ) );
		}

		if ( 'done' !== ( $job['status'] ?? '' ) ) {
			// Still running (the normal answer of a busy agent): the same job is read again in a minute, not a new one next hour.
			Jobs::schedule_once( Jobs::now() + MINUTE_IN_SECONDS, self::READ, array( $id, $job_id, 1 ) );

			return array();
		}

		return self::judge( $id, $job );
	}

	/**
	 * Reads a health job that was still running.
	 *
	 * @param string $id     Node id.
	 * @param string $job_id The job's id.
	 * @param int    $try    Look, from 1.
	 */
	public static function read( string $id, string $job_id, int $try = 1 ): void {
		$node = Nodes::get( $id );

		if ( Settings::get( 'pause_all' ) || null === $node || ! $node['enabled'] || 1 !== preg_match( '/^health-[A-Za-z0-9_.-]+-\d{10}$/D', $job_id ) ) {
			return;
		}

		try {
			$agent = Clients::agent_for( $node );
		} catch ( \Throwable $e ) {
			unset( $e );

			return;
		}

		$job = $agent->submit( 'agent.health', array(), $job_id ); // The same id: the agent answers with the job it has.

		if ( is_wp_error( $job ) || 'failed' === ( $job['status'] ?? '' ) ) {
			self::notify( $id, 'unreachable', 'The node agent did not answer: ' . ( is_wp_error( $job ) ? $job->get_error_code() : 'the health job failed' ) . '.' );

			return;
		}

		if ( 'done' === ( $job['status'] ?? '' ) ) {
			self::judge( $id, $job );

			return;
		}

		if ( $try < self::TRIES ) {
			Jobs::schedule_once( Jobs::now() + MINUTE_IN_SECONDS, self::READ, array( $id, $job_id, $try + 1 ) );

			return;
		}

		self::notify( $id, 'unreachable', 'The node agent did not finish the health job in ' . self::TRIES . ' minutes.' );
	}

	/**
	 * What a finished health job says.
	 *
	 * @param string               $id  Node id.
	 * @param array<string, mixed> $job The job.
	 * @return string[] Problem texts.
	 */
	private static function judge( string $id, array $job ): array {
		$problems = array();
		$result = (array) ( $job['result'] ?? array() );

		foreach ( array_slice( (array) ( $result['alerts'] ?? array() ), 0, 10 ) as $alert ) {
			$text       = is_array( $alert ) ? (string) ( $alert['message'] ?? $alert['kind'] ?? 'alert' ) : (string) $alert;
			$problems[] = self::notify( $id, 'alert:' . md5( $text ), 'The agent reports: ' . mb_substr( sanitize_text_field( $text ), 0, 200 ) );
		}

		$problems = array_merge( $problems, self::storage_problems( $id, $result ), self::guard_problems( $id, $result ) );

		// What the agent's health answer really carries: the container bridge and the state of each background loop.
		if ( false === ( $result['bridge'] ?? null ) ) {
			$problems[] = self::notify( $id, 'bridge', 'The network bridge of the containers is missing on the node.' );
		}

		$loops = array();

		foreach ( (array) ( $result['loops'] ?? array() ) as $name => $loop ) {
			if ( is_array( $loop ) && array_key_exists( 'ok', $loop ) && ! $loop['ok'] && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,40}$/D', (string) $name ) ) {
				$loops[] = (string) $name . ' (' . mb_substr( sanitize_text_field( (string) ( $loop['error'] ?? 'failed' ) ), 0, 40 ) . ')';
			}
		}

		if ( array() !== $loops ) {
			$problems[] = self::notify( $id, 'loops', 'Agent loop(s) failed: ' . implode( ', ', array_slice( $loops, 0, 10 ) ) . '.' );
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
	 * Free space the agent reports: the container storage of the node (`storage.node`) and the data disk of the shared database
	 * server (`storage.db`). A reading with an `error`, or an older agent without the member, is not a low reading.
	 *
	 * @param string               $id     Node id.
	 * @param array<string, mixed> $result The health result.
	 * @return string[] Problem texts.
	 */
	private static function storage_problems( string $id, array $result ): array {
		$storage  = (array) ( $result['storage'] ?? array() );
		$problems = array();
		$labels   = array(
			/* translators: 1: free space, 2: total. */
			'node' => 'Container storage on the node has %1$s free of %2$s.',
			'db'   => 'The database server has %1$s free of %2$s.',
		);

		foreach ( $labels as $key => $format ) {
			$row = is_array( $storage[ $key ] ?? null ) ? $storage[ $key ] : array();

			if ( ! isset( $row['total_bytes'], $row['free_bytes'] ) || (int) $row['total_bytes'] <= 0 ) {
				continue;
			}

			$total = (int) $row['total_bytes'];
			$free  = max( 0, (int) $row['free_bytes'] );

			if ( $free < self::FREE_MIN_BYTES || $free * 100 < $total * self::FREE_PERCENT ) {
				$problems[] = self::notify( $id, 'storage_' . $key, sprintf( $format, self::gb( $free ), self::gb( $total ) ) );
			}
		}

		return $problems;
	}

	/**
	 * The database guard of the agent (`db_guard`): when it tripped, the accounts it set read only are named.
	 *
	 * @param string               $id     Node id.
	 * @param array<string, mixed> $result The health result.
	 * @return string[] Problem texts.
	 */
	private static function guard_problems( string $id, array $result ): array {
		$guard = (array) ( $result['db_guard'] ?? array() );

		if ( 'tripped' !== (string) ( $guard['state'] ?? '' ) ) {
			return array();
		}

		$accounts = array();

		foreach ( (array) ( $guard['readonly'] ?? array() ) as $account ) {
			if ( is_string( $account ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/D', $account ) ) {
				$accounts[] = $account;
			}
		}

		$text = 'The database guard on the node set these accounts read only: ' . ( array() === $accounts ? 'none' : implode( ', ', array_slice( $accounts, 0, 20 ) ) ) . '. Free space on the database server is under the guard floor. The agent does not lift the flag itself.';

		return array( self::notify( $id, 'db_guard', $text ) );
	}

	/**
	 * Bytes as GB with one decimal.
	 *
	 * @param int $bytes Bytes.
	 */
	private static function gb( int $bytes ): string {
		return number_format( $bytes / 1073741824, 1, '.', '' ) . ' GB';
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
