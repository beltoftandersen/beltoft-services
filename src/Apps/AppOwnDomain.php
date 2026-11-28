<?php
/** An app on a domain the customer registered elsewhere. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Background;
use BeltoftServices\Domains\DnsWatch;
use BeltoftServices\Domains\DnsWatchJobs;
use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Domains\ManualCheck;
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
 * app_domain_dns_reason (none, other, aaaa, unreachable), app_domain_dns_seen (the IPv4 addresses the last read found, for the
 * box that tells the customer what DNS says now), app_domain_dns_mailed. When the name is live the usual
 * app_domain is set (app_domain_made stays false: no record of ours exists, so nothing is ever removed later).
 */
final class AppOwnDomain {

	public const HOOK = 'beltoft_services_app_own_domain_check';

	/** The job that hands a found name to the install again when the lock was busy ( resource id, try ). */
	public const FOUND_HOOK = 'beltoft_services_app_own_domain_found';

	/** The defaults of the shared DNS watch settings (Domains, DNS watch), kept for the domain change, which waits with the same numbers. */
	public const INTERVAL = 600;
	public const MIN_GAP  = 300;
	public const WAIT_DAYS = 14;

	/**
	 * The own-domain look is the shared DNS watch (Domains\DnsWatch): this registers the app's object with it. The look hook, its
	 * watchdog and the daily look are declared by the watch.
	 */
	public static function register(): void {
		add_filter(
			'beltoft_services_dns_watches',
			static function ( $features ) {
				$features                    = is_array( $features ) ? $features : array();
				$features[ AppDnsWatch::ID ] = new AppDnsWatch();

				return $features;
			}
		);

		add_action( self::FOUND_HOOK, array( __CLASS__, 'on_found' ), 10, 2 );
		Background::declare( self::FOUND_HOOK, array( 'resource' => true, 'label' => 'App own domain hand-over to the install' ) );
	}

	/**
	 * The retry of found(): the watch stored the name as live before found() ran, so the look would return at once; this job is
	 * the only thing that can still hand the name over.
	 *
	 * @param mixed $resource_id Resource id.
	 * @param mixed $try         Number of the try.
	 */
	public static function on_found( $resource_id, $try = 1 ): void {
		$r = Resource::find( (int) $resource_id );

		if ( $r && $r->is_live() && 'live' === (string) $r->get( 'app_domain_dns' ) ) {
			self::found( $r, max( 1, (int) $try ) );
		}
	}

	/** The watch's object for apps. */
	private static function feature(): ?AppDnsWatch {
		$f = DnsWatch::feature( AppDnsWatch::ID );

		return $f instanceof AppDnsWatch ? $f : null;
	}

	// -- The name ------------------------------------------------------------------------------

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
		$name = HostName::normalise( $raw );
		if ( null === $name ) {
			return __( 'That is not a domain name we can use. Enter a name such as shop.example.com, without https:// or a path, using letters (a to z), digits and hyphens.', 'beltoft-services' );
		}
		return array( 'fqdn' => $name );
	}

	// -- State ---------------------------------------------------------------------------------

	/**
	 * Is the app held back until the name points at the server (the first wait, before the install)? A name that was live and was lost
	 * is not this: the app is installed and runs (see lost()).
	 */
	public static function waiting( Resource $r ): bool {
		return '' !== (string) $r->get( 'app_domain_own' ) && 'waiting' === (string) $r->get( 'app_domain_dns' ) && '' === (string) $r->get( 'app_domain' );
	}

	/** Was the name found (stored live) without being handed to the install yet? The install must not run on the address then. */
	public static function handing_over( Resource $r ): bool {
		return '' !== (string) $r->get( 'app_domain_own' ) && '' === (string) $r->get( 'app_domain' ) && 'live' === (string) $r->get( 'app_domain_dns' );
	}

	/** Did the name point to the server once and not any more (the watch warns the customer; nothing is stopped)? */
	public static function lost( Resource $r ): bool {
		return '' !== (string) $r->get( 'app_domain_own' ) && '' !== (string) $r->get( 'app_domain' ) && in_array( (string) $r->get( 'app_domain_dns' ), array( 'waiting', 'timeout' ), true );
	}

	/** Is the name one the install used (live or lost), not one that is still awaited or that never worked? */
	public static function lost_or_live( Resource $r ): bool {
		return '' !== (string) $r->get( 'app_domain_own' ) && '' !== (string) $r->get( 'app_domain' ) && '' !== (string) $r->get( 'app_domain_dns' );
	}

	/** The meta of a name that starts its wait, and of a reinstall that asks for one again. */
	public static function fresh_wait( string $name ): array {
		return array(
			'app_domain_own'         => $name,
			'app_domain_dns'         => 'waiting',
			'app_domain_dns_since'   => null,
			'app_domain_dns_checked' => null,
			'app_domain_dns_reason'  => null,
			'app_domain_dns_seen'    => null,
			'app_domain_dns_mailed'  => null,
			'app_domain_dns_rows'    => null,
			'app_domain_dns_mode'    => null,
			'app_domain_dns_rechecked' => null,
			'app_domain_dns_reminded' => null,
			'app_domain_dns_lost_at' => null,
			'app_domain_dns_lost_keys' => null,
			'app_domain_dns_lost_mails' => null,
			'app_domain_dns_lost_pending' => null,
			'app_domain_dns_last_mail' => null,
			'app_domain_dns_back_at' => null,
			'app_domain_dns_timeout_mailed' => null,
			'app_domain_dns_admin_alerted' => null,
			'app_domain_dns_moved'   => null,
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
			'app_domain_dns_seen'    => null,
			'app_domain_dns_mailed'  => null,
			'app_domain_dns_rows'    => null,
			'app_domain_dns_mode'    => null,
			'app_domain_dns_rechecked' => null,
			'app_domain_dns_reminded' => null,
			'app_domain_dns_lost_at' => null,
			'app_domain_dns_lost_keys' => null,
			'app_domain_dns_lost_mails' => null,
			'app_domain_dns_lost_pending' => null,
			'app_domain_dns_last_mail' => null,
			'app_domain_dns_back_at' => null,
			'app_domain_dns_timeout_mailed' => null,
			'app_domain_dns_admin_alerted' => null,
			'app_domain_dns_moved'   => null,
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
		return $since > 0 ? $since + DnsWatchSettings::wait_days( AppDnsWatch::ID ) * DAY_IN_SECONDS : 0;
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

	/**
	 * Starts the wait once the server is ready: the clock begins and the first look is queued. A name that was live and was lost
	 * has its clock restarted by the watch itself.
	 */
	public static function start( Resource $r ): void {
		if ( ! self::waiting( $r ) && ! self::lost( $r ) ) {
			return;
		}
		$delay = 0;
		if ( 0 === (int) $r->get( 'app_domain_dns_since' ) ) {
			$stored = $r->locked(
				static function () use ( $r ): bool {
					$fresh = Resource::find( $r->id() );
					return $fresh && 0 === (int) $fresh->get( 'app_domain_dns_since' ) ? $fresh->set( array( 'app_domain_dns_since' => time() ) ) : true;
				}
			);
			// The clock could not be written (the lock was busy or the write failed): look again later, not at once, so a store that
			// keeps failing is not a tight loop.
			$delay = true === $stored ? 0 : 60;
		}
		self::schedule( $r->id(), $delay );
	}

	public static function schedule( int $id, int $delay ): void {
		$f = self::feature();

		if ( $f ) {
			DnsWatchJobs::look( $f, $id, $delay );
		}
	}

	/**
	 * One bounded read of public DNS for one server: the shared watch's look.
	 */
	public static function run( int $id ): void {
		$f = self::feature();

		if ( $f ) {
			DnsWatch::run( $f, $id );
		}
	}

	/**
	 * The name points to the server alone: it is handed to the install exactly as a name in a zone of ours is (the install job's
	 * `domain`), and no record of ours exists (app_domain_made false), so a later reinstall never removes anything for this name. Called
	 * by the watch when the needed record is found. A name found again after it was lost has been handed over already: nothing is queued.
	 */
	public static function found( Resource $r, int $try = 0 ): void {
		if ( '' !== (string) $r->get( 'app_domain' ) || ! in_array( (string) $r->get( 'app_state' ), array( '', 'pending' ), true ) ) {
			return;
		}
		$id    = $r->id();
		$name  = (string) $r->get( 'app_domain_own' );
		$stored = $r->locked(
			static function () use ( $id, $name ): bool {
				$fresh = Resource::find( $id );
				return $fresh && '' === (string) $fresh->get( 'app_domain' ) && $name === (string) $fresh->get( 'app_domain_own' ) && $fresh->set( array( 'app_domain' => $name, 'app_domain_made' => false ) );
			}
		);
		if ( true === $stored ) {
			AppInstall::schedule( $id, 0 );
		} elseif ( $try < 12 ) {
			// The watch has stored the name as live already, so a look would return at once: found() is tried again by its own job,
			// along a backoff (1, 2, 4 ... 60 minutes). The install job does the same hand-over (see handing_over()) if it runs first.
			Jobs::schedule_once( time() + min( 3600, 60 * ( 2 ** $try ) ), self::FOUND_HOOK, array( $id, $try + 1 ) );
		}
	}

	/**
	 * The wait ended without the name: the app is installed on the server address, as before the watch. A name that was live and was
	 * lost never starts an install: the watch only warns.
	 */
	public static function timed_out( Resource $r ): void {
		if ( '' === (string) $r->get( 'app_domain' ) && in_array( (string) $r->get( 'app_state' ), array( '', 'pending' ), true ) ) {
			AppInstall::schedule( $r->id(), 0 );
		}
	}

	/**
	 * What the box says follows the row the watch stored: the verdict (none, other, aaaa, unreachable) and the addresses seen. A name
	 * that is found clears the verdict.
	 *
	 * @param Resource             $r   Resource.
	 * @param array<string, mixed> $row The record's row.
	 */
	public static function sync_view( Resource $r, array $row ): void {
		$ok   = 'ok' === (string) ( $row['status'] ?? '' );
		$meta = array( 'app_domain_dns_reason' => $ok ? null : ( '' !== (string) ( $row['reason'] ?? '' ) ? (string) $row['reason'] : null ) );

		if ( ! $ok && is_array( $row['seen'] ?? null ) ) {
			$meta['app_domain_dns_seen'] = array_values( $row['seen'] );
		}

		if ( (string) $r->get( 'app_domain_dns_reason' ) === (string) $meta['app_domain_dns_reason'] && ( ! isset( $meta['app_domain_dns_seen'] ) || $r->get( 'app_domain_dns_seen' ) === $meta['app_domain_dns_seen'] ) ) {
			return;
		}

		$id = $r->id();
		$r->locked(
			static function () use ( $id, $meta ): bool {
				$fresh = Resource::find( $id );
				return $fresh && $fresh->set( $meta );
			}
		);
	}

	// -- The customer's Check now ----------------------------------------------------------------

	/** The times (Unix) of the customer's checks of the last hour. */
	public const MANUAL_META = 'app_domain_dns_manual';

	/** The shared least seconds between two customer checks of one server (DNS watch settings). */
	public static function manual_gap(): int {
		return DnsWatchSettings::check_gap( AppDnsWatch::ID );
	}

	/** The shared most customer checks of one server in a rolling hour (DNS watch settings). */
	public static function manual_per_hour(): int {
		return DnsWatchSettings::check_per_hour( AppDnsWatch::ID );
	}

	/** The moment the customer may check again, 0 when now. */
	public static function manual_next( Resource $r, ?int $now = null ): int {
		return ManualCheck::next( $r, self::MANUAL_META, self::manual_gap(), self::manual_per_hour(), $now );
	}

	/**
	 * The customer asks for a look now. The look is the watch's (DnsWatch::run, which a name that is lost and a name that is awaited both
	 * use); the limits are the shared ones and are kept here, on the server. A name whose look had stopped after the days ran out is
	 * started again. The job's schedule is not touched.
	 *
	 * @return array{verdict: string|null}|\WP_Error Error codes: not_waiting (409), rate_limited (429, data: next), busy (409).
	 */
	public static function check_now( Resource $r ) {
		$f = self::feature();
		if ( ! $f || ! $r->is_live() || 'ready' !== $r->get( 'state' ) || '' === self::address( $r ) ) {
			return new \WP_Error( 'not_waiting', __( 'This server is not waiting for your domain.', 'beltoft-services' ), array( 'status' => 409 ) );
		}
		$first = self::waiting( $r ) && 0 !== (int) $r->get( 'app_domain_dns_since' );
		$gone  = self::lost( $r );
		if ( ! $first && ! $gone ) {
			return new \WP_Error( 'not_waiting', __( 'This server is not waiting for your domain.', 'beltoft-services' ), array( 'status' => 409 ) );
		}
		$deadline = self::deadline( $r );
		if ( $first && $deadline > 0 && time() >= $deadline ) {
			return new \WP_Error( 'not_waiting', __( 'This server is not waiting for your domain.', 'beltoft-services' ), array( 'status' => 409 ) );
		}

		// The count is kept under the lock, so two clicks at once make one look.
		$next = ManualCheck::claim( $r, self::MANUAL_META, self::manual_gap(), self::manual_per_hour() );
		if ( null === $next ) {
			return new \WP_Error( 'busy', __( 'This server is busy. Please try again in a moment.', 'beltoft-services' ), array( 'status' => 409 ) );
		}
		if ( $next > 0 ) {
			return new \WP_Error( 'rate_limited', __( 'You are checking too often.', 'beltoft-services' ), array( 'status' => 429, 'next' => $next ) );
		}

		// A look that had stopped (after the days ran out) starts again, as the customer asked.
		if ( $gone && 'timeout' === (string) $r->get( 'app_domain_dns' ) && ! DnsWatch::restart( $f, $r ) ) {
			return new \WP_Error( 'busy', __( 'This server is busy. Please try again in a moment.', 'beltoft-services' ), array( 'status' => 409 ) );
		}

		DnsWatch::run( $f, $r->id(), true );
		$fresh = Resource::find( $r->id() ) ?? $r;

		if ( 'live' === (string) $fresh->get( 'app_domain_dns' ) ) {
			return array( 'verdict' => 'live' );
		}

		return array( 'verdict' => '' !== (string) $fresh->get( 'app_domain_dns_reason' ) ? (string) $fresh->get( 'app_domain_dns_reason' ) : null );
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
