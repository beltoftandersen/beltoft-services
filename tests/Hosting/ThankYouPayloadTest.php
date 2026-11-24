<?php
/**
 * The order received row of a hosting account, byte for byte (moved here from ThankYouTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;
use BeltoftServices\ThankYou;

/**
 * Nothing here calls a server: resource meta and the registered drivers only.
 */
final class ThankYouPayloadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->fake_driver();
	}

	/**
	 * A service of an order. $driver is the real driver whose kind the row shows (the product uses the fake one).
	 *
	 * @param \WC_Order            $order  Order.
	 * @param string               $driver Driver id stored in the meta.
	 * @param array<string, mixed> $meta   Meta.
	 */
	private function service( \WC_Order $order, string $driver, array $meta = array() ): Resource {
		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'ty-' . wp_generate_password( 6, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $order->get_customer_id() ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array_merge( array( 'state' => 'ready' ), $meta ) );

		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'driver' => $driver ) );

		return Resource::find( $resource->id() );
	}

	public function test_the_payload_is_unchanged_for_a_hosting_account(): void {
		$order = wc_create_order( array( 'customer_id' => self::factory()->user->create() ) );
		$order->set_status( 'completed' );
		$order->save();

		$made = array(
			'hosting-ready'  => $this->service( $order, 'hosting', array( 'state' => 'ready' ) ),
			'hosting-wait'   => $this->service( $order, 'hosting', array( 'state' => 'provisioning' ) ),
		);

		$out = array();

		foreach ( $made as $key => $r ) {
			$json = (string) wp_json_encode( ThankYou::row( Resource::find( $r->id() ) ), JSON_UNESCAPED_SLASHES );
			$json = preg_replace( '/"id":' . $r->id() . '\b/', '"id":"{id}"', $json );
			$json = str_replace( array( '-' . $r->id() . '"', home_url() ), array( '-{id}"', '{home}' ), $json );
			$json = (string) preg_replace( array( '/Service [A-Za-z0-9]{6}/', '/"started":\d+/', '/vps=\d+/' ), array( '{product}', '"started":"{time}"', 'vps={license}' ), $json );

			$out[ $key ] = $json;
		}

		$this->assertSame(
			array(
			'hosting-ready' => '{"id":"{id}","kind":"hosting","name":"{product}","group":"ready","ready":true,"label":"Ready","message":"Log in to WordPress from your hosting in My Account with one click. No password is needed. You can also set a WordPress password there.","progress":{"kind":"","phase":"idle","active":false,"title":"","steps":[],"current":"","minutes":0,"eta":"","text":"","leave":"","started":"{time}","note":"","hosts":[],"link":[]},"url":"{home}?licenses#beltoft-service-{id}","hosting":{"site_url":"","admin_url":""}}',
			'hosting-wait' => '{"id":"{id}","kind":"hosting","name":"{product}","group":"getting-ready","ready":false,"label":"Getting ready","message":"","progress":{"kind":"build","phase":"running","active":true,"title":"Setting up your service","steps":[{"key":"place","label":"Reserving resources","status":"current","status_label":"In progress"},{"key":"ip","label":"Reserving an address","status":"todo","status_label":"Waiting"},{"key":"vmid","label":"Preparing your container","status":"todo","status_label":"Waiting"},{"key":"clone","label":"Creating your container","status":"todo","status_label":"Waiting"},{"key":"configure","label":"Applying your plan","status":"todo","status_label":"Waiting"},{"key":"start","label":"Starting your container","status":"todo","status_label":"Waiting"},{"key":"prepare","label":"Preparing the system","status":"todo","status_label":"Waiting"},{"key":"db","label":"Creating the database","status":"todo","status_label":"Waiting"},{"key":"site","label":"Installing WordPress","status":"todo","status_label":"Waiting"},{"key":"edge","label":"Connecting the web server","status":"todo","status_label":"Waiting"},{"key":"dns","label":"Publishing the address","status":"todo","status_label":"Waiting"},{"key":"verify","label":"Checking your website","status":"todo","status_label":"Waiting"},{"key":"mail","label":"Setting up website mail","status":"todo","status_label":"Waiting"},{"key":"ready","label":"Final checks","status":"todo","status_label":"Waiting"}],"current":"place","minutes":3,"eta":"Usually about 3 minutes.","text":"Setting up your service. Usually about 3 minutes.","leave":"You can leave this site. The work goes on, and the same progress is in your account.","started":"{time}","note":"","hosts":[],"link":[]},"url":"{home}?licenses#beltoft-service-{id}"}',
			),
			$out
		);
	}
}
