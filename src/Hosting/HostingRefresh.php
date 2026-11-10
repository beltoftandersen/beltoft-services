<?php
/**
 * Keeps the in-container files current after a template change.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A container is a linked clone of the template it was made from, so a newer template reaches it only through the agent:
 * `ct.refresh {vmid}` compares the runner, the mail helper and the must-use plugins in the container with the agent's
 * copies and replaces what differs (answer: updated names, version = 12 hex characters). When a node's template setting
 * changes, a sweep runs at once; the same sweep runs every half hour, so an account that was not running (the agent's
 * "container not running") is done on a later pass. Each account is done once per template: meta `runner_for` is the
 * template id the account was refreshed for and `runner_version` the agent's answer (shown in the admin license box).
 */
final class HostingRefresh {

	public const HOOK = 'beltoft_services_hosting_refresh';
	public const CRON = '*/30 * * * *';

	/**
	 * Hooks the job, its schedule and the template-change trigger.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
		add_action( 'beltoft_services_hosting_template_changed', array( __CLASS__, 'queue' ) );
	}

	/**
	 * Schedules the half hourly sweep once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON );
	}

	/**
	 * Queues one sweep now (a node's template setting changed).
	 */
	public static function queue(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * The sweep: every ready account of every enabled node whose files were not refreshed for the node's template.
	 *
	 * @return array{done: int, skipped: int, failed: int} Counts.
	 */
	public static function run(): array {
		$count = array( 'done' => 0, 'skipped' => 0, 'failed' => 0 );

		if ( Settings::get( 'pause_all' ) ) {
			return $count;
		}

		foreach ( Nodes::all() as $id => $listed ) {
			if ( ! $listed['enabled'] || (int) $listed['template'] <= 0 ) {
				continue;
			}

			$node = Nodes::get( (string) $id );

			try {
				$agent = null === $node ? null : Clients::agent_for( $node );
			} catch ( \Throwable $e ) {
				unset( $e );
				$agent = null;
			}

			if ( null === $agent ) {
				continue;
			}

			foreach ( Records::resource_ids( (string) $id ) as $resource_id ) {
				$r = Resource::find( $resource_id );

				if ( ! $r || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || (int) $r->get( 'runner_for' ) === (int) $node['template'] ) {
					continue;
				}

				$outcome = self::refresh( $r, $agent, (int) $node['template'] );
				++$count[ $outcome ];
			}
		}

		return $count;
	}

	/**
	 * One account: a job per attempt (the agent returns the stored result for a job id it has seen, so a failed
	 * attempt needs a new id).
	 *
	 * @param Resource $r        Resource.
	 * @param Agent    $agent    Agent.
	 * @param int      $template Template id of the node.
	 * @return string done, skipped (not running or still running) or failed.
	 */
	private static function refresh( Resource $r, Agent $agent, int $template ): string {
		$record = Records::for_resource( $r->id() );

		if ( ! $record || null === $record['vmid'] ) {
			return 'skipped';
		}

		$gen = (int) $r->get( 'refresh_gen' );
		$id  = 'refresh:' . $r->get( 'uuid' ) . ':' . $template . ':' . $gen;
		$job = $agent->submit( 'ct.refresh', array( 'vmid' => (int) $record['vmid'] ), $id );

		if ( is_wp_error( $job ) ) {
			return 'skipped';
		}

		$status = (string) ( $job['status'] ?? '' );

		if ( 'queued' === $status || 'running' === $status ) {
			return 'skipped'; // Read again by the next sweep (same job id).
		}

		if ( 'done' === $status ) {
			$result = (array) ( $job['result'] ?? array() );
			$r->set(
				array(
					'runner_for'     => $template,
					'runner_version' => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $result['version'] ?? '' ) ) ),
					'refresh_gen'    => $gen + 1,
				)
			);

			return 'done';
		}

		$r->set( array( 'refresh_gen' => $gen + 1 ) );

		return false !== strpos( (string) ( $job['error'] ?? '' ), 'not running' ) ? 'skipped' : 'failed';
	}
}
