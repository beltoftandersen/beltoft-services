<?php
/**
 * Which script and stylesheet loads on which page: the dashboard assets stay on the pages that use them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Account\Overview;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\Servers;
use BeltoftServices\ThankYou;

/**
 * Asset scope tests.
 */
final class AssetScopeTest extends TestCase {
	private const SCRIPTS = array( 'beltoft-services-account', 'beltoft-services-progress', 'beltoft-services-vm', 'beltoft-services-metrics', 'beltoft-services-domain', 'beltoft-services-thankyou' );
	private const STYLES  = array( 'beltoft-services-account', 'beltoft-services-metrics', 'beltoft-services-thankyou' );

	public function set_up(): void {
		parent::set_up();
		$this->reset();
	}

	public function tear_down(): void {
		$this->reset();
		remove_all_filters( 'woocommerce_is_account_page' );
		remove_all_filters( 'woocommerce_is_order_received_page' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function reset(): void {
		global $wp;
		foreach ( self::SCRIPTS as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( self::STYLES as $handle ) {
			wp_dequeue_style( $handle );
		}
		foreach ( array_keys( WC()->query->get_query_vars() ) as $key ) {
			unset( $wp->query_vars[ $key ] );
		}
		unset( $wp->query_vars[ Servers::slug() ], $wp->query_vars['domains'], $wp->query_vars['licenses'] );
	}

	/** Pretends to be on My Account at this endpoint ('' = the dashboard), logged in or out. */
	private function on( string $endpoint, bool $logged_in = true ): void {
		global $wp;
		add_filter( 'woocommerce_is_account_page', '__return_true' );
		wp_set_current_user( $logged_in ? self::factory()->user->create() : 0 );
		if ( '' !== $endpoint ) {
			$wp->query_vars[ $endpoint ] = '';
		}
	}

	private function load(): array {
		Overview::assets();
		Panel::assets();

		$out = array();
		foreach ( self::SCRIPTS as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) ) {
				$out[] = $handle . '.js';
			}
		}
		foreach ( self::STYLES as $handle ) {
			if ( wp_style_is( $handle, 'enqueued' ) ) {
				$out[] = $handle . '.css';
			}
		}

		return $out;
	}

	public function test_nothing_loads_outside_my_account(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( array(), $this->load() );
		ThankYou::assets();
		$this->assertFalse( wp_style_is( 'beltoft-services-thankyou', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'beltoft-services-thankyou', 'enqueued' ) );
	}

	public function test_the_login_screen_loads_nothing(): void {
		$this->on( '', false );

		$this->assertSame( array(), $this->load() );
	}

	public function test_the_overview_loads_the_stylesheet_only(): void {
		$this->on( '' );

		$this->assertSame( array( 'beltoft-services-account.css' ), $this->load() );
	}

	public function test_account_details_and_other_core_endpoints_load_nothing(): void {
		foreach ( array( 'edit-account', 'lost-password' ) as $endpoint ) {
			$this->reset();
			$this->on( $endpoint );

			$this->assertSame( array(), $this->load(), $endpoint );
		}
	}

	public function test_the_servers_endpoint_loads_the_server_scripts_and_not_the_domain_script(): void {
		$this->on( Servers::slug() );
		$loaded = $this->load();

		foreach ( array( 'beltoft-services-account.js', 'beltoft-services-progress.js', 'beltoft-services-vm.js', 'beltoft-services-metrics.js', 'beltoft-services-metrics.css', 'beltoft-services-account.css' ) as $asset ) {
			$this->assertContains( $asset, $loaded );
		}
		$this->assertNotContains( 'beltoft-services-domain.js', $loaded );
	}

	public function test_the_domains_endpoint_loads_the_domain_script_and_not_the_server_scripts(): void {
		$this->on( 'domains' );
		$loaded = $this->load();

		$this->assertContains( 'beltoft-services-domain.js', $loaded );
		$this->assertContains( 'beltoft-services-account.js', $loaded );
		$this->assertNotContains( 'beltoft-services-vm.js', $loaded );
		$this->assertNotContains( 'beltoft-services-metrics.js', $loaded );
		$this->assertNotContains( 'beltoft-services-metrics.css', $loaded );
	}

	public function test_the_licenses_endpoint_loads_neither_the_server_nor_the_domain_scripts(): void {
		$this->on( 'licenses' );
		$loaded = $this->load();

		$this->assertContains( 'beltoft-services-account.js', $loaded );
		$this->assertNotContains( 'beltoft-services-vm.js', $loaded );
		$this->assertNotContains( 'beltoft-services-metrics.js', $loaded );
		$this->assertNotContains( 'beltoft-services-domain.js', $loaded );
	}

	public function test_the_thank_you_assets_are_not_loaded_on_my_account(): void {
		$this->on( '' );
		ThankYou::assets();

		$this->assertFalse( wp_style_is( 'beltoft-services-thankyou', 'enqueued' ) );
	}
}
