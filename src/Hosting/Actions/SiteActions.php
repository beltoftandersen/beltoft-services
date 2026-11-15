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
use BeltoftServices\Hosting\SiteAddresses;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteNames;
use BeltoftServices\Hosting\SiteStaging;
use BeltoftServices\Hosting\SiteWordPress;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

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
		$task    = Steps::view( $this->r );

		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			if ( ! empty( $site['flags']['pending'] ) ) {
				// Not finished: the customer sees it once it works. While it is being added it shows as a row that says so.
				if ( null !== $task && 'add_site' === $task['action'] && $task['slug'] === $site['slug'] ) {
					$out[] = array(
						'slug'    => $site['slug'],
						'label'   => $site['label'],
						'pending' => true,
						'task'    => $task,
					);
				}

				continue;
			}

			$names = array();
			$rows  = array();

			foreach ( $domains as $d ) {
				if ( $d['site_id'] === $site['id'] ) {
					$rows[]  = $d;
					$names[] = array(
						'host'  => $d['host'],
						'kind'  => $d['kind'],
						'state' => $d['state'],
					);
				}
			}

			$pairs = array();

			foreach ( SiteAddresses::pairs( $rows ) as $base => $pair ) {
				$pairs[] = array(
					'base'  => $base,
					'www'   => 'www.' . $base,
					'main'  => SiteAddresses::main_of_pair( $base, $pair[0] ),
					'ready' => 'active' === $pair[0]['state'] && 'active' === $pair[1]['state'],
				);
			}

			$preview = EdgeMap::preview_host( (string) $site['slug'] );

			$out[] = array(
				'slug'      => $site['slug'],
				'label'     => $site['label'],
				'php'       => $site['php'],
				'workers'   => $site['workers'],
				'memory_mb' => (int) ( $site['flags']['memory_mb'] ?? $this->plan['php_memory_mb'] ),
				'upload_mb' => (int) ( $site['flags']['upload_mb'] ?? $this->plan['upload_mb'] ),
				'domains'   => $names,
				'pairs'     => $pairs,
				'preview'   => $preview,
				'main'      => SiteAddresses::main_address( $rows, $preview ),
				'wp'        => SiteWordPress::view( $site, $rows, $preview, $task ),
				'admin_user'  => (string) ( $site['flags']['admin_user'] ?? '' ),
				'admin_email' => (string) ( $site['flags']['admin_email'] ?? '' ),
				'country'     => \BeltoftServices\Hosting\CountryBlock::view( $site ),
				'staging'     => SiteStaging::view( $site, $task ),
				'scan'        => \BeltoftServices\Hosting\MalwareScan::view( $site ),
				'task'        => null !== $task && $task['slug'] === $site['slug'] ? $task : null,
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

		// The name is chosen here, in the request: the job is given it, never a new one.
		$this->defer_with( array( 'slug' => $slug, 'host' => EdgeMap::preview_host( $slug ) ), array( $slug, $label, $php, $install ) );

		// A run of the job after a failure goes on with the cleanup it began, never with a new website.
		$failure = $this->in_job() ? ( Steps::state( $this->r )['fail'] ?? null ) : null;

		if ( is_array( $failure ) ) {
			$record = (array) Records::for_resource( $this->r->id() );
			$this->abandon_site( $slug, (int) $record['vmid'], ! empty( $failure['db'] ) );

			return $this->error( (string) $failure['code'], (string) $failure['message'] );
		}

		$existing = Sites::for_resource( $this->r->id() );
		$found    = Sites::find( $this->r->id(), $slug );
		$resuming = null !== $found && ( ! empty( $found['flags']['pending'] ) || $this->in_task( $slug ) );

		// A name this account was adding and could not finish (and could not clean up) is picked up again, not refused.
		if ( null !== $found && ! $resuming ) {
			return $this->error( 'exists', __( 'That site name is taken. Choose another.', 'beltoft-services' ) );
		}

		// The temporary address of a staging copy is taken too, though it is no website.
		if ( ! $resuming && Sites::taken( $slug ) ) {
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
			return $this->abort_site( $slug, (int) $record['vmid'], false, $gave );
		}

		// The site's database comes first: the install needs it (the account's user is the same, with the password kept since the order).
		$password = Crypto::decrypt( (string) $this->r->get( 'db_secret' ) );

		if ( null === $password || '' === $password ) {
			return $this->abort_site( $slug, (int) $record['vmid'], false, $this->error( 'failed', __( 'Websites cannot be added right now.', 'beltoft-services' ) ) );
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
			return $this->abort_site( $slug, (int) $record['vmid'], true, $done );
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
			return $this->abort_site( $slug, (int) $record['vmid'], true, $done );
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
				return $this->abort_site( $slug, (int) $record['vmid'], true, $done );
			}

			// Who the install made the administrator, so the page can say which user the sign-in link uses.
			Sites::update( $this->r->id(), $slug, array( 'flags' => array( 'admin_user' => Config::admin_user(), 'admin_email' => sanitize_email( (string) ( $license['email'] ?? '' ) ) ) ) );
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
	 * A website that could not be made, after the agent said so (never because it was slow): the failure is kept, and the
	 * cleanup runs, step by step in a job.
	 *
	 * @param string    $slug Site name.
	 * @param int       $vmid Container id.
	 * @param bool      $db   Whether a database may have been made.
	 * @param \WP_Error $e    The failure.
	 * @return \WP_Error The failure.
	 */
	private function abort_site( string $slug, int $vmid, bool $db, \WP_Error $e ): \WP_Error {
		// A slow agent (a caller that waited and was told to try again) is not a failure: nothing is removed, the website
		// stays marked pending and the next add of the same name goes on from there.
		if ( 'busy' === $e->get_error_code() ) {
			return $e;
		}

		if ( $this->in_job() ) {
			Steps::remember_failure( $this->r, $e, $db );
		}

		$this->abandon_site( $slug, $vmid, $db );

		return $e;
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
	 * The cleanup of a website that was being added and never finished (the watchdog queues it): its database and folder go, then
	 * its record. Only a website that is still marked pending is touched.
	 *
	 * @param string $slug Site name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function abandon_new_site( string $slug ) {
		return $this->locked(
			function () use ( $slug ) {
				$ready = $this->ready();

				if ( is_wp_error( $ready ) ) {
					return $ready;
				}

				$site = Sites::find( $this->r->id(), $slug );

				if ( null === $site || empty( $site['flags']['pending'] ) ) {
					return array( 'abandoned' => false );
				}

				$record = (array) Records::for_resource( $this->r->id() );
				$this->abandon_site( $slug, (int) $record['vmid'], true );

				return array( 'abandoned' => null === Sites::find( $this->r->id(), $slug ) );
			}
		);
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

		// A run of the job after the record was removed (a crash before the edge map was sent) only sends the edge map.
		if ( null === $site && $this->in_task( $slug ) && null !== Steps::memo( $this->r, 'db_drop_' . $slug ) ) {
			$edge = $this->edge();

			return is_wp_error( $edge ) ? $edge : array( 'removed' => $slug );
		}

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		if ( $confirm !== $slug ) {
			return $this->error( 'invalid', __( 'Type the site name to confirm.', 'beltoft-services' ) );
		}

		if ( count( Sites::for_resource( $this->r->id() ) ) < 2 ) {
			return $this->error( 'limit', __( 'The last website of an account cannot be removed.', 'beltoft-services' ) );
		}

		if ( SiteStaging::view( $site )['busy'] ) {
			return $this->error( 'busy', __( 'The staging copy of this website is being changed. Try again when that is done.', 'beltoft-services' ) );
		}

		$this->defer_with( array( 'removed' => $slug ) );

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

		// The node removed the website's staging copy with it; the copy's temporary address goes too.
		$released = SiteStaging::release( $this->r, $site, $this->node );

		if ( is_wp_error( $released ) ) {
			return $this->error( 'busy', __( 'The names of the website could not be removed yet. Try again in a moment.', 'beltoft-services' ) );
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

			return new \WP_Error( 'addon_required', Addons::confirm_text( Addons::KIND_DOMAIN, $quote, $needs ), array( 'kind' => Addons::KIND_DOMAIN, 'target' => $needs, 'amount_cents' => (int) round( $quote['now'] * 100 ), 'host' => strtolower( rtrim( trim( $host ), '.' ) ), 'slug' => $slug ) );
		}

		// A registrable domain is connected with and without www; a subdomain only as typed.
		$typed = strtolower( rtrim( trim( $host ), '.' ) );
		$names = SiteAddresses::names_for( $typed );
		$host  = $names[0];
		$added = SiteDns::add_domain( $this->r, $slug, $host, $this->node );

		if ( is_wp_error( $added ) ) {
			return $this->error( 'busy', __( 'The DNS could not be changed right now. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( is_string( $added ) ) {
			return $this->error( 'invalid', $this->customer_text( $added ) );
		}

		$notice = '';
		$also   = '';

		if ( isset( $names[1] ) ) {
			$site  = Sites::find( $this->r->id(), $slug );
			$other = SiteDomains::find( $names[1] );

			if ( null !== $other && ( $other['resource_id'] !== $this->r->id() || $other['site_id'] !== (int) ( $site['id'] ?? 0 ) ) ) {
				/* translators: %s: domain name. */
				$notice = sprintf( __( '%s is already used by another website and was left as it is.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $names[1] ) );
			} else {
				$second = SiteDns::add_domain( $this->r, $slug, $names[1], $this->node );

				if ( is_array( $second ) ) {
					$also = $names[1];
				} else {
					/* translators: %s: domain name. */
					$notice = sprintf( __( '%s could not be connected yet. Add it again in a moment.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $names[1] ) );
				}
			}

			// The main address starts as the form the customer typed; a choice made earlier is kept.
			$base = 0 === strpos( $typed, 'www.' ) ? substr( $typed, 4 ) : $typed;
			$row  = SiteDomains::find( $base );

			if ( '' !== $also && null !== $row && '' === $row['www_mode'] ) {
				SiteDomains::set_www_mode( $base, $typed === $base ? SiteAddresses::APEX : SiteAddresses::WWW );
			}
		}

		// The customer wants a domain back that was to leave at the renewal (the name stayed, so a new name of it needs no
		// add-on): its line bills again.
		if ( SiteDomains::valid_host( strtolower( rtrim( trim( $host ), '.' ) ) ) ) {
			Addons::keep( $this->r, Addons::KIND_DOMAIN, Addons::registrable( $host ) );
		}

		$answer = array_merge(
			$added,
			array(
				'host'     => $host,
				'also'     => $also,
				'notice'   => $notice,
				'point_at' => 'external' === $added['kind'] ? SiteDns::instructions( $this->node, $host, $this->r->id() ) : array(),
			)
		);

		// In a request the names go to the node in the background (the job also looks at WordPress's address afterwards).
		$this->defer_with( $answer, array() );

		$edge = $this->names_and_edge();

		if ( is_wp_error( $edge ) ) {
			return $edge;
		}

		// A first own domain that is live moves WordPress to it: Steps::done() looks at that when this change has ended.
		return $answer;
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

		$host = strtolower( $host );
		$row  = SiteDomains::find( $host );
		$mine = null !== $row && $row['resource_id'] === $this->r->id();

		if ( $mine && 'preview' === $row['kind'] ) {
			return $this->error( 'invalid', __( 'The temporary address cannot be removed.', 'beltoft-services' ) );
		}

		// The last name of a paid extra domain stays until the renewal: it was paid for, and no money comes back.
		if ( $mine && ! $final ) {
			$reg    = Addons::registrable( $row['host'] );
			$others = array_filter(
				SiteDomains::for_resource( $this->r->id() ),
				static fn( $d ) => 'preview' !== $d['kind'] && $d['host'] !== $row['host'] && Addons::registrable( $d['host'] ) === $reg
			);

			if ( array() === $others && Addons::has( $this->r, Addons::KIND_DOMAIN, $reg ) ) {
				Addons::end( $this->r, Addons::KIND_DOMAIN, $reg );

				return array( 'removed' => $row['host'], 'at_renewal' => true );
			}

			// The last name of the account's free domain: a paid extra domain takes its place as the free one.
			$free_gone = array() === $others;
		}

		// The names that go: the one asked for, or (its paid add-on ended) every name of that domain, in this one change.
		$rows = $mine ? array( $row ) : array();

		if ( $final ) {
			$reg  = Addons::registrable( $host );
			$rows = array_values( array_filter( SiteDomains::for_resource( $this->r->id() ), static fn( $d ) => 'preview' !== $d['kind'] && Addons::registrable( $d['host'] ) === $reg ) );
		}

		if ( array() === $rows ) {
			// A run of the job after the names went (a crash before the node was told): only the node is left to tell.
			if ( ! $this->in_job() ) {
				return $this->error( 'not_found', __( 'That domain is not on your account.', 'beltoft-services' ) );
			}

			$edge = $this->names_and_edge();

			return is_wp_error( $edge ) ? $edge : array( 'removed' => $host );
		}

		// WordPress is on one of the names and the add-on ended: moving it back is a call to the node, made while the node still knows the
		// name. In a request the whole removal is the job's (its steps are kept, so a repeat starts nothing twice).
		if ( 'request' === $this->mode && $final && $this->wordpress_is_on( $rows ) ) {
			$task = Steps::start( $this->r, 'drop_domain', array( $host, true ) );

			return is_wp_error( $task ) ? $task : array( 'removed' => $host, 'status' => 'pending', 'task' => $task );
		}

		foreach ( $rows as $one ) {
			$local = $this->remove_domain_row( $one, $final );

			if ( is_wp_error( $local ) ) {
				return $local;
			}
		}

		$result = array( 'removed' => $host );

		// The name is gone from the account now, so the paid domains left are the ones on a website. The handover of the free domain is
		// local work done once, in the request that removes the free domain's last name (a final removal never takes the free domain).
		$included = ! empty( $free_gone ) ? Addons::free_domain_replaced( $this->r ) : '';

		if ( '' !== $included ) {
			$result['included'] = $included;
		}

		$this->defer_with( $result, array() );

		$edge = $this->names_and_edge();

		return is_wp_error( $edge ) ? $edge : $result;
	}

	/**
	 * Does WordPress of one of the account's websites use one of these names?
	 *
	 * @param array<int, array<string, mixed>> $rows Name rows.
	 */
	private function wordpress_is_on( array $rows ): bool {
		$hosts = array_column( $rows, 'host' );

		foreach ( Sites::for_resource( $this->r->id() ) as $own ) {
			if ( in_array( (string) ( $own['flags']['wp_address'] ?? '' ), $hosts, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The whole removal of a domain whose paid add-on has ended, as the job of the change `remove_domain` (a request queues it when
	 * WordPress is on one of the names): WordPress goes back to the temporary address, the names leave DNS, and then the node and the
	 * edge are told.
	 *
	 * @param string $host  A name of the domain.
	 * @param bool   $final The paid add-on has ended.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function drop_domain( string $host, bool $final = true ) {
		return $this->queued_only() ?? $this->locked( fn() => $this->remove_domain_locked( $host, $final ) );
	}

	/**
	 * The local part of removing one name: WordPress leaves it, DNS and the records let it go. The names reach the node afterwards,
	 * once for all of them.
	 *
	 * @param array<string, mixed> $row   The name's row.
	 * @param bool                 $final The paid add-on has ended.
	 * @return true|\WP_Error
	 */
	private function remove_domain_row( array $row, bool $final ) {
		// WordPress itself is on this name: it is not left on a name that is gone.
		foreach ( Sites::for_resource( $this->r->id() ) as $own ) {
			if ( $own['id'] === $row['site_id'] && (string) ( $own['flags']['wp_address'] ?? '' ) === $row['host'] ) {
				if ( ! $final ) {
					return $this->error( 'invalid', __( 'WordPress uses this address. Make another address the WordPress address first, then remove this one.', 'beltoft-services' ) );
				}

				$this->wordpress_leaves( $own, $row['host'] );
			}
		}

		$gone = SiteDns::remove_domain( $this->r, $row['host'], $this->node );

		if ( is_wp_error( $gone ) ) {
			return $this->error( 'busy', __( 'The DNS could not be changed right now. Try again in a moment.', 'beltoft-services' ) );
		}

		// A pair that loses a name has no main address to choose any more.
		if ( 0 === strpos( $row['host'], 'www.' ) ) {
			SiteDomains::set_www_mode( substr( $row['host'], 4 ), '' );
		}

		return true;
	}

	/**
	 * Moves WordPress itself to one of the website's addresses, in the background: its database is copied first, every address
	 * in it is replaced, and when anything fails the copy is put back. The temporary address and the website's working names
	 * can be chosen. Also the way back to the address WordPress had before. A change of the shared action controller (the action
	 * `wordpress_address`): in a request it is checked and queued, in the job it is the agent's call, what is recorded and the
	 * names sent again.
	 *
	 * @param string $slug   Site.
	 * @param string $host   The address; empty for the main address.
	 * @param string $from   In a job: the address of her own that WordPress has and the customer agreed to replace, '' for none.
	 * @param bool   $manual In a job: false for the automatic move (it re-checks its reason and is silent when the agent cannot).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function wordpress_address( string $slug, string $host = '', string $from = '', bool $manual = true ) {
		if ( 'inline' === $this->mode ) {
			return $this->error( 'failed', __( 'That cannot be done right now.', 'beltoft-services' ) );
		}

		return $this->locked( fn() => $this->wordpress_address_locked( $slug, $host, $from, $manual ) );
	}

	/**
	 * wordpress_address with the site lock held.
	 *
	 * @return mixed
	 */
	private function wordpress_address_locked( string $slug, string $host, string $from, bool $manual ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site || ! empty( $site['flags']['pending'] ) ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$preview = EdgeMap::preview_host( $slug );
		$rows    = array_values( array_filter( SiteDomains::for_resource( $this->r->id() ), static fn( $d ) => $d['site_id'] === $site['id'] ) );
		$host    = strtolower( rtrim( trim( $host ), '.' ) );
		$host    = '' === $host ? SiteAddresses::main_address( $rows, $preview ) : $host;

		if ( ! SiteWordPress::is_name( $rows, $preview, $host ) ) {
			// In a job: the name went while the change waited. Nothing to do.
			return $this->in_job() ? array( 'skipped' => true ) : $this->error( 'invalid', __( 'That address is not one of this website\'s working addresses.', 'beltoft-services' ) );
		}

		if ( ! $this->in_job() ) {
			$view = SiteWordPress::view( $site, $rows, $preview );

			if ( $view['address'] === $host && '' === $view['note'] ) {
				return $this->error( 'invalid', __( 'WordPress already uses this address.', 'beltoft-services' ) );
			}

			// An address of her own that the customer set in WordPress is only replaced when it is the one the last look found.
			$this->defer_with(
				array(
					'moving'  => $host,
					/* translators: %s: address. */
					'message' => sprintf( __( 'WordPress is being moved to %s. This can take a few minutes.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $host ) ),
				),
				array( $slug, $host, 'foreign' === $view['note'] ? $view['seen'] : '', true )
			);
		} elseif ( ! $manual && $host !== SiteWordPress::auto_target( $site, $rows, $preview, true ) && null === Steps::memo( $this->r, 'wp_address_' . $slug ) ) {
			// An automatic move re-checks its reason: it may have been done or become wrong since it was queued.
			return array( 'skipped' => true );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$args   = array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'to' => $host );

		if ( $manual && '' !== $from ) {
			$args['from'] = $from; // The customer agreed to replace this address of her own; the node checks that WordPress still has it.
		}

		$done = $this->call( 'wp_address_' . $slug, 'site.wp_address', $args, 900 );

		if ( is_wp_error( $done ) ) {
			return $this->wordpress_failed( $slug, $manual, $done );
		}

		$note = SiteWordPress::record( $this->r, $slug, $host, $done );

		// The node put the database back after a move that did not work: nothing changed, and the customer is told (not staff).
		if ( 'failed' === $note ) {
			return new \WP_Error( 'failed', 'The WordPress address change failed, the node put the database back.', array( 'text' => __( 'Moving WordPress did not work. Your website was not changed.', 'beltoft-services' ), 'expected' => true ) );
		}

		// The names of the website go again: the temporary name redirects once WordPress is on the main address, and stops when it
		// is not, and a redirect between the names of a pair that waited for the move is sent now.
		$sent = $this->names_and_edge();

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		return array( 'moved' => $host );
	}

	/**
	 * A move the agent refused or failed: what the page says is the website's note, what the customer reads and staff are told is the
	 * outcome of the action.
	 *
	 * @param string    $slug   Site.
	 * @param bool      $manual The customer asked.
	 * @param \WP_Error $e      The failed call.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function wordpress_failed( string $slug, bool $manual, \WP_Error $e ) {
		$data = $e->get_error_data();
		$why  = is_array( $data ) ? (string) ( $data['why'] ?? '' ) : '';
		$text = __( 'Moving WordPress did not work. Your website was not changed.', 'beltoft-services' );

		// An agent that does not have the operation: nothing is shown for an automatic move, a customer who asked is told.
		if ( false !== strpos( $why, 'unknown_op' ) ) {
			$this->r->set( array( 'wp_address_unsupported' => \BeltoftServices\Jobs::now() ) );

			if ( ! $manual ) {
				return array( 'skipped' => true );
			}

			SiteWordPress::settle( $this->r, $slug, array( 'wp_note' => 'unsupported' ) );

			return new \WP_Error( 'failed', $e->get_error_message(), array( 'text' => __( 'Your hosting cannot move WordPress to another address yet. Contact support.', 'beltoft-services' ), 'expected' => true, 'why' => $why ) );
		}

		// A move that failed, or whose dump could not even be put back (the shared alert tells staff: nobody tries again).
		SiteWordPress::settle( $this->r, $slug, array( 'wp_note' => 'failed' ) );

		return new \WP_Error( 'failed', $e->get_error_message(), array( 'text' => $text, 'why' => $why ) );
	}

	/**
	 * WordPress is on a name that is being removed because its paid add-on ended: it moves back to the temporary address first,
	 * while the node still knows the name. When that does not work the operator is told; the name goes all the same.
	 *
	 * @param array<string, mixed> $site Site row.
	 * @param string               $host The name that goes.
	 */
	private function wordpress_leaves( array $site, string $host ): void {
		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'wp_back_' . $site['slug'], 'site.wp_address', array( 'vmid' => (int) $record['vmid'], 'slug' => (string) $site['slug'], 'to' => EdgeMap::preview_host( (string) $site['slug'] ) ) );

		if ( ! is_wp_error( $done ) && ( ! empty( $done['changed'] ) || ! empty( $done['unchanged'] ) ) ) {
			Sites::update( $this->r->id(), (string) $site['slug'], array( 'flags' => array( 'wp_address' => '', 'wp_prev' => $host, 'wp_at' => \BeltoftServices\Jobs::now(), 'wp_note' => '' ) ) );

			return;
		}

		\BeltoftServices\Alerts::notify( 'A website is left on a removed domain', array( 'Hosting resource ' . $this->r->id() . ', website ' . $site['slug'] . ': WordPress was on ' . $host . ' and could not be moved back to the temporary address before the name was removed.' ), $this->r, 'hosting-wp-leaves-' . $this->r->id() );
	}

	/**
	 * Sends the account's current routes to the edge (a name became active or stopped being).
	 *
	 * @return true|\WP_Error
	 */
	public function sync_edge() {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : $this->names_and_edge();
	}

	/**
	 * The job behind a change of the account's addresses (an address added or removed, the main address of a pair, a name that went
	 * live or stopped being): the names go to the node and the routes to the edge. Then WordPress's own address is looked at, as the
	 * change did when it ran in one request: Steps::done() queues that follow-up through the same path.
	 *
	 * @param string $chosen The website whose main address the customer just chose, or '' (the job's argument, read by Steps::done()).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply_names( string $chosen = '' ) {
		unset( $chosen );

		if ( 'inline' === $this->mode ) {
			return $this->error( 'failed', __( 'That cannot be done right now.', 'beltoft-services' ) );
		}

		return $this->locked(
			function () {
				$done = $this->sync_edge();

				return is_wp_error( $done ) ? $done : array( 'names' => true );
			}
		);
	}

	/**
	 * Chooses which name of a with-and-without-www pair is the main address; the other one redirects to it (301). The
	 * redirect changes at once. When WordPress is on the other name of the pair it moves to the chosen one in the background
	 * (SiteWordPress), and the redirect is sent when that is done; otherwise the WordPress address is left alone.
	 *
	 * @param string $host The name that becomes the main address (example.com or www.example.com).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_main_address( string $host ) {
		return $this->locked( fn() => $this->set_main_address_locked( $host ) );
	}

	/**
	 * set_main_address with the site lock held.
	 *
	 * @return mixed
	 */
	private function set_main_address_locked( string $host ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$host = strtolower( rtrim( trim( $host ), '.' ) );
		$base = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$apex = SiteDomains::find( $base );
		$www  = SiteDomains::find( 'www.' . $base );

		if ( null === $apex || null === $www || $apex['resource_id'] !== $this->r->id() || $www['resource_id'] !== $this->r->id() || 'preview' === $apex['kind'] ) {
			return $this->error( 'not_found', __( 'That address is not on your account with and without www.', 'beltoft-services' ) );
		}

		if ( $apex['site_id'] !== $www['site_id'] ) {
			return $this->error( 'invalid', __( 'The two addresses belong to different websites.', 'beltoft-services' ) );
		}

		$site = null;

		foreach ( Sites::for_resource( $this->r->id() ) as $candidate ) {
			if ( $candidate['id'] === $apex['site_id'] ) {
				$site = $candidate;
			}
		}

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$mode = $host === $base ? SiteAddresses::APEX : SiteAddresses::WWW;

		SiteDomains::set_www_mode( $base, $mode );

		// WordPress is on the other name of this pair: it moves to the chosen one first, and the job sends the redirect when it
		// is done. A redirect sent before would meet WordPress sending the visitor back (www and without are WordPress's own concern).
		$wp_now = SiteWordPress::address( $site, EdgeMap::preview_host( (string) $site['slug'] ) );

		if ( in_array( $wp_now, array( $base, 'www.' . $base ), true ) && 'active' === $apex['state'] && 'active' === $www['state'] && SiteWordPress::consider( $this->r, (string) $site['slug'], true ) ) {
			return array(
				'main'     => $host,
				'redirect' => true,
			);
		}

		$this->defer_with( array( 'main' => $host, 'redirect' => 'active' === $apex['state'] && 'active' === $www['state'] ), array( (string) $site['slug'] ) );

		$pushed = $this->push_site_names( $site );

		if ( true !== $pushed ) {
			SiteDomains::set_www_mode( $base, $apex['www_mode'] );

			return is_wp_error( $pushed ) ? $pushed : $this->error( 'failed', __( 'Redirecting from one address to the other is not available on your server yet. Contact support.', 'beltoft-services' ) );
		}

		SiteWordPress::consider( $this->r, (string) $site['slug'], true ); // WordPress still on the temporary address: queued when the names allow it.

		return array(
			'main'     => $host,
			'redirect' => 'active' === $apex['state'] && 'active' === $www['state'],
		);
	}

	/**
	 * The names of every site of the account go to the node (what its web server answers to, and the redirects), and then
	 * the routes go to the edge. The names come first: the edge starts to send a name when its server is ready for it.
	 *
	 * @return true|\WP_Error
	 */
	private function names_and_edge() {
		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			if ( ! empty( $site['flags']['pending'] ) ) {
				continue;
			}

			$pushed = $this->push_site_names( $site );

			if ( is_wp_error( $pushed ) ) {
				return $pushed;
			}
		}

		return $this->edge();
	}

	/**
	 * What the node should be told about a site's names: the active names of the customer, the redirects between a pair's names, and
	 * the signature of both (kept in the site's flags once sent).
	 *
	 * @param array<string, mixed> $site Site row.
	 * @return array{names: string[], redirects: array<int, array{from: string, to: string}>, sig: string, last: string}
	 */
	private function site_names( array $site ): array {
		$all   = array_values( array_filter( SiteDomains::for_resource( $this->r->id() ), static fn( $d ) => $d['site_id'] === $site['id'] && 'preview' !== $d['kind'] ) );
		$names = array();

		foreach ( $all as $d ) {
			if ( 'active' === $d['state'] ) {
				$names[] = $d['host'];
			}
		}

		$redirects = SiteAddresses::redirects( $all );
		$preview   = EdgeMap::preview_host( (string) $site['slug'] );
		$to_main   = SiteWordPress::preview_redirect( $site, $all, $preview );

		if ( null !== $to_main ) {
			$redirects[] = $to_main; // The temporary address sends visitors to the main address once WordPress is on it.
		}

		// The name WordPress itself is on stays a name of the website, also when it does not point here for now: the node
		// accepts the move back to the temporary address only from a name it knows.
		$wp = (string) ( $site['flags']['wp_address'] ?? '' );

		if ( '' !== $wp && ! in_array( $wp, $names, true ) && array() !== array_filter( $all, static fn( $d ) => $d['host'] === $wp ) ) {
			$names[] = $wp;
		}

		return array(
			'names'     => $names,
			'redirects' => $redirects,
			'sig'       => md5( (string) wp_json_encode( array( $names, $redirects ) ) ),
			'last'      => (string) ( $site['flags']['names_sig'] ?? '' ),
		);
	}

	/**
	 * Is the node behind what the shop says about the account's names: a site whose names or redirects differ from what was sent last,
	 * or an edge map that is not the one sent last? The domain sweep asks this and queues the change that sends them; the answer comes
	 * from the stored state, so no flag of "wanted" is kept. An account whose edge map was never recorded is not called stale for it.
	 */
	public function names_stale(): bool {
		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			if ( empty( $site['flags']['pending'] ) ) {
				$names = $this->site_names( $site );

				if ( $names['last'] !== $names['sig'] && ! ( '' === $names['last'] && array() === $names['names'] ) ) {
					return true;
				}
			}
		}

		$sent = (string) ( Resource::find( $this->r->id() ) ?? $this->r )->get( 'edge_sig' );

		if ( '' === $sent ) {
			( Resource::find( $this->r->id() ) ?? $this->r )->set( array( 'edge_sig' => $this->edge_signature() ) );

			return false;
		}

		return $sent !== $this->edge_signature();
	}

	/**
	 * Sends the names of one site (the active names of the customer, and the redirects between a pair's names) to the
	 * node when they differ from what was sent last. A node whose agent does not know redirects still gets the names.
	 *
	 * @param array<string, mixed> $site Site row.
	 * @return true|string|\WP_Error True when done or nothing to do, 'unsupported' when the names went without the redirects.
	 */
	private function push_site_names( array $site ) {
		$want      = $this->site_names( $site );
		$names     = $want['names'];
		$redirects = $want['redirects'];
		$sig       = $want['sig'];
		$last      = $want['last'];

		if ( $last === $sig || ( '' === $last && array() === $names ) ) {
			return true;
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$args   = array(
			'vmid'    => (int) $record['vmid'],
			'slug'    => (string) $site['slug'],
			'domains' => $names,
		);

		if ( array() !== $redirects ) {
			$args['redirects'] = $redirects;
		}

		$done = $this->call( 'site_names_' . $site['slug'], 'site.domains', $args );

		if ( is_wp_error( $done ) && array() !== $redirects ) {
			unset( $args['redirects'] );
			$plain = $this->call( 'site_names_plain_' . $site['slug'], 'site.domains', $args );

			if ( ! is_wp_error( $plain ) ) {
				Sites::update( $this->r->id(), (string) $site['slug'], array( 'flags' => array( 'names_sig' => '' ) ) );

				return 'unsupported';
			}
		}

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		Sites::update( $this->r->id(), (string) $site['slug'], array( 'flags' => array( 'names_sig' => $sig ) ) );

		return true;
	}
}
