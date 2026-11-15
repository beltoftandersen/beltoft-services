<?php
/**
 * The staging copy of a website, as the customer asks for it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteStaging;
use BeltoftServices\Hosting\Sites;

defined( 'ABSPATH' ) || exit;

/**
 * Making, refreshing, pushing, undoing and deleting the staging copy of a website of the account. Each is a change of the shared
 * action controller (Steps::start(), internal actions staging_create ... staging_delete): the request checks the rules, records
 * the start of a create or a delete (SiteStaging::begin) and queues the job; the job runs these same methods in job mode, one agent
 * step at a time with its steps kept (Steps), and ends with the shared last_result. The safety point before a push is the agent's
 * (`site.staging_push` makes it, puts it back when the push fails, and keeps it for the way back). Opening the copy asks the node
 * for a one-time sign-in address of the copy and stays in the request. Reached through the HostingActions facade.
 */
final class StagingActions {

	use Base;

	/**
	 * Where the copy of a website is now, for the page to look at while a job runs.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function staging( string $slug ) {
		$site = $this->site( $slug );

		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$view = SiteStaging::view( $site );

		return array(
			'state' => $view['state'],
			'busy'  => $view['busy'],
			'host'  => $view['host'],
		);
	}

	/**
	 * Makes the staging copy.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_staging( string $slug ) {
		return $this->queued_only() ?? $this->locked( fn() => $this->create_locked( $slug ) );
	}

	/**
	 * Makes the staging copy again from the website.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function refresh_staging( string $slug ) {
		return $this->change( $slug, 'refresh', false );
	}

	/**
	 * Pushes the staging copy to the website. A safety point is made first by the node.
	 *
	 * @param string $slug       Website.
	 * @param bool   $files_only Files only: the database and the media library of the website stay.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function push_staging( string $slug, bool $files_only = false ) {
		return $this->change( $slug, 'push', $files_only );
	}

	/**
	 * Puts the website back as it was before the last push.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo_staging( string $slug ) {
		return $this->change( $slug, 'undo', false );
	}

	/**
	 * Deletes the staging copy.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_staging( string $slug ) {
		return $this->change( $slug, 'delete', false );
	}

	/**
	 * A one-time WordPress sign-in address of the staging copy. It goes to the customer's browser and is never stored or logged here.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error url, and user and email when the node says.
	 */
	public function staging_login( string $slug ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = $this->site( $slug );

		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$view = SiteStaging::view( $site );

		if ( 'ready' !== $view['state'] ) {
			return $this->error( 'not_found', __( 'This website has no staging copy that is ready.', 'beltoft-services' ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$args   = array( 'vmid' => (int) $record['vmid'], 'slug' => $view['copy'] );
		$user   = (string) ( $site['flags']['admin_user'] ?? '' );

		if ( '' !== $user ) {
			$args['admin_user'] = $user;
		}

		$out = $this->call( 'staging_login_' . $slug, 'site.wp_login_link', $args );

		if ( is_wp_error( $out ) && isset( $args['admin_user'] ) ) {
			unset( $args['admin_user'] );
			$out = $this->call( 'staging_login_plain_' . $slug, 'site.wp_login_link', $args );
		}

		if ( is_wp_error( $out ) ) {
			return $this->error( 'failed', __( 'The sign-in link could not be made.', 'beltoft-services' ) );
		}

		$url = (string) ( $out['url'] ?? '' );

		// The address must be the copy's own, on https.
		if ( 0 !== strpos( $url, 'https://' . $view['host'] . '/' ) ) {
			return $this->error( 'failed', __( 'The sign-in link could not be made.', 'beltoft-services' ) );
		}

		$reply = array( 'url' => $url );

		foreach ( array( 'user', 'email' ) as $key ) {
			if ( isset( $out[ $key ] ) && is_string( $out[ $key ] ) ) {
				$reply[ $key ] = $out[ $key ];
			}
		}

		return $reply;
	}

	/**
	 * A website of the account, ready for a change.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function site( string $slug ) {
		$site = Sites::find( $this->r->id(), $slug );

		return null === $site || ! empty( $site['flags']['pending'] ) ? $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) ) : $site;
	}

	/**
	 * What a job that did not work ends with: the customer's sentence (the shared last_result) and whether staff are told. An agent that
	 * does not have the operation is expected (nothing was changed); a push that could not be put back is not (staff are told by the
	 * shared alert, and the safety point is on the node).
	 *
	 * @param string    $kind One of SiteStaging::KINDS.
	 * @param \WP_Error $e    The failed call.
	 */
	private function outcome( string $kind, \WP_Error $e ): \WP_Error {
		$data = $e->get_error_data();
		$why  = is_array( $data ) ? (string) ( $data['why'] ?? '' ) : '';
		$note = 'failed';

		if ( false !== strpos( $why, 'unknown_op' ) ) {
			$note = 'unsupported';
		} elseif ( false !== strpos( $why, 'could not be put back' ) ) {
			$note = 'unsafe';
		}

		return new \WP_Error( 'failed', $e->get_error_message(), array( 'text' => SiteStaging::outcome_text( $kind, $note ), 'expected' => 'unsupported' === $note, 'why' => $why ) );
	}

	/**
	 * The copy's address before the copy is made: held by the request, written to DNS here (a run that is repeated writes the same
	 * record). A DNS that is busy is a retry of the shared controller.
	 *
	 * @param string $slug Website.
	 * @return \WP_Error|null Null when the address is in DNS.
	 */
	private function name_in_dns( string $slug ): ?\WP_Error {
		if ( null !== Steps::memo( $this->r, 'staging_dns' ) ) {
			return null;
		}

		Steps::step( $this->r, 'staging_dns' );

		$view = SiteStaging::view( (array) Sites::find( $this->r->id(), $slug ) );
		$host = $view['host'];

		if ( '' === $host || ! SiteDomains::add( $this->r->id(), 0, $host, 'preview' ) ) {
			return new \WP_Error( 'failed', 'staging: the address could not be recorded (is it taken?)', array( 'text' => SiteStaging::outcome_text( 'create' ) ) );
		}

		$done = \BeltoftServices\Hosting\SiteDns::publish_preview( $view['copy'], $this->node );

		if ( is_wp_error( $done ) ) {
			throw new TaskPending( StepResult::retry( 0, 'staging: ' . $this->scrub( $done->get_error_message(), 160 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		if ( true !== $done ) {
			return new \WP_Error( 'failed', 'staging: ' . $this->scrub( (string) $done, 160 ), array( 'text' => SiteStaging::outcome_text( 'create' ) ) );
		}

		SiteDomains::set_state( $host, 'active' );
		Steps::remember( $this->r, 'staging_dns', true, array() );

		return null;
	}

	/**
	 * create_staging with the site lock held. In a request: the rules, the name held, the start recorded, then the first agent call
	 * queues the job. In the job: the address to DNS, the node's copy, the edge map.
	 *
	 * @param string $slug Website.
	 * @return mixed
	 */
	private function create_locked( string $slug ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = $this->site( $slug );

		if ( is_wp_error( $site ) ) {
			return $site;
		}

		if ( ! $this->in_job() ) {
			if ( SiteStaging::view( $site )['busy'] ) {
				return $this->error( 'busy', __( 'The staging copy of this website is being changed. Wait until that is done.', 'beltoft-services' ) );
			}

			$problem = SiteStaging::create_problem( $this->r, $slug );

			if ( null !== $problem ) {
				return $this->error( 'limit', $problem );
			}

			$label = SiteStaging::label_for( $slug );
			$host  = EdgeMap::preview_host( $label );

			// The name is held now, so no other website takes it before the job writes it to DNS.
			if ( '' === $label || '' === $host || ! SiteDomains::add( $this->r->id(), 0, $host, 'preview' ) ) {
				return $this->error( 'failed', __( 'A staging copy cannot be made right now.', 'beltoft-services' ) );
			}

			SiteStaging::begin( $this->r, $slug, 'create', $label );
			$this->defer_with(
				array(
					'state'   => 'creating',
					'message' => __( 'The staging copy is being made. This can take a few minutes.', 'beltoft-services' ),
				)
			);
		} else {
			$named = $this->name_in_dns( $slug );

			if ( null !== $named ) {
				return $named;
			}
		}

		$view   = SiteStaging::view( (array) Sites::find( $this->r->id(), $slug ) );
		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'staging_create_' . $slug, 'site.staging_create', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'copy' => $view['copy'], 'host' => $view['host'] ), SiteStaging::BUDGET );

		if ( is_wp_error( $done ) ) {
			return $this->outcome( 'create', $done );
		}

		SiteStaging::record( $this->r, $slug, 'create', $done );

		$edge = $this->edge();

		return is_wp_error( $edge ) ? $edge : array( 'state' => 'ready' );
	}

	/**
	 * One of refresh, push, undo and delete.
	 *
	 * @param string $slug       Website.
	 * @param string $kind       refresh, push, undo or delete.
	 * @param bool   $files_only A push of files only.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function change( string $slug, string $kind, bool $files_only ) {
		return $this->queued_only() ?? $this->locked( fn() => $this->change_locked( $slug, $kind, $files_only ) );
	}

	/**
	 * change with the site lock held.
	 *
	 * @param string $slug       Website.
	 * @param string $kind       Job.
	 * @param bool   $files_only A push of files only.
	 * @return mixed
	 */
	private function change_locked( string $slug, string $kind, bool $files_only ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = $this->site( $slug );

		if ( is_wp_error( $site ) ) {
			return $site;
		}

		if ( ! $this->in_job() ) {
			$view = SiteStaging::view( $site );

			if ( $view['busy'] ) {
				return $this->error( 'busy', __( 'The staging copy of this website is being changed. Wait until that is done.', 'beltoft-services' ) );
			}

			if ( 'undo' === $kind ) {
				if ( null === $view['undo'] ) {
					return $this->error( 'invalid', __( 'There is no push to undo.', 'beltoft-services' ) );
				}
			} elseif ( 'ready' !== $view['state'] ) {
				return $this->error( 'not_found', __( 'This website has no staging copy.', 'beltoft-services' ) );
			}

			if ( 'refresh' === $kind ) {
				$space = SiteStaging::space_problem( $this->r );

				if ( null !== $space ) {
					return $this->error( 'limit', $space );
				}
			}

			if ( 'delete' === $kind ) {
				SiteStaging::begin( $this->r, $slug, 'delete' );
			}

			$words = array(
				'refresh' => __( 'The staging copy is being refreshed from the live website. This can take a few minutes.', 'beltoft-services' ),
				'push'    => __( 'The staging copy is being pushed to the live website. This can take a few minutes.', 'beltoft-services' ),
				'undo'    => __( 'The last push is being undone. This can take a few minutes.', 'beltoft-services' ),
				'delete'  => __( 'The staging copy is being deleted.', 'beltoft-services' ),
			);

			$this->defer_with(
				array(
					'state'   => array( 'refresh' => 'refreshing', 'push' => 'pushing', 'undo' => 'undoing', 'delete' => 'deleting' )[ $kind ],
					'message' => $words[ $kind ],
				)
			);
		}

		$view   = SiteStaging::view( (array) Sites::find( $this->r->id(), $slug ) );
		$record = (array) Records::for_resource( $this->r->id() );
		$args   = array( 'vmid' => (int) $record['vmid'], 'slug' => $slug );

		if ( 'push' === $kind && $files_only ) {
			$args['files_only'] = true;
		}

		$op   = array( 'refresh' => 'site.staging_refresh', 'push' => 'site.staging_push', 'undo' => 'site.staging_undo', 'delete' => 'site.staging_delete' )[ $kind ];
		$done = $this->call( 'staging_' . $kind . '_' . $slug, $op, $args, SiteStaging::BUDGET );

		if ( is_wp_error( $done ) ) {
			return $this->outcome( $kind, $done );
		}

		if ( 'delete' === $kind ) {
			return $this->finish_delete( $slug, $view['host'] );
		}

		$recorded = SiteStaging::record( $this->r, $slug, $kind, $done );

		return is_wp_error( $recorded ) ? $recorded : array( 'state' => 'ready' );
	}

	/**
	 * Delete, last part: the copy's address leaves DNS, the records and the edge map (the node removed the copy). A DNS that is busy is a
	 * retry of the shared controller; the call is the same when it is repeated.
	 *
	 * @param string $slug Website.
	 * @param string $host The copy's address.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function finish_delete( string $slug, string $host ) {
		if ( '' !== $host ) {
			$gone = SiteDns::remove_domain( $this->r, $host, $this->node );

			if ( is_wp_error( $gone ) ) {
				throw new TaskPending( StepResult::retry( 0, 'staging: ' . $this->scrub( $gone->get_error_message(), 160 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
			}
		}

		SiteStaging::forget( $this->r, $slug );

		$edge = $this->edge();

		return is_wp_error( $edge ) ? $edge : array( 'deleted' => true );
	}
}
