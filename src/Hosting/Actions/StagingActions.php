<?php
/**
 * The staging copy of a website, as the customer asks for it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteStaging;
use BeltoftServices\Hosting\Sites;

defined( 'ABSPATH' ) || exit;

/**
 * Making, refreshing, pushing, undoing and deleting the staging copy of a website of the account. Every one of them only
 * checks the rules, records the start in the website's flags and queues the job (SiteStaging); the node agent does the work
 * in the background and the page shows the state. Opening the copy asks the node for a one-time sign-in address of the copy.
 * Reached through the HostingActions facade.
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
			'state'  => $view['state'],
			'busy'   => $view['busy'],
			'note'   => $view['note'],
			'failed' => $view['failed'],
			'host'   => $view['host'],
		);
	}

	/**
	 * Makes the staging copy.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_staging( string $slug ) {
		return $this->locked( fn() => $this->create_locked( $slug ) );
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
	 * create_staging with the site lock held.
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

		if ( SiteStaging::view( $site )['busy'] ) {
			return $this->error( 'busy', __( 'The staging copy of this website is being changed. Wait until that is done.', 'beltoft-services' ) );
		}

		if ( HostingRestore::active( $this->r ) ) {
			return $this->error( 'busy', __( 'A restore is running. Wait until it is done.', 'beltoft-services' ) );
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

		SiteStaging::queue( $this->r, $slug, 'create', false, $label );

		return array(
			'state'   => 'creating',
			'message' => __( 'The staging copy is being made. This can take a few minutes.', 'beltoft-services' ),
		);
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
		return $this->locked( fn() => $this->change_locked( $slug, $kind, $files_only ) );
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

		$view = SiteStaging::view( $site );

		if ( $view['busy'] ) {
			return $this->error( 'busy', __( 'The staging copy of this website is being changed. Wait until that is done.', 'beltoft-services' ) );
		}

		if ( HostingRestore::active( $this->r ) ) {
			return $this->error( 'busy', __( 'A restore is running. Wait until it is done.', 'beltoft-services' ) );
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

		SiteStaging::queue( $this->r, $slug, $kind, $files_only );

		$words = array(
			'refresh' => __( 'The staging copy is being refreshed from the live website. This can take a few minutes.', 'beltoft-services' ),
			'push'    => __( 'The staging copy is being pushed to the live website. This can take a few minutes.', 'beltoft-services' ),
			'undo'    => __( 'The last push is being undone. This can take a few minutes.', 'beltoft-services' ),
			'delete'  => __( 'The staging copy is being deleted.', 'beltoft-services' ),
		);

		return array(
			'state'   => array( 'refresh' => 'refreshing', 'push' => 'pushing', 'undo' => 'undoing', 'delete' => 'deleting' )[ $kind ],
			'message' => $words[ $kind ],
		);
	}
}
