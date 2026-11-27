<?php
/** Settings → Services → Domains. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Jobs;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DomainSettings {
	public const TAB = 'domains';

	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 3 );
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'tabs' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'sections' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
		add_filter( 'beltoft_services_setting_bad_lines', array( __CLASS__, 'bad_lines' ), 10, 3 );
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_beltoft_services_save_domains_account', array( __CLASS__, 'save_account' ) );
		add_action( 'beltoft_services_domain_prices', array( __CLASS__, 'sync_prices' ) );
		Jobs::recurring( array( __CLASS__, 'schedule_prices' ) );
		ThankYou::register();
	}

	/**
	 * Adds the domain and DNS drivers to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( ! is_array( $drivers ) ) {
			return $drivers;
		}

		if ( ! isset( $drivers[ 'domain' ] ) ) {
			$drivers[ 'domain' ] = new DomainDriver();
		}
		if ( ! isset( $drivers[ 'dns' ] ) ) {
			$drivers[ 'dns' ] = new DnsDriver();
		}

		return $drivers;
	}

	public static function schedule_prices(): void {
		Jobs::ensure_cron( 'beltoft_services_domain_prices', '15 4 * * *', array(), HOUR_IN_SECONDS );
	}

	public static function tabs( $tabs ) {
		if ( is_array( $tabs ) ) { $tabs[ self::TAB ] = __( 'Domains', 'beltoft-services' ); }
		return $tabs;
	}

	public static function sections( $sections ) {
		if ( is_array( $sections ) ) { $sections[ self::TAB ] = __( 'Domain registration and DNS', 'beltoft-services' ); }
		return $sections;
	}

	/**
	 * The override lines that cannot be read, for the settings screen.
	 *
	 * @param mixed  $bad  Lines so far.
	 * @param string $key  Setting key.
	 * @param mixed  $text Text.
	 * @return mixed
	 */
	public static function bad_lines( $bad, $key = '', $text = '' ) {
		return 'domain_price_overrides' === $key && is_array( $bad ) ? array_merge( $bad, Prices::bad_override_lines( (string) $text ) ) : $bad;
	}

	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) { return $fields; }
		$base = array( 'tab' => self::TAB, 'section' => self::TAB );
		$fields['domain_api_mode'] = $base + array( 'type' => 'select', 'default' => 'fake', 'options' => array( 'fake' => __( 'Fake', 'beltoft-services' ), 'sandbox' => __( 'Sandbox', 'beltoft-services' ), 'live' => __( 'Live', 'beltoft-services' ) ), 'label' => __( 'Registrar API mode', 'beltoft-services' ) );
		$fields['domain_nameservers'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Name servers', 'beltoft-services' ), 'desc' => __( 'Comma separated host names. Leave empty to disable automatic zones.', 'beltoft-services' ) );
		$fields['domain_markup_percent'] = $base + array( 'type' => 'number', 'default' => 30, 'min' => 0, 'max' => 500, 'label' => __( 'Markup percent', 'beltoft-services' ) );
		$fields['domain_min_margin'] = $base + array( 'type' => 'number', 'default' => 4, 'min' => 0, 'max' => 1000, 'label' => __( 'Minimum margin in EUR', 'beltoft-services' ) );
		$fields['domain_price_overrides'] = $base + array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Price overrides', 'beltoft-services' ), 'desc' => __( 'One extension and its yearly price excluding tax per line, for example com=15 or dk=95.20 (a dot, at most two decimals). A line that cannot be read stops the save.', 'beltoft-services' ) );
		$fields['domain_restricted_allowlist'] = $base + array( 'type' => 'text', 'default' => 'dk', 'label' => __( 'Allowed restricted TLDs', 'beltoft-services' ) );
		$fields['domain_featured_tlds'] = $base + array( 'type' => 'text', 'default' => 'com,net,org,eu', 'label' => __( 'Featured TLDs', 'beltoft-services' ) );
		$fields['domain_quick_tlds'] = $base + array( 'type' => 'text', 'default' => 'com,dk', 'label' => __( 'TLDs shown first', 'beltoft-services' ), 'desc' => __( 'Comma separated, from the featured TLDs. The search page shows these and the TLD typed as soon as the registrar answers, then adds the other featured TLDs from a second lookup. Empty: one lookup for all.', 'beltoft-services' ) );
		$fields['domain_renew_days_before'] = $base + array( 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 60, 'label' => __( 'Charge renewals before expiry, in days', 'beltoft-services' ) );
		$fields['domain_renew_loss_cap'] = $base + array( 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 1000, 'label' => __( 'Renew below cost up to, in EUR per domain, whole euros', 'beltoft-services' ), 'desc' => __( 'A paid renewal whose price is below the registrar cost is still bought when the loss is at most this amount in the cost currency, so the domain does not expire after the customer paid. Staff get one email with the price, the cost and the loss. A larger loss waits for staff. 0 turns this off. Registrations and transfers are not covered.', 'beltoft-services' ) );
		$fields['domain_free_transfers'] = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Free transfers', 'beltoft-services' ), 'desc' => __( 'For an extension the registrar moves without a year (a transfer price of 0 in its last price answer), the customer pays nothing when the transfer is ordered. The first yearly payment falls on the domain\'s renewal date, and no year is bought at the registrar before that payment. A customer can move a domain in and cancel before then: the move costs nothing, so the cost is support time only. Off: the transfer is charged as one year, as before.', 'beltoft-services' ) );
		$fields['domain_relock_days'] = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 60, 'label' => __( 'Lock an unlocked domain again after, in days', 'beltoft-services' ), 'desc' => __( 'A domain left unlocked for transfer is locked again after this many days. 0 turns it off. Turn it on only once you have confirmed how the registrar reports an outgoing transfer, because a transfer under way must not be locked.', 'beltoft-services' ) );
		$fields['domain_low_balance'] = $base + array( 'type' => 'number', 'default' => 50, 'min' => 0, 'label' => __( 'Low registrar balance in EUR', 'beltoft-services' ) );
		$fields['domain_seller_name'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Seller name in the .dk agreement', 'beltoft-services' ), 'desc' => __( 'The company that is paid for the registration, as named in the .dk checkout agreement. Empty uses the site name.', 'beltoft-services' ) );
		$fields['domain_infrastructure_zones'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Own zones on the DNS server', 'beltoft-services' ), 'desc' => __( 'Comma separated zones that belong to the site itself, for example its name server domain. The orphan zone check skips them.', 'beltoft-services' ) );
		$fields['domain_dns_url'] = $base + array( 'type' => 'text', 'default' => 'http://127.0.0.1:8081', 'label' => __( 'DNS API URL', 'beltoft-services' ) );
		$fields['domain_dns_primary_query_address'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Primary DNS query address', 'beltoft-services' ), 'desc' => __( 'Local IP address used to check the first name server when its public address cannot be reached from this server.', 'beltoft-services' ) );
		$fields['domain_dns_claims_enabled'] = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'DNS hosting for domains registered elsewhere', 'beltoft-services' ), 'desc' => __( 'Customers can verify a domain with a TXT record and add DNS hosting for it. Needs at least two name servers below.', 'beltoft-services' ) );
		$fields['domain_search_url'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Domain search page', 'beltoft-services' ), 'desc' => __( 'The address of the page where customers search for and register a domain, for example /domains/. The account shows a Register a domain button when it is set. Leave empty to hide the button.', 'beltoft-services' ) );
		$fields['parking_ipv4'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Parking page IPv4 address', 'beltoft-services' ), 'desc' => __( 'A domain on our name servers that has no A, AAAA or CNAME record at the root and at www gets these addresses (TTL 300) so a visitor sees a parking page. They go away when the customer or a service adds a record of its own. Leave both parking addresses empty to turn parking off.', 'beltoft-services' ) );
		$fields['parking_ipv6'] = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Parking page IPv6 address', 'beltoft-services' ) );
		$fields['dns_delegation_recheck_days'] = $base + array( 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 90, 'label' => __( 'Days between two name server checks of a domain that points to us', 'beltoft-services' ), 'desc' => __( 'A DNS hosting service whose name servers point to us is checked again this often, so a domain moved to another provider is noticed. A domain that does not point to us is checked every day.', 'beltoft-services' ) );
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
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Not allowed.', 'beltoft-services' ), '', array( 'response' => 403 ) ); }
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
		// The list may not carry what an extension allows (DNSSEC, WHOIS privacy): the extensions in use are read one by one.
		if ( ! is_wp_error( Prices::sync() ) ) { TldFacts::refresh_missing(); }
	}
}
