<?php
/**
 * wp beltoft-services mail-products.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Cli\MailProductsCommand;
use BeltoftServices\Product;

require_once __DIR__ . '/MailTestCase.php';
require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

final class MailProductsTest extends MailTestCase {

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
		foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => Product::META_DRIVER, 'meta_value' => 'mail' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_post( $id, true );
		}
	}

	private function run_command( array $flags ): string {
		try {
			( new MailProductsCommand() )( array(), $flags );
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_dry_run_writes_nothing(): void {
		$this->assertSame( '', $this->run_command( array( 'dry-run' => true ) ) );
		$this->assertSame( 0, MailProductsCommand::existing() );
		$this->assertStringContainsString( 'Personal: 5 EUR per month', \WP_CLI::text() );
		$this->assertStringContainsString( 'Business: 60 EUR per month excluding VAT, 10 mailboxes of 20 GB, 200 aliases, 10 domains', \WP_CLI::text() );
	}

	public function test_variation_descriptions_read_in_plain_words(): void {
		$this->assertSame( '5 mailboxes of 10 GB, 50 aliases, 3 domains', MailProductsCommand::plan_description( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ) ) );
		$this->assertSame( '2 mailboxes of 512 MB, 1 alias, 1 domain', MailProductsCommand::plan_description( array( 'mailboxes' => 2, 'quota_mb' => 512, 'aliases' => 1, 'domains' => 1 ) ) );
		$this->assertSame( '1 mailbox of 1.5 GB, 1 alias, 1 domain', MailProductsCommand::plan_description( array( 'mailboxes' => 1, 'quota_mb' => 1536, 'aliases' => 1, 'domains' => 1 ) ) );
		$this->assertSame( '1 mailbox of 5 GB, 1 alias, 1 domain', MailProductsCommand::plan_description( array( 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 1, 'domains' => 1 ) ) );
	}

	public function test_one_of_the_flags_is_needed(): void {
		$this->assertStringContainsString( 'exactly one', $this->run_command( array() ) );
		$this->assertStringContainsString( 'exactly one', $this->run_command( array( 'dry-run' => true, 'create' => true ) ) );
		$this->assertSame( 0, MailProductsCommand::existing() );
	}

	public function test_create_makes_one_variable_subscription_with_three_plans(): void {
		$this->assertSame( '', $this->run_command( array( 'create' => true ) ) );
		$id = MailProductsCommand::existing();
		$this->assertGreaterThan( 0, $id );

		$product = wc_get_product( $id );
		$this->assertInstanceOf( \WC_Product_Variable_Subscription::class, $product );
		$this->assertSame( 'Business email', $product->get_name() );
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( 'visible', $product->get_catalog_visibility() );
		$this->assertSame( 'taxable', $product->get_tax_status() );
		$this->assertSame( '', $product->get_tax_class() );
		$this->assertSame( 'mail', Product::driver_for( $id )->id() );
		$this->assertSame( 'month', get_post_meta( $id, '_subscription_period', true ) );
		$this->assertSame( array( 'Plan' ), array_map( static fn( $a ) => $a->get_name(), array_values( $product->get_attributes() ) ) );

		$want = array(
			'Personal' => array( '5', array( 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 20, 'domains' => 1 ) ),
			'Team'     => array( '25', array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ) ),
			'Business' => array( '60', array( 'mailboxes' => 10, 'quota_mb' => 20480, 'aliases' => 200, 'domains' => 10 ) ),
		);
		$children = $product->get_children();
		$this->assertCount( 3, $children );

		foreach ( $children as $vid ) {
			$v    = wc_get_product( $vid );
			$name = (string) $v->get_attributes()['plan'];
			$this->assertArrayHasKey( $name, $want );
			$this->assertSame( $want[ $name ][0], (string) $v->get_regular_price(), $name );
			$this->assertSame( $want[ $name ][1], Product::plan( $id, $vid ), $name );
			$this->assertSame( '0', (string) get_post_meta( $vid, '_subscription_sign_up_fee', true ) );
			$this->assertSame( '0', (string) get_post_meta( $vid, '_subscription_trial_length', true ) );
			$this->assertSame( 'month', get_post_meta( $vid, '_subscription_period', true ) );
		}
	}

	public function test_it_refuses_when_a_mail_product_exists(): void {
		$this->run_command( array( 'create' => true ) );
		$before = MailProductsCommand::existing();

		$this->assertStringContainsString( 'exists already', $this->run_command( array( 'create' => true ) ) );
		$this->assertStringContainsString( 'exists already', $this->run_command( array( 'dry-run' => true ) ) );
		$this->assertSame( $before, MailProductsCommand::existing() );
		$this->assertCount( 1, get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => Product::META_DRIVER, 'meta_value' => 'mail' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public function test_the_plans_can_be_filtered(): void {
		add_filter( 'beltoft_services_mail_plans', static fn( $p ) => array( 'Solo' => array( 'price' => '3', 'mailboxes' => 1, 'quota_mb' => 1024, 'aliases' => 5, 'domains' => 1 ) ) );
		$this->run_command( array( 'create' => true ) );
		remove_all_filters( 'beltoft_services_mail_plans' );
		$this->assertCount( 1, wc_get_product( MailProductsCommand::existing() )->get_children() );
	}

	public function test_old_descriptions_with_bracketed_plurals_can_be_refreshed(): void {
		$this->run_command( array( 'create' => true ) );
		$id = MailProductsCommand::existing();
		foreach ( wc_get_product( $id )->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			$v->set_description( '5 mailbox(es) of 10 GB, 50 aliases, 3 domain(s)' );
			$v->save();
		}

		$count = count( wc_get_product( $id )->get_children() );
		$this->run_command( array( 'refresh-descriptions' => true, 'dry-run' => true ) );
		$this->assertStringContainsString( '(es)', wc_get_product( wc_get_product( $id )->get_children()[0] )->get_description(), 'A dry run writes nothing.' );
		$this->assertSame( $count, MailProductsCommand::refresh_descriptions( false ), 'A dry run counts the plans that would change.' );

		$this->assertSame( '', $this->run_command( array( 'refresh-descriptions' => true ) ) );
		$descriptions = array_map( static fn( $vid ) => wc_get_product( $vid )->get_description(), wc_get_product( $id )->get_children() );
		$this->assertContains( '5 mailboxes of 10 GB, 50 aliases, 3 domains', $descriptions );
		$this->assertStringNotContainsString( '(es)', implode( ' ', $descriptions ) );
		$this->assertSame( 0, MailProductsCommand::refresh_descriptions(), 'A second run changes nothing.' );
	}
}
