<?php
/**
 * What a customer can do with a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The customer operations behind the account page and the REST routes: sites, domains, PHP version and limits,
 * cache purge, the WordPress login link, Email lite. Each one checks the plan's ceilings first, does its work
 * through the node agent (one job per call, so a retry never runs it twice) and keeps the records and the edge map
 * in step. Results are arrays; a refusal is a WP_Error with a short code (invalid, limit, exists, not_found, busy,
 * failed, not_ready) and words for the customer. Nothing an agent says is passed on.
 */
final class HostingActions {

	use Runs;

	/**
	 * The plan, typed.
	 *
	 * @var array<string, int|string|bool>
	 */
	private $plan = array();

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->r    = $r;
		$this->plan = Plan::typed( (array) $r->get( 'plan' ) );
	}

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
	 * The ceilings of the plan, for the forms.
	 *
	 * @return array<string, mixed>
	 */
	public function limits(): array {
		$sites = Sites::for_resource( $this->r->id() );
		$used  = 0;

		foreach ( $sites as $s ) {
			$used += (int) $s['workers'];
		}

		return array(
			'sites'         => (int) $this->plan['sites'],
			'sites_used'    => count( $sites ),
			'php_workers'   => (int) $this->plan['php_workers'],
			'workers_used'  => $used,
			'php_memory_mb' => (int) $this->plan['php_memory_mb'],
			'upload_mb'     => (int) $this->plan['upload_mb'],
			'php_versions'  => Config::php_versions(),
		);
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
	 * Runs a change of the account's websites while holding the account's site lock, so two requests (a double click,
	 * two tabs, the sweep) never change the same account at once.
	 *
	 * @param callable $change The change.
	 * @return mixed|\WP_Error The change's result, or busy.
	 */
	private function locked( callable $change ) {
		list( $taken, $result ) = \BeltoftServices\Locks::with( \BeltoftServices\Locks::name( 'hosting-site', $this->r->id() ), (int) apply_filters( 'beltoft_services_hosting_lock_wait', 10 ), $change );

		return $taken ? $result : $this->error( 'busy', __( 'Your hosting is being changed. Try again in a moment.', 'beltoft-services' ) );
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
	 * Sends the account's current routes to the edge (a name became active or stopped being).
	 *
	 * @return true|\WP_Error
	 */
	public function sync_edge() {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : $this->edge();
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
	 * Buys an add-on: an extra domain or 5 GB of storage for one Email lite mailbox. Nothing is bought until the
	 * customer has confirmed the price; the line is added once the order for the time until the renewal is paid.
	 *
	 * @param string $kind    domain or mail_pack.
	 * @param string $target  The domain name, or the mailbox address.
	 * @param int    $confirm_cents The amount charged now that the customer saw and accepted, in cents; 0 when not confirmed.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function buy_addon( string $kind, string $target, int $confirm_cents ) {
		return $this->locked( fn() => $this->buy_addon_locked( $kind, $target, $confirm_cents ) );
	}

	/**
	 * buy_addon with the site lock held.
	 *
	 * @param string $kind    Kind.
	 * @param string $target  Target.
	 * @param int    $confirm_cents Accepted amount in cents.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function buy_addon_locked( string $kind, string $target, int $confirm_cents ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$target = strtolower( trim( $target ) );

		if ( Addons::KIND_DOMAIN === $kind ) {
			$target = Addons::registrable( $target );

			if ( ! SiteDomains::valid_host( $target ) ) {
				return $this->error( 'invalid', __( 'The domain name is not valid.', 'beltoft-services' ) );
			}
		} elseif ( Addons::KIND_PACK === $kind ) {
			if ( ! $this->mailbox_exists( $target ) ) {
				return $this->error( 'not_found', __( 'That mailbox is not on your Email lite.', 'beltoft-services' ) );
			}
		} else {
			return $this->error( 'invalid', __( 'That add-on does not exist.', 'beltoft-services' ) );
		}

		$quote = Addons::quote( $this->r, $kind );

		if ( null === $quote ) {
			return $this->error( 'failed', __( 'This add-on cannot be bought online right now. Contact support.', 'beltoft-services' ) );
		}

		// Nothing is bought unless the customer accepted this very amount: a price that changed since is shown again.
		if ( $confirm_cents <= 0 || $confirm_cents !== (int) round( $quote['now'] * 100 ) ) {
			return new \WP_Error( 'confirm', Addons::confirm_text( $kind, $quote ), array( 'kind' => $kind, 'target' => $target, 'amount_cents' => (int) round( $quote['now'] * 100 ) ) );
		}

		$bought = Addons::buy( $this->r, $kind, $target );

		return is_wp_error( $bought ) ? $this->error( $bought->get_error_code(), $bought->get_error_message() ) : $bought;
	}

	/**
	 * Ends one storage pack of a mailbox: it leaves at the next renewal and the storage stays until then.
	 *
	 * @param string $mailbox Mailbox address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_pack( string $mailbox ) {
		return $this->locked(
			function () use ( $mailbox ) {
				$ready = $this->ready();

				return is_wp_error( $ready ) ? $ready : ( Addons::end( $this->r, Addons::KIND_PACK, strtolower( trim( $mailbox ) ) ) ? array( 'at_renewal' => true ) : $this->error( 'not_found', __( 'That mailbox has no extra storage to remove.', 'beltoft-services' ) ) );
			}
		);
	}

	/**
	 * Is the address a mailbox of the account's Email lite (the hidden website mailbox is not one)?
	 *
	 * @param string $email Address.
	 */
	private function mailbox_exists( string $email ): bool {
		$child = SiteMail::child_of( $this->r );
		$rows  = $child ? \BeltoftServices\Mail\Mailboxes::all( $child ) : array();

		if ( ! is_array( $rows ) ) {
			return false;
		}

		return in_array( $email, array_map( static fn( $row ) => strtolower( (string) $row['email'] ), $rows ), true );
	}

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
	 * @return array<string, mixed>|\WP_Error url.
	 */
	public function login_link( string $slug ) {
		$out = $this->site_job( $slug, 'site.wp_login_link', 'login', null );

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$url = (string) ( $out['url'] ?? '' );

		return 0 === strpos( $url, 'https://' ) ? array( 'url' => $url ) : $this->error( 'failed', __( 'The sign-in link could not be made.', 'beltoft-services' ) );
	}

	/**
	 * Makes Email lite for the account.
	 *
	 * @param string $domain The mail domain.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function enable_mail( string $domain ) {
		return $this->locked( fn() => $this->enable_mail_locked( $domain ) );
	}

	/**
	 * Sets up Email lite with the site lock held (a double click makes one child, not two).
	 *
	 * @param string $domain Mail domain.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function enable_mail_locked( string $domain ) {
		$child = LiteMail::enable( $this->r, $domain );

		if ( is_wp_error( $child ) ) {
			$map   = array(
				'exists'         => array( 'exists', __( 'Your account has Email lite already.', 'beltoft-services' ) ),
				'bad_domain'     => array( 'invalid', __( 'That domain name is not valid.', 'beltoft-services' ) ),
				'no_domain'      => array( 'invalid', __( 'Connect your own domain to a website first. Email lite uses that domain.', 'beltoft-services' ) ),
				'not_own_domain' => array( 'invalid', __( 'Email lite uses the domain connected to your hosting account.', 'beltoft-services' ) ),
				'no_mail'        => array( 'failed', __( 'Your plan has no mailboxes.', 'beltoft-services' ) ),
			);
			$known = $map[ $child->get_error_code() ] ?? array( 'failed', __( 'Email lite could not be set up.', 'beltoft-services' ) );

			return $this->error( $known[0], $known[1] );
		}

		return array( 'mail_resource' => $child->id(), 'domain' => (string) $child->get( \BeltoftServices\Mail\MailDomain::META_DOMAIN ) );
	}

	/**
	 * Points the websites' mail at the Email lite domain (the hidden system mailbox).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function use_email_for_sites() {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( null === SiteMail::child_of( $this->r ) ) {
			return $this->error( 'not_ready', __( 'Set up Email lite first.', 'beltoft-services' ) );
		}

		$done = ( new SiteMail( $this->r ) )->enable_system_mailbox();

		if ( $done instanceof StepResult ) {
			return $this->error( $done->is_failed() ? 'not_ready' : 'busy', $done->is_failed() ? __( 'Email lite is not ready yet. Try again in a moment.', 'beltoft-services' ) : __( 'The mail server is busy. Try again in a moment.', 'beltoft-services' ) );
		}

		return array( 'sender' => (string) ( $done['mail_sender']['address'] ?? '' ) );
	}

	/**
	 * The backups the account has (the stored listing), the safety point and the last restore.
	 *
	 * @return array<string, mixed>
	 */
	public function backups(): array {
		$safety = HostingRestore::safety( Resource::find( $this->r->id() ) ?? $this->r );

		return array(
			'enabled' => HostingBackups::enabled( $this->r ),
			'backups' => HostingBackups::stored( $this->r ),
			'running' => ( new HostingBackups( $this->r ) )->active() || HostingRestore::active( $this->r ),
			'safety'  => null === $safety ? null : array( 'at' => $safety['at'], 'expires' => $safety['expires'] ),
			'last'    => $this->r->get( 'restore_last' ),
		);
	}

	/**
	 * Back up now.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function backup_now() {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : ( new HostingBackups( $this->r ) )->now();
	}

	/**
	 * Starts a restore.
	 *
	 * @param string $scope account, files or database.
	 * @param string $volid Backup.
	 * @param string $slug  Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore( string $scope, string $volid, string $slug = '' ) {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : ( new HostingRestore( $this->r ) )->start( $scope, $volid, $slug );
	}

	/**
	 * Goes back to the safety point.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo() {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : ( new HostingRestore( $this->r ) )->undo();
	}

	/**
	 * Ticks a getting-started item the customer does by hand, or hides the list.
	 *
	 * @param string $item build, backups, or '' with $dismiss.
	 * @param bool   $dismiss Hide the list.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function checklist( string $item, bool $dismiss = false ) {
		$fresh = Resource::find( $this->r->id() ) ?? $this->r;

		if ( $dismiss ) {
			return $fresh->set( array( 'checklist_dismissed' => 1 ) ) ? array( 'dismissed' => true ) : $this->error( 'failed', __( 'That could not be saved. Try again.', 'beltoft-services' ) );
		}

		if ( ! in_array( $item, HostingAccount::MANUAL_ITEMS, true ) ) {
			return $this->error( 'invalid', __( 'Unknown item.', 'beltoft-services' ) );
		}

		$done = array_values( array_unique( array_merge( array_map( 'strval', (array) $fresh->get( 'checklist' ) ), array( $item ) ) ) );

		return $fresh->set( array( 'checklist' => $done ) ) ? array( 'done' => $item ) : $this->error( 'failed', __( 'That could not be saved. Try again.', 'beltoft-services' ) );
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * A job on one existing site.
	 *
	 * @param string                    $slug Site.
	 * @param string                    $op   Operation.
	 * @param string                    $name Job name.
	 * @param array<string, mixed>|null $out  What to return, or null for the agent's result.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function site_job( string $slug, string $op, string $name, ?array $out ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( null === Sites::find( $this->r->id(), $slug ) ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'site_' . $name . '_' . $slug, $op, array( 'vmid' => (int) $record['vmid'], 'slug' => $slug ) );

		return is_wp_error( $done ) ? $done : ( null === $out ? $done : $out );
	}

	/**
	 * The edge map of the account, sent again.
	 *
	 * @return true|\WP_Error
	 */
	private function edge() {
		$done = $this->call( 'edge_update', 'edge.apply', array( 'account' => Config::account( $this->r->id() ), 'routes' => EdgeMap::routes( $this->r->id(), false ) ) );

		return is_wp_error( $done ) ? $done : true;
	}

	/**
	 * Takes workers from the sites that have the most (down to 2 each) until the deficit is covered.
	 *
	 * @param int    $deficit Workers over the ceiling.
	 * @param string $except  The new site, which is left alone.
	 * @param int    $vmid    Container id.
	 * @return true|\WP_Error
	 */
	private function make_room( int $deficit, string $except, int $vmid ) {
		while ( $deficit > 0 ) {
			$donor = null;

			foreach ( Sites::for_resource( $this->r->id() ) as $s ) {
				if ( $s['slug'] !== $except && $s['workers'] > 2 && ( null === $donor || $s['workers'] > $donor['workers'] ) ) {
					$donor = $s;
				}
			}

			if ( null === $donor ) {
				return true; // Every site is at its minimum: the ceiling is a maximum, the minimum of 2 wins.
			}

			$new  = max( 2, $donor['workers'] - $deficit );
			$done = $this->call( 'site_workers_' . $donor['slug'], 'site.workers', array( 'vmid' => $vmid, 'slug' => $donor['slug'], 'workers' => $new ) );

			if ( is_wp_error( $done ) ) {
				return $done;
			}

			Sites::update( $this->r->id(), $donor['slug'], array( 'workers' => $new ) );
			$deficit -= $donor['workers'] - $new;
		}

		return true;
	}

	/**
	 * Workers given to the sites now.
	 */
	private function workers_used(): int {
		$n = 0;

		foreach ( Sites::for_resource( $this->r->id() ) as $s ) {
			$n += (int) $s['workers'];
		}

		return $n;
	}

	/**
	 * One agent job as a result or a customer error.
	 *
	 * @param string               $name Job name.
	 * @param string               $op   Operation.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( string $name, string $op, array $args ) {
		$done = $this->agent_call( $name, $op, $args );

		// A live agent answers queued first and finishes in a second or two: look again (same job id) for a few seconds before asking the customer to retry.
		/**
		 * Seconds a customer action waits for the agent's job.
		 *
		 * @param int $seconds Seconds.
		 */
		$until = microtime( true ) + (int) apply_filters( 'beltoft_services_hosting_action_wait', 8 );

		while ( $done instanceof StepResult && $done->is_poll() && microtime( true ) < $until ) {
			usleep( 400000 );
			$done = $this->agent_call( $name, $op, $args );
		}

		if ( ! $done instanceof StepResult ) {
			return $done;
		}

		return $done->is_poll()
			? $this->error( 'busy', __( 'The server is busy. Try again in a moment.', 'beltoft-services' ) )
			: $this->error( 'failed', __( 'That did not work. Try again in a moment, or contact support if it keeps happening.', 'beltoft-services' ) );
	}

	/**
	 * Is the account ready for a change? Connects to the node on the first call.
	 *
	 * @return true|\WP_Error
	 */
	private function ready() {
		$record = Records::for_resource( $this->r->id() );

		if ( 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) || ! in_array( 'ready', (array) $this->r->get( 'steps' ), true ) || ! $record ) {
			return $this->error( 'not_ready', __( 'Your hosting is not ready for changes.', 'beltoft-services' ) );
		}

		if ( null === $this->client ) {
			$err = $this->connect();

			if ( null !== $err ) {
				return $this->error( 'failed', __( 'Your hosting cannot be changed right now.', 'beltoft-services' ) );
			}
		}

		return true;
	}

	/**
	 * A customer error.
	 *
	 * @param string $code    Short code.
	 * @param string $message Words for the customer.
	 */
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}

	/**
	 * Customer words for a plain refusal of SiteDns (the reasons are already in plain English; they are kept short).
	 *
	 * @param string $reason Reason.
	 */
	private function customer_text( string $reason ): string {
		return mb_substr( sanitize_text_field( $reason ), 0, 200 );
	}
}
