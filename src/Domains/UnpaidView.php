<?php
/** What a domain's page and its admin panel say about renewal, expiry and restoring. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Currency;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The customer part is behind the setting domain_unpaid_notices. It states the renewal or expiry state, the restore period of the extension
 * and the reactivation fee (ICANN expects a reseller's site to show the post-expiry and restore fees: plan section 3.3 and finding 10).
 */
final class UnpaidView {

	/**
	 * The paragraphs of the domain page, from the stored data only (no registrar call).
	 *
	 * @param Resource $r Domain resource.
	 * @return array{lines: string[], pay_url: string, restore_url: string, restore_form: bool, contact: bool}
	 */
	public static function lines( Resource $r ): array {
		$out    = array( 'lines' => array(), 'pay_url' => '', 'restore_url' => '', 'restore_form' => false, 'contact' => false );
		$stage  = Stage::of( $r );
		$domain = (string) $r->get( 'domain' );

		if ( null === $stage['expiry'] ) {
			return $out;
		}

		// A paid restore that is being carried out shows whatever the stage says (paying it can already have made the licence active).
		if ( null !== Restore::paid_open( $r ) ) {
			$out['lines'][] = __( 'We received your payment and are restoring this domain. You will get an email when it is done.', 'beltoft-services' );

			return $out;
		}

		// With the notices off the page says nothing about renewals, only what an expired domain needs to be restored (when restoring is on).
		if ( ! Notices::enabled() && ( ! Restore::enabled() || Stage::EXPIRED !== $stage['stage'] ) ) {
			return $out;
		}

		$date     = wp_date( Notices::DATE, (int) $stage['expiry'] );
		$deadline = null === $stage['window_end'] ? '' : wp_date( Notices::DATE, (int) $stage['window_end'] );
		$terms    = ReactivationFee::terms( $domain );
		$currency = Currency::of_resource( $r );
		$fee      = ReactivationFee::fee( $domain );

		if ( Stage::DUE === $stage['stage'] ) {
			/* translators: %s: date. */
			$out['lines'][]  = sprintf( __( 'The renewal is due. Pay it by %s. The domain works until then.', 'beltoft-services' ), $date );
			$out['pay_url']  = Stage::pay_url( $r );
		} elseif ( Stage::EXPIRED === $stage['stage'] ) {
			/* translators: %s: date. */
			$out['lines'][] = sprintf( __( 'This domain expired on %s. The website and email on it do not work now.', 'beltoft-services' ), $date );

			if ( '' !== $deadline ) {
				/* translators: %s: date. */
				$out['lines'][] = sprintf( __( 'You can restore it until %s.', 'beltoft-services' ), $deadline );
			}

			$paid_year = Restore::year_paid( $r );

			if ( null !== $paid_year && Restore::enabled() && null !== $fee ) {
				/* translators: %s: reactivation fee, with "including VAT" or "excluding VAT". */
				$out['lines'][] = sprintf( __( 'Your renewal payment is received and counts toward restoring the domain. To restore it you only pay the reactivation fee of %s.', 'beltoft-services' ), ReactivationFee::text( $fee, $currency, $domain ) . ', ' . self::vat() );
			} elseif ( null !== $fee ) {
				$out['lines'][] = $fee > 0.0
					/* translators: 1: reactivation fee, 2: "including VAT" or "excluding VAT". */
					? sprintf( __( 'Restoring costs the renewal plus a reactivation fee of %1$s, %2$s.', 'beltoft-services' ), ReactivationFee::text( $fee, $currency, $domain ), self::vat() )
					: __( 'Restoring costs the renewal. There is no reactivation fee.', 'beltoft-services' );
			}

			if ( null !== Restore::paid_open( $r ) ) {
				$out['lines'][] = __( 'We received your payment and are restoring this domain. You will get an email when it is done.', 'beltoft-services' );

				return $out;
			}

			// The Restore button is a form on this page; a listener's own address (another shop flow) is a link instead.
			$out['restore_form'] = null !== Restore::offer( $r );
			$out['restore_url']  = $out['restore_form'] ? '' : (string) apply_filters( 'beltoft_services_domain_restore_url', '', $r );
			$out['contact']      = ! $out['restore_form'] && '' === $out['restore_url'];
		} elseif ( Stage::ENDED === $stage['stage'] ) {
			/* translators: %s: domain name. */
			$out['lines'][] = sprintf( __( '%s is no longer registered by us.', 'beltoft-services' ), $domain );
			$out['contact'] = true;
		}

		if ( Stage::ACTIVE === $stage['stage'] || Stage::DUE === $stage['stage'] ) {
			$out['lines'][] = self::terms_line( $terms, $currency, $domain );
		}

		$out['lines'] = array_values( array_filter( $out['lines'], static fn( string $line ): bool => '' !== $line ) );

		return $out;
	}

	/**
	 * The standing disclosure of what an unpaid renewal costs, per extension.
	 *
	 * @param array<string, mixed> $terms    ReactivationFee::terms().
	 * @param string               $currency Currency code.
	 * @param string               $domain   Full domain name.
	 */
	private static function terms_line( array $terms, string $currency, string $domain ): string {
		if ( null === $terms['window'] ) {
			return __( 'If the renewal is not paid, the domain stops working on its expiry date.', 'beltoft-services' );
		}

		$fee = $terms['fee'];

		if ( null === $fee ) {
			/* translators: %d: number of days. */
			return sprintf( _n( 'If the renewal is not paid, the domain stops working on its expiry date. You can restore it for %d day after that, at the renewal price plus a reactivation fee.', 'If the renewal is not paid, the domain stops working on its expiry date. You can restore it for %d days after that, at the renewal price plus a reactivation fee.', $terms['window'], 'beltoft-services' ), $terms['window'] );
		}

		if ( $fee <= 0.0 ) {
			/* translators: %d: number of days. */
			return sprintf( _n( 'If the renewal is not paid, the domain stops working on its expiry date. You can restore it for %d day after that, at the renewal price with no reactivation fee.', 'If the renewal is not paid, the domain stops working on its expiry date. You can restore it for %d days after that, at the renewal price with no reactivation fee.', $terms['window'], 'beltoft-services' ), $terms['window'] );
		}

		/* translators: 1: number of days, 2: reactivation fee, 3: "including VAT" or "excluding VAT". */
		return sprintf( _n( 'If the renewal is not paid, the domain stops working on its expiry date. You can restore it for %1$d day after that, at the renewal price plus a reactivation fee of %2$s, %3$s.', 'If the renewal is not paid, the domain stops working on its expiry date. You can restore it for %1$d days after that, at the renewal price plus a reactivation fee of %2$s, %3$s.', $terms['window'], 'beltoft-services' ), $terms['window'], ReactivationFee::text( $fee, $currency, $domain ), self::vat() );
	}

	private static function vat(): string {
		return 'incl' === TaxNote::display() ? __( 'including VAT', 'beltoft-services' ) : __( 'excluding VAT', 'beltoft-services' );
	}

	/**
	 * Prints the block in the overview of a domain's page (action beltoft_services_domain_overview_end).
	 *
	 * @param mixed $r Resource.
	 * @param mixed $d Page values.
	 */
	public static function overview( $r, $d = array() ): void {
		if ( ! $r instanceof Resource || ( ! Notices::enabled() && ! Restore::enabled() ) || 'domain' !== $r->get( 'driver' ) || ( is_array( $d ) && ! empty( $d['dns_only'] ) ) ) {
			return;
		}

		$view = self::lines( $r );

		if ( ! $view['lines'] ) {
			return;
		}

		echo '<div class="beltoft-services-domain__renewal" data-domain-renewal>';

		foreach ( $view['lines'] as $line ) {
			echo '<p class="beltoft-services-domain__note">' . esc_html( $line ) . '</p>';
		}

		if ( '' !== $view['pay_url'] ) {
			echo '<p><a class="bs-btn" href="' . esc_url( $view['pay_url'] ) . '">' . esc_html__( 'Pay the renewal', 'beltoft-services' ) . '</a></p>';
		}

		if ( $view['restore_form'] ) {
			$license = $r->license();
			$area    = \BeltoftServices\Account\ServiceArea::get( Domains::class );

			echo '<form method="post" action="' . esc_url( $area->url( $license ) ) . '">';
			echo '<input type="hidden" name="' . esc_attr( Restore::FIELD ) . '" value="1">';
			echo '<input type="hidden" name="license" value="' . esc_attr( (string) (int) $license['id'] ) . '">';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( Restore::nonce_action( (int) $license['id'] ) ) ) . '">';
			echo '<p><button type="submit" class="bs-btn">' . esc_html__( 'Restore domain', 'beltoft-services' ) . '</button></p>';
			echo '</form>';
		}

		if ( '' !== $view['restore_url'] ) {
			echo '<p><a class="bs-btn" href="' . esc_url( $view['restore_url'] ) . '">' . esc_html__( 'Restore the domain', 'beltoft-services' ) . '</a></p>';
		}

		if ( $view['contact'] ) {
			echo '<p class="beltoft-services-domain__note">' . esc_html__( 'To restore it, contact us.', 'beltoft-services' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Rows for the admin panel of a domain (filter beltoft_services_domain_admin_details): what the registrar says, the stage and the notices.
	 *
	 * @param mixed $details Rows so far.
	 * @param mixed $r       Resource.
	 * @return mixed
	 */
	public static function admin_details( $details, $r ) {
		if ( ! is_array( $details ) || ! $r instanceof Resource || 'domain' !== $r->get( 'driver' ) ) {
			return $details;
		}

		$stage = Stage::of( $r );
		$notes = array();

		foreach ( Notices::log( $r ) as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$notes[] = sprintf( '%1$s: %2$s %3$s %4$s', str_replace( '|', ' ', (string) $key ), (string) ( $entry['status'] ?? '' ), ! empty( $entry['time'] ) ? wp_date( 'Y-m-d H:i', (int) $entry['time'] ) : '', (string) ( $entry['to'] ?? '' ) );
			}
		}

		$rows = array(
			__( 'Renewal date at the registrar', 'beltoft-services' )     => $r->get( 'registrar_renewal_date' ),
			__( 'Expiration date at the registrar', 'beltoft-services' )  => $r->get( 'registrar_expiration_date' ),
			__( 'Registry expiration date', 'beltoft-services' )          => $r->get( 'registry_expires_at' ),
			__( 'Soft quarantine until (registrar)', 'beltoft-services' ) => $r->get( 'soft_quarantine_until' ),
			__( 'Hard quarantine until (registrar)', 'beltoft-services' ) => $r->get( 'hard_quarantine_until' ),
			__( 'Quarantine until (registrar)', 'beltoft-services' )      => $r->get( 'quarantine_until' ),
			__( 'Restorable until (registrar)', 'beltoft-services' )      => $r->get( 'restorable_until' ),
			__( 'Registrar delete status', 'beltoft-services' )           => $r->get( 'registrar_delete_status' ),
			__( 'Registrar autorenew', 'beltoft-services' )               => $r->get( 'registrar_autorenew' ),
			__( 'Renewal stage', 'beltoft-services' )                     => $stage['stage'] . ( $stage['unpaid'] ? ' (subscription on hold)' : '' ),
			__( 'Restore window', 'beltoft-services' )                    => null === $stage['window_days'] ? '' : sprintf( '%1$d days, to %2$s', $stage['window_days'], gmdate( 'Y-m-d', (int) $stage['window_end'] ) ),
			__( 'Notices sent', 'beltoft-services' )                      => implode( '; ', $notes ),
		);

		return array_merge( $details, array_filter( $rows, static fn( $value ): bool => null !== $value && '' !== (string) $value ) );
	}
}
