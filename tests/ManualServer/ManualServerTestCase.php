<?php
/**
 * Shared set-up of the dedicated (manual) server tests. No supplier exists in code: any HTTP call fails the test, and mail is captured.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Usage\UsageTestCase;
use BeltoftServices\Usage\Rates;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

/**
 * Base class.
 */
abstract class ManualServerTestCase extends UsageTestCase {

	/** @var array<int, array<string, mixed>> Captured mails. */
	protected array $staff_mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->settings( array( 'usage_new_limit' => 2 ) );
		$this->staff_mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== $pre ) {
					return $pre; // A test stub answered first.
				}

				$this->fail( 'A dedicated server test reached the network: ' . $url );

				return $pre;
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Records and stops a mail.
	 *
	 * @param mixed $pre  Short circuit.
	 * @param array $atts Mail.
	 */
	public function catch_mail( $pre, $atts ) {
		unset( $pre );
		$this->staff_mails[] = array(
			'to'      => (array) $atts['to'],
			'subject' => (string) $atts['subject'],
			'body'    => (string) $atts['message'],
		);

		return true;
	}

	/**
	 * Mails whose subject holds a text.
	 *
	 * @param string $needle Text.
	 * @return array<int, array<string, mixed>>
	 */
	protected function mails_with( string $needle ): array {
		return array_values( array_filter( $this->staff_mails, static fn( array $m ): bool => false !== strpos( $m['subject'], $needle ) ) );
	}

	/**
	 * A dedicated server product: Server and Billing attributes, a Monthly variation (price 119, the price source) and its Pay
	 * as you go variation (price 54 = the one-time setup fee, meta billing usage). Premium 0, so the monthly cap is the monthly price.
	 *
	 * @return array{m: \WC_Product_Variation, u: \WC_Product_Variation}
	 */
	protected function server_product(): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Dedicated server ' . wp_generate_password( 4, false ) );
		$product->set_status( 'publish' );

		foreach ( array( 'Server' => array( 'AMD Ryzen 7, 64 GB' ), 'Billing' => array( 'Monthly', 'Pay as you go' ) ) as $name => $options ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( $options );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}

		$product->set_attributes( $attributes );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'dedicated-' . wp_generate_password( 5, false ) );
		$product->update_meta_data( Product::META_DRIVER, 'manual-server' );
		$product->update_meta_data( Rates::META_PREMIUM, '0' );
		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 'm' => array( 'Monthly', '119' ), 'u' => array( 'Pay as you go', '54' ) ) as $short => $row ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'server' => 'AMD Ryzen 7, 64 GB', 'billing' => $row[0] ) );
			$v->set_regular_price( $row[1] );
			$v->set_status( 'publish' );

			foreach ( array(
				'server_cpu'           => 'AMD Ryzen 7 PRO 8700GE',
				'server_memory'        => '64 GB DDR5',
				'server_storage'       => '2 x 512 GB NVMe SSD',
				'server_location'      => 'Germany',
				'server_ref'           => 'AX42-1',
				'server_location_code' => 'FSN1',
				'cost_monthly'         => '99.00',
				'cost_setup'           => '49.00',
			) as $key => $value ) {
				$v->update_meta_data( Product::META_PLAN . $key, $value );
			}

			if ( 'u' === $short ) {
				$v->update_meta_data( Rates::META_BILLING, 'usage' );
				$v->update_meta_data( Rates::META_MONTHLY, '119' );
			}

			$v->save();
			$out[ $short ] = $v;
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	/**
	 * A pay as you go dedicated server that waits for staff: a usage license on the variation and a resource of the driver, queued.
	 *
	 * @param \WC_Product_Variation $variation Usage variation.
	 * @param int                   $user      Customer.
	 * @param int|null              $account   Usage account subscription id.
	 */
	protected function waiting_server( \WC_Product_Variation $variation, int $user, ?int $account = null ): Resource {
		$parent = wc_get_product( $variation->get_parent_id() );
		$data   = array(
			'product_id'   => $parent->get_id(),
			'product_slug' => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
			'variation_id' => $variation->get_id(),
			'user_id'      => $user,
			'email'        => self::MAIL,
			'source'       => 'usage',
			'fire'         => false,
		);

		if ( null !== $account ) {
			$data['subscription_id'] = $account;
		}

		$license  = $this->service_license( $parent, $data );
		$plan     = Product::plan( $parent->get_id(), $variation->get_id() );
		$resource = Resource::create( $license, Registry::get( 'manual-server' ), $plan, array() );
		$this->assertInstanceOf( Resource::class, $resource, is_wp_error( $resource ) ? $resource->get_error_code() . ': ' . $resource->get_error_message() : '' );

		return $resource;
	}

	/**
	 * Switches automatic ordering on with the in-process fake client and a key for the encrypted password.
	 */
	protected function automatic(): void {
		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', static fn() => $key );
		\BeltoftServices\ManualServer\FakeRobotClient::reset();
		$this->settings( array( 'dedicated_enabled' => 1, 'dedicated_client' => 'fake' ) );
	}

	/**
	 * A delivered, ready server.
	 *
	 * @param \WC_Product_Variation $variation Usage variation.
	 * @param int                   $user      Customer.
	 * @param int|null              $account   Usage account subscription id.
	 */
	protected function ready_server( \WC_Product_Variation $variation, int $user, ?int $account = null ): Resource {
		$r = $this->waiting_server( $variation, $user, $account );
		\BeltoftServices\Jobs::run( $r->id() );
		$done = \BeltoftServices\ManualServer\ManualServerDelivery::save( Resource::find( $r->id() ), "203.0.113.7\n2001:db8::7", 'Log in as root with your SSH key.', 'S-1234', 'tester', true );
		$this->assertTrue( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		\BeltoftServices\Jobs::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		return $fresh;
	}
}
