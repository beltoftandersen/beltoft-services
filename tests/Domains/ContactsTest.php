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
}
