<?php
/** An app on a domain the customer registered elsewhere. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * "Use my own domain": the customer keeps the DNS of the name wherever it is, and points an A record at the server
 * address we show. We never write DNS for it. When the server is ready the app install waits, a recurring job reads
 * public DNS (DnsQuery::addresses) at a bounded rate until the name resolves to this server and nothing else, and then
 * hands the name to the install job exactly as a name in a zone of ours is handed over (the job's `domain`), so the
 * app's Caddy asks for its certificate. After the waiting time the app is installed on the server address instead.
 *
 * Meta: app_domain_own (the name), app_domain_dns (waiting, live, timeout), app_domain_dns_since, app_domain_dns_checked,
 * app_domain_dns_reason (none, other, aaaa, unreachable), app_domain_dns_mailed. When the name is live the usual
 * app_domain is set (app_domain_made stays false: no record of ours exists, so nothing is ever removed later).
 */
final class AppOwnDomain {

	public const HOOK = 'beltoft_services_app_own_domain_check';

	/** Seconds between two public DNS reads of one server. */
	public const INTERVAL = 600;

	/** The least gap between two reads, whatever calls the job. */
	public const MIN_GAP = 300;

	/** Days the app waits for the name before it is installed on the server address. */
	public const WAIT_DAYS = 14;

	/** Top-level names that can never get a public certificate. */
	private const REFUSED_TLDS = array( 'localhost', 'local', 'internal', 'invalid', 'onion', 'lan', 'home' );

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( Jobs::SWEEP, array( __CLASS__, 'sweep' ) );
	}

	// -- The name ------------------------------------------------------------------------------

	/**
	 * A host name as it would be typed into DNS: lowercase ASCII letters, digits and hyphens in labels, at least two labels.
	 *
	 * @param mixed $raw Typed value.
	 */
	public static function normalise( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$name = strtolower( rtrim( trim( $raw ), '.' ) );
		if ( '' === $name || strlen( $name ) > 253 ) {
			return null;
		}
		$labels = explode( '.', $name );
		if ( count( $labels ) < 2 ) {
			return null;
		}
		foreach ( $labels as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label ) ) {
				return null;
			}
		}
		$tld = end( $labels );
		if ( in_array( $tld, self::REFUSED_TLDS, true ) || 1 !== preg_match( '/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D', $tld ) ) {
			return null;
		}
		return $name;
	}

	/**
	 * The checked choice for checkout and reinstall, with the same wording for both.
	 *
	 * @param array<string, mixed> $app App definition or offer entry.
	 * @param mixed                $raw Typed value.
	 * @return string|array{fqdn: string} The refusal text, or the name.
	 */
	public static function choose( array $app, $raw ) {
		if ( empty( $app['domain'] ) ) {
			return __( 'This app does not use a domain at install. You can add one later.', 'beltoft-services' );
		}
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return __( 'Enter the domain name you want to use.', 'beltoft-services' );
		}
		$name = self::normalise( $raw );
		if ( null === $name ) {
			return __( 'That is not a domain name we can use. Enter a name such as shop.example.com, without https:// or a path, using letters (a to z), digits and hyphens.', 'beltoft-services' );
		}
		return array( 'fqdn' => $name );
	}

	// -- State ---------------------------------------------------------------------------------

	/** Is the app held back until the name points at the server? */
	public static function waiting( Resource $r ): bool {
		return '' !== (string) $r->get( 'app_domain_own' ) && 'waiting' === (string) $r->get( 'app_domain_dns' );
	}

	/** The meta of a name that starts its wait, and of a reinstall that asks for one again. */
	public static function fresh_wait( string $name ): array {
		return array(
			'app_domain_own'         => $name,
			'app_domain_dns'         => 'waiting',
			'app_domain_dns_since'   => null,
			'app_domain_dns_checked' => null,
			'app_domain_dns_reason'  => null,
			'app_domain_dns_mailed'  => null,
		);
	}

	/** The meta that forgets an own domain (another choice at reinstall). */
	public static function cleared(): array {
		return array(
			'app_domain_own'         => null,
			'app_domain_dns'         => null,
			'app_domain_dns_since'   => null,
			'app_domain_dns_checked' => null,
			'app_domain_dns_reason'  => null,
			'app_domain_dns_mailed'  => null,
		);
	}

	/** The server's address the name must point at, '' while it has none. */
	public static function address( Resource $r ): string {
		$ip = IpPool::for_resource( $r->id() );
		return false !== filter_var( (string) ( $ip['address'] ?? '' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? (string) $ip['address'] : '';
	}

	/** The moment the wait ends, 0 before it has started. */
	public static function deadline( Resource $r ): int {
		$since = (int) $r->get( 'app_domain_dns_since' );
		return $since > 0 ? $since + self::WAIT_DAYS * DAY_IN_SECONDS : 0;
	}

	/**
	 * What public DNS says: live (points at the server and nowhere else), none (no A record yet), other (an A record
	 * that is not this server's), aaaa (an AAAA record, which the server cannot answer, would win for some visitors).
	 *
	 * @param array<int, string> $a    A records.
	 * @param array<int, string> $aaaa AAAA records.
	 */
	public static function verdict( string $ip, array $a, array $aaaa ): string {
		if ( array() === $a ) {
			return 'none';
		}
		if ( array( $ip ) !== array_values( array_unique( $a ) ) ) {
			return 'other';
		}
		return array() === $aaaa ? 'live' : 'aaaa';
	}

	// -- The job -------------------------------------------------------------------------------

	/** Starts the wait once the server is ready: the clock begins and the first read is queued. */
	public static function start( Resource $r ): void {
		if ( ! self::waiting( $r ) ) {
			return;
		}
		if ( 0 === (int) $r->get( 'app_domain_dns_since' ) ) {
			$r->locked(
				static function () use ( $r ): bool {
					$fresh = Resource::find( $r->id() );
					return $fresh && 0 === (int) $fresh->get( 'app_domain_dns_since' ) ? $fresh->set( array( 'app_domain_dns_since' => time() ) ) : true;
				}
			);
		}
		self::schedule( $r->id(), 0 );
	}

	public static function schedule( int $id, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$waiting = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );
		if ( ! $waiting ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), Jobs::GROUP );
		}
	}

	/** Recovers a read that was lost (a worker stopped, an action removed). */
	public static function sweep(): void {
		global $wpdb;
		$table   = \BeltoftLicenses\Install::tables()['activations'];
		$pending = '%' . $wpdb->esc_like( '"app_domain_dns":"waiting"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table sweep, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE kind = 'vm' AND deactivated_at IS NULL AND meta LIKE %s", $table, $pending ) );
		foreach ( (array) $ids as $id ) {
			$r = Resource::find( (int) $id );
			if ( $r && 'ready' === $r->get( 'state' ) && self::waiting( $r ) ) {
				self::start( $r );
			}
		}
	}

	/**
	 * One bounded read of public DNS for one server. The read is made outside the resource lock; the result is stored
	 * under it, after the resource has been read again.
	 */
	public static function run( int $id ): void {
		$r = Resource::find( $id );
		if ( ! $r || ! $r->is_live() || 'ready' !== $r->get( 'state' ) || ! self::waiting( $r ) ) {
			return;
		}

		$now  = time();
		$gap  = $now - (int) $r->get( 'app_domain_dns_checked' );
		$name = (string) $r->get( 'app_domain_own' );
		$ip   = self::address( $r );

		if ( (int) $r->get( 'app_domain_dns_checked' ) > 0 && $gap < self::MIN_GAP ) {
			self::schedule( $id, self::MIN_GAP - $gap );
			return;
		}

		$since = (int) $r->get( 'app_domain_dns_since' );
		if ( $since > 0 && $now >= $since + self::WAIT_DAYS * DAY_IN_SECONDS ) {
			self::store( $id, array( 'app_domain_dns' => 'timeout', 'app_domain_dns_checked' => $now, 'app_domain_dns_reason' => null ), true );
			return;
		}

		if ( '' === $ip ) {
			self::schedule( $id, self::INTERVAL );
			return;
		}

		$a    = DnsQuery::addresses( $name, 'A' );
		$aaaa = DnsQuery::addresses( $name, 'AAAA' );
		if ( is_wp_error( $a ) ) {
			$verdict = 'unreachable';
		} else {
			$verdict = self::verdict( $ip, $a, is_wp_error( $aaaa ) ? array() : $aaaa );
		}

		if ( 'live' === $verdict ) {
			// No record of ours exists (app_domain_made false), so a later reinstall never removes anything for this name.
			self::store( $id, array( 'app_domain' => $name, 'app_domain_made' => false, 'app_domain_dns' => 'live', 'app_domain_dns_checked' => $now, 'app_domain_dns_reason' => null ), true );
			return;
		}

		if ( self::store( $id, array( 'app_domain_dns_checked' => $now, 'app_domain_dns_reason' => $verdict ), false ) ) {
			self::schedule( $id, self::INTERVAL );
		}
	}

	/**
	 * Stores the result under the resource lock. A busy lock reads again soon.
	 *
	 * @param array<string, mixed> $meta    Meta.
	 * @param bool                 $install Queue the app install after storing (the wait is over).
	 * @return bool Whether it was stored.
	 */
	private static function store( int $id, array $meta, bool $install ): bool {
		$r = Resource::find( $id );
		if ( ! $r ) {
			return false;
		}
		$done = $r->locked(
			static function () use ( $id, $meta ): bool {
				$fresh = Resource::find( $id );
				return $fresh && self::waiting( $fresh ) && $fresh->set( $meta );
			}
		);
		if ( true !== $done ) {
			self::schedule( $id, 60 );
			return false;
		}
		if ( $install ) {
			AppInstall::schedule( $id, 0 );
		}
		return true;
	}

	// -- The customer is told --------------------------------------------------------------------

	/**
	 * The app is installed on the customer's own domain: one email says so. Called when the app reports ready.
	 */
	public static function installed( Resource $r ): void {
		if ( 'live' !== (string) $r->get( 'app_domain_dns' ) || '' === (string) $r->get( 'app_domain_own' ) || $r->get( 'app_domain_dns_mailed' ) ) {
			return;
		}
		$stored = $r->locked(
			static function () use ( $r ): bool {
				$fresh = Resource::find( $r->id() );
				return $fresh && ! $fresh->get( 'app_domain_dns_mailed' ) && $fresh->set( array( 'app_domain_dns_mailed' => time() ) );
			}
		);
		if ( true === $stored ) {
			$www = AppWww::view( $r );
			Emails::send( 'beltoft_services_app_domain_live', $r, array( 'app_domain' => (string) $r->get( 'app_domain_own' ), 'app_www' => $www['name'], 'app_www_state' => $www['state'], 'app_ip' => $www['ip'] ) );
		}
	}
}
