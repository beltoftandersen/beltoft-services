<?php
/** The Overview sections of My Account. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Overview;
use BeltoftServices\Account\Servers;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Progress;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\MonitoringTestCase;

require_once dirname( __DIR__ ) . '/Proxmox/MonitoringTestCase.php';

class OverviewTest extends MonitoringTestCase {
	public function set_up(): void {
		parent::set_up();
		Overview::reset();
	}

	public function tear_down(): void {
		Overview::reset();
		remove_all_filters( 'beltoft_services_welcome_cards' );
		remove_all_filters( 'beltoft_licenses_account_licenses' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function as_user( int $user_id ): void {
		Overview::reset();
		wp_set_current_user( $user_id );
	}

	private function server( ?int $user_id = null, array $meta = array() ): Resource {
		static $n = 0;
		$r = $this->ready_vm( 5000 + ( $n++ % 10 ) );

		if ( null !== $user_id ) {
			global $wpdb;
			$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'user_id' => $user_id ), array( 'id' => (int) $r->license()['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		}

		$r->set( array_merge( array( 'server_name' => 'web-01', 'ip' => '192.0.2.10' ), $meta ) );

		return Resource::find( $r->id() );
	}

	private function dns( int $user_id, string $domain = 'shop-example.dk', string $driver = 'dns' ): Resource {
		$product  = $this->service_product( $driver, array(), array( 'slug' => $driver . '-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user_id ) );
		$resource = Resource::create( $license, Registry::get( $driver ), array(), array( 'domain' => $domain ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	private function plugin_license( int $user_id, int $max = 3 ): array {
		$product = $this->service_product( '', array(), array( 'slug' => 'plugin-' . wp_generate_password( 5, false ), 'max' => $max ) );
		$product->set_name( 'Gift Cards Pro' );
		$product->save();

		return $this->service_license( $product, array( 'fire' => false, 'user_id' => $user_id ) );
	}

	private function subscription( int $user_id, string $status, int $days, string $item, string $total = '10.00' ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user_id, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => $status ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$product = new \WC_Product_Simple();
		$product->set_name( $item );
		$product->set_regular_price( $total );
		$product->save();
		$sub->add_product( $product, 1 );
		$sub->set_requires_manual_renewal( true );
		$sub->calculate_totals();
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) ) );
		$sub->save();

		return $sub;
	}

	private function html( string $section ): string {
		return Overview::section( $section );
	}

	// -- Empty ---------------------------------------------------------------------------------

	public function test_a_customer_with_nothing_and_no_links_sees_no_section(): void {
		$this->as_user( self::factory()->user->create() );

		foreach ( array_keys( Overview::SECTIONS ) as $section ) {
			$this->assertSame( '', $this->html( $section ), $section );
		}

		$this->assertSame( '', Overview::all() );
	}

	public function test_a_visitor_sees_nothing(): void {
		wp_set_current_user( 0 );
		Overview::reset();
		$this->assertSame( '', Overview::all() );
	}

	public function test_a_customer_with_one_kind_sees_only_that_section(): void {
		$user = self::factory()->user->create();
		$this->plugin_license( $user );
		$this->as_user( $user );

		$this->assertStringContainsString( 'Gift Cards Pro', $this->html( 'licenses' ) );

		foreach ( array( 'attention', 'servers', 'domains', 'next_payment', 'welcome' ) as $section ) {
			$this->assertSame( '', $this->html( $section ), $section );
		}
	}

	// -- Welcome -------------------------------------------------------------------------------

	public function test_the_welcome_shows_three_starting_points_from_the_settings(): void {
		$this->settings( array( 'welcome_servers_url' => 'https://example.test/servers/', 'welcome_domains_url' => 'https://example.test/domains/', 'welcome_plugins_url' => 'https://example.test/plugins/' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, 'first_name', 'Ida <b>' );
		$this->as_user( $user );
		$html = $this->html( 'welcome' );

		$this->assertStringContainsString( 'Welcome, Ida &lt;b&gt;', $html );
		$this->assertStringNotContainsString( 'Ida <b>', $html );
		$this->assertSame( 3, substr_count( $html, 'beltoft-services-ov__card"' ) );
		$this->assertStringContainsString( 'href="https://example.test/servers/"', $html );
		$this->assertStringContainsString( 'href="https://example.test/domains/"', $html );
		$this->assertStringContainsString( 'href="https://example.test/plugins/"', $html );
		$this->assertStringContainsString( 'beltoft-services-ov__welcome', Overview::all() );
	}

	public function test_a_starting_point_without_a_link_is_left_out_and_nothing_is_hardcoded(): void {
		$this->settings( array( 'welcome_domains_url' => 'https://example.test/domains/' ) );
		$this->as_user( self::factory()->user->create() );
		$html = $this->html( 'welcome' );

		$this->assertSame( 1, substr_count( $html, 'beltoft-services-ov__card"' ) );
		$this->assertStringNotContainsString( 'beltoft', str_replace( array( 'beltoft-services', 'beltoft_services' ), '', strtolower( strip_tags( $html ) ) ) );
	}

	public function test_a_link_can_come_from_the_filter_and_a_bad_scheme_is_not_printed(): void {
		add_filter(
			'beltoft_services_welcome_cards',
			static function ( $cards ) {
				$cards[0]['url'] = 'javascript:alert(1)';
				return $cards;
			}
		);
		$this->as_user( self::factory()->user->create() );
		$this->assertStringNotContainsString( 'javascript:', $this->html( 'welcome' ) );
	}

	public function test_the_welcome_is_hidden_once_the_customer_has_a_service(): void {
		$this->settings( array( 'welcome_servers_url' => 'https://example.test/servers/' ) );
		$user = self::factory()->user->create();
		$this->plugin_license( $user );
		$this->as_user( $user );
		$this->assertSame( '', $this->html( 'welcome' ) );
	}

	// -- Servers -------------------------------------------------------------------------------

	public function test_the_servers_are_the_rows_of_the_servers_page_with_a_link_to_all(): void {
		$user = self::factory()->user->create();
		$r    = $this->server( $user );
		Operations::remember_power( $r->id(), 'running' );
		$this->as_user( $user );
		$html = $this->html( 'servers' );

		$this->assertStringContainsString( 'web-01', $html );
		$this->assertStringContainsString( '192.0.2.10', $html );
		$this->assertStringContainsString( 'beltoft-services-srv', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Servers::url_list() ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Servers::url( $r->license() ) ) . '"', $html );

		$view = \BeltoftLicenses\Account\View::license( $r->license() );
		$this->assertSame( Servers::rows( array( $view ), array( (int) $view['id'] => $r ) ), Overview::data()['servers'] );
	}

	public function test_only_the_customers_own_servers_show(): void {
		$mine  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->server( $mine, array( 'server_name' => 'mine-01' ) );
		$this->server( $other, array( 'server_name' => 'other-01' ) );
		$this->as_user( $mine );
		$html = Overview::all();

		$this->assertStringContainsString( 'mine-01', $html );
		$this->assertStringNotContainsString( 'other-01', $html );
	}

	public function test_a_server_name_is_escaped(): void {
		$user = self::factory()->user->create();
		$this->server( $user, array( 'server_name' => '<script>alert(1)</script>' ) );
		$this->as_user( $user );
		$html = Overview::all();

		$this->assertStringNotContainsString( '<script>alert', $html );
	}

	public function test_the_list_is_cut_at_the_limit(): void {
		$user = self::factory()->user->create();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->server( $user );
		}

		add_filter( 'beltoft_services_overview_limit', static fn() => 2 );
		$this->as_user( $user );
		$this->assertSame( 2, substr_count( $this->html( 'servers' ), 'class="beltoft-services-srv"' ) );
		remove_all_filters( 'beltoft_services_overview_limit' );
	}

	// -- Domains and licenses ------------------------------------------------------------------

	public function test_domains_and_dns_show_their_state_and_link_to_their_card(): void {
		$user = self::factory()->user->create();
		$dns  = $this->dns( $user, 'shop-example.dk' );
		$this->as_user( $user );
		$html = $this->html( 'domains' );

		$this->assertStringContainsString( 'Domains &amp; DNS', $html );
		$this->assertStringContainsString( 'shop-example.dk', $html );
		$this->assertStringContainsString( 'DNS with us', $html );
		$this->assertStringContainsString( 'is-ok', $html );
		$this->assertStringContainsString( '#beltoft-license-' . (int) $dns->license()['id'], $html );
		$this->assertSame( '', $this->html( 'servers' ) );
	}

	public function test_a_domain_shows_its_registration_and_expiry(): void {
		$user   = self::factory()->user->create();
		$domain = $this->dns( $user, 'registered.example', 'domain' );
		$domain->set( array( 'expires_at' => '2027-11-02 00:00:00' ) );
		$this->as_user( $user );
		$html = $this->html( 'domains' );

		$this->assertStringContainsString( 'Registered and DNS with us', $html );
		$this->assertStringContainsString( 'Expires ' . wp_date( get_option( 'date_format' ), strtotime( '2027-11-02 00:00:00 UTC' ) ), $html );
	}

	public function test_only_the_customers_own_domains_show(): void {
		$mine  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->dns( $mine, 'mine.example' );
		$this->dns( $other, 'other.example' );
		$this->as_user( $mine );
		$html = $this->html( 'domains' );

		$this->assertStringContainsString( 'mine.example', $html );
		$this->assertStringNotContainsString( 'other.example', $html );
	}

	public function test_a_paused_dns_hosting_is_not_shown_as_running(): void {
		$user = self::factory()->user->create();
		$dns  = $this->dns( $user );
		$dns->set( array( 'state' => 'paused' ) );
		$this->as_user( $user );
		$html = $this->html( 'domains' );

		$this->assertStringContainsString( 'is-bad', $html );
		$this->assertStringContainsString( 'Paused', $html );
	}

	public function test_licenses_show_product_sites_status_and_only_the_customers_own(): void {
		$mine  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$license = $this->plugin_license( $mine, 3 );
		\BeltoftLicenses\Licenses::activate( $license, 'one.example' );
		$other_license = $this->plugin_license( $other );
		$this->as_user( $mine );
		$html = $this->html( 'licenses' );

		$this->assertStringContainsString( 'Gift Cards Pro', $html );
		$this->assertStringContainsString( '1 of 3 sites', $html );
		$this->assertStringContainsString( 'Active', $html );
		$this->assertStringContainsString( 'is-ok', $html );
		$this->assertStringNotContainsString( $other_license['license_key'], $html );
		$this->assertSame( 1, substr_count( $html, 'beltoft-services-ov__row"' ) );
	}

	public function test_an_expired_license_is_shown_as_not_ok(): void {
		$user    = self::factory()->user->create();
		$license = $this->plugin_license( $user );
		\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->as_user( $user );
		$html = $this->html( 'licenses' );

		$this->assertStringContainsString( 'Expired', $html );
		$this->assertStringContainsString( 'is-bad', $html );
	}

	public function test_licenses_are_loaded_once_for_all_sections(): void {
		$user  = self::factory()->user->create();
		$count = 0;
		$this->server( $user );
		$this->plugin_license( $user );
		add_filter(
			'beltoft_licenses_account_licenses',
			static function ( $licenses ) use ( &$count ) {
				++$count;
				return $licenses;
			}
		);
		$this->as_user( $user );
		Overview::all();
		Overview::all();

		$this->assertSame( 1, $count );
	}

	public function test_building_the_overview_calls_no_remote_service(): void {
		$user = self::factory()->user->create();
		$this->server( $user );
		$this->dns( $user );
		$this->plugin_license( $user );
		$before = count( $this->fake->calls() );
		$this->as_user( $user );
		Overview::all();

		$this->assertCount( $before, $this->fake->calls() );
		$this->assertSame( array(), $this->http_calls() );
	}

	// -- Needs you -----------------------------------------------------------------------------

	public function test_a_running_action_is_in_the_strip_and_links_to_the_server(): void {
		$user = self::factory()->user->create();
		$r    = $this->server( $user );
		Progress::mark( $r, 'reboot', 'Restart' );
		$this->as_user( $user );
		$html = $this->html( 'attention' );

		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'web-01', $html );
		$this->assertStringContainsString( 'Restart', $html );
		$this->assertStringContainsString( 'Follow it', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Servers::url( $r->license() ) ) . '"', $html );
		$this->assertStringContainsString( 'Working', $this->html( 'servers' ) );
	}

	public function test_the_strip_is_hidden_when_nothing_runs_and_nothing_failed(): void {
		$user = self::factory()->user->create();
		$this->server( $user );
		$this->as_user( $user );
		$this->assertSame( '', $this->html( 'attention' ) );
	}

	public function test_a_running_action_of_another_customer_is_not_shown(): void {
		$mine  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->server( $mine );
		Progress::mark( $this->server( $other, array( 'server_name' => 'other-01' ) ), 'reboot', 'Restart' );
		$this->as_user( $mine );
		$this->assertSame( '', $this->html( 'attention' ) );
	}

	public function test_an_on_hold_subscription_is_a_failed_payment_with_a_link_to_the_card(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold', 5, 'DNS hosting <i>x</i>' );
		$this->as_user( $user );
		$html = $this->html( 'attention' );

		$this->assertStringContainsString( 'Payment', $html );
		$this->assertStringContainsString( 'DNS hosting &lt;i&gt;x&lt;/i&gt;', $html );
		$this->assertStringContainsString( 'could not be renewed', $html );
		$this->assertStringContainsString( 'Update card', $html );
		$this->assertStringContainsString( 'payment-methods', $html );
		$this->assertStringContainsString( 'is-bad', $html );
		$this->assertNotNull( $sub );
	}

	public function test_an_active_or_another_customers_subscription_is_not_a_failed_payment(): void {
		$mine  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->subscription( $mine, 'active', 5, 'Fine' );
		$this->subscription( $other, 'on-hold', 5, 'Not mine' );
		$this->as_user( $mine );
		$this->assertSame( '', $this->html( 'attention' ) );
	}

	public function test_the_failed_payment_names_the_service_it_pays_for(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold', 5, 'Some product' );
		$r    = $this->server( $user );
		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ), array( 'id' => (int) $r->license()['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$this->as_user( $user );
		$html = $this->html( 'attention' );

		$this->assertStringContainsString( 'web-01', $html );
		$this->assertStringNotContainsString( 'Some product', $html );
	}

	// -- Next payment --------------------------------------------------------------------------

	public function test_the_next_payment_sums_the_subscriptions_due_on_the_earliest_day(): void {
		$user = self::factory()->user->create();
		$this->subscription( $user, 'active', 10, 'Server', '15.00' );
		$this->subscription( $user, 'active', 10, 'Snapshots', '3.75' );
		$this->subscription( $user, 'active', 40, 'Later', '99.00' );
		$this->as_user( $user );
		$html = $this->html( 'next_payment' );

		$this->assertStringContainsString( '18.75', $html );
		$this->assertStringNotContainsString( '99.00', $html );
		$this->assertStringContainsString( 'Server, Snapshots', $html );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), time() + 10 * DAY_IN_SECONDS ), $html );
		$this->assertStringContainsString( 'Go to Billing', $html );
	}

	public function test_the_next_payment_names_the_default_card(): void {
		$user  = self::factory()->user->create();
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 8, false ) );
		$token->set_gateway_id( 'cod' );
		$token->set_user_id( $user );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( gmdate( 'Y', strtotime( '+2 years' ) ) );
		$token->save();
		\WC_Payment_Tokens::set_users_default( $user, $token->get_id() );
		$sub = $this->subscription( $user, 'active', 10, 'Server' );
		$this->auto_renewal( $sub, 'cod' );
		$this->as_user( $user );

		$html = $this->html( 'next_payment' );
		$this->assertStringContainsString( 'Visa ending 4242', $html );
		$this->assertStringContainsString( '>Card</dt>', $html );
	}

	private function auto_renewal( \WC_Subscription $sub, string $gateway ): void {
		$sub->set_requires_manual_renewal( false );
		$sub->set_payment_method( $gateway );
		$sub->set_payment_method_title( 'Card payment' );
		$sub->save();
		add_filter( 'woocommerce_subscription_is_manual', '__return_false' );
	}

	private function card_token( int $user, string $gateway, string $last4, string $type = 'visa' ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 8, false ) );
		$token->set_gateway_id( $gateway );
		$token->set_user_id( $user );
		$token->set_card_type( $type );
		$token->set_last4( $last4 );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( gmdate( 'Y', strtotime( '+2 years' ) ) );
		$token->save();

		return $token;
	}

	public function test_a_gateways_own_token_class_counts_as_a_card(): void {
		$user = self::factory()->user->create();
		add_filter( 'woocommerce_payment_token_class', static fn( $class, $type ) => 'GatewayCard' === $type ? OverviewGatewayToken::class : $class, 10, 2 );
		$token = new OverviewGatewayToken();
		$token->set_token( 'tok_' . wp_generate_password( 8, false ) );
		$token->set_gateway_id( 'cod' );
		$token->set_user_id( $user );
		$token->save();
		$sub = $this->subscription( $user, 'active', 10, 'Server' );
		$this->auto_renewal( $sub, 'cod' );
		$sub->add_payment_token( $token );
		$this->as_user( $user );

		$this->assertStringContainsString( 'Visa ending 4242', $this->html( 'next_payment' ) );
	}

	public function test_a_manual_renewal_never_shows_a_card(): void {
		$user  = self::factory()->user->create();
		$token = $this->card_token( $user, 'cod', '4242' );
		\WC_Payment_Tokens::set_users_default( $user, $token->get_id() );
		$sub = $this->subscription( $user, 'active', 10, 'Server' );
		$sub->set_payment_method( 'cod' );
		$sub->set_payment_method_title( 'Bank transfer' );
		$sub->save();
		$this->as_user( $user );
		$html = $this->html( 'next_payment' );

		$this->assertStringNotContainsString( '4242', $html );
		$this->assertStringContainsString( 'Bank transfer', $html );
		$this->assertStringContainsString( '>Payment method</dt>', $html );
		$this->assertStringNotContainsString( '>Card</dt>', $html );
	}

	public function test_a_manual_renewal_without_a_method_is_paid_by_invoice(): void {
		$user = self::factory()->user->create();
		$this->subscription( $user, 'active', 10, 'Server' );
		$this->as_user( $user );

		$html = $this->html( 'next_payment' );
		$this->assertStringContainsString( 'Paid by invoice', $html );
		$this->assertStringContainsString( '>Payment method</dt>', $html );
	}

	public function test_the_subscriptions_own_token_wins_over_the_default_card(): void {
		$user    = self::factory()->user->create();
		$default = $this->card_token( $user, 'cod', '1111', 'mastercard' );
		$own     = $this->card_token( $user, 'cod', '4242' );
		\WC_Payment_Tokens::set_users_default( $user, $default->get_id() );
		$sub = $this->subscription( $user, 'active', 10, 'Server' );
		$this->auto_renewal( $sub, 'cod' );
		$sub->add_payment_token( $own );
		$this->as_user( $user );
		$html = $this->html( 'next_payment' );

		$this->assertStringContainsString( 'Visa ending 4242', $html );
		$this->assertStringNotContainsString( '1111', $html );
	}

	public function test_a_default_card_of_another_gateway_is_not_shown_for_this_subscription(): void {
		$user  = self::factory()->user->create();
		$token = $this->card_token( $user, 'stripe_cc', '4242' );
		\WC_Payment_Tokens::set_users_default( $user, $token->get_id() );
		$sub = $this->subscription( $user, 'active', 10, 'Server' );
		$this->auto_renewal( $sub, 'paypal' );
		$sub->set_payment_method_title( 'PayPal' );
		$sub->save();
		$this->as_user( $user );
		$html = $this->html( 'next_payment' );

		$this->assertStringNotContainsString( '4242', $html );
		$this->assertStringContainsString( 'PayPal', $html );
		$this->assertStringContainsString( '>Payment method</dt>', $html );
	}

	public function test_the_dates_are_converted_once_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'active', 10, 'Server' );
		// 22:30 on 15 July 2027 in Copenhagen (UTC+2) is 20:30 UTC.
		$sub->update_dates( array( 'next_payment' => '2027-07-15 20:30:00' ) );
		$sub->save();
		$this->as_user( $user );
		$data = Overview::data();

		$this->assertSame( wp_date( (string) get_option( 'date_format' ), strtotime( '2027-07-15 20:30:00 UTC' ) ), $data['next_payment']['date'] );
		$this->assertStringContainsString( '15', $data['next_payment']['date'] );
		$this->assertSame( '2027-07-15T20:30:00+00:00', $data['next_payment']['time'] );
	}

	public function test_the_failed_renewal_date_is_converted_once_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold', 5, 'Server' );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		// 22:30 local time (UTC+2) is 20:30 UTC.
		$order->set_date_created( '2026-07-15 22:30:00' );
		$order->save();
		$order->update_meta_data( '_subscription_renewal', $sub->get_id() );
		$order->save();
		$this->as_user( $user );
		$html = $this->html( 'attention' );

		$this->assertStringContainsString( 'July 15, 2026', $html );
		$this->assertStringNotContainsString( 'July 16, 2026', $html );
	}

	public function test_the_next_payment_is_hidden_without_an_active_subscription_and_ignores_other_customers(): void {
		$mine  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->subscription( $mine, 'on-hold', 10, 'Held' );
		$this->subscription( $other, 'active', 10, 'Theirs' );
		$this->as_user( $mine );

		$this->assertSame( '', $this->html( 'next_payment' ) );
	}

	// -- Activity ------------------------------------------------------------------------------

	public function test_events_are_mapped_to_plain_customer_text(): void {
		$this->assertSame( 'web-01: snapshot "deploy" taken', Overview::describe( 'Customer took the snapshot "deploy"', 'web-01', false ) );
		$this->assertSame( 'web-01: snapshot "deploy" deleted', Overview::describe( 'Snapshot "deploy" deleted', 'web-01', false ) );
		$this->assertSame( 'web-01: went back to snapshot "deploy"', Overview::describe( 'Server rolled back to the snapshot "deploy" of 2026-10-01 10:00 UTC', 'web-01', false ) );
		$this->assertSame( 'web-01: restored from a backup', Overview::describe( 'Server restored from the backup of 2026-10-01 10:00 UTC', 'web-01', false ) );
		$this->assertSame( 'web-01: restored from a backup', Overview::describe( 'Server restored from a backup', 'web-01', false ) );
		$this->assertSame( 'web-01: ready', Overview::describe( 'Service provisioned', 'web-01', false ) );
		$this->assertSame( 'web-01: paused', Overview::describe( 'Service paused', 'web-01', true ) );
		$this->assertSame( 'web-01: payment failed, paused', Overview::describe( 'Service paused', 'web-01', true, true ) );
	}

	public function test_admin_only_events_are_left_out(): void {
		foreach ( array(
			'Service paused',
			'Abuse suspected: cpu 98% for 2 hours',
			'Customer started the server',
			'Customer action "reboot" failed: The cluster is not available.',
			'Breach mark cleared by admin',
			'Monitoring email: server down',
			'Linked to existing VM 201 on lab by WP-CLI (address 192.0.2.5).',
			'Deletion warning: the service will be deleted on 2026-11-01 UTC.',
			'Plan changed on a linked VM; it was not applied.',
			'Retry requested by admin',
		) as $message ) {
			$this->assertSame( '', Overview::describe( $message, 'web-01', false ), $message );
		}

		$this->assertSame( '', Overview::describe( 'Service provisioned', '', false ) );
	}

	public function test_the_feed_has_five_items_newest_first_from_the_customers_own_services(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$mine = $this->server( $user );
		$id   = (int) $mine->license()['id'];
		$other = $this->server( self::factory()->user->create(), array( 'server_name' => 'other-01' ) );

		$events = \BeltoftLicenses\Install::tables()['events'];
		$add    = function ( int $license, string $message, int $ago ) use ( $wpdb, $events ): void {
			$wpdb->insert( $events, array( 'license_id' => $license, 'type' => 'service', 'message' => $message, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $ago ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		};

		$add( $id, 'Customer took the snapshot "one"', 700000 );
		$add( $id, 'Customer took the snapshot "two"', 600000 );
		$add( $id, 'Abuse suspected: secret admin text', 550000 );
		$add( $id, 'Customer took the snapshot "three"', 500000 );
		$add( $id, 'Snapshot "three" deleted', 400000 );
		$add( $id, 'Server restored from a backup', 300000 );
		$add( $id, 'Customer took the snapshot "<b>new</b>"', 7200 );
		$add( (int) $other->license()['id'], 'Customer took the snapshot "theirs"', 100 );

		$this->as_user( $user );
		$items = Overview::data()['activity'];
		$html  = $this->html( 'activity' );

		$this->assertCount( 5, $items );
		$this->assertStringContainsString( 'snapshot &quot;&lt;b&gt;new&lt;/b&gt;&quot; taken', $html );
		$this->assertLessThan( strpos( $html, 'restored from a backup' ), strpos( $html, 'new' ) );
		$this->assertLessThan( strpos( $html, 'deleted' ), strpos( $html, 'restored from a backup' ) );
		$this->assertStringNotContainsString( 'theirs', $html );
		$this->assertStringNotContainsString( 'secret admin text', $html );
		$this->assertStringNotContainsString( '"one"', $html );
		$this->assertStringNotContainsString( 'other-01', $html );
	}

	public function test_matching_events_are_found_behind_many_other_events(): void {
		global $wpdb;
		$user   = self::factory()->user->create();
		$mine   = $this->server( $user );
		$id     = (int) $mine->license()['id'];
		$events = \BeltoftLicenses\Install::tables()['events'];

		$wpdb->insert( $events, array( 'license_id' => $id, 'type' => 'service', 'message' => 'Customer took the snapshot "old"', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 900000 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		for ( $i = 0; $i < 150; $i++ ) {
			$wpdb->insert( $events, array( 'license_id' => $id, 'type' => 'service', 'message' => 'Monitoring email: server down ' . $i, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 800000 + $i ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		}

		$this->as_user( $user );

		$this->assertStringContainsString( 'snapshot &quot;old&quot; taken', $this->html( 'activity' ) );
	}

	public function test_a_percent_or_underscore_in_a_message_is_not_a_wildcard(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$id   = (int) $this->server( $user )->license()['id'];
		$wpdb->insert( \BeltoftLicenses\Install::tables()['events'], array( 'license_id' => $id, 'type' => 'service', 'message' => 'Snapshot_"x" deleted', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$this->as_user( $user );

		$this->assertSame( array(), Overview::data()['activity'] );
	}

	public function test_a_finished_backup_is_in_the_feed(): void {
		$user = self::factory()->user->create();
		$this->server( $user, array( 'backup_ok_at' => time() - 3600 ) );
		$this->as_user( $user );

		$this->assertStringContainsString( 'web-01: backup finished', $this->html( 'activity' ) );
	}

	public function test_a_pause_is_shown_only_when_the_subscription_is_on_hold(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold', 5, 'Server' );
		$r    = $this->server( $user );
		$id   = (int) $r->license()['id'];
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->insert( \BeltoftLicenses\Install::tables()['events'], array( 'license_id' => $id, 'type' => 'service', 'message' => 'Service paused', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$this->as_user( $user );

		$this->assertStringContainsString( 'web-01: paused', $this->html( 'activity' ) );
	}

	public function test_a_pause_is_payment_failed_only_when_the_newest_renewal_order_failed(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold', 5, 'Server' );
		$r    = $this->server( $user );
		$id   = (int) $r->license()['id'];
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->insert( \BeltoftLicenses\Install::tables()['events'], array( 'license_id' => $id, 'type' => 'service', 'message' => 'Service paused', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		// No renewal order at all: just paused.
		$this->as_user( $user );
		$this->assertStringContainsString( 'web-01: paused', $this->html( 'activity' ) );
		$this->assertStringNotContainsString( 'payment failed', $this->html( 'activity' ) );

		// The newest renewal order failed: payment failed.
		$bad = wc_create_order( array( 'customer_id' => $user ) );
		$bad->set_status( 'failed' );
		$bad->set_date_created( time() - 7200 );
		$bad->update_meta_data( '_subscription_renewal', $sub->get_id() );
		$bad->save();
		$this->as_user( $user );
		$this->assertStringContainsString( 'web-01: payment failed, paused', $this->html( 'activity' ) );

		// A newer renewal order that is not failed (paid by hand, or pending): the failure is behind us, just paused.
		$later = wc_create_order( array( 'customer_id' => $user ) );
		$later->set_status( 'pending' );
		$later->set_date_created( time() - 600 );
		$later->update_meta_data( '_subscription_renewal', $sub->get_id() );
		$later->save();
		$this->as_user( $user );
		$html = $this->html( 'activity' );
		$this->assertStringContainsString( 'web-01: paused', $html );
		$this->assertStringNotContainsString( 'payment failed', $html );
	}

	public function test_the_failed_renewal_is_looked_up_once_per_subscription(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold', 5, 'Server' );
		$id   = (int) $this->server( $user )->license()['id'];
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$pause = function ( int $ago ) use ( $wpdb, $id ): void {
			$wpdb->insert( \BeltoftLicenses\Install::tables()['events'], array( 'license_id' => $id, 'type' => 'service', 'message' => 'Service paused', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $ago ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		};
		$count = function () use ( $user ): int {
			$this->as_user( $user );
			$calls = 0;
			add_filter(
				'woocommerce_subscription_last_order',
				static function ( $order ) use ( &$calls ) {
					++$calls;
					return $order;
				}
			);
			Overview::data();
			remove_all_filters( 'woocommerce_subscription_last_order' );

			return $calls;
		};

		$pause( 3600 );
		$one = $count();

		$pause( 3700 );
		$pause( 3800 );
		$pause( 3900 );

		$this->assertSame( $one, $count(), 'More pause rows of one subscription do not add lookups.' );
	}

	public function test_rows_that_only_start_like_a_customer_message_do_not_use_up_the_window(): void {
		global $wpdb;
		$user   = self::factory()->user->create();
		$id     = (int) $this->server( $user )->license()['id'];
		$events = \BeltoftLicenses\Install::tables()['events'];

		$wpdb->insert( $events, array( 'license_id' => $id, 'type' => 'service', 'message' => 'Customer took the snapshot "old"', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 900000 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		for ( $i = 0; $i < 30; $i++ ) {
			$wpdb->insert( $events, array( 'license_id' => $id, 'type' => 'service', 'message' => 'Snapshot "auto-' . $i . '" quota check', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 800000 + $i ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			$wpdb->insert( $events, array( 'license_id' => $id, 'type' => 'service', 'message' => 'Service paused by the abuse check ' . $i, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 700000 + $i ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		}

		$this->as_user( $user );

		$this->assertStringContainsString( 'snapshot &quot;old&quot; taken', $this->html( 'activity' ) );
	}

	public function test_the_feed_is_hidden_when_there_is_nothing_to_tell(): void {
		$user = self::factory()->user->create();
		$this->plugin_license( $user );
		$this->as_user( $user );
		$this->assertSame( '', $this->html( 'activity' ) );
	}

	// -- Wiring --------------------------------------------------------------------------------

	public function test_the_shortcodes_exist_and_print_their_section(): void {
		$user = self::factory()->user->create();
		$this->plugin_license( $user );
		$this->as_user( $user );

		foreach ( Overview::SECTIONS as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), $tag );
		}

		$this->assertTrue( shortcode_exists( 'beltoft_services_overview' ) );
		$this->assertStringContainsString( 'Gift Cards Pro', do_shortcode( '[beltoft_services_licenses]' ) );
		$this->assertStringContainsString( 'beltoft-services-overview', do_shortcode( '[beltoft_services_overview]' ) );
	}

	public function test_the_dashboard_prints_the_overview_unless_a_site_places_it_itself(): void {
		$user = self::factory()->user->create();
		$this->plugin_license( $user );
		$this->as_user( $user );

		ob_start();
		do_action( 'woocommerce_account_dashboard' );
		$this->assertStringContainsString( 'Gift Cards Pro', (string) ob_get_clean() );

		Overview::reset();
		add_filter( 'beltoft_services_overview_auto', '__return_false' );
		ob_start();
		do_action( 'woocommerce_account_dashboard' );
		$this->assertSame( '', (string) ob_get_clean() );
		remove_all_filters( 'beltoft_services_overview_auto' );
	}

	public function test_the_overview_is_printed_once_when_both_dashboard_hooks_fire(): void {
		$user = self::factory()->user->create();
		$this->plugin_license( $user );
		$this->as_user( $user );

		ob_start();
		do_action( 'woocommerce_account_dashboard' );
		$first = (string) ob_get_clean();
		ob_start();
		do_action( 'woocommerce_account_dashboard' );
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'beltoft-services-overview', $first );
		$this->assertSame( '', $second );
	}

	public function test_the_stylesheet_is_loaded_with_a_section(): void {
		wp_dequeue_style( 'beltoft-services-account' );
		$user = self::factory()->user->create();
		$this->plugin_license( $user );
		$this->as_user( $user );
		$this->html( 'licenses' );

		$this->assertTrue( wp_style_is( \BeltoftServices\Account\Panel::HANDLE, 'enqueued' ) );
	}
}

/** A card token that, like the Stripe plugin's, does not extend WooCommerce's card token. */
class OverviewGatewayToken extends \WC_Payment_Token {
	protected $type = 'GatewayCard';

	public function get_last4() {
		return '4242';
	}

	public function get_card_type() {
		return 'Visa';
	}
}
