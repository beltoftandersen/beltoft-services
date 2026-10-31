<?php
/**
 * The email domain field at checkout, and upgrade-only plan changes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailField;
use BeltoftServices\Mail\MailSwitch;
use BeltoftServices\Product;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailCheckoutTest extends MailTestCase {

	public function tear_down(): void {
		unset( $_POST[ MailField::POST_KEY ] );
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		parent::tear_down();
	}

	private function product( string $slug = 'mail-co' ): \WC_Product {
		return $this->service_product( 'mail', self::PLAN, array( 'slug' => $slug . '-' . wp_generate_password( 4, false ) ) );
	}

	/** Puts a mail product in the cart and returns its cart key. */
	private function in_cart( \WC_Product $product ): string {
		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart( $product->get_id() );

		if ( ! $key ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		return (string) $key;
	}

	private function post( string $key, array $values ): void {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			do_action( 'woocommerce_checkout_process' );
		}

		$_POST[ MailField::POST_KEY ] = array( $key => $values );
	}

	private function errors(): array {
		$errors = new \WP_Error();
		MailField::validate( array(), $errors );

		return $errors->get_error_messages();
	}

	public function test_only_mail_lines_are_offered_the_field(): void {
		$this->assertSame( array(), MailField::cart_lines() );
		$key = $this->in_cart( $this->product() );
		$this->assertSame( array( $key ), array_keys( MailField::cart_lines() ) );

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->service_product( 'http', array(), array( 'slug' => 'http-co', 'connection' => 'x' ) )->get_id() );
		$this->assertSame( array(), MailField::cart_lines() );

		ob_start();
		MailField::render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_a_guest_can_only_type_a_domain(): void {
		$this->in_cart( $this->product() );
		wp_set_current_user( 0 );
		ob_start();
		MailField::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Email domain', $html );
		$this->assertStringContainsString( '[own]', $html );
		$this->assertStringNotContainsString( 'type="radio"', $html );
		$this->assertStringNotContainsString( '[zone]', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_a_customer_with_a_zone_of_ours_gets_both_choices(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'mine.example' );
		wp_set_current_user( $user );
		$this->in_cart( $this->product() );
		ob_start();
		MailField::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( '<option value="mine.example"', $html );
		$this->assertStringContainsString( '[own]', $html );
		$this->assertStringContainsString( 'A domain hosted elsewhere', $html );
	}

	public function test_resolve_checks_the_name(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'mine.example' );

		$this->assertSame( 'mine.example', MailField::resolve( array( 'mode' => 'ours', 'zone' => 'Mine.example' ), $user ) );
		$this->assertSame( 'typed.example', MailField::resolve( array( 'mode' => 'own', 'own' => ' Typed.Example. ' ), $user ) );
		$this->assertSame( 'mail_zone', MailField::resolve( array( 'mode' => 'ours', 'zone' => 'mine.example' ), 0 )->get_error_code(), 'A guest has no zones.' );
		$this->assertSame( 'mail_zone', MailField::resolve( array( 'mode' => 'ours', 'zone' => 'other.example' ), $user )->get_error_code(), 'Not the customer\'s zone.' );
		$this->assertSame( 'mail_own', MailField::resolve( array( 'mode' => 'own', 'own' => '' ), $user )->get_error_code() );
		$this->assertSame( 'mail_mode', MailField::resolve( array(), $user )->get_error_code() );
		$this->assertSame( 'mail_refused', MailField::resolve( array( 'mode' => 'own', 'own' => 'https://x.example/path' ), $user )->get_error_code() );
		$this->assertSame( 'mail_refused', MailField::resolve( array( 'mode' => 'own', 'own' => 'sub.reserved.example' ), $user )->get_error_code() );
		$this->assertSame( 'mail_refused', MailField::resolve( array( 'mode' => 'own', 'own' => 'mail.example.net' ), $user )->get_error_code(), 'The mail host itself.' );

		$this->mail_resource( 'taken.example' );
		$taken = MailField::resolve( array( 'mode' => 'own', 'own' => 'taken.example' ), $user );
		$this->assertSame( 'That domain already has email here.', $taken->get_error_message() );
	}

	public function test_checkout_without_or_with_a_bad_domain_is_refused_with_a_plain_message(): void {
		$key = $this->in_cart( $this->product() );

		$this->post( $key, array() );
		$this->assertSame( array( 'Choose the domain you want email for.' ), $this->errors() );

		$this->post( $key, array( 'mode' => 'own', 'own' => 'owner.example' ) );
		$this->assertSame( array( 'That domain cannot be used for email here.' ), $this->errors() );

		$this->post( $key, array( 'mode' => 'own', 'own' => 'ok.example' ) );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_one_domain_per_line_and_never_the_same_name_twice(): void {
		$product = $this->product();
		WC()->cart->empty_cart();
		$first  = (string) WC()->cart->add_to_cart( $product->get_id(), 2 );
		$this->post( $first, array( 'mode' => 'own', 'own' => 'ok.example' ) );
		$this->assertStringContainsString( 'Each email domain is its own line', implode( ' ', $this->errors() ) );

		WC()->cart->empty_cart();
		$a = (string) WC()->cart->add_to_cart( $product->get_id() );
		$b = (string) WC()->cart->add_to_cart( $this->product( 'second' )->get_id() );

		if ( '' === $a || '' === $b || $a === $b ) {
			$this->markTestSkipped( 'Two lines cannot be put in the cart here.' );
		}

		$_POST[ MailField::POST_KEY ] = array(
			$a => array( 'mode' => 'own', 'own' => 'same.example' ),
			$b => array( 'mode' => 'own', 'own' => 'SAME.example' ),
		);
		do_action( 'woocommerce_checkout_process' );
		$this->assertStringContainsString( 'same domain for two lines', implode( ' ', $this->errors() ) );
	}

	public function test_a_renewal_or_switch_line_asks_nothing(): void {
		$key = $this->in_cart( $this->product() );
		WC()->cart->cart_contents[ $key ]['subscription_switch'] = array( 'subscription_id' => 1 );
		$this->assertSame( array(), MailField::cart_lines() );
		$this->post( $key, array() );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_the_domain_is_saved_on_the_line_and_becomes_resource_meta(): void {
		$product = $this->product();
		$key     = $this->in_cart( $product );
		$this->post( $key, array( 'mode' => 'own', 'own' => 'Saved.Example' ) );

		$item = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		MailField::save_on_item( $item, $key );
		$this->assertSame( 'saved.example', $item->get_meta( MailField::ITEM_META ) );

		$other = new \WC_Order_Item_Product();
		MailField::save_on_item( $other, 'not-a-key' );
		$this->assertSame( '', (string) $other->get_meta( MailField::ITEM_META ), 'Not a mail line.' );

		$order = wc_create_order();
		$order->add_item( $item );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$meta    = MailField::initial_meta( array(), $license, Registry::get( 'mail' ) );
		$this->assertSame( 'saved.example', $meta[ MailDomain::META_DOMAIN ] );

		$this->assertSame( array( 'x' => 1 ), MailField::initial_meta( array( 'x' => 1 ), $license, Registry::get( 'http' ) ), 'Other drivers are left alone.' );
	}

	public function test_two_lines_of_one_plan_take_one_domain_each(): void {
		$product = $this->product();
		$order   = wc_create_order();

		foreach ( array( 'one.example', 'two.example' ) as $name ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product_id( $product->get_id() );
			$item->add_meta_data( MailField::ITEM_META, $name );
			$order->add_item( $item );
		}

		$order->save();
		$first  = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$meta1  = MailField::initial_meta( array(), $first, Registry::get( 'mail' ) );
		$this->assertSame( 'one.example', $meta1[ MailDomain::META_DOMAIN ] );
		Resource::create( $first, Registry::get( 'mail' ), Product::plan( $product->get_id() ), $meta1 );

		$second = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$meta2  = MailField::initial_meta( array(), $second, Registry::get( 'mail' ) );
		$this->assertSame( 'two.example', $meta2[ MailDomain::META_DOMAIN ] );
	}

	// -- Plan changes ---------------------------------------------------------------------------

	private function switch_message( int $subscription, \WC_Product $target, string $message = '' ) {
		MailSwitch::register();

		$sub = new class( $subscription ) {
			public function __construct( private int $id ) {}
			public function get_id() {
				return $this->id;
			}
		};

		return apply_filters( 'woocommerce_subscriptions_switch_error_message', $message, $target->get_id(), 1, 0, $sub, null );
	}

	public function test_a_mail_plan_can_be_raised_but_never_lowered(): void {
		$now     = $this->service_product( 'mail', array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ), array( 'slug' => 'mail-now' ) );
		$license = $this->service_license( $now, array( 'fire' => false, 'subscription_id' => 7171 ) );
		$r       = Resource::create( $license, Registry::get( 'mail' ), Product::plan( $now->get_id() ), array( MailDomain::META_DOMAIN => 'sw.example' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$no = 'Email plans can\'t be made smaller. Choose a plan with at least as many mailboxes, as much storage and as many aliases as you have now.';

		$cases = array(
			'fewer mailboxes' => array( array( 'mailboxes' => 1, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ), $no ),
			'smaller boxes'   => array( array( 'mailboxes' => 5, 'quota_mb' => 5120, 'aliases' => 50, 'domains' => 3 ), $no ),
			'fewer aliases'   => array( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 20, 'domains' => 3 ), $no ),
			'fewer domains'   => array( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 1 ), $no ),
			'equal'           => array( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ), '' ),
			'bigger'          => array( array( 'mailboxes' => 10, 'quota_mb' => 20480, 'aliases' => 200, 'domains' => 10 ), '' ),
		);
		$n = 0;

		foreach ( $cases as $name => $case ) {
			$target = $this->service_product( 'mail', $case[0], array( 'slug' => 'mail-target-' . ( ++$n ) ) );
			$this->assertSame( $case[1], $this->switch_message( 7171, $target ), $name );
		}

		$small = $this->service_product( 'mail', array( 'mailboxes' => 1 ), array( 'slug' => 'mail-small' ) );
		$this->assertSame( 'Other error.', $this->switch_message( 7171, $small, 'Other error.' ), 'WCS\'s own message is kept.' );
		$this->assertSame( '', $this->switch_message( 9999, $small ), 'A subscription with no email service.' );
	}

	public function test_the_limits_in_force_count_not_the_plan_on_the_license(): void {
		$now     = $this->service_product( 'mail', array( 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 20, 'domains' => 1 ), array( 'slug' => 'mail-lim' ) );
		$license = $this->service_license( $now, array( 'fire' => false, 'subscription_id' => 7272 ) );
		$r       = Resource::create( $license, Registry::get( 'mail' ), Product::plan( $now->get_id() ), array( MailDomain::META_DOMAIN => 'lim.example' ) );
		$r->set( array( 'mail_limits' => array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ) ) );

		$same = $this->service_product( 'mail', array( 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 20, 'domains' => 1 ), array( 'slug' => 'mail-lim2' ) );
		$this->assertNotSame( '', $this->switch_message( 7272, $same ), 'Applied limits were higher, so the switch is a downgrade.' );
	}
}
