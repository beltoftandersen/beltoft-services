<?php
/** Settings → Services → Domains. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DomainSettings {
	public const TAB = 'domains';

	public static function register(): void {
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'tabs' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'sections' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_beltoft_services_save_domains_account', array( __CLASS__, 'save_account' ) );
		add_action( 'beltoft_services_domain_prices', array( __CLASS__, 'sync_prices' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule_prices' ) );
	}

	public static function schedule_prices(): void {
		if ( function_exists( 'as_next_scheduled_action' ) && ! as_next_scheduled_action( 'beltoft_services_domain_prices', array(), 'beltoft-services' ) ) {
			as_schedule_cron_action( time() + HOUR_IN_SECONDS, '15 4 * * *', 'beltoft_services_domain_prices', array(), 'beltoft-services' );
		}
	}

	public static function tabs( $tabs ) {
		if ( is_array( $tabs ) ) { $tabs[ self::TAB ] = __( 'Domains', 'beltoft-services' ); }
		return $tabs;
	}

	public static function sections( $sections ) {
		if ( is_array( $sections ) ) { $sections[ self::TAB ] = __( 'Domain registration and DNS', 'beltoft-services' ); }
		return $sections;
	}

	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) { return $fields; }
		$base = array( 'tab' => self::TAB, 'section' => self::TAB );
		$fields['domain_api_mode'] = $base + array( 'type' => 'select', 'default' => 'fake', 'options' => array( 'fake' => __( 'Fake', 'beltoft-services' ), 'sandbox' => __( 'Sandbox', 'beltoft-services' ), 'live' => __( 'Live', 'beltoft-services' ) ), 'label' => __( 'Registrar API mode', 'beltoft-services' ) );
		$fields['domain_nameservers'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Name servers', 'beltoft-services' ), 'desc' => __( 'Comma separated host names. Leave empty to disable automatic zones.', 'beltoft-services' ) );
		$fields['domain_markup_percent'] = $base + array( 'type' => 'number', 'default' => 30, 'min' => 0, 'max' => 500, 'label' => __( 'Markup percent', 'beltoft-services' ) );
		$fields['domain_min_margin'] = $base + array( 'type' => 'number', 'default' => 4, 'min' => 0, 'max' => 1000, 'label' => __( 'Minimum margin in EUR', 'beltoft-services' ) );
		$fields['domain_price_overrides'] = $base + array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Price overrides', 'beltoft-services' ), 'desc' => __( 'One TLD and whole euro price per line, for example com=15.', 'beltoft-services' ) );
		$fields['domain_restricted_allowlist'] = $base + array( 'type' => 'text', 'default' => 'dk', 'label' => __( 'Allowed restricted TLDs', 'beltoft-services' ) );
		$fields['domain_featured_tlds'] = $base + array( 'type' => 'text', 'default' => 'com,net,org,eu', 'label' => __( 'Featured TLDs', 'beltoft-services' ) );
		$fields['domain_renew_days_before'] = $base + array( 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 60, 'label' => __( 'Charge renewals before expiry, in days', 'beltoft-services' ) );
		$fields['domain_relock_days'] = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 60, 'label' => __( 'Lock an unlocked domain again after, in days', 'beltoft-services' ), 'desc' => __( 'A domain left unlocked for transfer is locked again after this many days. 0 turns it off. Turn it on only once you have confirmed how the registrar reports an outgoing transfer, because a transfer under way must not be locked.', 'beltoft-services' ) );
		$fields['domain_low_balance'] = $base + array( 'type' => 'number', 'default' => 50, 'min' => 0, 'label' => __( 'Low registrar balance in EUR', 'beltoft-services' ) );
		$fields['domain_seller_name'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Seller name in the .dk agreement', 'beltoft-services' ), 'desc' => __( 'The company that is paid for the registration, as named in the .dk checkout agreement. Empty uses the site name.', 'beltoft-services' ) );
		$fields['domain_infrastructure_zones'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Own zones on the DNS server', 'beltoft-services' ), 'desc' => __( 'Comma separated zones that belong to the site itself, for example its name server domain. The orphan zone check skips them.', 'beltoft-services' ) );
		$fields['domain_dns_url'] = $base + array( 'type' => 'text', 'default' => 'http://127.0.0.1:8081', 'label' => __( 'DNS API URL', 'beltoft-services' ) );
		$fields['domain_dns_primary_query_address'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Primary DNS query address', 'beltoft-services' ), 'desc' => __( 'Local IP address used to check the first name server when its public address cannot be reached from this server.', 'beltoft-services' ) );
		$fields['domain_dns_claims_enabled'] = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'DNS hosting for domains registered elsewhere', 'beltoft-services' ), 'desc' => __( 'Customers can verify a domain with a TXT record and add DNS hosting for it. Needs at least two name servers below.', 'beltoft-services' ) );
		$fields['dns_claims_max_per_user'] = $base + array( 'type' => 'number', 'default' => 20, 'min' => 1, 'label' => __( 'DNS hosting domains per customer', 'beltoft-services' ) );
		return $fields;
	}

	public static function render(): void {
		if ( 'live' === Settings::get( 'domain_api_mode' ) && ! Clients::live_allowed() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Live mode is blocked until BELTOFT_SERVICES_DOMAINS_LIVE is enabled in wp-config.php.', 'beltoft-services' ) . '</p></div>';
		}
		echo '<form action="options.php" method="post">';
		settings_fields( Settings::PAGE );
		do_settings_sections( Settings::PAGE . '-' . self::TAB );
		submit_button();
		echo '</form>';
		$account = Account::get();
		echo '<h2>' . esc_html__( 'Registrar and DNS credentials', 'beltoft-services' ) . '</h2>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="beltoft_services_save_domains_account">';
		wp_nonce_field( 'beltoft_services_save_domains_account' );
		echo '<table class="form-table"><tbody>';
		foreach ( array( 'username' => __( 'Registrar username', 'beltoft-services' ), 'handle' => __( 'Reseller handle', 'beltoft-services' ), 'password' => __( 'Registrar password', 'beltoft-services' ), 'dns_key' => __( 'DNS API key', 'beltoft-services' ) ) as $key => $label ) {
			$secret = in_array( $key, array( 'password', 'dns_key' ), true );
			$value = $secret ? '' : $account[ $key ];
			$placeholder = $secret && '' !== $account[ $key ] ? __( 'Saved', 'beltoft-services' ) : '';
			printf( '<tr><th scope="row"><label for="domain-account-%1$s">%2$s</label></th><td><input class="regular-text" id="domain-account-%1$s" name="%1$s" type="%3$s" value="%4$s" placeholder="%5$s" autocomplete="%6$s"></td></tr>', esc_attr( $key ), esc_html( $label ), $secret ? 'password' : 'text', esc_attr( $value ), esc_attr( $placeholder ), $secret ? 'new-password' : 'off' );
		}
		echo '</tbody></table>';
		submit_button( __( 'Save credentials', 'beltoft-services' ) );
		echo '</form>';
	}

	public static function save_account(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Not allowed.', 'beltoft-services' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'beltoft_services_save_domains_account' );
		$input = array();
		foreach ( array( 'username', 'password', 'handle', 'dns_key' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Account::save sanitizes fields and must preserve exact secret bytes.
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) { $input[ $key ] = wp_unslash( $_POST[ $key ] ); }
		}
		try { Account::save( $input ); } catch ( \RuntimeException $e ) { wp_die( esc_html__( 'Configure the encryption key before saving credentials.', 'beltoft-services' ) ); }
		wp_safe_redirect( admin_url( 'options-general.php?page=' . Settings::PAGE . '&tab=' . self::TAB . '&updated=1' ) );
		exit;
	}

	public static function sync_prices(): void {
		Prices::sync();
	}
}
