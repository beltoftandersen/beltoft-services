<?php
/** Settings, Services, SSL certificates. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Jobs;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class SslSettings {
	public const TAB = 'ssl';

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 5 );
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'tabs' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'sections' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_beltoft_services_ssl_sync', array( __CLASS__, 'sync_now' ) );
		add_action( 'beltoft_services_ssl_costs', array( __CLASS__, 'sync_costs' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Adds the SSL driver to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( ! is_array( $drivers ) ) {
			return $drivers;
		}

		if ( ! isset( $drivers[ 'ssl' ] ) ) {
			$drivers[ 'ssl' ] = new SslDriver();
		}

		return $drivers;
	}

	/** The daily cost read. */
	public static function schedule(): void {
		Jobs::ensure_cron( 'beltoft_services_ssl_costs', '25 4 * * *', array(), HOUR_IN_SECONDS );
	}

	/**
	 * Is selling certificates switched on?
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'ssl_enabled' );
	}

	/**
	 * Days before the order's expiry that the renewal is charged: at most 30, because the registrar renews only within 30 days.
	 */
	public static function renew_days(): int {
		$days = Settings::get( 'ssl_renew_days_before' );

		return is_numeric( $days ) ? max( 1, min( 30, (int) $days ) ) : 28;
	}

	/**
	 * Days an order may wait for validation before it is cancelled.
	 */
	public static function wait_days(): int {
		$days = Settings::get( 'ssl_wait_days' );

		return is_numeric( $days ) ? max( 1, min( 30, (int) $days ) ) : 14;
	}

	/**
	 * Days after issue within which an order can still be cancelled for a refund.
	 */
	public static function refund_days(): int {
		$days = Settings::get( 'ssl_refund_days' );

		return is_numeric( $days ) ? max( 0, min( 90, (int) $days ) ) : 30;
	}

	/**
	 * Reissues one customer may ask for in a day.
	 */
	public static function reissues_per_day(): int {
		$count = Settings::get( 'ssl_reissues_per_day' );

		return is_numeric( $count ) ? max( 1, min( 20, (int) $count ) ) : 3;
	}

	/**
	 * Tab.
	 *
	 * @param mixed $tabs Tabs.
	 * @return mixed
	 */
	public static function tabs( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ] = __( 'SSL certificates', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * Section.
	 *
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function sections( $sections ) {
		if ( is_array( $sections ) ) {
			$sections[ self::TAB ] = __( 'SSL certificates', 'beltoft-services' );
		}

		return $sections;
	}

	/**
	 * Fields.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array( 'tab' => self::TAB, 'section' => self::TAB );

		$fields['ssl_enabled']          = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Sell SSL certificates', 'beltoft-services' ), 'desc' => __( 'Off: certificate orders are refused at checkout and nothing is ordered at the registrar. The registrar account and mode are set under Domains.', 'beltoft-services' ) );
		$fields['ssl_renew_days_before'] = $base + array( 'type' => 'number', 'default' => 28, 'min' => 1, 'max' => 30, 'label' => __( 'Charge renewals before expiry, in days', 'beltoft-services' ), 'desc' => __( 'The registrar renews an order only within 30 days of its expiry, so this is at most 30.', 'beltoft-services' ) );
		$fields['ssl_wait_days']        = $base + array( 'type' => 'number', 'default' => 14, 'min' => 1, 'max' => 30, 'label' => __( 'Cancel an order that is not validated after, in days', 'beltoft-services' ), 'desc' => __( 'The registrar order is cancelled and staff are told to refund the customer.', 'beltoft-services' ) );
		$fields['ssl_refund_days']      = $base + array( 'type' => 'number', 'default' => 30, 'min' => 0, 'max' => 90, 'label' => __( 'Refund window after issue, in days', 'beltoft-services' ), 'desc' => __( 'A customer can cancel inside this window. Match the registrar, which refunds within 30 days of issue.', 'beltoft-services' ) );
		$fields['ssl_reissues_per_day'] = $base + array( 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 20, 'label' => __( 'Reissues per customer and day', 'beltoft-services' ) );

		return $fields;
	}

	/** The tab. */
	public static function render(): void {
		echo '<form action="options.php" method="post">';
		settings_fields( Settings::PAGE );
		do_settings_sections( Settings::PAGE . '-' . self::TAB );
		submit_button();
		echo '</form>';
		echo '<h2>' . esc_html__( 'Registrar costs', 'beltoft-services' ) . '</h2>';
		echo '<p>' . esc_html__( 'Read from the registrar once a day. An order is placed only when the customer paid at least the cost below; prices are set on the product variations, not here.', 'beltoft-services' ) . '</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="beltoft_services_ssl_sync">';
		wp_nonce_field( 'beltoft_services_ssl_sync' );
		submit_button( __( 'Read costs now', 'beltoft-services' ), 'secondary', 'submit', false );
		echo '</form>';

		$rows = SslCatalog::all();

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No costs have been read yet.', 'beltoft-services' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px;margin-top:12px"><thead><tr>';

		foreach ( array( __( 'Id', 'beltoft-services' ), __( 'Product', 'beltoft-services' ), __( 'Validation', 'beltoft-services' ), __( 'Wildcard', 'beltoft-services' ), __( 'Issue time', 'beltoft-services' ), __( 'Cost per year (EUR)', 'beltoft-services' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				(int) $row['id'],
				esc_html( (string) $row['name'] ),
				esc_html( strtoupper( (string) $row['level'] ) ),
				$row['wildcard'] ? esc_html__( 'Yes', 'beltoft-services' ) : esc_html__( 'No', 'beltoft-services' ),
				esc_html( (string) $row['delivery'] ),
				esc_html( number_format_i18n( (float) $row['cost_1y'], 2 ) )
			);
		}

		echo '</tbody></table>';
	}

	/** Reads the costs on request. */
	public static function sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beltoft-services' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'beltoft_services_ssl_sync' );
		self::sync_costs();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . Settings::PAGE . '&tab=' . self::TAB ) );
		exit;
	}

	/** Reads the costs (the daily job). */
	public static function sync_costs(): void {
		SslCatalog::sync();
	}
}
