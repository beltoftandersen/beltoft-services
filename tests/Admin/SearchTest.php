<?php
/**
 * The search of WooCommerce → Services.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Admin;

use BeltoftServices\Admin\ListTable;
use BeltoftServices\Admin\Screen;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Search by address, name, customer, domain and number, with and without deleted services.
 */
final class SearchTest extends TestCase {

	public function tear_down(): void {
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * A service of a new customer.
	 *
	 * @param array<string, mixed> $meta Resource meta.
	 * @param array<string, mixed> $user User fields.
	 * @param array<string, mixed> $lic  License fields.
	 * @return Resource
	 */
	private function service( array $meta = array(), array $user = array(), array $lic = array() ): Resource {
		$fake    = $this->fake_driver( 'fake' );
		$product = $this->service_product( 'fake', array(), array( 'slug' => 'svc-fake' ) );
		$uid     = self::factory()->user->create( $user );

		if ( isset( $user['billing'] ) ) {
			foreach ( $user['billing'] as $key => $value ) {
				update_user_meta( $uid, 'billing_' . $key, $value );
			}
		}

		$license  = $this->service_license( $product, array_merge( array( 'fire' => false, 'user_id' => $uid ), $lic ) );
		$resource = Resource::create( $license, $fake, array( 'ram' => 2 ), array_merge( array( 'state' => 'ready' ), $meta ) );

		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	/**
	 * Ids the search finds among the given ones (the shared tables hold rows of other runs).
	 *
	 * @param string               $term    Search text.
	 * @param array<string, mixed> $filters More filters.
	 * @return int[]
	 */
	private function found( string $term, array $filters = array() ): array {
		$ids = array_map( static fn( Resource $r ) => $r->id(), Screen::resources( array_merge( array( 's' => $term ), $filters ) ) );

		return array_values( $ids );
	}

	public function test_ip_address_is_exact_and_finds_deleted_holders_only_when_asked(): void {
		$old  = $this->service( array( 'ip' => '203.0.113.164' ) );
		$near = $this->service( array( 'ip' => '203.0.113.1641' ) );
		$v6   = $this->service( array( 'ip6' => '2001:db8::1' ) );
		$old->close();
		$now = $this->service( array( 'ip' => '203.0.113.164' ) );

		$this->assertSame( array( $now->id() ), $this->found( '203.0.113.164' ), 'Deleted holders are left out by default.' );
		$this->assertSame( array( $now->id(), $old->id() ), $this->found( '203.0.113.164', array( 'deleted' => true ) ) );
		$this->assertNotContains( $near->id(), $this->found( '203.0.113.16' ), 'A piece of an address is not an address.' );
		$this->assertSame( array( $v6->id() ), $this->found( '2001:0db8:0000:0000:0000:0000:0000:0001' ), 'IPv6 in another spelling.' );
	}

	public function test_names_uuid_and_hostname(): void {
		$a = $this->service( array( 'name' => 'Mail relay Zebra', 'uuid' => 'abcdef12-0000-4000-8000-000000000001' ) );
		$b = $this->service( array( 'hostname' => 'web-zebra-7.example.net' ) );

		$this->assertContains( $a->id(), $this->found( 'relay zebra' ) );
		$this->assertContains( $a->id(), $this->found( 'vm-abcdef12' ) );
		$this->assertContains( $b->id(), $this->found( 'web-zebra-7' ) );
		$this->assertContains( $a->id(), $this->found( 'zebra' ) );
		$this->assertContains( $b->id(), $this->found( 'zebra' ) );
	}

	public function test_non_ascii_names(): void {
		$a = $this->service( array( 'name' => 'Server for Blåbær' ) );

		$this->assertContains( $a->id(), $this->found( 'Blåbær' ) );
	}

	public function test_customer_by_email_login_display_name_billing_and_user_id(): void {
		$a = $this->service(
			array(),
			array(
				'user_login'   => 'quokka_login',
				'user_email'   => 'quokka.owner@example.org',
				'display_name' => 'Quokka Display',
				'billing'      => array(
					'first_name' => 'Greta',
					'last_name'  => 'Fjordsen',
					'company'    => 'Fjordsen Holding ApS',
				),
			)
		);

		foreach ( array( 'quokka.owner@exa', 'quokka_login', 'Quokka Display', 'Greta', 'Fjordsen Holding', 'Greta Fjordsen' ) as $term ) {
			$this->assertContains( $a->id(), $this->found( $term ), $term );
		}

		$user_id = (int) $a->license()['user_id'];
		$this->assertContains( $a->id(), $this->found( (string) $user_id ) );
	}

	public function test_license_email_on_the_license_itself(): void {
		$a = $this->service( array(), array(), array( 'email' => 'billing.copy@example.net' ) );

		$this->assertContains( $a->id(), $this->found( 'billing.copy@' ) );
	}

	public function test_domains_in_meta_and_the_activation_domain(): void {
		$a = $this->service( array( 'domains' => array( 'shop.kiwi-example.dk', 'mail.kiwi-example.dk' ), 'zone' => 'kiwi-zone.test' ) );

		$this->assertContains( $a->id(), $this->found( 'shop.kiwi-example.dk' ) );
		$this->assertContains( $a->id(), $this->found( 'kiwi-zone.test' ) );
		$this->assertContains( $a->id(), $this->found( (string) $a->get( 'uuid' ) ), 'The uuid.' );
	}

	public function test_numbers(): void {
		$a = $this->service( array( 'vmid' => 987654 ) );
		$b = $this->service( array( 'vmid' => '9876541' ) );

		$license = $a->license();

		$this->assertContains( $a->id(), $this->found( (string) $a->id() ), 'Resource id.' );
		$this->assertContains( $a->id(), $this->found( (string) $license['id'] ), 'License id.' );
		$this->assertContains( $a->id(), $this->found( '987654' ), 'VM id.' );
		$this->assertNotContains( $b->id(), $this->found( '987654' ), 'A VM id is exact.' );

		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'order_id' => 4455001, 'subscription_id' => 4455002 ), array( 'id' => (int) $license['id'] ) );
		$this->assertContains( $a->id(), $this->found( '4455001' ), 'Order id.' );
		$this->assertContains( $a->id(), $this->found( '4455002' ), 'Subscription id.' );
	}

	public function test_no_match_and_empty_search(): void {
		$a = $this->service( array( 'ip' => '203.0.113.50' ) );

		$this->assertSame( array(), $this->found( 'no-such-thing-xyzzy' ) );
		$this->assertContains( $a->id(), array_map( static fn( Resource $r ) => $r->id(), Screen::resources( array( 's' => '' ) ) ), 'No search is the plain list.' );
	}

	public function test_driver_and_state_filters_combine_with_the_search(): void {
		$ok     = $this->service( array( 'name' => 'Combo thing', 'state' => 'ready' ) );
		$failed = $this->service( array( 'name' => 'Combo other', 'state' => 'failed' ) );

		$this->assertEqualsCanonicalizing( array( $ok->id(), $failed->id() ), array_values( array_intersect( $this->found( 'Combo' ), array( $ok->id(), $failed->id() ) ) ) );
		$this->assertSame( array( $failed->id() ), $this->found( 'Combo', array( 'failed' => true ) ) );
		$this->assertSame( array(), $this->found( 'Combo', array( 'driver' => 'nonexistent' ) ) );
	}

	public function test_page_counts_and_pages_the_matches(): void {
		$a = $this->service( array( 'name' => 'Pagey one' ) );
		$b = $this->service( array( 'name' => 'Pagey two' ) );

		$page = Screen::page( array( 's' => 'Pagey' ), 0, 1 );
		$this->assertSame( 2, $page['total'] );
		$this->assertSame( array( $b->id() ), array_map( static fn( Resource $r ) => $r->id(), $page['items'] ) );
		$this->assertSame( array( $a->id() ), array_map( static fn( Resource $r ) => $r->id(), Screen::page( array( 's' => 'Pagey' ), 1, 1 )['items'] ) );
	}

	public function test_filters_clean_the_request(): void {
		$_GET = array(
			's'       => "  <b>x</b> 1.2.3.4  ",
			'deleted' => '1',
		);
		$f    = Screen::filters();

		$this->assertSame( 'x 1.2.3.4', $f['s'] );
		$this->assertTrue( $f['deleted'] );

		$_GET = array( 's' => array( 'x' ) );
		$this->assertSame( '', Screen::filters()['s'] );
		$this->assertFalse( Screen::filters()['deleted'] );
	}

	public function test_sql_wildcards_and_quotes_are_literal(): void {
		$a = $this->service( array( 'name' => 'plain name' ) );

		$this->assertNotContains( $a->id(), $this->found( 'p%n' ) );
		$this->assertNotContains( $a->id(), $this->found( 'p_ain' ) );
		$this->assertSame( array(), $this->found( "x' OR '1'='1" ) );
	}

	public function test_the_table_shows_customer_ip_and_the_deleted_mark_and_escapes_the_term(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_current_screen( 'woocommerce_page_' . Screen::PAGE );

		$a = $this->service(
			array( 'ip' => '203.0.113.77', 'ip6' => '2001:db8::77' ),
			array( 'billing' => array( 'first_name' => 'Tabita', 'last_name' => 'Rasa' ) ),
			array( 'email' => 'tablecustomer@example.org' )
		);
		$b = $this->service( array( 'ip' => '203.0.113.77' ) );
		$b->close();

		$_GET = array( 's' => '203.0.113.77', 'deleted' => '1' );
		$t    = new ListTable();
		$t->prepare_items();
		ob_start();
		$t->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Tabita Rasa', $html );
		$this->assertStringContainsString( 'tablecustomer@example.org', $html );
		$this->assertStringContainsString( esc_url( get_edit_user_link( (int) $a->license()['user_id'] ) ), $html );
		$this->assertStringContainsString( '2001:db8::77', $html );
		$this->assertStringContainsString( 'bs-deleted', $html );
		$this->assertSame( 1, substr_count( $html, 'bs-deleted' ), 'Only the closed one is marked.' );

		$_GET = array( 's' => '"><script>alert(1)</script>', 'deleted' => '1' );
		$t    = new ListTable();
		$t->prepare_items();
		ob_start();
		$t->extra_tablenav( 'top' );
		$t->display();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'name="deleted"', $html );
		$this->assertStringContainsString( 'No services found.', $html );

		wp_set_current_user( $admin );
		ob_start();
		Screen::render();
		$page = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="&quot;&gt;"', $page, 'Tags are stripped and the rest comes back escaped in the box.' );
		$this->assertStringNotContainsString( '<script>alert', $page );
	}

	public function test_render_prints_the_search_box_for_admins_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Screen::render();
		$this->assertSame( '', (string) ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'woocommerce_page_' . Screen::PAGE );
		ob_start();
		Screen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="s"', $html );
	}
}
