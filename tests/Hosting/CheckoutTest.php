<?php
/**
 * The "Your website" checkout step and upgrade-only plan changes of hosting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\HostingSwitch;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteField;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Product;
use BeltoftServices\Resource;

/**
 * SiteField and HostingSwitch.
 */
class CheckoutTest extends HostingCase {

	public function tear_down(): void {
		unset( $_POST[ SiteField::POST_KEY ] );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		parent::tear_down();
	}

	/**
	 * A hosting product.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function product( array $plan = array() ): \WC_Product {
		return $this->service_product( 'hosting', array_merge( array( 'node_group' => 'g1', 'sites' => 3, 'disk_gb' => 10, 'cores' => 2, 'memory_mb' => 2048 ), $plan ), array( 'slug' => 'wp-' . wp_generate_password( 4, false ) ) );
	}

	private function in_cart( \WC_Product $product ): string {
		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart( $product->get_id() );

		if ( ! $key ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		return (string) $key;
	}

	/**
	 * @param array<string, string> $values Posted values of one line.
	 */
	private function post( string $key, array $values ): void {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			do_action( 'woocommerce_checkout_process' );
		}

		$_POST[ SiteField::POST_KEY ] = array( $key => $values );
	}

	/**
	 * @return string[]
	 */
	private function errors(): array {
		$errors = new \WP_Error();
		SiteField::validate( array(), $errors );

		return $errors->get_error_messages();
	}

	public function test_only_hosting_lines_are_offered_the_step_and_a_renewal_asks_nothing(): void {
		$this->assertSame( array(), SiteField::cart_lines() );
		$this->assertTrue( SiteField::is_hosting_product( $this->product()->get_id() ) );
		$this->assertFalse( SiteField::is_hosting_product( $this->service_product( 'fake' )->get_id() ) );
		$this->assertFalse( SiteField::is_hosting_product( 0 ) );

		$key = $this->in_cart( $this->product() );
		$this->assertSame( array( $key ), array_keys( SiteField::cart_lines() ) );

		WC()->cart->cart_contents[ $key ]['subscription_switch'] = array( 'subscription_id' => 1 );
		$this->assertSame( array(), SiteField::cart_lines() );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_the_form_prints_the_fields_and_offers_our_domains_only_to_a_customer_who_has_some(): void {
		$product = $this->product();
		$this->in_cart( $product );

		ob_start();
		SiteField::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Your website', $html );
		$this->assertStringContainsString( 'Temporary address', $html );
		$this->assertStringContainsString( 'A domain I have elsewhere', $html );
		$this->assertStringNotContainsString( 'A domain I have with you', $html, 'A visitor has no domains on our name servers.' );
		$this->assertStringContainsString( '<option value="wordpress" selected', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );

		$this->in_cart( $this->product( array( 'sites' => 1, 'woocommerce_default' => true ) ) );
		ob_start();
		SiteField::render();
		$this->assertStringContainsString( '<option value="woocommerce" selected', (string) ob_get_clean(), 'WooCommerce is preselected on the shop plans.' );

		WC()->cart->empty_cart();
		ob_start();
		SiteField::render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_store_api_checkout_is_refused_for_a_hosting_cart(): void {
		$order = new \WC_Order();
		$req   = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		SiteField::block_store_api( $order, $req );
		$this->assertTrue( true, 'An empty cart is not touched.' );

		$this->in_cart( $this->product() );
		try {
			SiteField::block_store_api( $order, $req );
			$this->fail( 'The Store API checkout should be refused.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'beltoft_services_site_checkout_required', $e->error_code );
		}
	}

	public function test_resolve_checks_every_choice(): void {
		$ok = SiteField::resolve( array( 'slug' => 'Shop', 'label' => 'My <b>Shop</b>', 'mode' => 'preview', 'install' => 'woocommerce', 'language' => 'da_DK', 'email' => 'christian.beltoft@gmail.com' ), 0 );
		$this->assertSame(
			array( 'slug' => 'shop', 'label' => 'My Shop', 'mode' => 'preview', 'domain' => '', 'install' => 'woocommerce', 'language' => 'da_DK', 'admin_email' => 'christian.beltoft@gmail.com' ),
			$ok
		);

		$defaults = SiteField::resolve( array( 'slug' => 'a' ), 0 );
		$this->assertSame( 'wordpress', $defaults['install'] );
		$this->assertSame( 'en_US', $defaults['language'] );
		$this->assertSame( '', $defaults['admin_email'] );

		$bad = array(
			'slug'        => array( array( 'slug' => 'Bad Slug' ), 'site_slug' ),
			'no slug'     => array( array(), 'site_slug' ),
			'mode'        => array( array( 'slug' => 'a', 'mode' => 'weird' ), 'site_mode' ),
			'own empty'   => array( array( 'slug' => 'a', 'mode' => 'own', 'host' => '' ), 'site_host' ),
			'own bad'     => array( array( 'slug' => 'a', 'mode' => 'own', 'host' => 'not a host' ), 'site_host' ),
			'preview zone' => array( array( 'slug' => 'a', 'mode' => 'own', 'host' => 'x.preview.example.test' ), 'site_host' ),
			'ours no zone' => array( array( 'slug' => 'a', 'mode' => 'ours', 'host' => 'www.nowhere.example' ), 'site_zone' ),
			'install'     => array( array( 'slug' => 'a', 'install' => 'drupal' ), 'site_install' ),
			'empty site'  => array( array( 'slug' => 'a', 'install' => 'empty' ), 'site_install' ),
			'reserved'    => array( array( 'slug' => 'www' ), 'site_slug' ),
			'language'    => array( array( 'slug' => 'a', 'language' => 'english' ), 'site_language' ),
			'language unlisted' => array( array( 'slug' => 'a', 'language' => 'xx_XX' ), 'site_language' ),
			'email'       => array( array( 'slug' => 'a', 'email' => 'nope' ), 'site_email' ),
		);

		foreach ( $bad as $name => $case ) {
			$err = SiteField::resolve( $case[0], 0 );
			$this->assertWPError( $err, $name );
			$this->assertSame( $case[1], $err->get_error_code(), $name );
		}
	}

	public function test_a_taken_name_or_domain_is_refused(): void {
		Sites::add( 77, 'taken', 'T', '8.3', 2 );
		SiteDomains::add( 77, 1, 'used.example.com', 'external' );

		$this->assertSame( 'site_taken', SiteField::resolve( array( 'slug' => 'taken' ), 0 )->get_error_code() );
		$this->assertSame( 'site_host', SiteField::resolve( array( 'slug' => 'free', 'mode' => 'own', 'host' => 'used.example.com' ), 0 )->get_error_code() );
		$this->assertIsArray( SiteField::resolve( array( 'slug' => 'free', 'mode' => 'own', 'host' => 'Other.Example.com' ), 0 ) );
	}

	public function test_a_domain_on_our_name_servers_is_offered_and_checked_against_the_customers_zones(): void {
		$user    = self::factory()->user->create();
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$domain  = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'mine.example', 'mode' => 'register', 'zone_ready' => true ) );
		$domain->set( array( 'state' => 'ready' ) );
		$this->dns->create_zone( 'mine.example', array( 'ns1.example.net' ) );

		$this->assertSame( array( 'mine.example' ), SiteField::zones( $user ) );
		$this->assertSame( array(), SiteField::zones( 0 ) );

		$found = SiteField::resolve( array( 'slug' => 'a', 'mode' => 'ours', 'host' => 'www.mine.example' ), $user );
		$this->assertSame( 'www.mine.example', $found['domain'] );
		$this->assertSame( 'site_zone', SiteField::resolve( array( 'slug' => 'a', 'mode' => 'ours', 'host' => 'www.other.example' ), $user )->get_error_code() );

		wp_set_current_user( $user );
		$this->in_cart( $this->product() );
		ob_start();
		SiteField::render();
		$html = (string) ob_get_clean();
		wp_set_current_user( 0 );

		$this->assertStringContainsString( 'A domain I have with you', $html );
		$this->assertStringContainsString( '<option value="mine.example"', $html );
	}

	public function test_validate_reports_the_problems_per_line(): void {
		$key = $this->in_cart( $this->product() );

		$this->post( $key, array( 'slug' => '', 'mode' => 'preview' ) );
		$this->assertCount( 1, $this->errors() );

		$this->post( $key, array( 'slug' => 'good-name', 'mode' => 'preview' ) );
		$this->assertSame( array(), $this->errors() );

		WC()->cart->empty_cart();
		$first = (string) WC()->cart->add_to_cart( $this->product()->get_id(), 2 );
		$this->post( $first, array( 'slug' => 'good-name' ) );
		$this->assertStringContainsString( 'its own line', $this->errors()[0] );
	}

	public function test_the_site_is_saved_on_the_line_and_becomes_resource_meta(): void {
		$product = $this->product();
		$key     = $this->in_cart( $product );
		$this->post( $key, array( 'slug_domain' => 'saved', 'label' => 'Saved', 'mode' => 'own', 'host' => 'Saved.Example.com', 'install' => 'woocommerce', 'language' => 'da_DK', 'email' => '' ) );

		$item = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		SiteField::save_on_item( $item, $key );
		$this->assertSame( 'saved.example.com', $item->get_meta( SiteField::ITEM_META )['domain'] );

		$other = new \WC_Order_Item_Product();
		SiteField::save_on_item( $other, 'not-a-key' );
		$this->assertSame( '', (string) $other->get_meta( SiteField::ITEM_META ), 'Not a hosting line.' );

		$order = wc_create_order();
		$order->add_item( $item );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$meta    = SiteField::initial_meta( array(), $license, Registry::get( 'hosting' ) );

		$this->assertSame(
			array( 'slug' => 'saved', 'label' => 'Saved', 'install' => true, 'install_kind' => 'woocommerce', 'language' => 'da_DK', 'admin_email' => '', 'domain' => 'saved.example.com' ),
			$meta['site']
		);
		$this->assertSame( array( 'x' => 1 ), SiteField::initial_meta( array( 'x' => 1 ), $license, Registry::get( 'http' ) ), 'Other drivers are left alone.' );
	}

	public function test_two_lines_of_one_plan_take_one_site_each(): void {
		$product = $this->product();
		$order   = wc_create_order();

		foreach ( array( 'one', 'two' ) as $slug ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product_id( $product->get_id() );
			$item->add_meta_data( SiteField::ITEM_META, array( 'slug' => $slug ) );
			$order->add_item( $item );
		}

		$order->save();
		$first = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$meta1 = SiteField::initial_meta( array(), $first, Registry::get( 'hosting' ) );
		$this->assertSame( 'one', $meta1['site']['slug'] );
		Resource::create( $first, Registry::get( 'hosting' ), Product::plan( $product->get_id() ), $meta1 );

		$second = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$this->assertSame( 'two', SiteField::initial_meta( array(), $second, Registry::get( 'hosting' ) )['site']['slug'] );
	}

	public function test_provisioning_uses_the_order_site_attaches_an_external_domain_and_passes_the_language(): void {
		$r = $this->resource(
			array(),
			array(
				'site' => array( 'slug' => 'ordered', 'label' => 'Ordered', 'install' => true, 'language' => 'da_DK', 'admin_email' => 'christian.beltoft@gmail.com', 'domain' => 'www.elsewhere.example' ),
			)
		);
		$this->provision_fully( $r );

		$this->assertNotNull( Sites::find( $r->id(), 'ordered' ) );
		$install = $this->agent->calls_of( 'site.wp_install' )[0]['args'];
		$this->assertSame( 'da_DK', $install['locale'] );
		$this->assertSame( 'christian.beltoft@gmail.com', $install['admin_email'] );
		$this->assertSame( 'waiting', SiteDomains::find( 'www.elsewhere.example' )['state'], 'An external name waits for the customer.' );
	}

	public function test_provisioning_a_name_on_our_servers_goes_live_with_a_route(): void {
		$user = self::factory()->user->create();
		$p    = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$l    = $this->service_license( $p, array( 'fire' => false, 'user_id' => $user ) );
		$d    = Resource::create( $l, new DomainDriver(), array(), array( 'domain' => 'ours.example', 'mode' => 'register', 'zone_ready' => true ) );
		$d->set( array( 'state' => 'ready' ) );
		$this->dns->create_zone( 'ours.example', array( 'ns1.example.net' ) );

		$product = $this->product();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, Registry::get( 'hosting' ), Product::plan( $product->get_id() ), array( 'site' => array( 'slug' => 'live', 'domain' => 'www.ours.example' ) ) );
		$this->provision_fully( $r );

		$this->assertSame( 'active', SiteDomains::find( 'www.ours.example' )['state'] );
		$hosts = array_column( $this->agent->state()['edge'][ 'c' . $r->id() ], 'host' );
		$this->assertContains( 'www.ours.example', $hosts );
		$this->assertContains( 'live.preview.example.test', $hosts );
	}

	// -- Plan changes ---------------------------------------------------------------------------

	/**
	 * A provisioned account on a plan.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function account( array $plan = array() ): Resource {
		$r = $this->resource( array_merge( array( 'sites' => 3, 'php_workers' => 10 ), $plan ) );
		$this->provision_fully( $r );

		return Resource::find( $r->id() );
	}

	/**
	 * A typed target plan.
	 *
	 * @param array<string, mixed> $over Overrides of the account's plan.
	 * @return array<string, int|string|bool>
	 */
	private function target( Resource $r, array $over ): array {
		return \BeltoftServices\Hosting\Plan::typed( array_merge( (array) $r->get( 'plan' ), $over ) );
	}

	public function test_an_upgrade_is_allowed_and_a_smaller_plan_is_refused(): void {
		$r = $this->account( array( 'disk_gb' => 20, 'memory_mb' => 2048, 'cores' => 2 ) );

		$this->assertNull( HostingSwitch::refusal( $r, $this->target( $r, array( 'disk_gb' => 50, 'memory_mb' => 4096, 'cores' => 3, 'php_workers' => 20, 'sites' => 5 ) ) ) );
		$this->assertNull( HostingSwitch::refusal( $r, $this->target( $r, array() ) ), 'The same plan is fine.' );

		foreach ( array( 'disk_gb' => 10, 'memory_mb' => 1024, 'cores' => 1, 'php_workers' => 5 ) as $key => $less ) {
			$this->assertStringContainsString( "can't be made smaller", (string) HostingSwitch::refusal( $r, $this->target( $r, array( $key => $less ) ) ), $key );
		}

		$this->assertStringContainsString( 'different server group', (string) HostingSwitch::refusal( $r, $this->target( $r, array( 'node_group' => 'g9' ) ) ) );
	}

	public function test_a_name_chosen_at_checkout_is_held_for_that_customer(): void {
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();

		$first = SiteField::resolve( array( 'slug' => 'heldname' ), $a );
		$this->assertIsArray( $first );
		$this->assertSame( 'site_taken', SiteField::resolve( array( 'slug' => 'heldname' ), $b )->get_error_code(), 'Held for the first customer: the second cannot pay for it too.' );
		$this->assertIsArray( SiteField::resolve( array( 'slug' => 'heldname' ), $a ), 'The holder can submit again.' );

		// The hold lapses.
		$name = 'beltoft_services_slug_hold_' . substr( md5( 'heldname' ), 0, 20 );
		update_option( $name, array( 'o' => 'u' . $a, 't' => time() - 1 ), false );
		$this->assertIsArray( SiteField::resolve( array( 'slug' => 'heldname' ), $b ) );

		// Made for real, the hold is let go and the name is taken in the table.
		$r = $this->account();
		Sites::add( $r->id(), 'heldname', 'H', '8.3', 2 );
		$this->assertFalse( Sites::held_by_other( 'heldname', 'u' . $a ) );
		$this->assertSame( 'site_taken', SiteField::resolve( array( 'slug' => 'heldname' ), $a )->get_error_code() );
	}

	public function test_a_provision_whose_name_was_taken_meanwhile_starts_on_a_generated_name(): void {
		$other = $this->account();
		Sites::add( $other->id(), 'wanted', 'W', '8.3', 2 );

		$r = $this->resource( array(), array( 'site' => array( 'slug' => 'wanted', 'install' => true ) ) );
		$this->provision_fully( $r );

		$sites = Sites::for_resource( $r->id() );
		$this->assertCount( 1, $sites );
		$this->assertSame( 'w' . $r->id(), $sites[0]['slug'], 'The order is not failed over a name.' );
	}

	public function test_the_other_limits_may_not_go_down_either(): void {
		$r = $this->account( array( 'php_memory_mb' => 512, 'backup_days' => 14, 'valkey_mb' => 64, 'files' => 2000000, 'mail_mailboxes' => 5, 'upload_mb' => 100 ) );

		foreach ( array( 'php_memory_mb' => 256, 'backup_days' => 7, 'valkey_mb' => 0, 'files' => 1000, 'mail_mailboxes' => 2, 'upload_mb' => 50 ) as $key => $less ) {
			$this->assertStringContainsString( "can't be made smaller", (string) HostingSwitch::refusal( $r, $this->target( $r, array( $key => $less ) ) ), $key );
		}
	}

	public function test_an_upgrade_that_does_not_fit_the_accounts_node_is_refused_with_a_clear_message(): void {
		$r = $this->account( array( 'memory_mb' => 1024, 'disk_gb' => 10 ) );

		$this->assertNull( HostingSwitch::refusal( $r, $this->target( $r, array( 'memory_mb' => 2048 ) ) ), 'Room on the node.' );

		$node = (array) \BeltoftServices\Hosting\Nodes::get( 'n1' );
		$this->save_node( array( 'mem_gb' => (int) $node['mem_gb'] > 0 ? 1 : 1 ) ); // A node with no memory left after the reserve.
		$refusal = HostingSwitch::refusal( $r, $this->target( $r, array( 'memory_mb' => 8192 ) ) );

		$this->assertStringContainsString( 'cannot be added to your server', (string) $refusal );
		$this->assertNull( HostingSwitch::refusal( $r, $this->target( $r, array() ) ), 'No growth, no refusal.' );
	}

	public function test_fewer_websites_than_in_use_is_refused(): void {
		$r = $this->account( array( 'sites' => 3 ) );
		Sites::add( $r->id(), 'second', 'S', '8.3', 2 );

		$this->assertStringContainsString( 'fewer websites', (string) HostingSwitch::refusal( $r, $this->target( $r, array( 'sites' => 1 ) ) ) );
		$this->assertNull( HostingSwitch::refusal( $r, $this->target( $r, array( 'sites' => 2 ) ) ) );
	}

	public function test_the_wcs_filter_refuses_before_payment_and_leaves_other_products_alone(): void {
		$r        = $this->account( array( 'disk_gb' => 20, 'sites' => 3 ) );
		$small    = $this->product( array( 'disk_gb' => 10, 'sites' => 3 ) );
		$big      = $this->product( array( 'disk_gb' => 40, 'sites' => 3, 'valkey_mb' => 64 ) );
		$license  = $r->license();

		\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'subscription_id' => 4242 ) );
		$subscription = new class() {
			public function get_id() {
				return 4242;
			}
		};

		$message = HostingSwitch::check( '', $small->get_id(), 1, 0, $subscription, null );
		$this->assertStringContainsString( "can't be made smaller", $message );
		$this->assertSame( '', HostingSwitch::check( '', $big->get_id(), 1, 0, $subscription, null ) );
		$this->assertSame( 'Already refused', HostingSwitch::check( 'Already refused', $small->get_id(), 1, 0, $subscription, null ) );
		$this->assertSame( '', HostingSwitch::check( '', $this->service_product( 'fake' )->get_id(), 1, 0, $subscription, null ), 'Not a hosting product.' );
		$this->assertSame( '', HostingSwitch::check( '', $small->get_id(), 1, 0, 'not a subscription', null ) );
		$this->assertNotNull( Records::for_resource( $r->id() ) );
	}

	public function test_a_domain_order_needs_no_temporary_name_and_gets_a_unique_one(): void {
		$one = SiteField::resolve( array( 'mode' => 'own', 'host' => 'www.Example.com' ), 0 );
		$this->assertIsArray( $one );
		$this->assertSame( 'example-com', $one['slug'] );
		$this->assertSame( 'example.com', '' === $one['domain'] ? '' : preg_replace( '/^www\./', '', $one['domain'] ) );
		$this->assertSame( 'www.example.com', $one['label'], 'The title defaults to the domain.' );

		// The same person asking again keeps the name they hold.
		$this->assertSame( 'example-com', SiteField::resolve( array( 'mode' => 'own', 'host' => 'www.example.com' ), 0 )['slug'] );

		// A taken name gets a number.
		Sites::add( 78, 'shop-example-org', 'T', '8.3', 2 );
		$this->assertSame( 'shop-example-org-2', SiteField::resolve( array( 'mode' => 'own', 'host' => 'shop.example.org' ), 0 )['slug'] );

		// A long domain still makes a valid name.
		$long = SiteField::resolve( array( 'mode' => 'own', 'host' => 'a-very-long-subdomain-name.another-long-label.example.com' ), 0 );
		$this->assertTrue( Sites::valid_slug( $long['slug'] ) );

		// A name typed under "more options" is used as typed; a preview order still needs its name.
		$typed = SiteField::resolve( array( 'mode' => 'own', 'host' => 'typed.example.net', 'slug' => 'my-pick' ), 0 );
		$this->assertSame( 'my-pick', $typed['slug'] );
		$this->assertSame( 'site_slug', SiteField::resolve( array( 'mode' => 'preview' ), 0 )->get_error_code() );
	}

	public function test_each_address_option_posts_its_own_inputs(): void {
		$key = $this->in_cart( $this->product() );
		$this->post( $key, array( 'mode' => 'own', 'host' => 'posted.example.com', 'slug' => 'ignored', 'slug_domain' => '' ) );
		$this->assertSame( array(), $this->errors() );

		$product = $this->product();
		$key     = $this->in_cart( $product );
		$this->post( $key, array( 'mode' => 'own', 'host' => 'posted2.example.com', 'slug' => 'ignored', 'slug_domain' => 'chosen' ) );
		$item = new \WC_Order_Item_Product();
		SiteField::save_on_item( $item, $key );
		$this->assertSame( 'chosen', $item->get_meta( SiteField::ITEM_META )['slug'], 'The advanced name counts for a domain.' );

		$key = $this->in_cart( $this->product() );
		$this->post( $key, array( 'mode' => 'preview', 'slug' => 'plain', 'slug_domain' => 'ignored' ) );
		$item = new \WC_Order_Item_Product();
		SiteField::save_on_item( $item, $key );
		$this->assertSame( 'plain', $item->get_meta( SiteField::ITEM_META )['slug'] );
	}

	public function test_an_account_with_the_old_empty_install_still_works(): void {
		$product = $this->product();
		$order   = wc_create_order();
		$item    = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		$item->add_meta_data( SiteField::ITEM_META, array( 'slug' => 'oldempty', 'install' => 'empty', 'language' => 'da_DK' ) );
		$order->add_item( $item );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$meta    = SiteField::initial_meta( array(), $license, Registry::get( 'hosting' ) );

		$this->assertFalse( $meta['site']['install'] );
		$this->assertSame( 'empty', $meta['site']['install_kind'] );
		$this->assertContains( 'empty', SiteField::INSTALLS );
		$this->assertNotContains( 'empty', SiteField::OFFERED );
	}

	public function test_the_form_has_a_language_list_without_the_empty_site_and_explains_the_domain_step(): void {
		$this->in_cart( $this->product() );
		ob_start();
		SiteField::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Empty site', $html );
		$this->assertStringNotContainsString( 'value="empty"', $html );
		$this->assertStringContainsString( '<select name="beltoft_services_site[' . array_key_first( WC()->cart->get_cart() ) . '][language]"', $html );
		$this->assertStringContainsString( '<option value="en_US" selected', $html );
		$this->assertStringContainsString( '>Dansk</option>', $html );
		$this->assertStringContainsString( '>Deutsch</option>', $html );
		$this->assertStringContainsString( 'TXT record', $html );
		$this->assertStringContainsString( 'A record', $html );
		$this->assertStringContainsString( 'reachable at this address until your domain points to us', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
		$this->assertStringNotContainsString( 'Hetzner', $html );
		$this->assertStringContainsString( 'data-site-panel="preview"', $html );
		$this->assertMatchesRegularExpression( '/data-site-panel="own" hidden/', $html, 'Only the chosen option is open.' );
	}
}
