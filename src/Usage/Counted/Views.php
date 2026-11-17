<?php
/**
 * Settings and the customer's view of a counted service: the free intro and the list of billed orders.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Counted;

use BeltoftServices\Currency;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Meter as HourlyMeter;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks: the settings fields (section usage) and `beltoft_services_http_account_extra`, which the HTTP driver's account card runs
 * after its own rows. Nothing is shown for a service that is not counted.
 */
final class Views {

	public const LIST_ROWS = 50;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'settings_fields' ) );
		add_action( 'beltoft_services_http_account_extra', array( __CLASS__, 'render' ) );
	}

	/**
	 * The settings.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function settings_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array(
			'tab'     => 'general',
			'section' => 'usage',
			'type'    => 'number',
			'min'     => 0,
		);

		return array_merge(
			$fields,
			array(
				'counted_billing'     => array_merge( $base, array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Bill counted services (orders processed)', 'beltoft-services' ), 'desc' => __( 'Off: the events of counted services are pulled and kept, free ones are marked free, but nothing is written to the usage ledger.', 'beltoft-services' ) ) ),
				'counted_intro_days'  => array_merge( $base, array( 'default' => 7, 'label' => __( 'Free intro for a counted service, in days', 'beltoft-services' ), 'desc' => __( 'Counted from the first event the plugin receives. 0 turns the intro off. A variation can set its own with the meta _beltoft_services_intro_days.', 'beltoft-services' ) ) ),
				'counted_intro_alert' => array_merge( $base, array( 'default' => 1000, 'label' => __( 'Alert when a free intro passes this many events', 'beltoft-services' ) ) ),
				'counted_cap_min'     => array_merge( $base, array( 'default' => 500, 'min' => 1, 'label' => __( 'Daily cap of new events per service: at least', 'beltoft-services' ), 'desc' => __( 'Events over the cap are held, not billed, until staff release them. The cap is the larger of this and the factor below times the service\'s daily average of the last 14 days.', 'beltoft-services' ) ) ),
				'counted_cap_factor'  => array_merge( $base, array( 'default' => 5, 'min' => 1, 'label' => __( 'Daily cap factor', 'beltoft-services' ) ) ),
				'counted_hold_hours'  => array_merge( $base, array( 'default' => 24, 'label' => __( 'Hold events this many hours before billing them', 'beltoft-services' ) ) ),
			)
		);
	}

	/**
	 * The card rows of a counted service.
	 *
	 * @param mixed $r Resource.
	 */
	public static function render( $r ): void {
		if ( ! $r instanceof Resource || ! Rates::is_counted( (int) ( $r->license()['variation_id'] ?? 0 ) ) ) {
			return;
		}

		$now      = time();
		$intro    = Intro::status( $r, $now );
		$free     = Rule::free_rate( $r );
		$states   = Store::states( $r->id() );
		$currency = HourlyMeter::currency_of( $r->license(), (int) ( $r->license()['user_id'] ?? 0 ) );
		$variation = wc_get_product( (int) ( $r->license()['variation_id'] ?? 0 ) );
		$steps    = $variation ? Currency::with( $currency, static fn() => Rates::unit_steps( $variation, $currency ) )[1] : array();
		$first    = $steps ? (float) $steps[0]['rate'] : 0.0;

		echo '<div class="beltoft-services-counted">';
		echo '<dl class="beltoft-services-http__facts">';

		if ( $free ) {
			echo '<dt>' . esc_html__( 'Rate', 'beltoft-services' ) . '</dt><dd>' . esc_html__( 'Free of charge', 'beltoft-services' ) . '</dd>';
		} elseif ( 'running' === $intro['state'] ) {
			/* translators: 1: number of days left, 2: end date and time. */
			$text = sprintf( _n( 'Free intro: %1$d day left, ends %2$s', 'Free intro: %1$d days left, ends %2$s', $intro['days_left'], 'beltoft-services' ), $intro['days_left'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $intro['ends'] ) );
			echo '<dt>' . esc_html__( 'Intro', 'beltoft-services' ) . '</dt><dd>' . esc_html( $text ) . '</dd>';
			echo '<dt>' . esc_html__( 'Orders so far', 'beltoft-services' ) . '</dt><dd>' . esc_html( number_format_i18n( $states[ Store::FREE ] ?? 0 ) ) . '</dd>';

			if ( $first > 0 ) {
				/* translators: %s: amount excluding VAT. */
				echo '<dt>' . esc_html__( 'At the normal price', 'beltoft-services' ) . '</dt><dd>' . esc_html( sprintf( __( '%s excluding VAT', 'beltoft-services' ), Rates::money( ( $states[ Store::FREE ] ?? 0 ) * $first, 2, $currency ) ) ) . '</dd>';
			}
		} elseif ( 'ended' === $intro['state'] ) {
			/* translators: %s: date. */
			echo '<dt>' . esc_html__( 'Intro', 'beltoft-services' ) . '</dt><dd>' . esc_html( sprintf( __( 'Free intro ended %s', 'beltoft-services' ), wp_date( get_option( 'date_format' ), $intro['ends'] ) ) ) . '</dd>';
		} elseif ( 'waiting' === $intro['state'] && Intro::days( $r ) > 0 ) {
			echo '<dt>' . esc_html__( 'Intro', 'beltoft-services' ) . '</dt><dd>' . esc_html__( 'The free days start when the first order is synced.', 'beltoft-services' ) . '</dd>';
		}

		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00' ) . ' UTC' );
		$count = Store::billed_in_month( $r->id(), $month, $now + HOUR_IN_SECONDS ) + 0;
		echo '<dt>' . esc_html__( 'Billed orders this month', 'beltoft-services' ) . '</dt><dd>' . esc_html( number_format_i18n( $count ) ) . '</dd>';
		echo '</dl>';

		$rows = Store::recent( $r->id(), self::LIST_ROWS );

		if ( $rows ) {
			echo '<h4>' . esc_html__( 'Billed orders', 'beltoft-services' ) . '</h4>';
			echo '<table class="beltoft-services-counted__list"><thead><tr><th>' . esc_html__( 'Order', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Order date', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Odoo order', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Counted', 'beltoft-services' ) . '</th></tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$date = '' !== (string) $row['order_date'] ? wp_date( get_option( 'date_format' ), (int) strtotime( $row['order_date'] . ' UTC' ) ) : '';
				echo '<tr><td>' . esc_html( (string) $row['order_number'] ) . '</td><td>' . esc_html( $date ) . '</td><td>' . esc_html( (string) $row['odoo_order_name'] ) . '</td><td>' . esc_html( self::state_text( $row ) ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * What an event's state is called for the customer.
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	public static function state_text( array $row ): string {
		switch ( (string) $row['state'] ) {
			case Store::FREE:
				return 'rate' === (string) $row['free_reason'] ? __( 'Free of charge', 'beltoft-services' ) : __( 'Free (intro)', 'beltoft-services' );
			case Store::HELD:
				return __( 'Under review', 'beltoft-services' );
			case Store::CREDITED:
				return __( 'Credited', 'beltoft-services' );
		}

		return __( 'Billed', 'beltoft-services' );
	}
}
