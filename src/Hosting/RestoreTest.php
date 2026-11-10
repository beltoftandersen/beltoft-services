<?php
/**
 * The weekly restore test.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 10.1: a backup that cannot be restored is no backup. Once a week one random live account's newest backup is
 * restored on the node into a scratch container with no network, its web server and PHP are started, its dump is
 * imported into a scratch database, the home page is fetched with a Host header, the answer must be HTTP 200 with
 * the title the site has, and the scratch container is deleted. All of that is the agent's operation
 * `backup.restore_test` {vmid, snapshot, scratch_vmid}; the plugin only picks the backup and records the answer.
 * The scratch container id is a node setting (`scratch_vmid`, outside the customer range, 0 = the test is off).
 * A failure mails the admin (once a day per node). The result of the last run is kept in the option
 * `beltoft_services_hosting_restore_test`.
 */
final class RestoreTest {

	public const HOOK   = 'beltoft_services_hosting_restore_test';
	public const CRON   = '30 6 * * 0';
	public const OPTION = 'beltoft_services_hosting_restore_test';

	/**
	 * Hooks the job and its weekly schedule.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the weekly run once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON );
	}

	/**
	 * The last result.
	 *
	 * @return array<string, mixed>
	 */
	public static function last(): array {
		$last = get_option( self::OPTION, array() );

		return is_array( $last ) ? $last : array();
	}

	/**
	 * The job: one account per node with a scratch container id.
	 *
	 * @return array<string, array<string, mixed>> Result by node id.
	 */
	public static function run(): array {
		if ( Settings::get( 'pause_all' ) || ! Settings::get( 'hosting_restore_test' ) ) {
			return array();
		}

		$results = array();

		foreach ( Nodes::all() as $id => $node ) {
			if ( $node['enabled'] && (int) $node['scratch_vmid'] > 0 ) {
				$results[ (string) $id ] = self::test_node( (string) $id );
			}
		}

		if ( array() !== $results ) {
			update_option( self::OPTION, array( 'at' => Jobs::now(), 'nodes' => $results ), false );
		}

		return $results;
	}

	/**
	 * One node.
	 *
	 * @param string $id Node id.
	 * @return array<string, mixed> ok (bool), and why or the account.
	 */
	public static function test_node( string $id ): array {
		$node = Nodes::get( $id );

		if ( null === $node || (int) $node['scratch_vmid'] < 100 || ( (int) $node['scratch_vmid'] >= (int) $node['vmid_min'] && (int) $node['scratch_vmid'] <= (int) $node['vmid_max'] ) ) {
			return self::fail( $id, 'The scratch container id is not set or is inside the customer range.' );
		}

		if ( (int) $node['scratch_vmid'] === (int) $node['template'] ) {
			return self::fail( $id, 'The scratch container id is the container template.' );
		}

		$pick = self::pick( $id );

		if ( null === $pick ) {
			return array( 'ok' => true, 'skipped' => 'No account with a backup on this node.' );
		}

		list( $r, $backup ) = $pick;

		try {
			$agent = Clients::agent_for( $node );
		} catch ( \Throwable $e ) {
			unset( $e );

			return self::fail( $id, 'The agent client could not be built.' );
		}

		$record = (array) Records::for_resource( $r->id() );
		$job    = $agent->submit(
			'backup.restore_test',
			array(
				'vmid'         => (int) $record['vmid'],
				'snapshot'     => 'b' . (int) $backup['ctime'],
				'scratch_vmid' => (int) $node['scratch_vmid'],
			),
			'restoretest-' . gmdate( 'oW', Jobs::now() ) . '-' . $r->id() . '-' . (int) $backup['ctime']
		);

		if ( is_wp_error( $job ) ) {
			return self::fail( $id, 'The agent refused the test: ' . $job->get_error_code() . '.' );
		}

		if ( 'done' !== ( $job['status'] ?? '' ) ) {
			return 'failed' === ( $job['status'] ?? '' )
				? self::fail( $id, 'The restore test failed: ' . mb_substr( sanitize_text_field( (string) ( $job['error'] ?? '' ) ), 0, 200 ), $r )
				: array( 'ok' => true, 'pending' => true, 'resource' => $r->id() ); // Still running: the next look reads it (same job id).
		}

		$result = (array) ( $job['result'] ?? array() );

		if ( empty( $result['ok'] ) || 200 !== (int) ( $result['status'] ?? 0 ) || empty( $result['title_match'] ) ) {
			return self::fail( $id, sprintf( 'The restore test did not pass (HTTP %d, title %s).', (int) ( $result['status'] ?? 0 ), ! empty( $result['title_match'] ) ? 'matches' : 'differs' ), $r );
		}

		return array( 'ok' => true, 'resource' => $r->id(), 'backup' => (int) $backup['ctime'] );
	}

	/**
	 * A random ready account of the node with a stored backup, and its newest backup.
	 *
	 * @param string $id Node id.
	 * @return array{0: Resource, 1: array<string, mixed>}|null
	 */
	private static function pick( string $id ): ?array {
		$candidates = array();

		foreach ( Records::resource_ids( $id ) as $resource_id ) {
			$r = Resource::find( $resource_id );

			if ( $r && 'ready' === $r->get( 'state' ) && 'ready' === $r->get( 'desired' ) && array() !== HostingBackups::stored( $r ) ) {
				$candidates[] = $r;
			}
		}

		if ( array() === $candidates ) {
			return null;
		}

		/**
		 * Which candidate the restore test uses (an index). Tests pick one; the default is random.
		 *
		 * @param int $index Index.
		 * @param int $count Candidates.
		 */
		$index = (int) apply_filters( 'beltoft_services_hosting_restore_pick', random_int( 0, count( $candidates ) - 1 ), count( $candidates ) );
		$r     = $candidates[ max( 0, min( count( $candidates ) - 1, $index ) ) ];

		return array( $r, HostingBackups::stored( $r )[0] );
	}

	/**
	 * A failed test: the result and one admin mail a day per node.
	 *
	 * @param string        $id  Node id.
	 * @param string        $why What went wrong.
	 * @param Resource|null $r   The account tested.
	 * @return array<string, mixed>
	 */
	private static function fail( string $id, string $why, ?Resource $r = null ): array {
		$lines = array( $why );

		if ( $r ) {
			$lines[] = sprintf( 'Resource: %d', $r->id() );
		}

		$lines[] = 'A backup that cannot be restored is no backup: look at it before the next night.';

		Alerts::notify( sprintf( 'Hosting restore test failed on node %s', $id ), $lines, null, 'beltoft_services_hosting_restore_test_alert_' . md5( $id ) );

		return array( 'ok' => false, 'why' => $why, 'resource' => $r ? $r->id() : 0 );
	}
}
