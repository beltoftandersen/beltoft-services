<?php
/**
 * Tells the hosting nodes' edge which names are parked.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Background;
use BeltoftServices\Domains\Parking;
use BeltoftServices\Jobs;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A parked domain points at the edge of a hosting node (setting `parking_ipv4`). The edge shows the parking page, and gets a
 * certificate for a name, only when the name is in its map as parked: the agent operation `edge.parked` sets the whole list
 * (the apex and www of every parked zone) on every enabled node. A name that an account's routes hold is never replaced.
 *
 * One Background job, queued when a zone is parked or unparked (the actions of Domains\Parking), and by `wp beltoft-services
 * parking push`. There is no daily hosting job to hang a resync on, so a push that did not reach every node stays marked
 * (option `beltoft_services_parked_push`) and is tried again: the job queues itself a few minutes later, and the Background
 * watchdog queues it when the mark is there and no job is.
 */
final class ParkedEdge {

	public const HOOK    = 'beltoft_services_hosting_parked_push';
	public const PENDING = 'beltoft_services_parked_push';
	public const RETRY   = 300;

	/** Most names the agent takes in one call. */
	public const MAX = 5000;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'beltoft_services_parked', array( __CLASS__, 'queue' ) );
		add_action( 'beltoft_services_unparked', array( __CLASS__, 'queue' ) );
		Background::declare(
			self::HOOK,
			array(
				'due'   => array( __CLASS__, 'due' ),
				'label' => 'Parked names to the edge',
			)
		);
	}

	/**
	 * Marks the list as not sent and queues the job.
	 */
	public static function queue(): void {
		update_option( self::PENDING, (string) microtime( true ), false );
		Jobs::schedule_once( Jobs::now(), self::HOOK );
	}

	/**
	 * For the watchdog: a job is due while the mark is there.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	public static function due(): array {
		return false === get_option( self::PENDING, false ) ? array() : array( array() );
	}

	/**
	 * The names: the apex and www of every parked zone, sorted, at most MAX.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		$hosts = array();

		foreach ( Parking::all() as $row ) {
			$hosts[] = (string) $row['zone'];
			$hosts[] = 'www.' . $row['zone'];
		}

		$hosts = array_values( array_unique( $hosts ) );
		sort( $hosts );

		return array_slice( $hosts, 0, self::MAX );
	}

	/**
	 * The job: sends the list to every enabled node. The mark is cleared only when every node took it and the list did not change
	 * meanwhile; otherwise the job is queued again.
	 *
	 * @return array<string, string> Node id => done, running or the reason it failed (for tests and the CLI).
	 */
	public static function run(): array {
		$out = array();

		if ( Settings::get( 'pause_all' ) ) {
			return $out;
		}

		$mark  = get_option( self::PENDING, false );
		$stamp = false === $mark ? (string) microtime( true ) : (string) $mark;
		$hosts = self::hosts();
		$ok    = true;

		foreach ( Nodes::all() as $id => $node ) {
			if ( empty( $node['enabled'] ) ) {
				continue;
			}

			$out[ (string) $id ] = self::send( (string) $id, $node, $hosts, $stamp );
			$ok                  = $ok && 'done' === $out[ (string) $id ];
		}

		$now = get_option( self::PENDING, false );

		if ( $ok && ( false === $now || (string) $now === $stamp ) ) {
			delete_option( self::PENDING );

			return $out;
		}

		// A node did not take it, or the list changed while sending: the mark stays and the job comes again.
		// A job that failed is final at the agent (the same id gives the same answer): the next try has a new mark, so a new id.
		$stuck = array_diff( $out, array( 'done', 'running' ) );

		if ( array() !== $stuck || false === $now ) {
			update_option( self::PENDING, array() !== $stuck ? (string) microtime( true ) : $stamp, false );
		}

		Jobs::schedule_once( Jobs::now() + ( in_array( 'running', $out, true ) || $ok ? MINUTE_IN_SECONDS : self::RETRY ), self::HOOK );

		return $out;
	}

	/**
	 * One node.
	 *
	 * @param string               $id    Node id.
	 * @param array<string, mixed> $node  Node.
	 * @param string[]             $hosts Names.
	 * @param string               $stamp The mark the list was read under.
	 * @return string done, running or a short reason.
	 */
	private static function send( string $id, array $node, array $hosts, string $stamp ): string {
		try {
			$agent = Clients::agent_for( $node );
		} catch ( \Throwable $e ) {
			unset( $e );

			return 'no_client';
		}

		// The id carries the mark and the list, so a later, different list is a new job and a retry of this one is the same job.
		$job_id = substr( 'parked-' . $id . '-' . preg_replace( '/[^0-9]/', '', $stamp ) . '-' . substr( sha1( (string) wp_json_encode( $hosts ) ), 0, 12 ), 0, 120 );
		$job    = $agent->submit( 'edge.parked', array( 'hosts' => $hosts ), $job_id );

		if ( is_wp_error( $job ) ) {
			return $job->get_error_code();
		}

		$status = (string) ( $job['status'] ?? '' );

		return 'done' === $status || 'failed' === $status ? ( 'done' === $status ? 'done' : 'failed' ) : 'running';
	}
}
