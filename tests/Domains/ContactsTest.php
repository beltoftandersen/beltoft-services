<?php
/** Registrant contact tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Contacts;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Tests\TestCase;

class ContactsTest extends TestCase {
	public function test_contact_handle_reused_for_second_domain(): void {
		$user = self::factory()->user->create();
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.test' );
		$order->set_billing_address_1( 'Main Street 2' );
		$order->set_billing_city( 'London' );
		$order->set_billing_postcode( '1000' );
		$order->set_billing_country( 'GB' );
		$order->set_billing_phone( '+441234567890' );
		$order->save();
		$fake = new FakeClient();
		$fake->reply( 'POST', '/customers', array( 'handle' => 'AL123-GB' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 'AL123-GB', Contacts::handle_for( $user, $order ) );
		$this->assertSame( 'AL123-GB', Contacts::handle_for( $user, $order ) );
		$this->assertCount( 1, $fake->calls() );
	}

	public function test_the_eu_vat_plugins_number_reaches_the_registrar_without_spaces(): void {
		$user = self::factory()->user->create();
		$order = wc_create_order( array( 'customer_id' => $user ) );
		foreach ( array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'address_1' => 'Main Street 2', 'city' => 'Aarhus', 'postcode' => '8000', 'country' => 'DK', 'phone' => '+4512345678' ) as $k => $v ) { $order->{"set_billing_$k"}( $v ); }
		$order->update_meta_data( '_billing_eu_vat_number', 'DK 1234 5678' );
		$order->save();
		$fake = new FakeClient();
		$fake->reply( 'POST', '/customers', array( 'handle' => 'AL1-DK' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		Contacts::handle_for( $user, $order );
		$this->assertSame( 'DK12345678', $fake->calls()[0]['body']['vat'] );
	}

	private function order( int $user, array $over = array() ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$values = array_merge( array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'address_1' => 'Main Street 2', 'city' => 'Aarhus', 'postcode' => '8000', 'country' => 'DK', 'phone' => '+4512345678', 'company' => '' ), $over );
		foreach ( array_diff_key( $values, array( 'vat' => 1, 'type' => 1 ) ) as $k => $v ) { $order->{"set_billing_$k"}( $v ); }
		if ( isset( $over['vat'] ) ) { $order->update_meta_data( '_billing_eu_vat_number', $over['vat'] ); }
		if ( isset( $over['type'] ) ) { $order->update_meta_data( '_billing_customer_type', $over['type'] ); }
		$order->save();
		return $order;
	}

	private function fake_with_handles( string ...$handles ): FakeClient {
		$fake = new FakeClient();
		foreach ( $handles as $handle ) { $fake->reply( 'POST', '/customers', array( 'handle' => $handle ) ); }
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		return $fake;
	}

	/** @return array<string, array<int, array<string, string>>> */
	public static function changes(): array {
		return array(
			'first name' => array( array( 'first_name' => 'Augusta' ) ),
			'last name'  => array( array( 'last_name' => 'King' ) ),
			'company'    => array( array( 'company' => 'Analytical Engines' ) ),
			'address'    => array( array( 'address_1' => 'Other Road 5' ) ),
			'postcode'   => array( array( 'postcode' => '8200' ) ),
			'city'       => array( array( 'city' => 'Odense' ) ),
			'email'      => array( array( 'email' => 'ada@other.test' ) ),
			'country'    => array( array( 'country' => 'SE' ) ),
			'vat'        => array( array( 'vat' => 'DK12345678' ) ),
		);
	}

	/** @dataProvider changes */
	public function test_a_changed_billing_detail_makes_a_new_handle( array $change ): void {
		$user = self::factory()->user->create();
		$fake = $this->fake_with_handles( 'ONE-DK', 'TWO-DK' );
		$this->assertSame( 'ONE-DK', Contacts::handle_for( $user, $this->order( $user ) ) );
		$this->assertSame( 'ONE-DK', Contacts::handle_for( $user, $this->order( $user ) ), 'The same details keep the handle.' );
		$this->assertCount( 1, $fake->calls() );
		$this->assertSame( 'TWO-DK', Contacts::handle_for( $user, $this->order( $user, $change ) ) );
		$this->assertCount( 2, $fake->calls() );
		$this->assertSame( array( 'POST', 'POST' ), array_column( $fake->calls(), 'method' ), 'A handle is created, never updated in place.' );
		$this->assertSame( 'TWO-DK', Contacts::handle_for( $user, $this->order( $user, $change ) ), 'The new details are kept in turn.' );
	}

	public function test_letter_case_and_spaces_are_not_a_change(): void {
		$user = self::factory()->user->create();
		$fake = $this->fake_with_handles( 'ONE-DK', 'TWO-DK' );
		Contacts::handle_for( $user, $this->order( $user, array( 'vat' => 'DK 1234 5678', 'email' => 'Ada@Example.test', 'first_name' => 'ADA' ) ) );
		$this->assertSame( 'ONE-DK', Contacts::handle_for( $user, $this->order( $user, array( 'vat' => 'dk12345678', 'email' => 'ada@example.test', 'first_name' => 'Ada' ) ) ) );
		$this->assertCount( 1, $fake->calls() );
	}

	public function test_a_handle_from_before_the_details_were_kept_is_adopted_not_replaced(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'OLD-DK' );
		$fake = $this->fake_with_handles( 'NEW-DK' );
		$blank = wc_create_order( array( 'customer_id' => $user ) );
		$this->assertSame( 'OLD-DK', Contacts::handle_for( $user, $blank ), 'No billing details to compare: the handle stays.' );
		$this->assertSame( array(), Contacts::details_of( $user, 'OLD-DK' ) );
		$this->assertSame( 'OLD-DK', Contacts::handle_for( $user, $this->order( $user ) ), 'The first complete order adopts it: no new handle from the deploy.' );
		$this->assertCount( 0, $fake->calls() );
		$this->assertSame( 'DK', Contacts::details_of( $user, 'OLD-DK' )['country'] );
		$this->assertSame( 'OLD-DK', Contacts::handle_for( $user, $this->order( $user ) ) );
		$this->assertSame( 'NEW-DK', Contacts::handle_for( $user, $this->order( $user, array( 'city' => 'Odense' ) ) ), 'A real change after that makes a new handle.' );
	}

	public function test_incomplete_billing_without_a_handle_is_an_error(): void {
		$user = self::factory()->user->create();
		$result = Contacts::handle_for( $user, wc_create_order( array( 'customer_id' => $user ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'contact_incomplete', $result->get_error_code() );
	}

	public function test_eligibility_uses_the_handle_that_is_sent(): void {
		$user = self::factory()->user->create();
		$this->fake_with_handles( 'US-1', 'DK-1', 'DK-2', 'DK-3' );
		$se = Contacts::handle_for( $user, $this->order( $user, array( 'country' => 'US' ) ) );
		$us = Contacts::details_of( $user, $se );
		$this->assertSame( 'US', $us['country'] );
		$this->assertInstanceOf( \WP_Error::class, Contacts::eligible( 'shop.eu', $us ) );
		$this->assertTrue( Contacts::eligible( 'shop.com', $us ) );
		$this->assertSame( array(), Contacts::details_of( $user, 'SOME-OTHER-HANDLE' ), 'Details belong to their own handle only.' );
		$this->assertTrue( Contacts::eligible( 'shop.eu', array() ), 'Unknown details pass: the registrar decides.' );
		$this->assertTrue( Contacts::eligible( 'shop.dk', array() ) );
		$business = Contacts::details_of( $user, Contacts::handle_for( $user, $this->order( $user, array( 'country' => 'DK', 'company' => 'Acme' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, Contacts::eligible( 'shop.dk', $business ), 'A business without VAT.' );
		$with_vat = Contacts::details_of( $user, Contacts::handle_for( $user, $this->order( $user, array( 'country' => 'DK', 'company' => 'Acme', 'vat' => 'DK12345678' ) ) ) );
		$this->assertTrue( Contacts::eligible( 'shop.dk', $with_vat ) );
		$private = Contacts::details_of( $user, Contacts::handle_for( $user, $this->order( $user, array( 'country' => 'DK', 'type' => 'private' ) ) ) );
		$this->assertTrue( Contacts::eligible( 'shop.dk', $private ), 'A private person needs no VAT number.' );
	}
}
