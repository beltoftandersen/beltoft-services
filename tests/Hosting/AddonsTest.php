<?php
/**
 * Hosting add-ons: an extra registrable domain and extra Email lite storage per mailbox, billed on the hosting subscription.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\Addons;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Mail\FakeMailcowClient;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

/**
 * Addons, MailPacks and the actions around them.
 */
class AddonsTest extends HostingCase {

	/**
	 * The fake mail server.
	 *
	 * @var FakeMailcowClient
	 */
	private $mailcow;

	public function set_up(): void {
		parent::set_up();

		delete_option( FakeMailcowClient::OPTION );
		$this->mailcow = new FakeMailcowClient();
		add_filter( 'beltoft_services_mail_client', fn() => $this->mailcow );
		$this->settings(
			array(
				'domain_nameservers' => 'ns1.example.net,ns2.example.net',
				'mail_host'          => 'mail.example.net',
				'mail_spf_include'   => '_spf.mail.example.net',
				'mail_rl_minute'     => 30,
				'mail_rl_day'        => 500,
			)
		);
		$proof = array(
			'delegation' => array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.host.example' ), 'ips' => array(), 'ds' => null, 'ours' => false ),
			'check_txt'  => array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' ),
		);
		add_filter( 'beltoft_services_dns_proof_result', fn( $pre, $call ) => $proof[ $call ] ?? $pre, 10, 2 );
		add_filter( 'beltoft_services_mail_dns_lookup', fn() => array(), 10, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );
		// No gateway that takes subscription payments on the test site: manual is what the subscription says.
		add_filter( 'woocommerce_subscription_is_manual', static fn( $manual, $sub ) => true === $sub->get_requires_manual_renewal(), 10, 2 );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_mail_client' );
		remove_all_filters( 'beltoft_services_dns_proof_result' );
		remove_all_filters( 'beltoft_services_mail_dns_lookup' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_actions( 'woocommerce_scheduled_subscription_payment_bacs' );
		remove_all_filters( 'woocommerce_subscription_is_manual' );
		delete_option( FakeMailcowClient::OPTION );
		parent::tear_down();

		// Orders and subscriptions commit in these tests, and with them the settings written above: after the rollback, delete what was committed.
		delete_option( \BeltoftServices\Settings::OPTION );
	}

	/**
	 * The shop's add-on products, EUR 1 a month each.
	 */
	private function products(): void {
		foreach ( array( Addons::KIND_DOMAIN, Addons::KIND_PACK ) as $kind ) {
			$p = new \WC_Product_Simple();
			$p->set_name( 'Add-on ' . $kind );
			$p->set_status( 'publish' );
			$p->set_regular_price( '1' );
			$p->update_meta_data( Addons::PRODUCT_KEY, $kind );
			$p->save();
		}
	}

	/**
	 * The saved card pays the one-off orders at once.
	 */
	private function card_pays(): void {
		add_action( 'woocommerce_scheduled_subscription_payment_bacs', static fn( $amount, $order ) => $order->payment_complete(), 10, 2 );
	}

	/**
	 * A ready hosting account paid by a subscription.
	 *
	 * @param string $period     month or year.
	 * @param int    $next_after Seconds until the next renewal.
	 */
	private function account( string $period = 'month', int $next_after = 10 * DAY_IN_SECONDS ): Resource {
		$this->products();
		$r = $this->resource( array( 'sites' => 0, 'mail_mailboxes' => 5, 'mail_quota_mb' => 1024, 'mail_aliases' => 3, 'mail_per_minute' => 60 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		$user = (int) $r->license()['user_id'];
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => $period, 'billing_interval' => 1, 'status' => 'active' ) );
		$host = new \WC_Product_Simple();
		$host->set_name( 'Hosting' );
		$host->set_regular_price( '9' );
		$host->save();
		$sub->add_product( $host, 1 );
		$sub->set_payment_method( 'bacs' );
		$sub->set_requires_manual_renewal( false );
		$sub->calculate_totals();
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + $next_after ) ) );
		$sub->save();
		Licenses::update( (int) $r->license()['id'], array( 'subscription_id' => $sub->get_id() ) );

		return Resource::find( $r->id() );
	}

	private function sub( Resource $r ): \WC_Subscription {
		return Addons::subscription( $r );
	}

	/**
	 * Email lite on the account's own domain with one mailbox.
	 */
	private function with_mailbox( Resource $r ): Resource {
		$this->connect_domain( $r, 'mail.customer.example' );
		$child  = $this->lite( $r, 'mail.customer.example' );
		$result = Registry::get( 'mail' )->provision( Resource::find( $child->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$child->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) );
		$child = Resource::find( $child->id() );
		$made  = Mailboxes::create( $child, 'info', 'Info', 0 );
		$this->assertFalse( is_wp_error( $made ), is_wp_error( $made ) ? $made->get_error_message() : '' );

		return $child;
	}

	public function test_the_price_is_one_unit_for_each_started_month_until_the_renewal(): void {
		$r = $this->account( 'month', 10 * DAY_IN_SECONDS );
		$q = Addons::quote( $r, Addons::KIND_DOMAIN );
		$this->assertSame( 1, $q['months_now'] );
		$this->assertSame( 1.0, $q['now'] );
		$this->assertSame( 1.0, $q['recurring'] );

		$y = $this->account( 'year', 5 * 31 * DAY_IN_SECONDS + 10 * DAY_IN_SECONDS );
		$q = Addons::quote( $y, Addons::KIND_DOMAIN );
		$this->assertSame( 6, $q['months_now'], 'Five months and ten days is six started months.' );
		$this->assertSame( 6.0, $q['now'] );
		$this->assertSame( 12.0, $q['recurring'], 'EUR 12 with each yearly renewal.' );

		$this->assertSame( 12, Addons::months_to_renewal( $this->sub( $y ), strtotime( '-1 year +10 days', (int) $this->sub( $y )->get_time( 'next_payment' ) ) ) );
		$this->assertStringContainsString( 'excluding VAT', Addons::confirm_text( Addons::KIND_DOMAIN, $q ) );
	}

	public function test_the_line_is_added_only_after_the_order_is_paid(): void {
		$r = $this->account( 'year', 5 * 31 * DAY_IN_SECONDS + 10 * DAY_IN_SECONDS );

		$bought = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		$this->assertSame( 'pay', $bought['status'], 'No saved card: a pay-for-order link.' );
		$this->assertNotSame( '', $bought['pay_url'] );
		$this->assertSame( 6.0, $bought['total'] );
		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ), 'Nothing is active before the payment.' );
		$this->assertFalse( Addons::has( $r, Addons::KIND_DOMAIN, 'second.example' ) );

		wc_get_order( $bought['order'] )->payment_complete();
		$lines = Addons::lines( $r, Addons::KIND_DOMAIN, 'second.example' );
		$this->assertCount( 1, $lines );
		$this->assertSame( 12.0, (float) reset( $lines )->get_total(), 'The line bills the year at renewal.' );

		wc_get_order( $bought['order'] )->payment_complete();
		$this->assertCount( 1, Addons::lines( $r, Addons::KIND_DOMAIN, 'second.example' ), 'A second signal adds nothing.' );
	}

	public function test_a_saved_card_pays_at_once(): void {
		$this->card_pays();
		$r      = $this->account();
		$bought = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );

		$this->assertSame( 'paid', $bought['status'] );
		$this->assertTrue( Addons::has( $r, Addons::KIND_DOMAIN, 'second.example' ) );
		$this->assertSame( 1.0, (float) wc_get_order( $bought['order'] )->get_subtotal() );
	}

	public function test_a_removed_line_is_not_billed_at_the_renewal_and_what_it_gave_goes(): void {
		$this->card_pays();
		$r = $this->account();
		Addons::buy( $r, Addons::KIND_DOMAIN, 'keep.example' );
		Addons::buy( $r, Addons::KIND_DOMAIN, 'gone.example' );
		$this->assertTrue( Addons::end( $r, Addons::KIND_DOMAIN, 'gone.example' ) );
		$this->assertTrue( Addons::has( $r, Addons::KIND_DOMAIN, 'gone.example' ), 'It stays until the renewal.' );

		$renewal = wcs_create_renewal_order( $this->sub( $r ) );
		$this->assertFalse( is_wp_error( $renewal ) );

		$names = array_map( static fn( $i ) => $i->get_name(), $renewal->get_items() );
		$this->assertCount( 2, $names, 'The hosting line and the line that stays.' );
		$this->assertFalse( Addons::has( $r, Addons::KIND_DOMAIN, 'gone.example' ) );
		$this->assertTrue( Addons::has( $r, Addons::KIND_DOMAIN, 'keep.example' ) );
		$this->assertSame( 10.0, (float) $renewal->get_total(), 'EUR 9 for the hosting and EUR 1 for the domain that stays.' );
		$this->assertSame( 10.0, (float) $this->sub( $r )->get_total() );
	}

	public function test_a_second_domain_needs_the_add_on_and_subdomains_never_do(): void {
		$this->card_pays();
		$r = $this->account();
		$this->connect_domain( $r, 'main.example' );
		$a = new HostingActions( Resource::find( $r->id() ) );
		$s = 'w' . $r->id();

		$this->assertNull( Addons::domain_needing_addon( $r, 'www.main.example' ) );
		$this->assertNull( Addons::domain_needing_addon( $r, 'a.b.main.example' ) );
		$this->assertSame( 'other.example', Addons::domain_needing_addon( $r, 'www.other.example' ) );

		$err = $a->add_domain( $s, 'www.other.example' );
		$this->assertWPError( $err );
		$this->assertSame( 'addon_required', $err->get_error_code() );
		$this->assertStringContainsString( 'excluding VAT', $err->get_error_message() );
		$this->assertSame( 'other.example', $err->get_error_data()['target'] );
		$this->assertNull( SiteDomains::find( 'www.other.example' ) );

		// Subdomains of the account's domain: free, no order made.
		$orders = count( wc_get_orders( array( 'limit' => -1, 'created_via' => 'beltoft_services_addon' ) ) );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertIsArray( $a->add_domain( $s, 'sub' . $i . '.main.example' ) );
		}
		$this->assertCount( $orders, wc_get_orders( array( 'limit' => -1, 'created_via' => 'beltoft_services_addon' ) ) );
		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ) );

		// Not confirmed: nothing is bought. Confirmed: paid, then the domain can be added.
		$ask = $a->buy_addon( 'domain', 'www.other.example', false );
		$this->assertSame( 'confirm', $ask->get_error_code() );
		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ) );
		$done = $a->buy_addon( 'domain', 'www.other.example', true );
		$this->assertSame( 'paid', $done['status'] );
		$this->assertIsArray( $a->add_domain( $s, 'www.other.example' ) );
		$this->assertIsArray( $a->add_domain( $s, 'shop.other.example' ), 'The same registrable domain is paid once.' );

		// Removing: the last name of the paid domain stays until the renewal, the line ends, no new charge.
		$a->remove_domain( 'shop.other.example' );
		$this->assertNull( SiteDomains::find( 'shop.other.example' ), 'A name of a domain that keeps another name goes at once.' );
		$gone = $a->remove_domain( 'www.other.example' );
		$this->assertTrue( $gone['at_renewal'] );
		$this->assertNotNull( SiteDomains::find( 'www.other.example' ) );
		wcs_create_renewal_order( $this->sub( $r ) );
		$this->assertNull( SiteDomains::find( 'www.other.example' ), 'Gone with the renewal.' );
		$this->assertFalse( Addons::has( $r, Addons::KIND_DOMAIN, 'other.example' ) );
	}

	public function test_without_a_shop_product_or_subscription_nothing_is_sold(): void {
		$r = $this->resource( array( 'sites' => 0 ) );
		$this->assertNull( Addons::quote( $r, Addons::KIND_DOMAIN ) );
		$this->assertWPError( Addons::buy( $r, Addons::KIND_DOMAIN, 'x.example' ) );
	}

	public function test_a_mailbox_gets_five_gb_for_each_pack_up_to_four_and_goes_back_at_the_renewal(): void {
		$this->card_pays();
		$r     = $this->account();
		$child = $this->with_mailbox( $r );
		$a     = new HostingActions( Resource::find( $r->id() ) );
		$mb    = static fn( $m ) => (int) round( (int) $m['quota'] / 1048576 );

		$this->assertSame( 1024, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ) );
		$this->assertSame( 'not_found', $a->buy_addon( 'mail_pack', 'nobody@mail.customer.example', true )->get_error_code() );

		$this->assertSame( 'paid', $a->buy_addon( 'mail_pack', 'info@mail.customer.example', true )['status'] );
		$this->assertSame( 1024 + 5120, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ) );
		$this->assertSame( (string) ( 1024 + 5120 ), $this->mailcow->domain( 'mail.customer.example' )['maxquota'] );

		for ( $i = 2; $i <= 4; $i++ ) {
			$this->assertSame( 'paid', $a->buy_addon( 'mail_pack', 'info@mail.customer.example', true )['status'] );
		}
		$this->assertSame( 1024 + 4 * 5120, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ), '+20 GB at most.' );
		$this->assertSame( 'limit', $a->buy_addon( 'mail_pack', 'info@mail.customer.example', true )->get_error_code() );

		// Removing one pack changes nothing until the renewal, then the size is lowered by 5 GB.
		$this->assertTrue( $a->remove_pack( 'info@mail.customer.example' )['at_renewal'] );
		$this->assertSame( 1024 + 4 * 5120, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ) );
		wcs_create_renewal_order( $this->sub( $r ) );
		$this->assertSame( 1024 + 3 * 5120, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ) );

		foreach ( range( 1, 3 ) as $unused ) {
			$a->remove_pack( 'info@mail.customer.example' );
		}
		wcs_create_renewal_order( $this->sub( $r ) );
		$this->assertSame( 1024, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ), 'Back to the plan size.' );
		$this->assertSame( 'not_found', $a->remove_pack( 'info@mail.customer.example' )->get_error_code() );
	}

	public function test_the_account_page_offers_packs_per_mailbox(): void {
		$this->card_pays();
		$r = $this->account();
		$this->with_mailbox( $r );

		ob_start();
		\BeltoftServices\Hosting\HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-hosting-action="add_pack" data-mailbox="info@mail.customer.example"', $html );
		$this->assertStringContainsString( 'up to 4 packs', $html );
		$this->assertStringNotContainsString( 'remove_pack', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}
}
