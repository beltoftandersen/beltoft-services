<?php
/**
 * The PHP settings of a website, the cache purge and the WordPress login link.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The PHP settings of a website, the cache purge and the WordPress login link. Reached through the HostingActions facade; results are arrays and a refusal is a WP_Error.
 */
final class PhpActions {

	use Base;

	/**
	 * Changes the PHP version and the memory and upload ceilings of a site, never above the plan.
	 *
	 * @param string   $slug      Site.
	 * @param string   $php       Version.
	 * @param int|null $memory_mb memory_limit in MB, or null to keep.
	 * @param int|null $upload_mb Upload size in MB, or null to keep.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_php( string $slug, string $php, ?int $memory_mb = null, ?int $upload_mb = null ) {
		return $this->locked( fn() => $this->set_php_locked( $slug, $php, $memory_mb, $upload_mb ) );
	}

	/**
	 * set_php with the site lock held.
	 *
	 * @return mixed
	 */
	private function set_php_locked( string $slug, string $php, ?int $memory_mb = null, ?int $upload_mb = null ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		if ( ! in_array( $php, Config::php_versions(), true ) ) {
			return $this->error( 'invalid', __( 'That PHP version is not offered.', 'beltoft-services' ) );
		}

		$memory = $memory_mb ?? (int) ( $site['flags']['memory_mb'] ?? $this->plan['php_memory_mb'] );
		$upload = $upload_mb ?? (int) ( $site['flags']['upload_mb'] ?? $this->plan['upload_mb'] );

		if ( $memory < 64 || $memory > (int) $this->plan['php_memory_mb'] ) {
			/* translators: %d: the plan's memory_limit ceiling in MB. */
			return $this->error( 'limit', sprintf( __( 'PHP memory can be set from 64 MB up to %d MB on your plan.', 'beltoft-services' ), (int) $this->plan['php_memory_mb'] ) );
		}

		if ( $upload < 1 || $upload > (int) $this->plan['upload_mb'] ) {
			/* translators: %d: the plan's upload size ceiling in MB. */
			return $this->error( 'limit', sprintf( __( 'The upload size can be set from 1 MB up to %d MB on your plan.', 'beltoft-services' ), (int) $this->plan['upload_mb'] ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call(
			'site_php_' . $slug,
			'site.php',
			array(
				'vmid'      => (int) $record['vmid'],
				'slug'      => $slug,
				'php'       => $php,
				'memory_mb' => $memory,
				'upload_mb' => $upload,
			)
		);

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		Sites::update( $this->r->id(), $slug, array( 'php' => $php, 'flags' => array( 'memory_mb' => $memory, 'upload_mb' => $upload ) ) );

		return array( 'slug' => $slug, 'php' => $php, 'memory_mb' => $memory, 'upload_mb' => $upload );
	}

	/**
	 * Goes back to the PHP version a site ran on before the automatic switch, for the 7 days after it. The old
	 * version may be out of support: the site stays on it, with a warning in the account, until the customer moves it.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function php_rollback( string $slug ) {
		return $this->locked( fn() => $this->php_rollback_locked( $slug ) );
	}

	/**
	 * php_rollback with the site lock held.
	 *
	 * @return mixed
	 */
	private function php_rollback_locked( string $slug ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$fresh  = Resource::find( $this->r->id() ) ?? $this->r;
		$marker = ( (array) $fresh->get( 'php_rollback' ) )[ $slug ] ?? null;

		if ( null === Sites::find( $this->r->id(), $slug ) || ! is_array( $marker ) || (int) ( $marker['until'] ?? 0 ) <= \BeltoftServices\Jobs::now() ) {
			return $this->error( 'not_found', __( 'There is nothing to go back to for this website.', 'beltoft-services' ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'site_php_back_' . $slug, 'site.php', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'php' => (string) $marker['from'] ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		Sites::update( $this->r->id(), $slug, array( 'php' => (string) $marker['from'] ) );
		$left = (array) $fresh->get( 'php_rollback' );
		unset( $left[ $slug ] );
		$pinned          = (array) $fresh->get( 'php_pinned' );
		$pinned[ $slug ] = (string) $marker['from'];
		$fresh->set( array( 'php_rollback' => $left, 'php_pinned' => $pinned ) );

		return array( 'slug' => $slug, 'php' => (string) $marker['from'] );
	}

	/**
	 * Moves PHP workers to a site. The sites together stay within the plan's ceiling, each has at least 2.
	 *
	 * @param string $slug    Site.
	 * @param int    $workers Workers.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_workers( string $slug, int $workers ) {
		return $this->locked( fn() => $this->set_workers_locked( $slug, $workers ) );
	}

	/**
	 * set_workers with the site lock held.
	 *
	 * @return mixed
	 */
	private function set_workers_locked( string $slug, int $workers ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$others = $this->workers_used() - (int) $site['workers'];

		if ( $workers < 2 || $others + $workers > (int) $this->plan['php_workers'] ) {
			/* translators: %d: PHP workers the account may spread over its websites. */
			return $this->error( 'limit', sprintf( __( 'A website needs at least 2 PHP workers, and your websites together can have up to %d.', 'beltoft-services' ), (int) $this->plan['php_workers'] ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'site_workers_' . $slug, 'site.workers', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'workers' => $workers ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		Sites::update( $this->r->id(), $slug, array( 'workers' => $workers ) );

		return array( 'slug' => $slug, 'workers' => $workers );
	}

	/**
	 * Clears the page cache of a site.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function purge_cache( string $slug ) {
		return $this->site_job( $slug, 'site.cache_purge', 'purge', array( 'purged' => $slug ) );
	}

	/**
	 * A one-time sign-in address for the site's WordPress.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url, and user and email of the administrator it signs in as when the node says.
	 */
	public function login_link( string $slug ) {
		return $this->one_time_link( $slug, 'site.wp_login_link', 'login', __( 'The sign-in link could not be made.', 'beltoft-services' ) );
	}

	/**
	 * A one-time address that opens WordPress's own "choose a new password" screen for the website's administrator.
	 * The reset key is made by the website when the address is opened; it does not pass through this site.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url, and user and email of the administrator when the node says.
	 */
	public function password_link( string $slug ) {
		return $this->one_time_link( $slug, 'site.wp_password_link', 'password', __( 'Setting a WordPress password from here is not available on your server yet. Use "Lost your password?" on the WordPress sign-in page.', 'beltoft-services' ) );
	}

	/**
	 * One of the two one-time addresses. The administrator the install made is asked for by name; a node whose agent does
	 * not know the argument is asked again without it. The address is returned to the customer's browser and never stored
	 * or logged here.
	 *
	 * @param string $slug    Site.
	 * @param string $op      Agent operation.
	 * @param string $name    Job name.
	 * @param string $failure What the customer reads when the node cannot do it.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function one_time_link( string $slug, string $op, string $name, string $failure ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$args   = array( 'vmid' => (int) $record['vmid'], 'slug' => $slug );
		$user   = (string) ( $site['flags']['admin_user'] ?? '' );

		if ( '' !== $user ) {
			$args['admin_user'] = $user;
		}

		$out = $this->call( 'site_' . $name . '_' . $slug, $op, $args );

		if ( is_wp_error( $out ) && isset( $args['admin_user'] ) ) {
			unset( $args['admin_user'] );
			$out = $this->call( 'site_' . $name . '_plain_' . $slug, $op, $args );
		}

		if ( is_wp_error( $out ) ) {
			return 'password' === $name ? $this->error( 'failed', $failure ) : $out;
		}

		$url = (string) ( $out['url'] ?? '' );

		if ( 0 !== strpos( $url, 'https://' ) ) {
			return $this->error( 'failed', $failure );
		}

		$result = array( 'url' => $url );

		foreach ( array( 'user', 'email' ) as $key ) {
			if ( isset( $out[ $key ] ) && is_string( $out[ $key ] ) && '' !== $out[ $key ] ) {
				$result[ $key ] = sanitize_text_field( $out[ $key ] );
			}
		}

		return $result;
	}
}
