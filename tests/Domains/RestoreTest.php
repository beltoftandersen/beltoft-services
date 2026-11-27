<?php
/** Pay-to-restore: the order, the guarded restore job, the late renewal and the page button. Fake registrar only, no network. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Notices;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Renewals;
use BeltoftServices\Domains\Restore;
use BeltoftServices\Domains\UnpaidView;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class RestoreTest extends TestCase {
	/** @var array<int, array<string, mixed>> Mails that would have gone out. Nothing ever leaves the test. */
	private array $mails = array();

	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		// Paying an order queues a job and an Action Scheduler row would outlive the test: the tests run the job themselves.
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			remove_action( $hook, array( Restore::class, 'on_paid' ), 20 );
		}
		remove_action( 'woocommerce_subscription_renewal_payment_complete', array( Renewals::class, 'on_paid' ), 20 );
		update_option( 'woocommerce_currency', 'EUR' );
		\BeltoftServices\Currency::reset();
		$this->settings( array( 'domain_unpaid_notices' => 1, 'domain_restore_enabled' => 1, 'admin_email' => 'christian.beltoft@gmail.com', 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		update_option(
			Prices::OPTION,
			array(
				'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 43.08, 'soft_restore' => 0.0, 'soft_quarantine_days' => 40, 'quarantine_days' => 0 ),
				'dk'  => array( 'create' => 8.0, 'renew' => 8.15, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 17.56, 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
			)
		);
	}

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );
		}
		parent::tear_down();
		// Some step of these tests commits (the order and subscription tables), so the rollback leaves the settings: put them back by hand.
		$this->settings( array( 'domain_nameservers' => '', 'domain_restore_enabled' => 0, 'domain_unpaid_notices' => 0, 'domain_reactivation_markup_percent' => 30 ) );
		update_option( 'woocommerce_currency', 'USD' );
		\BeltoftServices\Currency::reset();
	}

	/** The renewal date of an expired domain: three days ago. */
	private function expiry( int $days_ago = 3 ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
	}

	/** An expired domain whose renewal was not paid: subscription on hold, licence suspended. */
	private function expired( string $domain = 'restore-example.com', ?string $expires = null, array $meta = array() ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'restore-' . wp_generate_password( 6, false ) ) );
		$this->user = self::factory()->user->create( array( 'user_email' => 'christian.beltoft+' . wp_generate_password( 6, false ) . '@gmail.com' ) );
		$sub     = wcs_create_subscription( array( 'customer_id' => $this->user, 'billing_period' => 'year', 'billing_interval' => 1, 'currency' => 'EUR' ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user, 'email' => 'christian.beltoft@gmail.com', 'subscription_id' => $sub->get_id() ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), $meta + array( 'domain' => $domain, 'remote_id' => '777', 'expires_at' => $expires ?? $this->expiry(), 'state' => 'paused', 'mode' => 'register' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$sub->update_status( 'active' );
		$sub->update_status( 'on-hold' );
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );

		return Resource::find( $r->id() );
	}

	private function paid_order( Resource $r ): \WC_Order {
		$order = Restore::create_order( $r, $this->user );
		$this->assertInstanceOf( \WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );
		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	private function fake(): FakeClient {
		$fake = new FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );

		return $fake;
	}

	/** @return array<int, array<string, mixed>> Calls of one method. */
	private function calls( FakeClient $fake, string $method ): array {
		return array_values( array_filter( $fake->calls(), static fn( $c ) => $method === $c['method'] ) );
	}

	/** Every call the job makes: never a DELETE. */
	private function assert_no_delete( FakeClient $fake ): void {
		$this->assertCount( 0, $this->calls( $fake, 'DELETE' ), 'The plugin never deletes a registration.' );
	}

	private function domain_reply( string $date, string $status = 'ACT', array $more = array() ): array {
		return $more + array( 'status' => $status, 'renewal_date' => $date, 'name_servers' => array( array( 'name' => 'ns1.example.net' ), array( 'name' => 'ns2.example.net' ) ) );
	}

	/** Moves the stored time of the restore POST back, as if $hours had passed since. */
	private function age_post( Resource $r, \WC_Order $order, int $hours ): void {
		$posted = (array) Resource::find( $r->id() )->get( 'restore_posted' );
		$posted[ (string) $order->get_id() ] = time() - $hours * HOUR_IN_SECONDS;
		$r->set( array( 'restore_posted' => $posted ) );
	}

	/** @return string[] Subjects of the staff mails (the shop's own order mails are left out). */
	private function mail_subjects(): array {
		return array_values( array_filter( array_column( $this->mails, 'subject' ), static fn( $s ) => ! str_contains( (string) $s, 'New order' ) ) );
	}

	/** @return string All staff mail text, subjects and bodies. */
	private function staff_text(): string {
		return implode( "\n", array_map( static fn( $m ) => $m['subject'] . "\n" . $m['message'], array_filter( $this->mails, static fn( $m ) => ! str_contains( (string) $m['subject'], 'New order' ) ) ) );
	}

	// The order.

	public function test_the_feature_is_off_by_default_and_makes_no_order(): void {
		$this->settings( array( 'domain_restore_enabled' => 0 ) );
		$r = $this->expired();
		$this->assertNull( Restore::offer( $r ) );
		$this->assertWPError( Restore::create_order( $r, $this->user ) );
		ob_start();
		UnpaidView::overview( $r, array( 'dns_only' => false ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'To restore it, contact us.', $html );
		$this->assertStringNotContainsString( 'Restore domain', $html );
	}

	public function test_the_setting_exists_and_defaults_off(): void {
		$fields = apply_filters( 'beltoft_services_settings_fields', array() );
		$this->assertArrayHasKey( 'domain_restore_enabled', $fields );
		$this->assertSame( 0, $fields['domain_restore_enabled']['default'] );
		$this->assertSame( 'domains', $fields['domain_restore_enabled']['tab'] );
	}

	public function test_an_expired_domain_gets_one_order_with_the_renewal_and_the_full_cost_fee(): void {
		$r      = $this->expired();
		$offer  = Restore::offer( $r );
		$this->assertSame( 13.0, $offer['renewal'] );
		// 43.08 is the full restore price, not the soft restore price 0: the most expensive case is assumed (owner 2026-11-27).
		$this->assertSame( 56.0, $offer['fee'] );
		$this->assertSame( 69.0, $offer['total'] );
		$order = Restore::create_order( $r, $this->user );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( $this->user, $order->get_customer_id() );
		$this->assertCount( 2, $order->get_items( 'fee' ) );
		$this->assertCount( 0, $order->get_items( 'line_item' ), 'No product line: nothing can provision a service.' );
		$roles  = array();
		foreach ( $order->get_items( 'fee' ) as $item ) {
			$roles[ $item->get_meta( Restore::LINE_META ) ] = (float) $item->get_total();
		}
		$this->assertSame( array( 'renewal' => 13.0, 'fee' => 56.0 ), $roles );
		$this->assertSame( 69.0, (float) $order->get_total() );
		$meta = $order->get_meta( Restore::ORDER_META );
		$this->assertSame( $r->id(), $meta['resource'] );
		$this->assertSame( 'restore-example.com', $meta['domain'] );
		$this->assertSame( (string) $r->get( 'expires_at' ), $meta['baseline'] );
		$this->assertStringContainsString( 'pay_for_order', $order->get_checkout_payment_url() . '' );
	}

	public function test_a_second_click_uses_the_same_unpaid_order_and_a_changed_price_replaces_it(): void {
		$r      = $this->expired();
		$first  = Restore::create_order( $r, $this->user );
		$r      = Resource::find( $r->id() );
		$again  = Restore::create_order( $r, $this->user );
		$this->assertSame( $first->get_id(), $again->get_id() );
		$this->settings( array( 'domain_reactivation_markup_percent' => 50 ) );
		$new = Restore::create_order( Resource::find( $r->id() ), $this->user );
		$this->assertNotSame( $first->get_id(), $new->get_id() );
		$this->assertSame( 'cancelled', wc_get_order( $first->get_id() )->get_status() );
		$this->assertSame( 64.62, round( (float) $new->get_meta( Restore::ORDER_META )['fee'], 2 ) );
	}

	public function test_only_an_expired_domain_of_the_customer_is_offered(): void {
		$active = $this->expired( 'active-example.com', gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ) );
		Licenses::update( (int) $active->license()['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->assertNull( Restore::offer( Resource::find( $active->id() ) ) );
		$due = $this->expired( 'due-example.com', gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) );
		$this->assertNull( Restore::offer( $due ), 'The domain still works: pay the renewal.' );
		$ended = $this->expired( 'ended-example.com', $this->expiry( 100 ) );
		$this->assertNull( Restore::offer( $ended ), 'The window is over.' );
		$mine = $this->expired();
		$this->assertWPError( Restore::create_order( $mine, self::factory()->user->create() ), 'Not another customer\'s domain.' );
	}

	public function test_the_order_is_refused_below_the_registrar_cost(): void {
		$table                = get_option( Prices::OPTION );
		$table['com']['price'] = 5;
		update_option( Prices::OPTION, $table );
		$this->settings( array( 'domain_reactivation_markup_percent' => 0 ) );
		$r = $this->expired();
		// 5 + 43.08 is below renewal cost 8 + restore cost 43.08.
		$this->assertWPError( Restore::create_order( $r, $this->user ) );
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( 'restore_order' ) );
	}

	public function test_an_unknown_restore_cost_offers_nothing(): void {
		$r = $this->expired( 'nocost-example.xx' );
		$this->assertNull( Restore::offer( $r ) );
	}

	public function test_the_order_is_in_the_customers_currency(): void {
		$r      = $this->expired();
		$method = new \ReflectionMethod( Restore::class, 'amounts' );
		$this->assertSame( 'EUR', $method->invoke( null, $r )['currency'] );
	}

	public function test_the_button_posts_to_a_payment_page_for_the_customer_only(): void {
		$r   = $this->expired();
		$bad = Restore::process( self::factory()->user->create(), (int) $r->license()['id'] );
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( 'That domain was not found.', $bad['message'] );
		$ok = Restore::process( $this->user, (int) $r->license()['id'] );
		$this->assertTrue( $ok['ok'], $ok['message'] );
		$this->assertStringContainsString( 'pay_for_order', $ok['redirect'] );
	}

	public function test_the_page_shows_the_restore_form_and_the_notice_link_points_at_the_page(): void {
		$r = $this->expired();
		ob_start();
		UnpaidView::overview( $r, array( 'dns_only' => false ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Restore domain', $html );
		$this->assertStringContainsString( 'name="beltoft_domain_restore"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'reactivation fee of', $html );
		$this->assertStringNotContainsString( 'contact us', $html );
		// N3 to N5 link here.
		$stage = \BeltoftServices\Domains\Stage::of( $r );
		$facts = Notices::facts( $r, 'N3', $stage, time() );
		$this->assertNotSame( '', $facts['restore_url'] );
		$this->assertSame( $facts['restore_url'], apply_filters( 'beltoft_services_domain_restore_url', '', $r ) );
		$this->settings( array( 'domain_restore_enabled' => 0 ) );
		$this->assertSame( '', Notices::facts( $r, 'N3', $stage, time() )['restore_url'] );
	}

	public function test_once_paid_the_page_says_so_and_offers_no_second_order(): void {
		$r = $this->expired();
		$this->paid_order( $r );
		$r = Resource::find( $r->id() );
		$this->assertNull( Restore::offer( $r ) );
		ob_start();
		UnpaidView::overview( $r, array( 'dns_only' => false ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'We received your payment and are restoring this domain.', $html );
		$this->assertStringNotContainsString( 'Restore domain', $html );
	}

	public function test_a_paid_restore_order_queues_the_job_once(): void {
		$r     = $this->expired();
		$order = $this->paid_order( $r );
		Restore::on_paid( $order->get_id() );
		Restore::on_paid( $order->get_id() );
		$jobs = as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		$mine = array_filter(
			$jobs,
			static function ( $id ) use ( $r ): bool {
				$args = \ActionScheduler::store()->fetch_action( $id )->get_args();
				return $r->id() === (int) $args[0] && 'restore' === $args[1];
			}
		);
		$this->assertCount( 1, $mine );
		$this->assertSame( 0, Restore::sweep(), 'The sweep leaves a job that is already waiting.' );
	}

	public function test_an_unpaid_order_queues_nothing(): void {
		$r     = $this->expired();
		$order = Restore::create_order( $r, $this->user );
		Restore::on_paid( $order->get_id() );
		$jobs = as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		$this->assertCount( 0, array_filter( $jobs, static fn( $id ) => $r->id() === (int) \ActionScheduler::store()->fetch_action( $id )->get_args()[0] ) );
	}

	// The job.

	public function test_an_order_that_is_not_paid_or_not_this_domains_fails_without_a_call(): void {
		$r     = $this->expired();
		$fake  = $this->fake();
		$order = Restore::create_order( $r, $this->user );
		$this->assertTrue( Restore::run( $r, (string) $order->get_id() )->is_failed(), 'Unpaid.' );
		$other = $this->expired( 'other-example.com' );
		$paid  = $this->paid_order( $other );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $paid->get_id() )->is_failed(), 'Another domain\'s order.' );
		$this->assertTrue( Restore::run( $r, 'abc' )->is_failed() );
		$this->assertSame( array(), $fake->calls() );
	}

	public function test_already_restored_finishes_without_calling_anything(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$next     = gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $next ) );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$this->assertCount( 1, $fake->calls() );
		$this->assertSame( 'GET', $fake->calls()[0]['method'] );
		$this->assertSame( $next, $result->meta()['expires_at'] );
		$this->assertSame( array( (string) $order->get_id() ), $result->meta()['restored_orders'] );
		$this->assertNotEmpty( $order->get_id() );
		$this->assertTrue( Restore::run( $r, 'x' )->is_failed() );
	}

	public function test_a_restore_that_moves_the_date_does_not_renew_again(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$next     = gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $next ) );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$posts = $this->calls( $fake, 'POST' );
		$this->assertCount( 1, $posts );
		$this->assertSame( '/domains/777/restore', $posts[0]['path'] );
		$this->assertSame( array( 'name' => 'restore-example', 'extension' => 'com' ), $posts[0]['body']['domain'] );
		$this->assertSame( array(), $result->meta()['restore_started'], 'The marker is freed when it is done.' );
		$this->assert_no_delete( $fake );
	}

	public function test_a_restore_that_leaves_the_date_waits_for_the_registry_and_buys_no_year_yet(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$first = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $first->is_retry() );
		$this->assertSame( 1800, $first->delay() );
		$this->assertSame( array( '/domains/777/restore' ), array_column( $this->calls( $fake, 'POST' ), 'path' ), 'No renew right after the restore.' );
		// A second run well inside the wait: the date is still the old one.
		$this->age_post( $r, $order, 1 );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$second = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $second->is_retry() );
		$this->assertSame( array( '/domains/777/restore' ), array_column( $this->calls( $fake, 'POST' ), 'path' ), 'Still no renew before 6 hours.' );
		$this->assert_no_delete( $fake );
	}

	public function test_after_the_wait_with_the_date_still_old_the_year_is_bought_once(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$next     = gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->age_post( $r, $order, 7 );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$fake->reply( 'POST', '/domains/777/renew', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $next ) );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$posts = $this->calls( $fake, 'POST' );
		$this->assertSame( array( '/domains/777/restore', '/domains/777/renew' ), array_column( $posts, 'path' ) );
		$this->assertSame( array( 'period' => 1 ), $posts[1]['body'] );
		$this->assert_no_delete( $fake );
	}

	public function test_a_date_that_moves_during_the_wait_finishes_without_renewing(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$next     = gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $next ) );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$this->assertSame( array( '/domains/777/restore' ), array_column( $this->calls( $fake, 'POST' ), 'path' ), 'The registry showed the year: no renew.' );
	}

	public function test_finishing_sets_the_dates_reactivates_the_subscription_marks_the_order_and_sends_n6(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		// A reminder was sent for this expiry: N6 follows.
		$r->set( array( Notices::LOG => array( gmdate( 'Y-m-d', strtotime( $baseline . ' UTC' ) ) . '|N3' => array( 'time' => time(), 'to' => 'x@example.com', 'tries' => 1, 'sent' => true, 'status' => 'sent' ) ) ) );
		$order = $this->paid_order( $r );
		$fake  = $this->fake();
		$next  = gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $next ) );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$sub = \BeltoftServices\Domains\Stage::subscription( Resource::find( $r->id() ) );
		$this->assertTrue( $sub->has_status( 'active' ), 'The restore is the payment of the held renewal.' );
		$this->assertGreaterThan( time(), (int) $sub->get_time( 'next_payment' ) );
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_beltoft_services_restore_done' ) );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => ! str_starts_with( (string) $m['subject'], '[' ) ), 'The customer gets notice N6.' );
	}

	public function test_a_timeout_on_the_restore_call_keeps_the_marker_and_never_posts_twice(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', new \WP_Error( 'transport', 'timeout' ) );
		$first = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $first->is_retry() );
		$this->assertSame( array( (string) $order->get_id() => $baseline ), Resource::find( $r->id() )->get( 'restore_started' ) ?: array(), 'The marker was written before the call and kept.' );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$second = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $second->is_retry() );
		$this->assertCount( 1, $this->calls( $fake, 'POST' ), 'No second POST while the first may have gone through.' );
		// The registrar later shows the restored domain: the job finishes without a call.
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) ) ) );
		$third = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $third->is_done(), (string) $third->error() );
		$this->assertCount( 1, $this->calls( $fake, 'POST' ) );
	}

	public function test_the_marker_is_written_before_the_post(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = new class( $r->id() ) extends FakeClient {
			public $seen = null;
			private int $rid;
			public function __construct( int $rid ) { parent::__construct(); $this->rid = $rid; }
			public function request( string $method, string $path, array $body = array(), array $query = array() ): array|\WP_Error {
				if ( 'POST' === $method ) { $this->seen = Resource::find( $this->rid )->get( 'restore_started' ); }
				return parent::request( $method, $path, $body, $query );
			}
		};
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', new \WP_Error( 'transport', 'timeout' ) );
		Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertEquals( array( (string) $order->get_id() => $baseline ), $fake->seen );
	}

	public function test_a_definite_refusal_frees_the_marker_and_the_next_run_asks_again(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400 ) ) );
		$first = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $first->is_retry() );
		$this->assertEmpty( Resource::find( $r->id() )->get( 'restore_started' ), 'Nothing was bought, so the marker is free.' );
		$this->assertStringContainsString( 'refused to restore', $this->staff_text(), 'Staff are told.' );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) ) ) );
		$second = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $second->is_done(), (string) $second->error() );
		$this->assertCount( 2, $this->calls( $fake, 'POST' ) );
	}

	public function test_a_lost_answer_after_the_restore_post_goes_on_to_the_renewal_without_posting_the_restore_again(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', new \WP_Error( 'transport', 'timeout' ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->age_post( $r, $order, 7 );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$fake->reply( 'POST', '/domains/777/renew', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) ) ) );
		$second = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $second->is_done(), (string) $second->error() );
		$this->assertSame( array( '/domains/777/restore', '/domains/777/renew' ), array_column( $this->calls( $fake, 'POST' ), 'path' ) );
	}

	public function test_a_timeout_on_the_renew_call_keeps_its_marker(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->age_post( $r, $order, 7 );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$fake->reply( 'POST', '/domains/777/renew', new \WP_Error( 'http_503', 'unavailable' ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->assertNotEmpty( Resource::find( $r->id() )->get( 'restore_renew_started' ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->assertCount( 2, $this->calls( $fake, 'POST' ), 'One restore and one renew, never a second renew.' );
	}

	public function test_a_refused_renew_frees_its_marker(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->age_post( $r, $order, 7 );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline ) );
		$fake->reply( 'POST', '/domains/777/renew', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400 ) ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->assertEmpty( Resource::find( $r->id() )->get( 'restore_renew_started' ) );
	}

	public function test_name_servers_are_put_back_only_when_they_differ(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$next     = gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$fake->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $next, 'ACT', array( 'name_servers' => array( array( 'name' => 'ina1.registrar.eu' ), array( 'name' => 'ina2.registrar.eu' ) ) ) ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_done() );
		$puts = $this->calls( $fake, 'PUT' );
		$this->assertCount( 1, $puts );
		$this->assertSame( '/domains/777', $puts[0]['path'] );
		$this->assertSame( array( array( 'name' => 'ns1.example.net' ), array( 'name' => 'ns2.example.net' ) ), $puts[0]['body']['name_servers'] );

		$r2     = $this->expired( 'same-ns-example.com' );
		$base2  = (string) $r2->get( 'expires_at' );
		$order2 = $this->paid_order( $r2 );
		$fake2  = $this->fake();
		$fake2->reply( 'GET', '/domains/777', $this->domain_reply( $base2, 'DEL' ) );
		$fake2->reply( 'POST', '/domains/777/restore', array( 'success' => true ) );
		$fake2->reply( 'GET', '/domains/777', $this->domain_reply( gmdate( 'Y-m-d H:i:s', strtotime( $base2 . ' UTC +1 year' ) ), 'ACT', array( 'name_servers' => array( array( 'name' => 'NS2.example.net.' ), array( 'name' => 'ns1.example.net' ) ) ) ) );
		$this->assertTrue( Restore::run( Resource::find( $r2->id() ), (string) $order2->get_id() )->is_done() );
		$this->assertCount( 0, $this->calls( $fake2, 'PUT' ), 'The same servers in another order and case are the same.' );
	}

	public function test_dk_makes_no_registrar_call_and_tells_staff_to_restore_by_hand(): void {
		$r     = $this->expired( 'restore-example.dk' );
		$fake  = $this->fake();
		$order = $this->paid_order( $r );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array(), $fake->calls(), 'No registrar call at all, not even a read.' );
		$this->assertStringContainsString( 'Restore .dk by hand: restore-example.dk', $this->staff_text() );
		$this->assertTrue( (bool) ( $result->meta()[ Jobs::EXPECTED ] ?? false ) );
		$this->assertNotSame( '', (string) $result->meta()[ Jobs::TEXT ] );
	}

	public function test_dk_can_still_be_paid_for(): void {
		$r = $this->expired( 'pay-example.dk' );
		$this->assertNotNull( Restore::offer( $r ) );
		$this->assertSame( 22.83, Restore::offer( $r )['fee'] );
	}

	public function test_a_window_that_is_over_places_no_call_and_alerts_staff(): void {
		$r     = $this->expired( 'late-example.com', $this->expiry( 3 ) );
		$order = $this->paid_order( $r );
		$r->set( array( 'expires_at' => $this->expiry( 100 ) ) );
		$order->update_meta_data( Restore::ORDER_META, array_merge( $order->get_meta( Restore::ORDER_META ), array( 'baseline' => $this->expiry( 100 ) ) ) );
		$order->save();
		$fake = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $this->expiry( 100 ), 'DEL' ) );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_failed() );
		$this->assertCount( 0, $this->calls( $fake, 'POST' ) );
		$this->assertStringContainsString( 'Paid restore cannot be done', $this->staff_text() );
	}

	public function test_a_deleted_domain_places_no_call(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL', array( 'is_deleted' => true ) ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_failed() );
		$this->assertCount( 0, $this->calls( $fake, 'POST' ) );
	}

	public function test_the_guard_refuses_an_order_paid_below_the_cost(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$order    = $this->paid_order( $r );
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( 'fee' === $item->get_meta( Restore::LINE_META ) ) {
				$item->set_total( 1.0 );
			}
		}
		$order->save();
		$fake = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $baseline, 'DEL' ) );
		$result = Restore::run( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'The order was paid below the registrar cost.', $result->error() );
		$this->assertCount( 0, $this->calls( $fake, 'POST' ) );
		$this->assertEmpty( Resource::find( $r->id() )->get( 'restore_started' ), 'No marker without a call.' );
	}

	public function test_the_guard_counts_the_full_restore_cost_not_the_soft_price(): void {
		$this->assertNull( \BeltoftServices\Domains\SpendGuard::problem_restore_amounts( 'a.com', 51.08, 'EUR' ), '8 renewal plus 43.08 restore.' );
		$this->assertNotNull( \BeltoftServices\Domains\SpendGuard::problem_restore_amounts( 'a.com', 50.0, 'EUR' ) );
		$this->assertSame( 'The registrar cost to restore this domain is unknown.', \BeltoftServices\Domains\SpendGuard::problem_restore_amounts( 'a.xx', 999.0, 'EUR' ) );
		$this->assertNotNull( \BeltoftServices\Domains\SpendGuard::problem_restore_amounts( 'a.com', 999.0, 'DKK' ), 'No guard rate for kroner: not compared, refused.' );
	}

	public function test_another_orders_marker_blocks_a_second_restore_of_the_same_domain(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$r->set( array( 'restore_started' => array( '999999' => $baseline ) ) );
		$order = $this->paid_order( Resource::find( $r->id() ) );
		$fake  = $this->fake();
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$this->assertSame( array(), $fake->calls() );
	}

	public function test_a_second_paid_order_for_a_restored_domain_buys_nothing_and_tells_staff(): void {
		$r        = $this->expired();
		$baseline = (string) $r->get( 'expires_at' );
		$next     = gmdate( 'Y-m-d H:i:s', strtotime( $baseline . ' UTC +1 year' ) );
		$order    = $this->paid_order( $r );
		$fake     = $this->fake();
		$fake->reply( 'GET', '/domains/777', $this->domain_reply( $next ) );
		$this->assertTrue( Restore::run( Resource::find( $r->id() ), (string) $order->get_id() )->is_done() );
		$this->assertCount( 0, $this->calls( $fake, 'POST' ) );
		$this->assertStringContainsString( 'already restored', $this->staff_text() );
	}

	public function test_the_job_is_an_internal_action_of_the_domain_driver(): void {
		$this->assertContains( 'restore', ( new DomainDriver() )->internal_actions() );
	}

	// A late paid renewal.

	/** @return array{0: Resource, 1: \WC_Order} A domain with a paid normal renewal order, the registrar date being $expires. */
	private function paid_renewal( string $expires ): array {
		$product = $this->service_product( 'domain' );
		$user    = self::factory()->user->create();
		$sub     = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'currency' => 'EUR' ) );
		$sub->add_product( $product, 1 );
		$sub->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'late-example.com', 'remote_id' => '123', 'expires_at' => $expires, 'state' => 'ready' ) );
		$order   = wcs_create_renewal_order( $sub );
		$order->set_currency( 'EUR' );
		$order->remove_order_items( 'line_item' );
		$order->add_product( $product, 1, array( 'subtotal' => 13, 'total' => 13 ) );
		$order->set_status( 'processing' );
		$order->save();

		return array( $r, $order );
	}

	public function test_a_paid_normal_renewal_after_the_expiry_buys_nothing_and_tells_staff(): void {
		$expires = $this->expiry( 3 );
		list( $r, $order ) = $this->paid_renewal( $expires );
		$fake = $this->fake();
		$fake->reply( 'GET', '/domains/123', array( 'status' => 'ACT', 'renewal_date' => $expires ) );
		$result = Renewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $result->is_failed(), 'It stops, it does not retry the renew every hour.' );
		$this->assertCount( 0, $this->calls( $fake, 'POST' ) );
		$this->assertTrue( (bool) ( $result->meta()[ Jobs::EXPECTED ] ?? false ) );
		$this->assertStringContainsString( 'Paid renewal arrived after the domain expired', $this->staff_text() );
		$this->assertStringContainsString( 'Renewal order: ' . $order->get_id(), $this->staff_text() );
		$this->assert_no_delete( $fake );
	}

	public function test_a_paid_renewal_before_the_expiry_is_bought_as_before(): void {
		$expires = gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS );
		list( $r, $order ) = $this->paid_renewal( $expires );
		$fake = $this->fake();
		$fake->reply( 'GET', '/domains/123', array( 'status' => 'ACT', 'renewal_date' => $expires ) );
		$fake->reply( 'POST', '/domains/123/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/123', array( 'status' => 'ACT', 'renewal_date' => gmdate( 'Y-m-d H:i:s', time() + 385 * DAY_IN_SECONDS ) ) );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_done() );
		$this->assertCount( 1, $this->calls( $fake, 'POST' ) );
	}

	public function test_with_the_feature_off_a_late_renewal_behaves_as_before(): void {
		$this->settings( array( 'domain_restore_enabled' => 0 ) );
		$expires = $this->expiry( 3 );
		list( $r, $order ) = $this->paid_renewal( $expires );
		$fake = $this->fake();
		$fake->reply( 'GET', '/domains/123', array( 'status' => 'ACT', 'renewal_date' => $expires ) );
		$fake->reply( 'POST', '/domains/123/renew', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400 ) ) );
		$result = Renewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $result->is_retry() );
		$this->assertCount( 1, $this->calls( $fake, 'POST' ) );
	}

	public function test_the_fake_registrar_model_restores_a_domain(): void {
		delete_option( FakeClient::OPTION );
		$client  = new FakeClient( true );
		$created = $client->request( 'POST', '/domains', array( 'domain' => array( 'name' => 'fake-restore-example', 'extension' => 'com' ), 'owner_handle' => 'FAKEOWNER-XX' ) );
		$reply   = $client->request( 'POST', '/domains/' . (int) $created['id'] . '/restore', array( 'domain' => array( 'name' => 'fake-restore-example', 'extension' => 'com' ) ) );
		$this->assertIsArray( $reply );
		$this->assertSame( 'ACT', $reply['status'] );
	}
}
