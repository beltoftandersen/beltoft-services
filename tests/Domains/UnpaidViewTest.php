<?php
/** What the domain page and its admin panel say about renewal, expiry and restoring. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Notices;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Stage;
use BeltoftServices\Domains\Unpaid;
use BeltoftServices\Domains\UnpaidView;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class UnpaidViewTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_wp_mail', static fn() => true );
		$this->settings( array( 'domain_unpaid_notices' => 1 ) );
		update_option(
			Prices::OPTION,
			array(
				'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 43.08, 'soft_restore' => 0.0, 'soft_quarantine_days' => 40, 'quarantine_days' => 0 ),
				'dk'  => array( 'create' => 8.0, 'renew' => 8.15, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 17.56, 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
				'no'  => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
			)
		);
	}

	private function domain( string $name, string $expires, bool $lapsed = true ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'view-' . wp_generate_password( 6, false ) ) );
		$user    = self::factory()->user->create();
		$sub     = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'remote_id' => '55', 'expires_at' => $expires, 'state' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$sub->update_status( 'active' );

		if ( $lapsed ) {
			$sub->update_status( 'on-hold' );
			Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		}

		return Resource::find( $r->id() );
	}

	private function page( Resource $r ): string {
		ob_start();
		UnpaidView::overview( $r, array( 'dns_only' => false ) );

		return (string) ob_get_clean();
	}

	public function test_the_stage_of_a_domain(): void {
		$expiry = '2027-10-26 00:00:00';
		$r      = $this->domain( 'stage-example.com', $expiry );
		$time   = strtotime( $expiry . ' UTC' );
		$this->assertSame( Stage::DUE, Stage::of( $r, $time - DAY_IN_SECONDS )['stage'] );
		$this->assertTrue( Stage::of( $r, $time - DAY_IN_SECONDS )['unpaid'] );
		$this->assertSame( Stage::EXPIRED, Stage::of( $r, $time + DAY_IN_SECONDS )['stage'] );
		$this->assertSame( Stage::EXPIRED, Stage::of( $r, $time + 39 * DAY_IN_SECONDS )['stage'] );
		$this->assertSame( Stage::ENDED, Stage::of( $r, $time + 40 * DAY_IN_SECONDS )['stage'] );
		$this->assertSame( $time + 40 * DAY_IN_SECONDS, Stage::of( $r, $time )['window_end'] );
		$paid = $this->domain( 'paid-example.com', $expiry, false );
		Licenses::update( (int) $paid->license()['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->assertSame( Stage::ACTIVE, Stage::of( Resource::find( $paid->id() ), $time + 50 * DAY_IN_SECONDS )['stage'] );
	}

	public function test_a_paid_domain_shows_the_standing_terms_with_the_window_and_the_fee_rule(): void {
		$r = $this->domain( 'terms-example.dk', gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ), false );
		Licenses::update( (int) $r->license()['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'If the renewal is not paid, the domain stops working on its expiry date.', $html );
		$this->assertStringContainsString( 'You can restore it for 30 days after that', $html );
		$this->assertStringContainsString( 'reactivation fee of', $html );
		$this->assertStringContainsString( '22.83', $html, '17.56 x 1.30 from the stored restore price.' );
		$this->assertStringNotContainsString( 'Pay the renewal', $html );
	}

	public function test_a_gtld_pays_the_fee_in_its_soft_quarantine_too(): void {
		$r = $this->domain( 'free-example.com', gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ), false );
		Licenses::update( (int) $r->license()['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'You can restore it for 40 days after that', $html );
		$this->assertStringContainsString( 'reactivation fee of', $html );
		$this->assertStringContainsString( '56.00', $html, '43.08 x 1.30: the full restore cost, never the free soft restore.' );
		$this->assertStringNotContainsString( 'no reactivation fee', $html );
	}

	public function test_an_extension_without_a_window_says_only_that_it_stops_working(): void {
		$r = $this->domain( 'nowin-example.no', gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ), false );
		Licenses::update( (int) $r->license()['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'If the renewal is not paid, the domain stops working on its expiry date.', $html );
		$this->assertStringNotContainsString( 'restore it for', $html );
	}

	public function test_an_unpaid_domain_before_the_expiry_shows_the_due_state_and_a_pay_button_not_paused(): void {
		$r    = $this->domain( 'due-example.com', gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'The renewal is due. Pay it by', $html );
		$this->assertStringContainsString( 'The domain works until then.', $html );
		$this->assertStringContainsString( 'Pay the renewal', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'paused', $html );
	}

	public function test_an_expired_domain_shows_the_state_the_deadline_the_fee_and_how_to_restore(): void {
		$r    = $this->domain( 'gone-example.dk', gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'This domain expired on', $html );
		$this->assertStringContainsString( 'The website and email on it do not work now.', $html );
		$this->assertStringContainsString( 'You can restore it until', $html );
		$this->assertStringContainsString( 'reactivation fee of', $html );
		$this->assertStringContainsString( '22.83', $html );
		$this->assertStringContainsString( 'To restore it, contact us.', $html, 'Pay-to-restore is off by default.' );
		$this->assertStringNotContainsString( 'Restore the domain', $html );
	}

	public function test_a_restore_url_from_a_listener_becomes_a_button(): void {
		add_filter( 'beltoft_services_domain_restore_url', static fn() => 'https://example.org/restore' );
		$r    = $this->domain( 'gone2-example.com', gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'href="https://example.org/restore"', $html );
		$this->assertStringContainsString( 'Restoring costs the renewal plus a reactivation fee of', $html );
		$this->assertStringNotContainsString( 'contact us', $html );
	}

	public function test_a_domain_after_its_window_is_no_longer_registered_by_us(): void {
		$r    = $this->domain( 'late-example.com', gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'late-example.com is no longer registered by us.', $html );
		$this->assertStringNotContainsString( 'Restore the domain', $html );
	}

	public function test_nothing_is_printed_while_the_setting_is_off_or_for_dns_hosting(): void {
		$r = $this->domain( 'off-example.com', gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) );
		ob_start();
		UnpaidView::overview( $r, array( 'dns_only' => true ) );
		$this->assertSame( '', (string) ob_get_clean() );
		$this->settings( array( 'domain_unpaid_notices' => 0 ) );
		$this->assertSame( '', $this->page( $r ) );
	}

	public function test_the_account_page_template_prints_the_block_through_its_action(): void {
		$this->assertNotFalse( has_action( 'beltoft_services_domain_overview_end', array( UnpaidView::class, 'overview' ) ) );
		$this->assertStringContainsString( "do_action( 'beltoft_services_domain_overview_end'", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/account/domain-page.php' ) );
	}

	public function test_the_admin_panel_shows_the_registrar_fields_the_stage_and_the_notices(): void {
		add_filter( 'pre_wp_mail', static fn() => true );
		$r = $this->domain( 'admin-example.com', gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) );
		$r->set( array( 'registrar_renewal_date' => '2027-10-24 00:00:00', 'restorable_until' => '2027-12-03 00:00:00', 'registrar_autorenew' => 'off' ) );
		Notices::process( Resource::find( $r->id() ) );
		ob_start();
		\BeltoftServices\Domains\Panels::admin( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Renewal date at the registrar:</strong> 2027-10-24 00:00:00', $html );
		$this->assertStringContainsString( 'Restorable until (registrar):</strong> 2027-12-03 00:00:00', $html );
		$this->assertStringContainsString( 'Renewal stage:</strong> expired', $html );
		$this->assertStringContainsString( 'Restore window:</strong> 40 days', $html );
		$this->assertStringContainsString( ' N3 sent ', str_replace( array( ': ', ' |' ), ' ', $html ) . ' ' );
	}

	public function test_the_settings_are_off_by_default_and_have_their_names(): void {
		$fields = \BeltoftServices\Settings::fields();
		$this->assertSame( 0, $fields[ Unpaid::SETTING ]['default'] );
		$this->assertSame( 'checkbox', $fields[ Unpaid::SETTING ]['type'] );
		$this->assertSame( 'domain_unpaid_notices', Unpaid::SETTING );
		$this->assertSame( 30, $fields['domain_reactivation_markup_percent']['default'] );
		$this->assertSame( 'domains', $fields[ Unpaid::SETTING ]['tab'] );
	}
}
