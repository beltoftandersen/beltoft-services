<?php
/** The unpaid domain notices N1 to N7: when each is due, sending it once, and the proof that it was sent. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Currency;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 2026-11-19-unpaid-domains.md sections 3.1, 3.2 and 3.7. Times are counted from the domain's expiry D (the registrar's renewal date)
 * and the end of the restore window W (D plus the extension's window days):
 *
 * - N1 the renewal could not be paid (the subscription is on hold) and D is ahead: once, when first seen;
 * - N2 the same, 7 days before D (inside the ICANN window of 4 to 10 days);
 * - N3 one day after D, the domain has expired (ICANN: within 5 days after the expiry);
 * - N4 and N5 the restore period ends in 10 and in 2 days (owner decision: the full set for every extension);
 * - N6 a paid renewal after a reminder was sent;
 * - N7 the restore period is over.
 *
 * Each notice is sent once per domain per expiry date. The proof of each (time, address, tries, whether the mail was handed over) is kept in
 * the resource meta `unpaid_notices` and as a line in the service history. When a run finds several notices due at once (the setting was
 * turned on late) it sends the latest and marks the earlier ones skipped, so a customer never gets "expires in 7 days" after "expired".
 * Staff alerts are sent whether or not the customer notices are on.
 */
final class Notices {

	public const HOOK      = 'beltoft_services_domain_unpaid';
	public const CRON      = '30 7 * * *';
	public const LOG       = 'unpaid_notices';
	public const MAX_TRIES = 3;

	/** The date format of the notices and the domain page. Not ServiceEmail::DATE: that class extends WC_Email, which a My Account page has not loaded. */
	public const DATE = 'j F Y';

	/** Notices in the order they fall due. N6 is event driven and not in this list. */
	private const ORDER = array( 'N1', 'N2', 'N3', 'N4', 'N5', 'N7' );

	/** Are the customer notices on? */
	public static function enabled(): bool {
		return (bool) Settings::get( 'domain_unpaid_notices' );
	}

	/**
	 * The daily job: every live domain once.
	 *
	 * @return int Notices sent.
	 */
	public static function run(): int {
		$sent = 0;

		foreach ( Resource::live_ids( 'domain' ) as $id ) {
			$r = Resource::find( $id );

			if ( ! $r || ! $r->is_live() || 'domain' !== $r->get( 'driver' ) ) {
				continue;
			}

			try {
				if ( '' !== self::process( $r ) ) {
					++$sent;
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'Beltoft Services: the unpaid domain check of resource %d failed: %s', $id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- one domain must not stop the others.
			}
		}

		return $sent;
	}

	/**
	 * The notice log of a resource: "expiry date|notice" => time, to, tries, sent, status.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, array<string, mixed>>
	 */
	public static function log( Resource $r ): array {
		$log = $r->get( self::LOG );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Does what is due for one domain: staff alerts, and (when the notices are on) the customer notice.
	 *
	 * @param Resource $r   Domain resource.
	 * @param int|null $now Unix time, now by default.
	 * @return string The notice sent, or ''.
	 */
	public static function process( Resource $r, ?int $now = null ): string {
		$now   = $now ?? time();
		$stage = Stage::of( $r, $now );

		if ( Stage::ACTIVE === $stage['stage'] || null === $stage['expiry'] || ! ctype_digit( (string) $r->get( 'remote_id' ) ) ) {
			return '';
		}

		self::staff( $r, $stage, $now );

		if ( ! self::enabled() ) {
			return '';
		}

		$stamp = gmdate( 'Y-m-d', (int) $stage['expiry'] );
		$due   = self::due( $stage, $now );
		$log   = self::log( $r );
		$todo  = array_values( array_filter( $due, static fn( string $kind ): bool => ! self::settled( $log[ $stamp . '|' . $kind ] ?? null ) ) );

		if ( ! $todo ) {
			return '';
		}

		list( $ran, $sent ) = Locks::with(
			Locks::name( 'domain_unpaid', $r->id() ),
			0,
			static function () use ( $r, $stage, $now, $stamp, $todo ): string {
				// Read again inside the lock: another run may have just sent it.
				$log  = self::log( Resource::find( $r->id() ) ?? $r );
				$todo = array_values( array_filter( $todo, static fn( string $kind ): bool => ! self::settled( $log[ $stamp . '|' . $kind ] ?? null ) ) );

				if ( ! $todo ) {
					return '';
				}

				$kind = (string) end( $todo );

				foreach ( array_slice( $todo, 0, -1 ) as $earlier ) {
					$log[ $stamp . '|' . $earlier ] = array( 'time' => $now, 'to' => '', 'tries' => 0, 'sent' => false, 'status' => 'skipped' );
				}

				$r->set( array( self::LOG => $log ) );

				return self::deliver( $r, $kind, $stage, $now, $stamp ) ? $kind : '';
			}
		);

		return $ran ? (string) $sent : '';
	}

	/**
	 * The notices due at a time, in order. Pure: it does not look at what was sent.
	 *
	 * @param array<string, mixed> $stage Stage::of().
	 * @param int                  $now   Unix time.
	 * @return string[]
	 */
	public static function due( array $stage, int $now ): array {
		$due    = array();
		$expiry = (int) $stage['expiry'];
		$end    = $stage['window_end'];

		if ( Stage::DUE === $stage['stage'] ) {
			if ( $stage['unpaid'] ) {
				$due[] = 'N1';

				if ( $now >= $expiry - 7 * DAY_IN_SECONDS ) {
					$due[] = 'N2';
				}
			}
		} elseif ( Stage::EXPIRED === $stage['stage'] ) {
			if ( $now >= $expiry + DAY_IN_SECONDS ) {
				$due[] = 'N3';
			}

			if ( null !== $end && $now >= $end - 10 * DAY_IN_SECONDS ) {
				$due[] = 'N4';
			}

			if ( null !== $end && $now >= $end - 2 * DAY_IN_SECONDS ) {
				$due[] = 'N5';
			}
		} elseif ( Stage::ENDED === $stage['stage'] ) {
			$due[] = 'N7';
		}

		return $due;
	}

	/**
	 * Is a log entry final: sent, skipped, or tried the most times?
	 *
	 * @param mixed $entry Log entry.
	 */
	private static function settled( $entry ): bool {
		if ( ! is_array( $entry ) ) {
			return false;
		}

		return ! empty( $entry['sent'] ) || 'skipped' === ( $entry['status'] ?? '' ) || (int) ( $entry['tries'] ?? 0 ) >= self::MAX_TRIES;
	}

	/**
	 * Sends one notice and keeps the proof. The attempt is written before the mail goes, so a crash or a repeated run never sends it twice.
	 *
	 * @param Resource             $r     Resource.
	 * @param string               $kind  N1 to N7.
	 * @param array<string, mixed> $stage Stage::of().
	 * @param int                  $now   Unix time.
	 * @param string               $stamp Expiry date the notice belongs to.
	 */
	private static function deliver( Resource $r, string $kind, array $stage, int $now, string $stamp ): bool {
		$key   = $stamp . '|' . $kind;
		$log   = self::log( $r );
		$tries = (int) ( $log[ $key ]['tries'] ?? 0 ) + 1;
		$to    = self::recipient( $r );

		$log[ $key ] = array( 'time' => $now, 'to' => $to, 'tries' => $tries, 'sent' => false, 'status' => 'trying' );
		$r->set( array( self::LOG => $log ) );

		$sent = Emails::send( 'beltoft_services_domain_unpaid', $r, array( 'unpaid' => self::facts( $r, $kind, $stage, $now ) ) );

		$log[ $key ]['sent']   = $sent;
		$log[ $key ]['status'] = $sent ? 'sent' : 'failed';
		$r->set( array( self::LOG => $log ) );

		$license_id = (int) ( $r->license()['id'] ?? 0 );

		if ( $license_id > 0 ) {
			Events::add( $license_id, 'service', $sent ? sprintf( 'Domain notice %1$s sent to %2$s (expiry %3$s).', $kind, $to, $stamp ) : sprintf( 'Domain notice %1$s could not be sent to %2$s (try %3$d).', $kind, $to, $tries ) );
		}

		return $sent;
	}

	/**
	 * The address a notice goes to, as the email picks it: the licence's, else the customer's own.
	 *
	 * @param Resource $r Resource.
	 */
	public static function recipient( Resource $r ): string {
		$license = $r->license();
		$to      = (string) ( $license['email'] ?? '' );

		if ( '' === $to && ! empty( $license['user_id'] ) ) {
			$user = get_userdata( (int) $license['user_id'] );
			$to   = $user ? (string) $user->user_email : '';
		}

		return $to;
	}

	/**
	 * What a notice says: dates, prices and links, in the customer's currency and the shop's tax display.
	 *
	 * @param Resource             $r     Resource.
	 * @param string               $kind  N1 to N7.
	 * @param array<string, mixed> $stage Stage::of().
	 * @param int                  $now   Unix time.
	 * @return array<string, mixed>
	 */
	public static function facts( Resource $r, string $kind, array $stage, int $now ): array {
		$domain   = (string) $r->get( 'domain' );
		$currency = Currency::of_resource( $r );
		$fmt      = static fn( ?int $time ): string => $time ? wp_date( self::DATE, $time ) : '';
		$end      = $stage['window_end'];
		$days     = 'N2' === $kind ? max( 0, (int) $stage['days_to_expiry'] ) : ( null !== $end ? max( 0, (int) ceil( ( $end - $now ) / DAY_IN_SECONDS ) ) : 0 );
		$facts    = array(
			'kind'        => $kind,
			'days'        => $days,
			'expiry_date' => $fmt( $stage['expiry'] ),
			'deadline'    => $fmt( $end ),
			'vat'         => 'incl' === TaxNote::display() ? __( 'including VAT', 'beltoft-services' ) : __( 'excluding VAT', 'beltoft-services' ),
			'pay_url'     => Stage::pay_url( $r ),
			'restore_url' => (string) apply_filters( 'beltoft_services_domain_restore_url', '', $r ),
		);

		$list = Prices::price( (string) ( RestoreFacts::row( $domain )[0] ), $currency );
		$base = null === $list ? null : (float) $list;

		if ( null !== $base ) {
			$facts['renewal_price'] = TaxNote::text( $base, 2, Cart::domain_product(), $currency, false );
		}

		$fee = ReactivationFee::fee( $domain );

		if ( null !== $fee ) {
			$facts['fee_zero'] = $fee <= 0.0;
			$facts['fee']      = $fee > 0.0 ? ReactivationFee::text( $fee, $currency, $domain ) : '';

			if ( null !== $base && $fee > 0.0 && Currency::base() === Currency::or_current( $currency ) ) {
				$facts['total'] = TaxNote::text( $base + $fee, 2, Cart::domain_product(), $currency, false );
			} elseif ( null !== $base && $fee <= 0.0 ) {
				$facts['total'] = $facts['renewal_price'];
			}
		}

		if ( 'N6' === $kind ) {
			$facts['new_expiry'] = $fmt( strtotime( (string) $r->get( 'expires_at' ) . ' UTC' ) ?: null );
		}

		return $facts;
	}

	/**
	 * N6: a paid renewal finished. The customer is told once when a reminder (N1 to N5) was sent for the expiry that has just been paid
	 * past; a domain that was renewed on time hears nothing.
	 *
	 * @param mixed $r      Resource.
	 * @param mixed $expiry The new expiry.
	 */
	public static function on_renewed( $r, $expiry ): void {
		try {
			if ( ! $r instanceof Resource || ! self::enabled() ) {
				return;
			}

			$log   = self::log( $r );
			$told  = '';
			$stamp = '';

			foreach ( $log as $key => $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['sent'] ) || str_ends_with( (string) $key, '|N6' ) || str_ends_with( (string) $key, '|N7' ) ) {
					continue;
				}

				$told  = (string) $key;
				$stamp = (string) strstr( $told, '|', true );
			}

			if ( '' === $told || isset( $log[ $stamp . '|N6' ] ) ) {
				return;
			}

			// The reminder belonged to an older expiry than the one just paid.
			$new = strtotime( (string) $expiry . ' UTC' );

			if ( ! $new || gmdate( 'Y-m-d', $new ) === $stamp ) {
				return;
			}

			$stage = Stage::of( $r );
			$now   = time();
			$log[ $stamp . '|N6' ] = array( 'time' => $now, 'to' => self::recipient( $r ), 'tries' => 1, 'sent' => false, 'status' => 'trying' );
			$r->set( array( self::LOG => $log ) );
			$sent = Emails::send( 'beltoft_services_domain_unpaid', $r, array( 'unpaid' => self::facts( $r, 'N6', $stage, $now ) ) );
			$log[ $stamp . '|N6' ]['sent']   = $sent;
			$log[ $stamp . '|N6' ]['status'] = $sent ? 'sent' : 'failed';
			$r->set( array( self::LOG => $log ) );

			$license_id = (int) ( $r->license()['id'] ?? 0 );

			if ( $license_id > 0 ) {
				Events::add( $license_id, 'service', $sent ? sprintf( 'Domain notice N6 sent to %1$s (renewed to %2$s).', self::recipient( $r ), gmdate( 'Y-m-d', $new ) ) : 'Domain notice N6 could not be sent.' );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Beltoft Services: the domain renewed notice failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a notice never fails a renewal.
		}
	}

	/**
	 * Staff alerts, once per domain, expiry date and event (plan section 3.7): the renewal failed, the domain expired, the restore window ends
	 * in 10 days, the window ended, and a window nobody can work out.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $stage Stage::of().
	 * @param int                  $now   Unix time.
	 */
	private static function staff( Resource $r, array $stage, int $now ): void {
		$domain = (string) $r->get( 'domain' );
		$stamp  = gmdate( 'Y-m-d', (int) $stage['expiry'] );
		$end    = $stage['window_end'];
		$events = array();

		if ( Stage::DUE === $stage['stage'] && $stage['unpaid'] ) {
			$events['failed'] = array( 'Domain renewal not paid: ' . $domain, 'The renewal is not paid. The domain works until its expiry.' );
		}

		if ( in_array( $stage['stage'], array( Stage::EXPIRED, Stage::ENDED ), true ) ) {
			$events['expired'] = array( 'Domain expired: ' . $domain, 'The domain has expired and the renewal was not paid.' );

			if ( null === $end ) {
				$events['nowindow'] = array( 'Domain restore window unknown: ' . $domain, 'No restore window is known for this extension (the price table has no quarantine days), so no restore notice dates can be set. Check the extension by hand.' );
			}
		}

		if ( Stage::EXPIRED === $stage['stage'] && null !== $end && $now >= $end - 10 * DAY_IN_SECONDS ) {
			$events['ending'] = array( 'Domain restore window ends soon: ' . $domain, 'The restore window ends in at most 10 days.' );
		}

		if ( Stage::ENDED === $stage['stage'] ) {
			$events['ended'] = array( 'Domain restore window ended: ' . $domain, 'The restore window is over. The registrar or registry deletes or releases the domain. This plugin never deletes a registration.' );
		}

		foreach ( $events as $event => $text ) {
			Alerts::quietly(
				static function () use ( $r, $text, $stamp, $event, $stage ): void {
					Alerts::notify(
						$text[0],
						array(
							sprintf( 'Domain: %s', (string) $r->get( 'domain' ) ),
							sprintf( 'Renewal date: %s', (string) $r->get( 'expires_at' ) ),
							sprintf( 'Resource: %d', $r->id() ),
							$text[1],
							sprintf( 'Customer notices are %s.', self::enabled() ? 'on' : 'off' ),
						),
						$r,
						'beltoft_services_domain_unpaid_' . $r->id() . '_' . $stamp . '_' . $event,
						array( 'ttl' => YEAR_IN_SECONDS )
					);
				}
			);
		}
	}
}
