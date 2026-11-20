<?php
/**
 * The last step of a webhotel's process line, and what the customer is told when the domain joins or the wait ends.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\HostingView;
use BeltoftServices\Hosting\SiteDomainStep;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteField;
use BeltoftServices\Hosting\SiteNewDomain;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\ThankYou;

/**
 * Fake node, agent and DNS; mail is captured, never sent.
 */
final class SiteDomainStepTest extends HostingCase {

	/**
	 * Mails captured.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;

				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );

		parent::tear_down();
	}

	/**
	 * A hosting account and a domain of one order, same customer (as in CheckoutTest).
	 *
	 * @return array{0: Resource, 1: Resource}
	 */
	private function order_with_domain( string $name ): array {
		$product = $this->service_product( 'hosting', array( 'node_group' => 'g1', 'sites' => 3, 'disk_gb' => 10, 'cores' => 2, 'memory_mb' => 2048 ), array( 'slug' => 'hosting-' . wp_generate_password( 5, false ) ) );
		$user    = self::factory()->user->create();
		$item    = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		$item->add_meta_data( SiteField::ITEM_META, array( 'slug' => 'n' . wp_generate_password( 5, false, false ), 'label' => $name, 'mode' => 'new', 'domain' => $name, 'install' => 'wordpress', 'language' => 'en_US', 'admin_email' => '' ) );
		$order = wc_create_order();
		$order->add_item( $item );
		$order->save();

		$license = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $user ) );
		$meta    = SiteField::initial_meta( array(), $license, Registry::get( 'hosting' ) );
		$host    = Resource::create( $license, Registry::get( 'hosting' ), Product::plan( $product->get_id() ), $meta );
		$this->provision_fully( $host );

		$dp     = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$dl     = $this->service_license( $dp, array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $user ) );
		$domain = Resource::create( $dl, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net' ) );

		return array( Resource::find( $host->id() ), $domain );
	}

	/**
	 * The first mail as one line of plain words (a plain-text mail wraps its lines).
	 */
	private function mail_text(): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $this->mails[0]['message'] ) ) );
	}

	private function ready( Resource $r ): void {
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		SiteNewDomain::on_state_changed( Resource::find( $r->id() ), 'provisioning', 'ready' );
	}

	/**
	 * The thank-you row of an account.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, mixed>
	 */
	private function row( Resource $r ): array {
		return ThankYou::row( Resource::find( $r->id() ) );
	}

	/**
	 * The last step of a row, or null.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, string>|null
	 */
	private function last( array $row ): ?array {
		$steps = (array) $row['progress']['steps'];

		return $steps ? end( $steps ) : null;
	}

	public function test_a_building_account_shows_connect_domain_as_the_last_step_still_to_do(): void {
		list( $host ) = $this->order_with_domain( 'fresh.example' );
		$host->set( array( 'state' => 'provisioning', 'steps' => array() ) );
		$last = $this->last( $this->row( $host ) );

		$this->assertSame( SiteDomainStep::CONNECT, $last['key'] );
		$this->assertSame( 'Connect domain', $last['label'] );
		$this->assertContains( $last['status'], array( 'todo' ) );
	}

	public function test_a_ready_account_waits_on_the_domain_with_all_its_own_steps_done_and_keeps_polling(): void {
		list( $host ) = $this->order_with_domain( 'fresh.example' );
		$this->ready( $host );
		$row   = $this->row( $host );
		$steps = $row['progress']['steps'];

		$this->assertSame( 'current', $this->last( $row )['status'] );
		$this->assertSame( array(), array_filter( array_slice( $steps, 0, -1 ), static fn( $s ) => 'done' !== $s['status'] ) );
		$this->assertTrue( $row['progress']['active'], 'The line keeps running until the domain joined.' );
		$this->assertTrue( $row['ready'] );
		$this->assertStringContainsString( 'fresh.example', $row['message'] );
	}

	public function test_a_pending_domain_has_no_link_and_no_connect_by_hand_note(): void {
		list( $host ) = $this->order_with_domain( 'fresh.example' );
		$this->ready( $host );
		$row = $this->row( $host );

		$this->assertSame( 'current', $this->last( $row )['status'] );
		$this->assertEmpty( $row['progress']['link'], 'A domain that joins by itself has no link.' );
		$this->assertStringNotContainsString( 'Connect it from your hosting page', wp_json_encode( $row['progress'] ) );
	}

	public function test_a_joined_domain_ticks_the_step(): void {
		list( $host, $domain ) = $this->order_with_domain( 'fresh.example' );
		$this->ready( $host );
		$this->ready( $domain );
		$row = $this->row( $host );

		$this->assertSame( 'joined', Resource::find( $host->id() )->get( SiteNewDomain::META_DONE ) );
		$this->assertSame( 'done', $this->last( $row )['status'] );
	}

	public function test_an_expired_wait_ends_neutral_with_the_text_and_a_link(): void {
		list( $host ) = $this->order_with_domain( 'late.example' );
		$this->ready( $host );
		$host->set( array( SiteNewDomain::META_UNTIL => time() - 5 ) );
		$row  = $this->row( $host );
		$last = $this->last( $row );

		$this->assertSame( 'skipped', $last['status'] );
		$this->assertNotSame( 'failed', $last['status'] );
		$this->assertSame( 'Not connected', $last['status_label'] );
		$this->assertStringContainsString( 'was not ready within 7 days', $row['progress']['note'] );
		$this->assertFalse( $row['progress']['active'] );
	}

	public function test_an_own_domain_has_point_your_domain_here_done_when_its_records_are_found(): void {
		$host = $this->resource( array(), array( 'site' => array( 'slug' => 'own', 'domain' => 'mine.example', 'install' => true ) ) );
		$this->provision_fully( $host );
		$host->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$site = \BeltoftServices\Hosting\Sites::for_resource( $host->id() )[0] ?? null;
		SiteDomains::add( $host->id(), (int) ( $site['id'] ?? 1 ), 'mine.example', 'external' );

		$own = $this->row( $host );
		$this->assertSame( 'point your domain here', $own['waits'], 'The row says what waits on the customer.' );
		$this->assertFalse( $own['progress']['active'], 'A step the customer does is not polled.' );
		$this->assertSame( 'waiting', \BeltoftServices\Account\OverviewOrder::state( array( $own ) ) );

		$last = $this->last( $this->row( $host ) );
		$this->assertSame( SiteDomainStep::POINT, $last['key'] );
		$this->assertSame( 'Point your domain here', $last['label'] );
		$this->assertSame( 'current', $last['status'] );

		SiteDomains::set_state( 'mine.example', 'active' );
		$this->assertSame( 'done', $this->last( $this->row( $host ) )['status'] );
		$this->assertArrayNotHasKey( 'waits', $this->row( $host ) );
	}

	public function test_an_account_without_a_domain_step_is_left_alone(): void {
		$host = $this->resource();
		$this->provision_fully( $host );
		$host->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$row = $this->row( $host );

		$this->assertNotContains( SiteDomainStep::CONNECT, array_column( $row['progress']['steps'], 'key' ) );
		$this->assertNotContains( SiteDomainStep::POINT, array_column( $row['progress']['steps'], 'key' ) );
	}

	public function test_the_hosting_page_progress_is_not_touched(): void {
		list( $host ) = $this->order_with_domain( 'fresh.example' );
		$this->ready( $host );

		$this->assertNotContains( SiteDomainStep::CONNECT, array_column( \BeltoftServices\Progress::build( Resource::find( $host->id() ) )['steps'], 'key' ) );
	}

	public function test_the_customer_gets_one_email_and_a_notice_when_the_domain_joined(): void {
		list( $host, $domain ) = $this->order_with_domain( 'fresh.example' );
		$this->ready( $host );
		$this->ready( $domain );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'now runs on fresh.example', $this->mail_text() );
		$this->assertStringContainsString( 'login address is now on the domain', $this->mail_text() );
		$this->assertStringContainsString( 'https certificate follows', $this->mail_text() );

		$host = Resource::find( $host->id() );
		SiteNewDomain::sweep( $host );
		SiteNewDomain::attempt( $host );
		$this->assertCount( 1, $this->mails, 'Told once.' );

		$texts = array_column( HostingView::notices( $host ), 'text' );
		$this->assertContains( SiteNewDomain::joined_text( $host ), $texts );
		$this->assertStringContainsString( 'fresh.example', SiteNewDomain::joined_text( $host ) );

		$host->set( array( SiteNewDomain::META_AT => time() - 15 * DAY_IN_SECONDS ) );
		$this->assertSame( '', SiteNewDomain::joined_text( Resource::find( $host->id() ) ), 'The notice goes after two weeks.' );
	}

	public function test_an_expired_wait_gets_the_hint_and_one_email_from_the_sweep(): void {
		list( $host ) = $this->order_with_domain( 'late.example' );
		$this->ready( $host );
		$this->assertSame( '', SiteNewDomain::hint( Resource::find( $host->id() ) ), 'Still waiting: no hint.' );

		$host->set( array( SiteNewDomain::META_UNTIL => time() - 5 ) );
		$this->assertStringContainsString( 'late.example was not ready within 7 days', SiteNewDomain::hint( Resource::find( $host->id() ) ), 'The hint shows as soon as the deadline passed.' );

		SiteNewDomain::sweep( Resource::find( $host->id() ) );
		SiteNewDomain::sweep( Resource::find( $host->id() ) );

		$this->assertSame( 'expired', Resource::find( $host->id() )->get( SiteNewDomain::META_DONE ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'was not ready within 7 days', $this->mail_text() );
		$this->assertContains( SiteNewDomain::hint( Resource::find( $host->id() ) ), array_column( HostingView::notices( Resource::find( $host->id() ) ), 'text' ) );
	}

	public function test_an_old_order_is_closed_silently_by_the_sweep(): void {
		list( $host, $domain ) = $this->order_with_domain( 'old.example' );
		$this->ready( $host );
		$host->set( array( SiteNewDomain::META_UNTIL => time() - 90 * DAY_IN_SECONDS ) );

		SiteNewDomain::sweep( Resource::find( $host->id() ) );

		$this->assertSame( 'expired', Resource::find( $host->id() )->get( SiteNewDomain::META_DONE ) );
		$this->assertCount( 0, $this->mails, 'A months-old order is not mailed.' );
	}

	public function test_the_expired_text_and_mail_follow_registration_or_transfer(): void {
		list( $host, $domain ) = $this->order_with_domain( 'late.example' );
		$this->ready( $host );
		$host->set( array( SiteNewDomain::META_UNTIL => time() - 5 ) );
		$this->assertStringContainsString( 'when the registration is done', SiteNewDomain::hint( Resource::find( $host->id() ) ) );

		SiteNewDomain::sweep( Resource::find( $host->id() ) );
		$this->assertStringContainsString( 'when the registration is done', $this->mail_text() );
		$this->assertStringNotContainsString( 'transfer', $this->mail_text() );

		$domain->set( array( 'mode' => 'transfer' ) );
		$this->assertStringContainsString( 'when the transfer is done', SiteNewDomain::hint( Resource::find( $host->id() ) ) );
	}

	public function test_the_new_strings_have_no_em_dash_en_dash_or_middle_dot(): void {
		foreach ( array( 'src/Hosting/SiteDomainStep.php', 'src/Hosting/SiteNewDomain.php', 'src/Account/OverviewOrder.php', 'templates/account/overview/new-order.php', 'templates/order/handoff.php', 'templates/emails/beltoft-services-hosting-notice.php' ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/[\x{2014}\x{2013}\x{00B7}]/u', (string) file_get_contents( BELTOFT_SERVICES_PATH . $file ), $file );
		}
	}
}
