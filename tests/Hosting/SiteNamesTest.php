<?php
/**
 * The temporary website name that is made, not typed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\SiteField;
use BeltoftServices\Hosting\SiteNames;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

/**
 * SiteNames, and how SiteField and the account use it.
 */
class SiteNamesTest extends HostingCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_hosting_generated_name' );
		unset( $_POST[ SiteField::POST_KEY ], $_POST['billing_company'], $_POST['billing_first_name'], $_POST['billing_last_name'], $_POST['billing_email'] );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function slug( array $input ): string {
		$found = SiteField::resolve( array_merge( array( 'mode' => 'preview' ), $input ), 0 );
		$this->assertIsArray( $found, is_wp_error( $found ) ? $found->get_error_message() : '' );

		return $found['slug'];
	}

	public function test_the_name_comes_from_the_title_then_company_then_person_then_email(): void {
		$this->assertMatchesRegularExpression( '/^my-shop-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'label' => 'My Shop', 'company' => 'Firm', 'first_name' => 'Anna' ) ) );
		$this->assertMatchesRegularExpression( '/^mitfirma-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'company' => 'MitFirma', 'first_name' => 'Anna', 'last_name' => 'Hansen' ) ) );
		$this->assertMatchesRegularExpression( '/^anna-hansen-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'first_name' => 'Anna', 'last_name' => 'Hansen', 'billing_email' => 'x@example.com' ) ) );
		$this->assertMatchesRegularExpression( '/^john-doe-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'billing_email' => 'john.doe@example.com' ) ) );
		$this->assertMatchesRegularExpression( '/^john-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'company' => '!!!', 'billing_email' => 'john@example.com' ) ), 'A company with no letters is skipped.' );
	}

	public function test_danish_and_other_national_letters_are_spelled_out(): void {
		$this->assertMatchesRegularExpression( '/^soeren-aagaard-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'first_name' => 'Søren', 'last_name' => 'Ågård' ) ) );
		$this->assertSame( 'aeblegroed-oester', SiteNames::clean( 'Æblegrød Øster!' ) );
		$this->assertSame( 'muller-cafe', SiteNames::clean( 'Müller Café' ) );
	}

	public function test_a_long_base_is_cut_to_20_and_never_ends_in_a_dash(): void {
		$this->assertSame( 'a-very-long-company', SiteNames::clean( 'A very long company name that goes on' ) );
		$this->assertSame( 'abcdefghijklmnopqrs', SiteNames::clean( 'abcdefghijklmnopqrs tuvwxyz' ), 'Cut at the dash.' );
		$name = $this->slug( array( 'company' => str_repeat( 'x', 60 ) ) );
		$this->assertSame( 20 + 3, strlen( $name ) );
	}

	public function test_an_empty_or_reserved_base_falls_back_to_site(): void {
		$this->assertMatchesRegularExpression( '/^site-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array() ) );
		$this->assertMatchesRegularExpression( '/^site-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'company' => 'Admin' ) ) );
		$this->assertMatchesRegularExpression( '/^site-[a-hj-km-np-z2-9]{2}$/D', $this->slug( array( 'billing_email' => 'www@example.com' ) ) );
	}

	public function test_the_suffix_is_two_characters_from_the_readable_alphabet(): void {
		$seen = '';
		$name = '';

		for ( $i = 0; $i < 60; $i++ ) {
			$name = SiteNames::generate( array( 'company' => 'Acme' ), 'u1', '', false );
			$this->assertMatchesRegularExpression( '/^acme-[' . SiteNames::ALPHABET . ']{2}$/D', $name );
			$seen .= substr( $name, -2 );
		}

		$this->assertDoesNotMatchRegularExpression( '/[01oli]/', $seen );
		$this->assertTrue( Sites::valid_slug( $name ) );
	}

	public function test_two_orders_in_a_row_get_different_names_and_hold_them(): void {
		$one = $this->slug( array( 'company' => 'Acme' ) );
		$two = $this->slug( array( 'company' => 'Acme' ) );
		$this->assertNotSame( $one, $two );
		$this->assertTrue( Sites::held_by_other( $one, 'someone-else' ) );
	}

	public function test_a_taken_draw_is_drawn_again_and_after_20_it_widens_to_three_characters(): void {
		$calls = array();
		add_filter(
			'beltoft_services_hosting_generated_name',
			function ( $name, $context ) use ( &$calls ) {
				$calls[] = $context['length'] . ':' . $context['attempt'];

				return $context['attempt'] < 3 && 2 === $context['length'] ? 'acme-aa' : $name;
			},
			10,
			2
		);
		Sites::add( 7, 'acme-aa', 'T', '8.3', 2 );

		$name = SiteNames::generate( array( 'company' => 'Acme' ), 'u1', '', false );
		$this->assertNotSame( 'acme-aa', $name );
		$this->assertSame( array( '2:1', '2:2', '2:3' ), $calls, 'Two taken draws, then a free one.' );

		// Every two-character name taken: the 21st draw has three characters.
		remove_all_filters( 'beltoft_services_hosting_generated_name' );
		$calls = array();
		add_filter(
			'beltoft_services_hosting_generated_name',
			function ( $name, $context ) use ( &$calls ) {
				$calls[] = $context['length'] . ':' . $context['attempt'];

				return 2 === $context['length'] ? 'acme-aa' : $name;
			},
			10,
			2
		);
		$name = SiteNames::generate( array( 'company' => 'Acme' ), 'u1', '', false );
		$this->assertMatchesRegularExpression( '/^acme-[' . SiteNames::ALPHABET . ']{3}$/D', $name );
		$this->assertCount( 21, $calls );
		$this->assertSame( '3:1', $calls[20] );
	}

	public function test_a_name_held_by_another_customer_is_skipped(): void {
		$other = self::factory()->user->create();
		add_filter(
			'beltoft_services_hosting_generated_name',
			static fn( $name, $context ) => 1 === $context['attempt'] && 2 === $context['length'] ? 'acme-aa' : $name,
			10,
			2
		);
		$this->assertTrue( Sites::reserve( 'acme-aa', 'u' . $other ) );
		$name = SiteField::resolve( array( 'mode' => 'preview', 'company' => 'Acme' ), 0 )['slug'];
		$this->assertNotSame( 'acme-aa', $name );
	}

	public function test_no_free_name_gives_the_taken_error(): void {
		add_filter( 'beltoft_services_hosting_generated_name', static fn() => 'fixed', 10, 2 );
		Sites::add( 7, 'fixed', 'T', '8.3', 2 );

		$err = SiteField::resolve( array( 'mode' => 'preview' ), 0 );
		$this->assertWPError( $err );
		$this->assertSame( 'site_taken', $err->get_error_code() );
	}

	public function test_the_filter_can_supply_its_own_name_which_is_still_checked(): void {
		add_filter( 'beltoft_services_hosting_generated_name', static fn( $name, $context ) => 'Own-' . $context['base'], 10, 2 );
		$this->assertSame( 'own-acme', $this->slug( array( 'company' => 'Acme' ) ) );

		remove_all_filters( 'beltoft_services_hosting_generated_name' );
		add_filter( 'beltoft_services_hosting_generated_name', static fn() => 'not valid!', 10, 2 );
		$this->assertSame( 'site_taken', SiteField::resolve( array( 'mode' => 'preview' ), 0 )->get_error_code(), 'An invalid candidate is never used.' );
	}

	public function test_an_explicit_name_is_validated_as_before_and_a_domain_order_is_unchanged(): void {
		$this->assertSame( 'chosen', $this->slug( array( 'slug' => 'Chosen', 'company' => 'Acme' ) ) );
		$this->assertSame( 'site_slug', SiteField::resolve( array( 'slug' => 'Bad Slug' ), 0 )->get_error_code() );
		Sites::add( 7, 'taken', 'T', '8.3', 2 );
		$this->assertSame( 'site_taken', SiteField::resolve( array( 'slug' => 'taken' ), 0 )->get_error_code() );
		$this->assertSame( 'example-com', SiteField::resolve( array( 'mode' => 'own', 'host' => 'www.example.com', 'company' => 'Acme' ), 0 )['slug'] );
	}

	public function test_the_checkout_makes_the_name_from_the_billing_details_once_per_line(): void {
		$product = $this->service_product( 'hosting', array( 'node_group' => 'g1', 'sites' => 3, 'disk_gb' => 10, 'cores' => 2, 'memory_mb' => 2048 ), array( 'slug' => 'wp-' . wp_generate_password( 4, false ) ) );
		WC()->cart->empty_cart();
		$key = (string) WC()->cart->add_to_cart( $product->get_id() );

		if ( '' === $key ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		do_action( 'woocommerce_checkout_process' );

		$_POST['billing_company']     = 'Mit Firma ApS';
		$_POST['billing_first_name']  = 'Søren';
		$_POST[ SiteField::POST_KEY ] = array( $key => array( 'mode' => 'preview', 'slug' => '' ) );

		$errors = new \WP_Error();
		SiteField::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_messages() );

		$item = new \WC_Order_Item_Product();
		SiteField::save_on_item( $item, $key );
		$saved = $item->get_meta( SiteField::ITEM_META );
		$this->assertMatchesRegularExpression( '/^mit-firma-aps-[a-hj-km-np-z2-9]{2}$/D', $saved['slug'] );

		// The same name flows to the new account.
		$order = wc_create_order();
		$item2 = new \WC_Order_Item_Product();
		$item2->set_product_id( $product->get_id() );
		$item2->add_meta_data( SiteField::ITEM_META, $saved );
		$order->add_item( $item2 );
		$order->save();
		$license = array( 'id' => 0, 'order_id' => $order->get_id(), 'product_id' => $product->get_id(), 'variation_id' => 0 );
		$meta    = SiteField::initial_meta( array(), $license, \BeltoftServices\Drivers\Registry::get( 'hosting' ) );
		$this->assertSame( $saved['slug'], $meta['site']['slug'] );
	}

	public function test_an_account_adds_a_site_without_a_name_and_a_given_name_still_works(): void {
		$r = $this->resource( array( 'sites' => 4, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$r = Resource::find( $r->id() );
		$a = new HostingActions( $r );

		$made = $a->add_site( '', '', '', false );
		$this->assertIsArray( $made, is_wp_error( $made ) ? $made->get_error_message() : '' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*-[a-hj-km-np-z2-9]{2}$/D', $made['slug'] );
		$this->assertNotNull( Sites::find( $r->id(), $made['slug'] ) );

		$this->assertSame( 'given', $a->add_site( 'given', '', '', false )['slug'] );
		$this->assertSame( 'exists', $a->add_site( 'given', '', '', false )->get_error_code() );
	}
}
