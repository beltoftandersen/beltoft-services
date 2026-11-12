<?php
/**
 * The websites of a hosting account: add, abandon, remove, their domain names and the edge map.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Crypto;
use BeltoftServices\Hosting\Addons;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Provision;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteNames;
use BeltoftServices\Hosting\Sites;

defined( 'ABSPATH' ) || exit;

/**
 * The websites of a hosting account: add, abandon, remove, their domain names and the edge map. Reached through the HostingActions facade; results are arrays and a refusal is a WP_Error.
 */
final class SiteActions {

	use Base;

	/**
	 * The sites of the account with their names and settings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sites(): array {
		$domains = SiteDomains::for_resource( $this->r->id() );
		$out     = array();

		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			if ( ! empty( $site['flags']['pending'] ) ) {
				continue; // Not finished: the customer sees it once it works.
			}

			$names = array();

			foreach ( $domains as $d ) {
				if ( $d['site_id'] === $site['id'] ) {
					$names[] = array(
						'host'  => $d['host'],
						'kind'  => $d['kind'],
						'state' => $d['state'],
					);
				}
			}

			$out[] = array(
				'slug'      => $site['slug'],
				'label'     => $site['label'],
				'php'       => $site['php'],
				'workers'   => $site['workers'],
				'memory_mb' => (int) ( $site['flags']['memory_mb'] ?? $this->plan['php_memory_mb'] ),
				'upload_mb' => (int) ( $site['flags']['upload_mb'] ?? $this->plan['upload_mb'] ),
				'domains'   => $names,
			);
		}

		return $out;
	}

	/**
	 * Adds a website: a record, its name, its place on the agent and the edge.
	 *
	 * @param string $slug    The name used in the preview address.
	 * @param string $label   A title.
	 * @param string $php     PHP version ('' for the default).
	 * @param bool   $install Install WordPress.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_site( string $slug, string $label = '', string $php = '', bool $install = true ) {
		return $this->locked( fn() => $this->add_site_locked( $slug, $label, $php, $install ) );
	}

	/**
	 * Adds a website with the lock held.
	 *
	 * @param string $slug    Site name.
	 * @param string $label   A title.
	 * @param string $php     PHP version.
	 * @param bool   $install Install WordPress.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function add_site_locked( string $slug, string $label, string $php, bool $install ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$slug = strtolower( trim( $slug ) );

		if ( '' === $slug ) {
			// No name given: the customer's own details make one. A name that is given is checked as it always was.
			$license = $this->r->license();
			$slug    = SiteNames::generate( SiteNames::source_for_user( (int) ( $license['user_id'] ?? 0 ), (string) ( $license['email'] ?? '' ) ) + array( 'title' => $label ), 'r' . $this->r->id(), '', false );

			if ( '' === $slug ) {
				return $this->error( 'failed', __( 'Websites cannot be added right now.', 'beltoft-services' ) );
			}
		}

		$php  = '' === $php ? Config::get( 'php_default' ) : $php;

		if ( ! Sites::valid_slug( $slug ) ) {
			return $this->error( 'invalid', __( 'The site name can have lowercase letters, numbers and dashes, and must start and end with a letter or number.', 'beltoft-services' ) );
		}

		if ( ! in_array( $php, Config::php_versions(), true ) ) {
			return $this->error( 'invalid', __( 'That PHP version is not offered.', 'beltoft-services' ) );
		}

		$existing = Sites::for_resource( $this->r->id() );
		$found    = Sites::find( $this->r->id(), $slug );
		$resuming = null !== $found && ! empty( $found['flags']['pending'] );

		// A name this account was adding and could not finish (and could not clean up) is picked up again, not refused.
		if ( null !== $found && ! $resuming ) {
			return $this->error( 'exists', __( 'That site name is taken. Choose another.', 'beltoft-services' ) );
		}

		if ( ! $resuming && (int) $this->plan['sites'] > 0 && count( $existing ) >= (int) $this->plan['sites'] ) {
			return $this->error( 'limit', __( 'Your plan has no room for another website.', 'beltoft-services' ) );
		}

		if ( '' === EdgeMap::preview_host( $slug ) ) {
			return $this->error( 'failed', __( 'Websites cannot be added right now.', 'beltoft-services' ) );
		}

		// An equal share, at least 2. When the account's ceiling has no room for it, the sites with the most workers give some up.
		$workers = $resuming ? (int) $found['workers'] : max( 2, Plan::workers_per_site( $this->plan, count( $existing ) + 1 ) );
		$deficit = $this->workers_used() + ( $resuming ? 0 : $workers ) - (int) $this->plan['php_workers'];

		if ( ! $resuming && ! Sites::add( $this->r->id(), $slug, sanitize_text_field( $label ), $php, $workers, array( 'pending' => true ) ) ) {
			return $this->error( 'exists', __( 'That site name is taken. Choose another.', 'beltoft-services' ) );
		}

		$site   = Sites::find( $this->r->id(), $slug );
		$record = (array) Records::for_resource( $this->r->id() );
		$host   = EdgeMap::preview_host( $slug );

		$gave = $this->make_room( $deficit, $slug, (int) $record['vmid'] );

		if ( is_wp_error( $gave ) ) {
			$this->abandon_site( $slug, (int) $record['vmid'], false );

			return $gave;
		}

		// The site's database comes first: the install needs it (the account's user is the same, with the password kept since the order).
		$password = Crypto::decrypt( (string) $this->r->get( 'db_secret' ) );

		if ( null === $password || '' === $password ) {
			Sites::remove( $this->r->id(), $slug );

			return $this->error( 'failed', __( 'Websites cannot be added right now.', 'beltoft-services' ) );
		}

		$this->secrets[] = $password;
		$done            = $this->call(
			'db_add_' . $slug,
			'db.create',
			array(
				'account'   => Config::account( $this->r->id() ),
				'vmid'      => (int) $record['vmid'],
				'ip'        => (string) $record['ip'],
				'databases' => array( Provision::db_name( $this->r->id(), $slug ) ),
				'password'  => $password,
			)
		);

		if ( is_wp_error( $done ) ) {
			$this->abandon_site( $slug, (int) $record['vmid'] );

			return $done;
		}

		$done = $this->call(
			'site_add_' . $slug,
			'site.create',
			array(
				'vmid'    => (int) $record['vmid'],
				'slug'    => $slug,
				'php'     => $php,
				'workers' => $workers,
				'host'    => $host,
			)
		);

		if ( is_wp_error( $done ) ) {
			$this->abandon_site( $slug, (int) $record['vmid'] );

			return $done;
		}

		if ( $install ) {
			$license = $this->r->license();
			$done    = $this->call(
				'site_install_' . $slug,
				'site.wp_install',
				array(
					'vmid'        => (int) $record['vmid'],
					'slug'        => $slug,
					'title'       => '' !== $label ? sanitize_text_field( $label ) : $slug,
					'admin_user'  => Config::admin_user(),
					'admin_email' => sanitize_email( (string) ( $license['email'] ?? '' ) ),
					'woocommerce' => false,
				)
			);

			if ( is_wp_error( $done ) ) {
				$this->abandon_site( $slug, (int) $record['vmid'] );

				return $done;
			}
		}

		Sites::update( $this->r->id(), $slug, array( 'flags' => array( 'pending' => false ) ) );
		SiteDomains::add( $this->r->id(), (int) $site['id'], $host, 'preview' );
		$dns = SiteDns::publish_preview( $slug, $this->node );

		if ( true !== $dns ) {
			return $this->error( 'failed', __( 'The website was made but its address could not be set up yet. Try again in a moment.', 'beltoft-services' ) );
		}

		SiteDomains::set_state( $host, 'active' );
		$edge = $this->edge();

		return is_wp_error( $edge ) ? $edge : array( 'slug' => $slug, 'host' => $host );
	}

	/**
	 * A website that could not be made: its database and its folder are removed again, and then its record, so the
	 * name is free. When the agent cannot be reached to clean up, the record stays marked pending and the same
	 * account's next add of the same name carries on from there.
	 *
	 * @param string $slug Site name.
	 * @param int    $vmid Container id.
	 * @param bool   $db   Whether a database may have been made.
	 */
	private function abandon_site( string $slug, int $vmid, bool $db = true ): void {
		$clean = true;

		if ( $db ) {
			$clean = ! is_wp_error( $this->call( 'db_drop_new_' . $slug, 'db.drop', array( 'account' => Config::account( $this->r->id() ), 'databases' => array( Provision::db_name( $this->r->id(), $slug ) ) ) ) );
			$clean = ! is_wp_error( $this->call( 'site_del_new_' . $slug, 'site.delete', array( 'vmid' => $vmid, 'slug' => $slug ) ) ) && $clean;
		}

		if ( $clean ) {
			Sites::remove( $this->r->id(), $slug );
		}
	}

	/**
	 * Removes a website: its files and database go, its names too. The last website cannot be removed.
	 *
	 * @param string $slug    Site.
	 * @param string $confirm Must be the site name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_site( string $slug, string $confirm ) {
		return $this->locked( fn() => $this->remove_site_locked( $slug, $confirm ) );
	}

	/**
	 * remove_site with the site lock held.
	 *
	 * @return mixed
	 */
	private function remove_site_locked( string $slug, string $confirm ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		if ( $confirm !== $slug ) {
			return $this->error( 'invalid', __( 'Type the site name to confirm.', 'beltoft-services' ) );
		}

		if ( count( Sites::for_resource( $this->r->id() ) ) < 2 ) {
			return $this->error( 'limit', __( 'The last website of an account cannot be removed.', 'beltoft-services' ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );

		$done = $this->call( 'site_del_' . $slug, 'site.delete', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$done = $this->call(
			'db_drop_' . $slug,
			'db.drop',
			array(
				'account'   => Config::account( $this->r->id() ),
				'databases' => array( Provision::db_name( $this->r->id(), $slug ) ),
			)
		);

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		foreach ( SiteDomains::for_resource( $this->r->id() ) as $d ) {
			if ( $d['site_id'] === $site['id'] ) {
				$gone = SiteDns::remove_domain( $this->r, $d['host'], $this->node );

				if ( is_wp_error( $gone ) ) {
					return $this->error( 'busy', __( 'The names of the website could not be removed yet. Try again in a moment.', 'beltoft-services' ) );
				}
			}
		}

		Sites::remove( $this->r->id(), $slug );
		$edge = $this->edge();

		return is_wp_error( $edge ) ? $edge : array( 'removed' => $slug );
	}

	/**
	 * Adds a domain to a website.
	 *
	 * @param string $slug Site.
	 * @param string $host Domain name.
	 * @return array<string, mixed>|\WP_Error Kind and state, and for an external name what to point it at.
	 */
	public function add_domain( string $slug, string $host ) {
		return $this->locked( fn() => $this->add_domain_locked( $slug, $host ) );
	}

	/**
	 * add_domain with the site lock held.
	 *
	 * @return mixed
	 */
	private function add_domain_locked( string $slug, string $host ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$needs = SiteDomains::valid_host( strtolower( rtrim( trim( $host ), '.' ) ) ) ? Addons::domain_needing_addon( $this->r, $host ) : null;

		// A second registrable domain is a paid add-on (subdomains of the account's domains never are).
		if ( null !== $needs && ! Addons::has( $this->r, Addons::KIND_DOMAIN, $needs ) ) {
			$quote = Addons::quote( $this->r, Addons::KIND_DOMAIN );

			if ( null === $quote ) {
				return $this->error( 'failed', __( 'Your account has its domain. Another domain cannot be added online right now. Contact support.', 'beltoft-services' ) );
			}

			return new \WP_Error( 'addon_required', Addons::confirm_text( Addons::KIND_DOMAIN, $quote ), array( 'kind' => Addons::KIND_DOMAIN, 'target' => $needs, 'amount_cents' => (int) round( $quote['now'] * 100 ), 'host' => strtolower( rtrim( trim( $host ), '.' ) ), 'slug' => $slug ) );
		}

		$added = SiteDns::add_domain( $this->r, $slug, $host, $this->node );

		if ( is_wp_error( $added ) ) {
			return $this->error( 'busy', __( 'The DNS could not be changed right now. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( is_string( $added ) ) {
			return $this->error( 'invalid', $this->customer_text( $added ) );
		}

		// The customer wants a domain back that was to leave at the renewal (the name stayed, so a new name of it needs no
		// add-on): its line bills again.
		if ( SiteDomains::valid_host( strtolower( rtrim( trim( $host ), '.' ) ) ) ) {
			Addons::keep( $this->r, Addons::KIND_DOMAIN, Addons::registrable( $host ) );
		}

		$edge = $this->edge();

		if ( is_wp_error( $edge ) ) {
			return $edge;
		}

		return array_merge( $added, array( 'point_at' => 'external' === $added['kind'] ? SiteDns::instructions( $this->node, strtolower( rtrim( trim( $host ), '.' ) ), $this->r->id() ) : array() ) );
	}

	/**
	 * Removes a domain from a website (the preview name stays).
	 *
	 * @param string $host  Domain name.
	 * @param bool   $final True when its paid add-on has ended (the renewal): the names go now, whatever the line says.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_domain( string $host, bool $final = false ) {
		return $this->locked( fn() => $this->remove_domain_locked( $host, $final ) );
	}

	/**
	 * remove_domain with the site lock held.
	 *
	 * @return mixed
	 */
	private function remove_domain_locked( string $host, bool $final = false ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$row = SiteDomains::find( strtolower( $host ) );

		if ( null === $row || $row['resource_id'] !== $this->r->id() ) {
			return $this->error( 'not_found', __( 'That domain is not on your account.', 'beltoft-services' ) );
		}

		if ( 'preview' === $row['kind'] ) {
			return $this->error( 'invalid', __( 'The temporary address cannot be removed.', 'beltoft-services' ) );
		}

		// The last name of a paid extra domain stays until the renewal: it was paid for, and no money comes back.
		if ( ! $final ) {
			$reg    = Addons::registrable( $row['host'] );
			$others = array_filter(
				SiteDomains::for_resource( $this->r->id() ),
				static fn( $d ) => 'preview' !== $d['kind'] && $d['host'] !== $row['host'] && Addons::registrable( $d['host'] ) === $reg
			);

			if ( array() === $others && Addons::has( $this->r, Addons::KIND_DOMAIN, $reg ) ) {
				Addons::end( $this->r, Addons::KIND_DOMAIN, $reg );

				return array( 'removed' => $row['host'], 'at_renewal' => true );
			}
		}

		$gone = SiteDns::remove_domain( $this->r, $row['host'], $this->node );

		if ( is_wp_error( $gone ) ) {
			return $this->error( 'busy', __( 'The DNS could not be changed right now. Try again in a moment.', 'beltoft-services' ) );
		}

		$edge = $this->edge();

		return is_wp_error( $edge ) ? $edge : array( 'removed' => $row['host'] );
	}

	/**
	 * Sends the account's current routes to the edge (a name became active or stopped being).
	 *
	 * @return true|\WP_Error
	 */
	public function sync_edge() {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : $this->edge();
	}
}
