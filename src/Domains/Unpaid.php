<?php
/** Wires the unpaid domain feature: settings, the daily notice job, the paused email swap, the renewed notice and the registrar checks. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 2026-11-19-unpaid-domains.md, phases 1 and 2. Everything that changes what a customer receives or sees is behind the setting
 * domain_unpaid_notices (off by default). Reading the registrar's dates and fields, the restore prices and the staff alerts need no switch.
 */
final class Unpaid {

	public const SETTING = 'domain_unpaid_notices';

	public static function register(): void {
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
		add_filter( 'beltoft_services_send_paused_email', array( __CLASS__, 'paused_email' ), 10, 2 );
		add_action( 'beltoft_services_domain_renewed', array( Notices::class, 'on_renewed' ), 10, 2 );
		add_action( Notices::HOOK, array( Notices::class, 'run' ) );
		add_filter( 'beltoft_services_domain_admin_details', array( UnpaidView::class, 'admin_details' ), 10, 2 );
		Restore::register();
		add_action( 'beltoft_services_domain_overview_end', array( UnpaidView::class, 'overview' ), 10, 2 );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/** Schedules the daily check, after the registrar sync (04:45) so it reads fresh dates. */
	public static function schedule(): void {
		Jobs::ensure_cron( Notices::HOOK, Notices::CRON );
	}

	/**
	 * The settings of the feature, on the Domains tab.
	 *
	 * @param mixed $fields Fields so far.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array( 'tab' => DomainSettings::TAB, 'section' => DomainSettings::TAB );

		$fields[ self::SETTING ]                      = $base + array(
			'type'    => 'checkbox',
			'default' => 0,
			'label'   => __( 'Notices for domains whose renewal is not paid', 'beltoft-services' ),
			'desc'    => __( 'On: a domain whose renewal could not be paid gets a plain email that says it still works until its expiry date (not "paused"), a reminder a week before, and after the expiry the notices about restoring it and when that ends. The domain page shows the renewal state, the restore period and the reactivation fee. Off: the generic paused email, as before. Staff alerts are sent either way.', 'beltoft-services' ),
		);
		$fields[ Restore::SETTING ]                  = $base + array(
			'type'    => 'checkbox',
			'default' => 0,
			'label'   => __( 'Let customers restore an expired domain', 'beltoft-services' ),
			'desc'    => __( 'On: the domain page of an expired domain has a Restore domain button. It makes one order with the renewal of one year and the reactivation fee, and when the order is paid the domain is restored at the registrar (.dk: our staff are told to restore it by hand). The fee is always charged, because it is not known whether the registrar restores free. A normal renewal that is paid after the expiry buys nothing and tells our staff. Off: the page says to contact us. Needs the notices setting above to show anything to the customer.', 'beltoft-services' ),
		);
		$fields[ ReactivationFee::MARKUP_SETTING ] = $base + array(
			'type'    => 'number',
			'default' => 30,
			'min'     => 0,
			'max'     => 500,
			'label'   => __( 'Reactivation fee markup, in percent of the registrar restore cost', 'beltoft-services' ),
			'desc'    => __( 'The fee for restoring an expired domain is the full registrar restore cost plus this markup, on top of the normal renewal. No fee before the expiry. It is charged even while the registrar might restore free, because that is not known.', 'beltoft-services' ),
		);

		$fields[ ReactivationFee::DKK_RATE_SETTING ] = $base + array(
			'type'    => 'text',
			'default' => '',
			'label'   => __( 'Reactivation fee: kroner per euro', 'beltoft-services' ),
			'desc'    => __( 'A fixed rate you set, for example 7.46. When set, the fee shown to a customer in kroner is the euro fee times this rate, rounded up to whole kroner, with VAT shown as for other prices. Empty: the fee is shown in euro.', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * Swaps the generic "paused" email of a domain whose renewal was not paid for the domain's own notice: the domain still works until its
	 * expiry, so "paused" was wrong. A domain paused for another reason (an admin, a refund, a breach) keeps the generic email.
	 *
	 * @param mixed $send Whether the generic email is sent.
	 * @param mixed $r    Resource.
	 * @return mixed
	 */
	public static function paused_email( $send, $r ) {
		if ( ! $send || ! $r instanceof Resource || 'domain' !== $r->get( 'driver' ) || ! Notices::enabled() ) {
			return $send;
		}

		if ( ! in_array( (string) $r->get( 'paused_reason' ), array( 'License suspended', 'License expired' ), true ) ) {
			return $send;
		}

		try {
			Notices::process( $r );
		} catch ( \Throwable $e ) {
			error_log( 'Beltoft Services: the unpaid domain notice failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a notice never fails a pause.
		}

		return false;
	}

	/**
	 * After the daily registrar read of a domain: tells staff about a registrar autorenew that is not off (the registrar would renew it from our
	 * balance without a customer payment, plan risk list) and about quarantine dates that disagree with the extension's window.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $reply GET /domains/{id}.
	 */
	public static function after_sync( Resource $r, array $reply ): void {
		$auto = strtolower( trim( (string) ( $reply['autorenew'] ?? '' ) ) );

		if ( '' !== $auto && 'off' !== $auto ) {
			Alerts::quietly(
				static function () use ( $r, $auto ): void {
					Alerts::notify(
						'Domain has registrar autorenew on: ' . (string) $r->get( 'domain' ),
						array( sprintf( 'Domain: %s', (string) $r->get( 'domain' ) ), sprintf( 'Registrar autorenew: %s', $auto ), 'The registrar renews this domain from our balance without a customer payment. This plugin creates domains with autorenew off.' ),
						$r,
						'beltoft_services_domain_autorenew_' . $r->id(),
						array( 'ttl' => YEAR_IN_SECONDS )
					);
				}
			);
		}

		$field = trim( (string) ( $reply['restorable_until'] ?? '' ) );
		$until = '' !== $field ? (int) strtotime( $field . ' UTC' ) : 0;
		$days  = RestoreFacts::window_days( (string) $r->get( 'domain' ) );
		$start = strtotime( RestoreFacts::expiry_of( $reply ) . ' UTC' );

		if ( $until > 0 && null !== $days && $start && abs( $until - ( $start + $days * DAY_IN_SECONDS ) ) > 2 * DAY_IN_SECONDS ) {
			Alerts::quietly(
				static function () use ( $r, $reply, $until, $days, $start ): void {
					Alerts::notify(
						'Domain restore dates disagree: ' . (string) $r->get( 'domain' ),
						array(
							sprintf( 'Domain: %s', (string) $r->get( 'domain' ) ),
							sprintf( 'Registrar restorable_until: %s', (string) $reply['restorable_until'] ),
							sprintf( 'Renewal date plus %d days: %s', $days, gmdate( 'Y-m-d', $start + $days * DAY_IN_SECONDS ) ),
							'The notices count the window from the renewal date and the extension days, not from the registrar field. Check which is right.',
						),
						$r,
						'beltoft_services_domain_restore_dates_' . $r->id() . '_' . gmdate( 'Y-m-d', $until ),
						array( 'ttl' => YEAR_IN_SECONDS )
					);
				}
			);
		}
	}
}
