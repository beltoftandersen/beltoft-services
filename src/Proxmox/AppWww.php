<?php
/** The www name of an app on a bare domain. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\PublicSuffix;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * When an app is served on a bare domain (example.com, not shop.example.com), www.example.com is served too: both names
 * get a certificate and www redirects to the domain. Only apps that declare `www` in their definition (a web server of
 * our own on the server) take part, and only from the recipe that can do it (`www.since_recipe`).
 *
 * On a domain in a zone of ours the www A record is written next to the domain's, through the same zone-locked write,
 * and only when www has no record of any type. On the customer's own DNS nothing is written: the name is looked up.
 * A www name that does not point at the server never blocks or fails the install: it waits, and this job (the same
 * bounded public DNS read as AppOwnDomain) adds it later by writing /var/lib/beltoft-app/www.json, which the guest
 * (units made by the install script, no template change) turns into a new Caddyfile.
 *
 * Meta: app_www (waiting, applying, live), app_www_reason (none, other, aaaa, unreachable, retry, apply), app_www_checked,
 * app_www_at (when the file was written), app_www_made (we wrote the record), app_www_ip (the address we wrote).
 */
final class AppWww {

	public const HOOK = 'beltoft_services_app_www_check';

	/** Guest files: the request the plugin writes and the answer the guest writes. */
	public const FILE   = '/var/lib/beltoft-app/www.json';
	public const STATUS = '/var/lib/beltoft-app/www.status.json';

	/** Seconds between two public DNS reads of one server. */
	public const INTERVAL = 600;

	/** The least gap between two reads, whatever calls the job. */
	public const MIN_GAP = 300;

	/** Seconds the guest has to confirm a www name before it is looked at again from the start. */
	public const APPLY_WAIT = 1800;

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( Jobs::SWEEP, array( __CLASS__, 'sweep' ) );
	}

	// -- Which names ---------------------------------------------------------------------------

	/** Is the name a bare domain (registrable on its own) and not a subdomain of one? */
	public static function is_apex( string $name ): bool {
		$name = strtolower( rtrim( trim( $name ), '.' ) );
		if ( '' === $name ) {
			return false;
		}
		$registrable = PublicSuffix::registrable( $name );
		$apex        = null !== $registrable ? $registrable === $name : 2 === count( explode( '.', $name ) );
		return (bool) apply_filters( 'beltoft_services_app_www_apex', $apex, $name );
	}

	/** The name an app serves for a domain: 'www.' plus the domain, '' when it does not apply. */
	public static function name_for( Resource $r, string $domain ): string {
		$domain = strtolower( $domain );
		$id     = (string) $r->get( 'app_id' );
		$app    = '' === $id ? null : Apps::version( $id, (string) $r->get( 'app_version' ), true );
		if ( '' === $domain || null === $app ) {
			return '';
		}
		$since  = (int) ( $app['www']['since_recipe'] ?? 0 );
		$recipe = (int) ( $r->get( 'app_recipe' ) ?: ( $app['recipe'] ?? 0 ) );
		if ( $since <= 0 || $recipe < $since ) {
			return '';
		}
		$zone = strtolower( (string) $r->get( 'app_zone' ) );
		$apex = '' !== $zone ? $domain === $zone : self::is_apex( $domain );
		return $apex ? 'www.' . $domain : '';
	}

	/** The name the app is, or is going to be, served on. */
	public static function target( Resource $r ): string {
		foreach ( array( 'app_domain', 'app_domain_want', 'app_domain_own' ) as $key ) {
			if ( '' !== (string) $r->get( $key ) ) {
				return strtolower( (string) $r->get( $key ) );
			}
		}
		return '';
	}

	/** What public DNS says about a name: live, none, other, aaaa or unreachable (see AppOwnDomain::verdict). */
	public static function verdict( string $name, string $ip ): string {
		$a    = DnsQuery::addresses( $name, 'A' );
		$aaaa = DnsQuery::addresses( $name, 'AAAA' );
		if ( is_wp_error( $a ) ) {
			return 'unreachable';
		}
		return AppOwnDomain::verdict( $ip, $a, is_wp_error( $aaaa ) ? array() : $aaaa );
	}

	// -- At install ------------------------------------------------------------------------------

	/**
	 * Decides the www name for the install job. In a zone of ours the record is written now when www is free; a www that
	 * is not free, or on the customer's own DNS, is looked up. Anything that is not ready is left waiting; this never fails.
	 *
	 * @return string The name to serve from the start, or ''.
	 */
	public static function at_install( Resource $r, string $domain, string $ip, int $user_id ): string {
		$www = self::name_for( $r, $domain );
		if ( '' === $www ) {
			return '';
		}
		$zone = strtolower( (string) $r->get( 'app_zone' ) );
		$live = array( 'app_www' => 'live', 'app_www_reason' => null );
		if ( '' !== $zone && $domain === $zone ) {
			$wrote = false;
			$made  = AppDomains::publish( $user_id, $zone, $www, $ip, $wrote, true );
			if ( true === $made ) {
				$r->set( array_merge( $live, array( 'app_www_made' => $wrote || true === $r->get( 'app_www_made' ), 'app_www_ip' => $ip ) ) );
				return $www;
			}
			if ( is_wp_error( $made ) ) {
				// The zone could not be read or written just now: the check job tries again.
				$r->set( array( 'app_www' => 'waiting', 'app_www_reason' => 'retry' ) );
				return '';
			}
		}
		$verdict = self::verdict( $www, $ip );
		if ( 'live' === $verdict ) {
			$r->set( $live );
			return $www;
		}
		$r->set( array( 'app_www' => 'waiting', 'app_www_reason' => $verdict, 'app_www_checked' => time() ) );
		return '';
	}

	/** The app reported ready: a www name still waiting gets its first look. */
	public static function installed( Resource $r ): void {
		if ( in_array( (string) $r->get( 'app_www' ), array( 'waiting', 'applying' ), true ) ) {
			self::schedule( $r->id(), 0 );
		}
	}

	// -- The job ---------------------------------------------------------------------------------

	public static function schedule( int $id, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$waiting = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );
		if ( ! $waiting ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), Jobs::GROUP );
		}
	}

	/** Recovers a look that was lost (a worker stopped, an action removed). */
	public static function sweep(): void {
		global $wpdb;
		$table = \BeltoftLicenses\Install::tables()['activations'];
		$wait  = '%' . $wpdb->esc_like( '"app_www":"waiting"' ) . '%';
		$apply = '%' . $wpdb->esc_like( '"app_www":"applying"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table sweep, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE kind = 'vm' AND deactivated_at IS NULL AND (meta LIKE %s OR meta LIKE %s)", $table, $wait, $apply ) );
		foreach ( (array) $ids as $id ) {
			$r = Resource::find( (int) $id );
			if ( $r && 'ready' === $r->get( 'state' ) && 'ready' === $r->get( 'app_state' ) ) {
				self::schedule( (int) $id, 0 );
			}
		}
	}

	/** One bounded look at one server: confirm what the guest was asked, or read public DNS and ask it. */
	public static function run( int $id ): void {
		$r = Resource::find( $id );
		if ( ! $r || ! $r->is_live() || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'app_state' ) || ! in_array( (string) $r->get( 'app_www' ), array( 'waiting', 'applying' ), true ) ) {
			return;
		}
		$domain = strtolower( (string) $r->get( 'app_domain' ) );
		$www    = self::name_for( $r, $domain );
		$ip     = AppOwnDomain::address( $r );
		if ( '' === $www || '' === $ip ) {
			return;
		}

		$now = time();
		if ( 'applying' === (string) $r->get( 'app_www' ) ) {
			self::confirm( $r, $www, $now );
			return;
		}

		$gap = $now - (int) $r->get( 'app_www_checked' );
		if ( (int) $r->get( 'app_www_checked' ) > 0 && $gap < self::MIN_GAP ) {
			self::schedule( $id, self::MIN_GAP - $gap );
			return;
		}

		// A write that could not be made at install (the zone did not answer) is made now, still only when www is free.
		if ( 'retry' === (string) $r->get( 'app_www_reason' ) && '' !== (string) $r->get( 'app_zone' ) && $domain === strtolower( (string) $r->get( 'app_zone' ) ) ) {
			$wrote = false;
			$made  = AppDomains::publish( (int) ( $r->license()['user_id'] ?? 0 ), (string) $r->get( 'app_zone' ), $www, $ip, $wrote, true );
			if ( true === $made ) {
				$r->set( array( 'app_www_made' => $wrote || true === $r->get( 'app_www_made' ), 'app_www_ip' => $ip, 'app_www_reason' => 'none' ) );
			} elseif ( is_wp_error( $made ) ) {
				$r->set( array( 'app_www_checked' => $now ) );
				self::schedule( $id, self::INTERVAL );
				return;
			} else {
				$r->set( array( 'app_www_reason' => 'none' ) );
			}
		}

		$verdict = self::verdict( $www, $ip );
		if ( 'live' === $verdict && self::write( $r, $domain, $www ) ) {
			$r->set( array( 'app_www' => 'applying', 'app_www_reason' => null, 'app_www_checked' => $now, 'app_www_at' => $now ) );
			self::schedule( $id, 60 );
			return;
		}

		$r->set( array( 'app_www_reason' => 'live' === $verdict ? 'apply' : $verdict, 'app_www_checked' => $now ) );
		self::schedule( $id, self::INTERVAL );
	}

	/** Did the guest serve the name? It answers in www.status.json; a late or failed answer sends the name back to waiting. */
	private static function confirm( Resource $r, string $www, int $now ): void {
		$guest = self::guest( $r );
		$read  = null === $guest ? null : Apps::read_file( $guest['client'], $guest['node'], $guest['vmid'], self::STATUS );
		$state = null === $read ? null : json_decode( $read, true );
		if ( is_array( $state ) && ( $state['www'] ?? '' ) === $www && 'ready' === ( $state['state'] ?? '' ) ) {
			$r->set( array( 'app_www' => 'live', 'app_www_reason' => null, 'app_www_checked' => $now ) );
			return;
		}
		$failed = is_array( $state ) && ( $state['www'] ?? '' ) === $www && 'failed' === ( $state['state'] ?? '' );
		if ( $failed || $now - (int) $r->get( 'app_www_at' ) > self::APPLY_WAIT ) {
			$r->set( array( 'app_www' => 'waiting', 'app_www_reason' => 'apply', 'app_www_checked' => $now ) );
			self::schedule( $r->id(), self::INTERVAL );
			return;
		}
		self::schedule( $r->id(), 60 );
	}

	/** Asks the guest to serve the name. */
	private static function write( Resource $r, string $domain, string $www ): bool {
		$guest = self::guest( $r );
		if ( null === $guest ) {
			return false;
		}
		$base  = '/nodes/' . rawurlencode( $guest['node'] ) . '/qemu/' . $guest['vmid'];
		$reply = $guest['client']->request( 'POST', $base . '/agent/file-write', array( 'file' => self::FILE, 'content' => wp_json_encode( array( 'v' => 1, 'domain' => $domain, 'www' => $www ) ) ) );
		return ! is_wp_error( $reply );
	}

	/** @return array{client: Client, node: string, vmid: int}|null */
	private static function guest( Resource $r ): ?array {
		$record  = VmRecords::for_resource( $r->id() );
		$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
		if ( ! $record || ! $cluster || null !== Clients::gate( $record['cluster'], $cluster, Clients::live_allowed() ) ) {
			return null;
		}
		try {
			return array( 'client' => Clients::for( $cluster ), 'node' => (string) $record['node'], 'vmid' => (int) $record['vmid'] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	// -- What the customer sees ----------------------------------------------------------------------

	/**
	 * The www name of a server for the App tab and the emails: its name, its state (waiting, applying, live, or '' while
	 * nothing has been decided) and the reason it waits.
	 *
	 * @return array{name: string, state: string, reason: string, ip: string}
	 */
	public static function view( Resource $r ): array {
		$name = self::name_for( $r, self::target( $r ) );
		return array(
			'name'   => $name,
			'state'  => '' === $name ? '' : (string) $r->get( 'app_www' ),
			'reason' => '' === $name ? '' : (string) $r->get( 'app_www_reason' ),
			'ip'     => AppOwnDomain::address( $r ),
		);
	}
}
