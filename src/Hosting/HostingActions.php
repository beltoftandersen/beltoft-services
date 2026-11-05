<?php
/**
 * What a customer can do with a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

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

		if ( null !== Sites::find( $this->r->id(), $slug ) ) {
			return $this->error( 'exists', __( 'That site name is taken. Choose another.', 'beltoft-services' ) );
		}

		if ( count( $existing ) >= (int) $this->plan['sites'] ) {
			return $this->error( 'limit', __( 'Your plan has no room for another website.', 'beltoft-services' ) );
		}

		if ( '' === EdgeMap::preview_host( $slug ) ) {
			return $this->error( 'failed', __( 'Websites cannot be added right now.', 'beltoft-services' ) );
		}

		// An equal share, at least 2. When the account's ceiling has no room for it, the sites with the most workers give some up.
		$workers = max( 2, Plan::workers_per_site( $this->plan, count( $existing ) + 1 ) );
		$deficit = $this->workers_used() + $workers - (int) $this->plan['php_workers'];

		if ( ! Sites::add( $this->r->id(), $slug, sanitize_text_field( $label ), $php, $workers ) ) {
			return $this->error( 'exists', __( 'That site name is taken. Choose another.', 'beltoft-services' ) );
		}

		$site   = Sites::find( $this->r->id(), $slug );
		$record = (array) Records::for_resource( $this->r->id() );
		$host   = EdgeMap::preview_host( $slug );

		$gave = $this->make_room( $deficit, $slug, (int) $record['vmid'] );

		if ( is_wp_error( $gave ) ) {
			Sites::remove( $this->r->id(), $slug );

			return $gave;
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
				return $done;
			}
		}

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
	 * Removes a website: its files and database go, its names too. The last website cannot be removed.
	 *
	 * @param string $slug    Site.
	 * @param string $confirm Must be the site name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_site( string $slug, string $confirm ) {
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
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$added = SiteDns::add_domain( $this->r, $slug, $host, $this->node );

		if ( is_wp_error( $added ) ) {
			return $this->error( 'busy', __( 'The DNS could not be changed right now. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( is_string( $added ) ) {
			return $this->error( 'invalid', $this->customer_text( $added ) );
		}

		$edge = $this->edge();

		if ( is_wp_error( $edge ) ) {
			return $edge;
		}

		return array_merge( $added, array( 'point_at' => 'external' === $added['kind'] ? SiteDns::instructions( $this->node ) : array() ) );
	}

	/**
	 * Removes a domain from a website (the preview name stays).
	 *
	 * @param string $host Domain name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_domain( string $host ) {
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

		$gone = SiteDns::remove_domain( $this->r, $row['host'], $this->node );

		if ( is_wp_error( $gone ) ) {
			return $this->error( 'busy', __( 'The DNS could not be changed right now. Try again in a moment.', 'beltoft-services' ) );
		}

		$edge = $this->edge();

		return is_wp_error( $edge ) ? $edge : array( 'removed' => $row['host'] );
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
	 * Moves PHP workers to a site. The sites together stay within the plan's ceiling, each has at least 2.
	 *
	 * @param string $slug    Site.
	 * @param int    $workers Workers.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_workers( string $slug, int $workers ) {
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
		$child = LiteMail::enable( $this->r, $domain );

		if ( is_wp_error( $child ) ) {
			$texts = array(
				'exists'     => __( 'Your account has Email lite already.', 'beltoft-services' ),
				'bad_domain' => __( 'That domain name is not valid.', 'beltoft-services' ),
				'no_mail'    => __( 'Your plan has no mailboxes.', 'beltoft-services' ),
			);

			return $this->error( in_array( $child->get_error_code(), array( 'exists', 'bad_domain' ), true ) ? ( 'exists' === $child->get_error_code() ? 'exists' : 'invalid' ) : 'failed', $texts[ $child->get_error_code() ] ?? __( 'Email lite could not be set up.', 'beltoft-services' ) );
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
