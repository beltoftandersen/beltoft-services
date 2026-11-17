<?php
/**
 * The scheduled jobs of a website: save the whole list, send it again after a restore, read the last runs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteCron;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The scheduled jobs of a website (Hosting\SiteCron says what a job may be). The list is the site flag `cron`; a save replaces the whole list on the
 * node in one agent call (`site.cron_set`), so it is one change of the shared controller (`set_cron`): the request checks everything and answers at
 * once, the job makes the call, and the stored list changes only when the node has taken it (a failed call leaves the old list as it was). The list
 * travels encrypted in the step memory (the page text of a job, an address with a key in it, is not kept in the arguments of the job). Reached
 * through the HostingActions facade; results are arrays and a refusal is a WP_Error.
 */
final class CronActions {

	use Base;

	/** How long a read of the last runs is reused (seconds). The node runs a job every few minutes at most; a save clears it. */
	public const STATUS_TTL = 60;

	/** How long a page load waits for the node when it reads the last runs (seconds). */
	public const STATUS_WAIT = 3;

	/**
	 * Transient of one website's cached read.
	 *
	 * @param string $slug Website.
	 */
	private function status_key( string $slug ): string {
		return 'bs_cron_status_' . md5( $this->r->id() . '|' . $slug );
	}

	/**
	 * What a website's list has to be to be saved: the plan's limit, the offered PHP versions, the names the website serves, whether it has
	 * WordPress, and the ids that may be kept.
	 *
	 * @param array<string, mixed> $site Site row.
	 * @param string[]             $keep Ids that may be kept.
	 * @return array<string, mixed>
	 */
	private function context( array $site, array $keep ): array {
		return array(
			'limit'        => SiteCron::limit( $this->plan ),
			'php_versions' => Config::php_versions(),
			'names'        => SiteCron::own_names( $this->r->id(), $site ),
			'wordpress'    => Sites::has_wordpress( $site ),
			'keep'         => $keep,
		);
	}

	/**
	 * Checks the list a customer sent and returns the list to store (with ids), or the refusal. Nothing is sent anywhere.
	 *
	 * @param array<int, mixed> $jobs Jobs as sent.
	 * @param string            $slug Website.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function prepare_cron( array $jobs, string $slug ) {
		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site || ! empty( $site['flags']['pending'] ) ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		return SiteCron::normalise( $jobs, $this->context( $site, array_column( SiteCron::stored( $site ), 'id' ) ) );
	}

	/**
	 * Replaces the whole list of the website's scheduled jobs. A change of the shared controller: the list is the secret first argument (kept
	 * encrypted until the change ends), the website the second.
	 *
	 * @param string $jobs The checked list as JSON (see HostingActions::queue()).
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_cron( string $jobs, string $slug ) {
		return $this->locked( fn() => $this->set_cron_locked( $jobs, $slug ) );
	}

	/**
	 * set_cron with the site lock held.
	 *
	 * @param string $json The list as JSON.
	 * @param string $slug Website.
	 * @return mixed
	 */
	private function set_cron_locked( string $json, string $slug ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site || ! empty( $site['flags']['pending'] ) ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$list = json_decode( $json, true );

		if ( ! is_array( $list ) ) {
			return $this->error( 'invalid', __( 'The scheduled jobs are not a list.', 'beltoft-services' ) );
		}

		// In the job the ids are the ones the request made; in the request the website's own are the only ones that stay.
		$keep = $this->in_job() ? array_column( $list, 'id' ) : array_column( SiteCron::stored( $site ), 'id' );
		$jobs = SiteCron::normalise( $list, $this->context( $site, array_map( 'strval', $keep ) ) );

		if ( is_wp_error( $jobs ) ) {
			return $jobs;
		}

		// What the list says may carry a key in an address: if the agent echoes any of it in an error, it is masked.
		$this->secrets[] = $json;
		$this->defer_with( array( 'slug' => $slug, 'count' => count( $jobs ) ) );

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'site_cron_' . $slug, 'site.cron_set', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'jobs' => SiteCron::for_agent( $jobs ) ) );

		if ( is_wp_error( $done ) ) {
			return $this->refused( $done );
		}

		Sites::update(
			$this->r->id(),
			$slug,
			array(
				'flags' => array(
					'cron'    => $jobs,
					'cron_wp' => array(
						'wp_config'        => ! empty( $done['wp_config'] ),
						'wp_cron_disabled' => ! empty( $done['wp_cron_disabled'] ),
					),
				),
			)
		);
		( \BeltoftServices\Resource::find( $this->r->id() ) ?? $this->r )->set( array( SiteCron::UNSUPPORTED => 0 ) );
		delete_transient( $this->status_key( $slug ) );

		return array( 'slug' => $slug, 'count' => count( $jobs ) );
	}

	/**
	 * What a save that did not work ends with: the customer's sentence and whether staff are told. An agent that does not have the operation is
	 * expected (nothing was changed).
	 *
	 * @param \WP_Error $e The failed call.
	 */
	private function refused( \WP_Error $e ): \WP_Error {
		$data = $e->get_error_data();
		$why  = is_array( $data ) ? (string) ( $data['why'] ?? '' ) : '';

		if ( false !== strpos( $why, 'unknown_op' ) ) {
			( \BeltoftServices\Resource::find( $this->r->id() ) ?? $this->r )->set( array( SiteCron::UNSUPPORTED => Jobs::now() ) );

			return new \WP_Error( 'failed', $e->get_error_message(), array( 'text' => __( 'Scheduled jobs are not available on your server yet. Your jobs were not changed.', 'beltoft-services' ), 'expected' => true, 'why' => $why ) );
		}

		return new \WP_Error( 'failed', $e->get_error_message(), array( 'text' => __( 'Saving the scheduled jobs did not work. Your jobs were not changed.', 'beltoft-services' ), 'why' => $why ) );
	}

	/**
	 * Sends the stored list of every website that has one to the node again (a restore of the whole account put the container back as it was, with the
	 * jobs it had then). The stored list is the truth. A change of the shared controller, queued by SiteCron::resend_run(); in a job each website is
	 * one agent step, and the websites that could not be sent are named in the failure staff are told about. A node that does not know the operation is left alone.
	 *
	 * @return true|\WP_Error
	 */
	public function resend_cron() {
		return $this->queued_only() ?? $this->locked( fn() => $this->resend_locked() );
	}

	/**
	 * resend_cron with the site lock held.
	 *
	 * @return true|\WP_Error
	 */
	private function resend_locked() {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$failed = array();

		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			if ( ! empty( $site['flags']['pending'] ) || ! isset( $site['flags']['cron'] ) ) {
				continue;
			}

			$done = $this->call( 'site_cron_resend_' . $site['slug'], 'site.cron_set', array( 'vmid' => (int) $record['vmid'], 'slug' => (string) $site['slug'], 'jobs' => SiteCron::for_agent( SiteCron::stored( $site ) ) ) );
			delete_transient( $this->status_key( (string) $site['slug'] ) );

			if ( is_wp_error( $done ) ) {
				$data = $done->get_error_data();

				if ( ! ( is_array( $data ) && false !== strpos( (string) ( $data['why'] ?? '' ), 'unknown_op' ) ) ) {
					$failed[] = (string) $site['slug'];
				}
			}
		}

		if ( array() !== $failed ) {
			return new \WP_Error( 'failed', sprintf( 'The stored scheduled jobs of website "%s" could not be sent to the node after a restore. The node may not have them.', implode( '", "', array_map( 'sanitize_key', $failed ) ) ) );
		}

		return true;
	}

	/**
	 * The last runs of the website's jobs, from the node (read only, never stored): per job id when it started and ended, how it ended and the
	 * tail of its output, and whether the node can run scheduled jobs at all. The output can hold secrets: it goes to the account owner only.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error available (false for a node that does not know the operation), jobs, wp_config, wp_cron_disabled.
	 */
	public function cron_status( string $slug ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site || ! empty( $site['flags']['pending'] ) ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		// Every Websites tab load asks once per website: a read from the last minute is reused, so a slow node holds no worker per load.
		$cached = get_transient( $this->status_key( $slug ) );

		if ( is_array( $cached ) ) {
			$cached['jobs'] = (object) $cached['jobs'];

			return $cached;
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$args   = array( 'vmid' => (int) $record['vmid'], 'slug' => $slug );
		$name   = 'site_cron_status_' . $slug;
		$done   = $this->agent_call( $name, 'site.cron_status', $args );
		$until  = microtime( true ) + (int) apply_filters( 'beltoft_services_hosting_action_wait', self::STATUS_WAIT, $name ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- the plugin's own filter.

		while ( $done instanceof StepResult && $done->is_poll() && microtime( true ) < $until ) {
			usleep( 400000 );
			$done = $this->agent_call( $name, 'site.cron_status', $args );
		}

		if ( $done instanceof StepResult && false !== strpos( (string) $done->error(), 'unknown_op' ) ) {
			( \BeltoftServices\Resource::find( $this->r->id() ) ?? $this->r )->set( array( SiteCron::UNSUPPORTED => Jobs::now() ) );

			return array( 'available' => false, 'jobs' => array(), 'wp_config' => false, 'wp_cron_disabled' => false );
		}

		if ( ! is_array( $done ) ) {
			return $this->error( 'failed', __( 'That did not work. Try again in a moment, or contact support if it keeps happening.', 'beltoft-services' ) );
		}

		( \BeltoftServices\Resource::find( $this->r->id() ) ?? $this->r )->set( array( SiteCron::UNSUPPORTED => 0 ) );

		$jobs = array();

		foreach ( (array) ( $done['jobs'] ?? array() ) as $id => $run ) {
			if ( 1 !== preg_match( '/^[0-9a-f]{8}$/D', (string) $id ) || ! is_array( $run ) ) {
				continue;
			}

			$jobs[ (string) $id ] = array(
				'started'   => is_int( $run['started'] ?? null ) ? $run['started'] : null,
				'ended'     => is_int( $run['ended'] ?? null ) ? $run['ended'] : null,
				'exit'      => is_int( $run['exit'] ?? null ) ? $run['exit'] : null,
				'timed_out' => ! empty( $run['timed_out'] ),
				'skipped'   => ! empty( $run['skipped'] ),
				'tail'      => mb_substr( wp_check_invalid_utf8( (string) ( $run['tail'] ?? '' ), true ), 0, 4096 ),
			);
		}

		$out = array(
			'available'        => true,
			'jobs'             => (object) $jobs,
			'wp_config'        => ! empty( $done['wp_config'] ),
			'wp_cron_disabled' => ! empty( $done['wp_cron_disabled'] ),
		);

		set_transient( $this->status_key( $slug ), array_merge( $out, array( 'jobs' => $jobs ) ), self::STATUS_TTL );

		return $out;
	}
}
