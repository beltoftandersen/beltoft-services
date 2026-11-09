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
use BeltoftServices\Hosting\HostingAccount;
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

	public function test_the_hosting_page_shows_the_storage_block_with_no_mailbox_and_links_to_the_mailboxes(): void {
		$r = $this->account();
		$this->connect_domain( $r, 'mail.customer.example' );
		$child  = $this->lite( $r, 'mail.customer.example' );
		$result = Registry::get( 'mail' )->provision( Resource::find( $child->id() ) );
		$child->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Extra mailbox storage', $html );
		$this->assertStringContainsString( 'Create a mailbox first', $html );
		$this->assertStringContainsString( 'Manage mailboxes', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="add_pack"', $html );

		Mailboxes::create( Resource::find( $child->id() ), 'info', 'Info', 0 );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Create a mailbox first', $html );
		$this->assertStringContainsString( 'data-hosting-action="add_pack"', $html );
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

	public function test_the_order_is_tied_to_the_subscription_by_notes_and_never_as_a_renewal(): void {
		$r      = $this->account();
		$bought = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		$order  = wc_get_order( $bought['order'] );
		$sub    = $this->sub( $r );

		$this->assertSame( '', (string) $order->get_meta( '_subscription_renewal' ), 'A renewal link would make WooCommerce Subscriptions treat the payment as a renewal.' );
		$this->assertSame( $sub->get_id(), (int) $order->get_meta( Addons::ORDER_META )['sub'] );

		$on_order = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$on_sub   = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( '#' . $sub->get_id(), $on_order );
		$this->assertStringContainsString( '#' . $order->get_id(), $on_sub );
	}

	public function test_the_confirm_text_names_months_in_plain_words(): void {
		$text = Addons::confirm_text( Addons::KIND_DOMAIN, array( 'unit' => 1.0, 'now' => 6.0, 'recurring' => 12.0, 'months_now' => 6 ) );
		$this->assertStringContainsString( '6 months until your next renewal', $text );
		$this->assertStringNotContainsString( 'started', $text );
		$one = Addons::confirm_text( Addons::KIND_DOMAIN, array( 'unit' => 1.0, 'now' => 1.0, 'recurring' => 12.0, 'months_now' => 1 ) );
		$this->assertStringContainsString( '1 month until your next renewal', $one );
	}

	public function test_the_hosting_script_reads_the_nested_error_data_and_uses_the_account_dialog(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/hosting.js' );
		$this->assertStringContainsString( 'result.data.data', $js );
		$this->assertStringNotContainsString( 'result.data.target', $js );
		$this->assertStringNotContainsString( 'result.data.amount_cents', $js );
		$this->assertStringContainsString( 'S.confirm', $js );
		$this->assertSame( 1, substr_count( $js, 'window.confirm' ), 'Only the fallback when the dialog script is missing.' );
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
		$ask = $a->buy_addon( 'domain', 'www.other.example', 0 );
		$this->assertSame( 'confirm', $ask->get_error_code() );
		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ) );
		$done = $a->buy_addon( 'domain', 'www.other.example', 100 );
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
		$this->assertSame( 'not_found', $a->buy_addon( 'mail_pack', 'nobody@mail.customer.example', 100 )->get_error_code() );

		$this->assertSame( 'paid', $a->buy_addon( 'mail_pack', 'info@mail.customer.example', 100 )['status'] );
		$this->assertSame( 1024 + 5120, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ) );
		$this->assertSame( (string) ( 1024 + 5120 ), $this->mailcow->domain( 'mail.customer.example' )['maxquota'] );

		for ( $i = 2; $i <= 4; $i++ ) {
			$this->assertSame( 'paid', $a->buy_addon( 'mail_pack', 'info@mail.customer.example', 100 )['status'] );
		}
		$this->assertSame( 1024 + 4 * 5120, $mb( $this->mailcow->mailbox( 'info@mail.customer.example' ) ), '+20 GB at most.' );
		$this->assertSame( 'limit', $a->buy_addon( 'mail_pack', 'info@mail.customer.example', 100 )->get_error_code() );

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

	public function test_a_declined_card_leaves_no_line_and_a_second_buy_reuses_the_open_order(): void {
		add_action( 'woocommerce_scheduled_subscription_payment_bacs', static fn( $amount, $order ) => $order->update_status( 'failed' ), 10, 2 );
		$r = $this->account();

		$first = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		$this->assertSame( 'pay', $first['status'] );
		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ) );

		$again = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		$this->assertSame( $first['order'], $again['order'], 'The open order is offered again, not doubled.' );
		$this->assertCount( 1, wc_get_orders( array( 'limit' => -1, 'created_via' => 'beltoft_services_addon' ) ) );

		wc_get_order( $first['order'] )->payment_complete();
		$this->assertCount( 1, Addons::lines( $r, Addons::KIND_DOMAIN ), 'Paid by the link: one line.' );
		$this->assertSame( 'exists', Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' )->get_error_code(), 'A target with a line is not bought twice.' );
	}

	public function test_a_payment_in_process_is_not_a_paid_add_on(): void {
		add_action( 'woocommerce_scheduled_subscription_payment_bacs', static fn( $amount, $order ) => $order->update_status( 'on-hold' ), 10, 2 );
		$r   = $this->account();
		$out = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );

		$this->assertSame( 'pending', $out['status'] );
		$this->assertSame( 'Your payment is being processed.', $out['message'] );
		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ) );
	}

	public function test_a_paid_order_for_a_target_that_has_a_line_is_not_added_twice_and_alerts_the_owner(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$mails = array();
		add_filter( 'wp_mail', static function ( $args ) use ( &$mails ) {
			if ( str_contains( (string) $args['subject'], 'Add-on payment needs a look' ) ) {
				$mails[] = $args;
			}
			return $args;
		} );
		$r = $this->account();

		$one = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		wc_get_order( $one['order'] )->payment_complete();
		$this->assertCount( 1, Addons::lines( $r, Addons::KIND_DOMAIN ) );

		// A second order for the same domain is paid anyway (made another way, or in parallel).
		$meta = wc_get_order( $one['order'] )->get_meta( Addons::ORDER_META );
		$dup  = wc_create_order( array( 'customer_id' => (int) $r->license()['user_id'], 'created_via' => 'beltoft_services_addon' ) );
		$dup->update_meta_data( Addons::ORDER_META, $meta );
		$dup->set_total( '1.00' );
		$dup->save();
		$dup->payment_complete();

		$this->assertCount( 1, Addons::lines( $r, Addons::KIND_DOMAIN ), 'Not added twice.' );
		$this->assertSame( 'refused', wc_get_order( $dup->get_id() )->get_meta( Addons::ORDER_DONE ), 'Kept on record, never dropped silently.' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'duplicate', $mails[0]['message'] );
		remove_all_filters( 'wp_mail' );
	}

	public function test_an_order_paid_after_its_quoted_renewal_is_refused_and_stale_orders_are_cancelled(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$mails = array();
		add_filter( 'wp_mail', static function ( $args ) use ( &$mails ) {
			if ( str_contains( (string) $args['subject'], 'Add-on payment needs a look' ) ) {
				$mails[] = $args;
			}
			return $args;
		} );
		$r   = $this->account();
		$one = Addons::buy( $r, Addons::KIND_DOMAIN, 'late.example' );
		$o   = wc_get_order( $one['order'] );
		$m   = $o->get_meta( Addons::ORDER_META );
		$m['next'] = time() - 60;
		$o->update_meta_data( Addons::ORDER_META, $m );
		$o->save();
		$o->payment_complete();

		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ) );
		$this->assertSame( 'refused', wc_get_order( $o->get_id() )->get_meta( Addons::ORDER_DONE ) );
		$this->assertCount( 1, $mails );

		$old = Addons::buy( $r, Addons::KIND_DOMAIN, 'old.example' );
		$oo  = wc_get_order( $old['order'] );
		$oo->set_date_created( time() - 3 * DAY_IN_SECONDS );
		$oo->save();
		Addons::cancel_stale();
		$this->assertSame( 'cancelled', wc_get_order( $old['order'] )->get_status() );
		remove_all_filters( 'wp_mail' );
	}

	public function test_the_vat_position_of_the_subscription_decides_the_tax_of_the_one_off_order(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );

		$normal = $this->account();
		$this->sub( $normal )->set_billing_country( 'DK' );
		$this->sub( $normal )->save();
		$a = Addons::buy( $normal, Addons::KIND_DOMAIN, 'taxed.example' );
		$this->assertSame( 1.25, $a['total'], 'Charged like the hosting product: with VAT.' );

		$exempt = $this->account();
		$sub    = $this->sub( $exempt );
		$sub->set_billing_country( 'DE' );
		$sub->update_meta_data( 'is_vat_exempt', 'yes' );
		$sub->update_meta_data( '_billing_eu_vat_number', 'DE123456789' );
		$sub->save();
		$b = Addons::buy( $exempt, Addons::KIND_DOMAIN, 'exempt.example' );
		$this->assertSame( 1.0, $b['total'], 'No VAT for an exempt customer.' );
		$this->assertSame( 'DE123456789', wc_get_order( $b['order'] )->get_meta( '_billing_eu_vat_number' ) );
		delete_option( 'woocommerce_calc_taxes' );
	}

	public function test_a_refunded_order_ends_its_line_and_the_confirmed_amount_must_match(): void {
		$this->card_pays();
		$r   = $this->account();
		$a   = new HostingActions( Resource::find( $r->id() ) );
		$this->connect_domain( $r, 'main.example' );

		$stale = $a->buy_addon( 'domain', 'www.other.example', 999 );
		$this->assertSame( 'confirm', $stale->get_error_code(), 'The accepted amount differs from the price now.' );
		$this->assertSame( 100, $stale->get_error_data()['amount_cents'] );
		$this->assertSame( array(), Addons::lines( $r, Addons::KIND_DOMAIN ) );

		$ok = $a->buy_addon( 'domain', 'www.other.example', 100 );
		$this->assertSame( 'paid', $ok['status'] );
		wc_get_order( $ok['order'] )->update_status( 'refunded' );
		$lines = Addons::lines( $r, Addons::KIND_DOMAIN );
		$line  = reset( $lines );
		$this->assertSame( 'yes', (string) $line->get_meta( Addons::I_ENDING ), 'Ends at once, leaves at the renewal.' );
		$this->assertTrue( Addons::has( $r, Addons::KIND_DOMAIN, 'other.example' ), 'The line is still there until the renewal.' );
	}

	public function test_a_plan_switch_to_another_period_reprices_the_add_on_lines_and_keeps_them(): void {
		$this->card_pays();
		$r = $this->account();
		Addons::buy( $r, Addons::KIND_DOMAIN, 'keep.example' );
		$sub = $this->sub( $r );
		$sub->set_billing_period( 'year' );
		$sub->save();

		Addons::resync_prices( $sub );
		$lines = Addons::lines( $r, Addons::KIND_DOMAIN );
		$this->assertCount( 1, $lines, 'Kept through the switch.' );
		$this->assertSame( 12.0, (float) reset( $lines )->get_total(), 'EUR 12 for a yearly period now.' );
	}

	public function test_a_line_saved_without_the_done_mark_is_not_added_again(): void {
		$r   = $this->account();
		$one = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		wc_get_order( $one['order'] )->payment_complete();
		$this->assertCount( 1, Addons::lines( $r, Addons::KIND_DOMAIN ) );

		$o = wc_get_order( $one['order'] );
		$o->delete_meta_data( Addons::ORDER_DONE );
		$o->save();
		do_action( 'woocommerce_order_status_completed', $one['order'] );

		$this->assertCount( 1, Addons::lines( $r, Addons::KIND_DOMAIN ), 'Still one line.' );
		$this->assertNotSame( '', (string) wc_get_order( $one['order'] )->get_meta( Addons::ORDER_DONE ), 'The order is marked again.' );
	}

	public function test_an_on_hold_order_is_reused_whatever_its_age_and_never_cancelled(): void {
		add_action( 'woocommerce_scheduled_subscription_payment_bacs', static fn( $amount, $order ) => $order->update_status( 'on-hold' ), 10, 2 );
		$r   = $this->account();
		$one = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		$o   = wc_get_order( $one['order'] );
		$o->set_date_created( time() - 5 * DAY_IN_SECONDS );
		$o->save();

		Addons::cancel_stale();
		$this->assertSame( 'on-hold', wc_get_order( $one['order'] )->get_status() );

		$two = Addons::buy( $r, Addons::KIND_DOMAIN, 'second.example' );
		$this->assertSame( 'pending', $two['status'] );
		$this->assertSame( 'Your payment is being processed.', $two['message'] );
		$this->assertSame( $one['order'], $two['order'] );
		$this->assertCount( 1, wc_get_orders( array( 'limit' => -1, 'created_via' => 'beltoft_services_addon' ) ) );
	}

	private function addon_product_id( string $kind ): int {
		$ids = get_posts( array( 'post_type' => 'product', 'fields' => 'ids', 'meta_key' => Addons::PRODUCT_KEY, 'meta_value' => $kind, 'post_status' => 'publish' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return (int) $ids[0];
	}

	public function test_an_add_on_product_cannot_be_added_to_the_cart_directly(): void {
		$this->products();
		WC()->cart->empty_cart();
		wc_clear_notices();

		foreach ( array( Addons::KIND_DOMAIN, Addons::KIND_PACK ) as $kind ) {
			$id = $this->addon_product_id( $kind );
			// The add-to-cart form, the AJAX add and the Store API all run this filter (the cart object itself does not).
			$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $id, 1 ), $kind );
		}

		$notices = array_map( static fn( $n ) => is_array( $n ) ? (string) $n['notice'] : (string) $n, wc_get_notices( 'error' ) );
		$this->assertContains( 'Extra domains and mailbox storage are bought from your hosting account page.', $notices );

		// The renewal and resubscribe carts of WooCommerce Subscriptions carry the lines and pass.
		foreach ( array( 'subscription_renewal', 'subscription_resubscribe', 'subscription_switch' ) as $flag ) {
			$this->assertTrue( Addons::refuse_direct_add( true, $this->addon_product_id( Addons::KIND_DOMAIN ), 1, 0, array(), array( $flag => array( 'x' => 1 ) ) ), $flag );
		}
		$this->assertTrue( Addons::refuse_direct_add( true, 0, 1 ), 'Not an add-on product.' );
		$this->assertFalse( Addons::refuse_direct_add( false, 0, 1 ), 'An earlier refusal stays.' );
		wc_clear_notices();
	}

	public function test_a_paid_order_of_an_add_on_product_that_was_not_made_here_alerts_the_owner(): void {
		$this->products();
		$mails = array();
		add_filter( 'wp_mail', static function ( $args ) use ( &$mails ) {
			if ( str_contains( (string) $args['subject'], 'Add-on payment needs a look' ) ) {
				$mails[] = $args;
			}
			return $args;
		} );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );

		$order = wc_create_order( array( 'created_via' => 'checkout' ) );
		$order->add_product( wc_get_product( $this->addon_product_id( Addons::KIND_DOMAIN ) ), 1 );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete();

		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Add-on payment needs a look', (string) $mails[0]['subject'] );
		$this->assertSame( 'yes', (string) wc_get_order( $order->get_id() )->get_meta( '_beltoft_services_addon_stray_alerted' ) );

		$order->update_status( 'completed' );
		$this->assertCount( 1, $mails, 'Once.' );

		// A renewal order carries the add-on lines on purpose.
		$this->card_pays();
		$r = $this->account();
		Addons::buy( $r, Addons::KIND_DOMAIN, 'keep.example' );
		$before = count( $mails );
		$renewal = wcs_create_renewal_order( $this->sub( $r ) );
		$renewal->payment_complete();
		$this->assertCount( $before, $mails, 'A renewal is not a stray order.' );
	}

	public function test_a_removed_domain_that_is_added_again_stays_at_the_renewal(): void {
		$this->card_pays();
		$r = $this->account();
		$this->connect_domain( $r, 'main.example' );
		$a = new HostingActions( Resource::find( $r->id() ) );
		$s = 'w' . $r->id();
		$a->buy_addon( 'domain', 'www.other.example', 100 );
		$this->assertIsArray( $a->add_domain( $s, 'www.other.example' ) );
		$this->assertTrue( $a->remove_domain( 'www.other.example' )['at_renewal'] );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertMatchesRegularExpression( '/www\.other\.example[^,<]*\(ends at the next renewal\)/', (string) ob_get_clean() );

		// Added again before the renewal: the line bills again and the domain stays.
		$this->assertIsArray( $a->add_domain( $s, 'shop.other.example' ) );
		foreach ( Addons::lines( $r, Addons::KIND_DOMAIN, 'other.example' ) as $item ) {
			$this->assertSame( '', (string) $item->get_meta( Addons::I_ENDING ) );
		}
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'ends at the next renewal)', (string) ob_get_clean() );

		wcs_create_renewal_order( $this->sub( $r ) );
		$this->assertNotNull( SiteDomains::find( 'www.other.example' ) );
		$this->assertNotNull( SiteDomains::find( 'shop.other.example' ) );
		$this->assertTrue( Addons::has( $r, Addons::KIND_DOMAIN, 'other.example' ) );
	}

	public function test_nothing_is_sold_on_a_subscription_that_will_not_renew(): void {
		$this->card_pays();
		$r = $this->account();
		$this->connect_domain( $r, 'main.example' );
		$this->assertNotNull( Addons::quote( $r, Addons::KIND_DOMAIN ) );

		$sub = $this->sub( $r );
		add_filter( 'woocommerce_can_subscription_be_updated_to_on-hold', '__return_true' );
		$sub->update_status( 'on-hold' );
		remove_filter( 'woocommerce_can_subscription_be_updated_to_on-hold', '__return_true' );
		$this->assertNull( Addons::quote( $r, Addons::KIND_DOMAIN ) );
		$err = Addons::buy( $r, Addons::KIND_DOMAIN, 'other.example' );
		$this->assertWPError( $err );
		$this->assertSame( 'failed', $err->get_error_code() );
		$this->assertSame( 'failed', ( new HostingActions( Resource::find( $r->id() ) ) )->add_domain( 'w' . $r->id(), 'www.other.example' )->get_error_code() );
		$this->assertSame( array(), wc_get_orders( array( 'limit' => -1, 'created_via' => 'beltoft_services_addon' ) ) );
	}

	public function test_a_subscription_with_no_renewal_date_left_sells_nothing(): void {
		$r   = $this->account();
		$sub = $this->sub( $r );
		$sub->update_dates( array( 'next_payment' => 0 ) );
		$this->assertNull( Addons::quote( $r, Addons::KIND_PACK ) );
	}

	public function test_an_add_on_is_not_sold_for_a_domain_that_needs_none(): void {
		$this->card_pays();
		$r = $this->account();
		$this->connect_domain( $r, 'main.example' );
		$a = new HostingActions( Resource::find( $r->id() ) );

		foreach ( array( 'main.example', 'www.main.example' ) as $target ) {
			$err = $a->buy_addon( 'domain', $target, 100 );
			$this->assertWPError( $err );
			$this->assertSame( 'invalid', $err->get_error_code(), $target );
		}

		$this->assertIsArray( $a->buy_addon( 'domain', 'other.example', 100 ) );
		$again = $a->buy_addon( 'domain', 'other.example', 100 );
		$this->assertWPError( $again );
		$this->assertSame( 'invalid', $again->get_error_code(), 'Covered by a line already.' );
		$this->assertCount( 1, Addons::lines( $r, Addons::KIND_DOMAIN ) );
		$this->assertCount( 1, wc_get_orders( array( 'limit' => -1, 'created_via' => 'beltoft_services_addon' ) ) );
	}

	public function test_a_domain_paid_through_the_pay_link_is_connected_after_payment(): void {
		$r = $this->account();
		$this->connect_domain( $r, 'main.example' );
		$a    = new HostingActions( Resource::find( $r->id() ) );
		$slug = 'w' . $r->id();

		$bought = $a->buy_addon( 'domain', 'other.example', 100, 'www.other.example', $slug );
		$this->assertIsArray( $bought );
		$this->assertSame( 'pay', $bought['status'] );
		$this->assertNull( SiteDomains::find( 'www.other.example' ) );

		wc_get_order( $bought['order'] )->payment_complete();

		$this->assertTrue( Addons::has( $r, Addons::KIND_DOMAIN, 'other.example' ) );
		$row = SiteDomains::find( 'www.other.example' );
		$this->assertNotNull( $row, 'The paid domain joins the website.' );
		$this->assertSame( $r->id(), $row['resource_id'] );
	}

	public function test_a_domain_paid_with_the_saved_card_is_left_to_the_page_that_adds_it(): void {
		$this->card_pays();
		$r = $this->account();
		$this->connect_domain( $r, 'main.example' );
		$a = new HostingActions( Resource::find( $r->id() ) );

		$bought = $a->buy_addon( 'domain', 'other.example', 100, 'www.other.example', 'w' . $r->id() );
		$this->assertSame( 'paid', $bought['status'] );
		$this->assertNull( SiteDomains::find( 'www.other.example' ), 'The page adds it right after, so it is not added twice.' );
		$this->assertIsArray( $a->add_domain( 'w' . $r->id(), 'www.other.example' ) );
	}

	public function test_the_hosting_page_shows_a_paid_domain_that_is_not_on_a_website_yet(): void {
		$this->card_pays();
		$r = $this->account();
		$this->connect_domain( $r, 'main.example' );
		Addons::buy( $r, Addons::KIND_DOMAIN, 'other.example' );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'Your extra domain other.example is paid. It is being connected.', (string) ob_get_clean() );

		$a = new HostingActions( Resource::find( $r->id() ) );
		$a->add_domain( 'w' . $r->id(), 'www.other.example' );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'is being connected', (string) ob_get_clean() );
	}

	public function test_the_hosting_script_sends_the_typed_domain_with_the_purchase(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/hosting.js' );
		$this->assertStringContainsString( 'host: offer.host', $js );
		$this->assertStringContainsString( 'slug: offer.slug', $js );
	}

	public function test_a_renewal_that_cannot_take_the_storage_back_alerts_the_owner(): void {
		$this->card_pays();
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$mails = array();
		add_filter( 'wp_mail', static function ( $args ) use ( &$mails ) {
			if ( str_contains( (string) $args['subject'], 'Add-on change needs a look' ) ) {
				$mails[] = $args;
			}
			return $args;
		} );
		$r = $this->account();
		$this->with_mailbox( $r );
		$a = new HostingActions( Resource::find( $r->id() ) );
		$a->buy_addon( 'mail_pack', 'info@mail.customer.example', 100 );
		$a->remove_pack( 'info@mail.customer.example' );

		$this->mailcow->fail_next( 'list_mailboxes', new \WP_Error( 'down', 'The mail server is down.' ) );
		wcs_create_renewal_order( $this->sub( $r ) );

		$this->assertFalse( Addons::has( $r, Addons::KIND_PACK, 'info@mail.customer.example' ), 'The line is gone.' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'info@mail.customer.example', $mails[0]['message'] );
		remove_all_filters( 'wp_mail' );
	}
}
