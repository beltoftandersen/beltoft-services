<?php
/**
 * Ended services are not listed when nothing is left for the customer to act on.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\MenuCounts;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Servers;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

final class EndedServicesTest extends TestCase {

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'] );
		parent::tear_down();
	}

	private function user(): int {
		return self::factory()->user->create();
	}

	private function disk( int $user, string $status, ?string $resource = 'live' ): array {
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $r );

		if ( 'closed' === $resource ) {
			$r->close();
		}

		Licenses::update( (int) $license['id'], array( 'status' => $status ) );

		return array( (int) $license['id'], Resource::find( $r->id() ) );
	}

	private function db( int $user, string $status, bool $closed = false, string $state = 'ready' ): int {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 20 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array( 'state' => $state, 'desired' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $r );

		if ( $closed ) {
			$r->close();
		}

		Licenses::update( (int) $license['id'], array( 'status' => $status ) );

		return (int) $license['id'];
	}

	private function views( int $user ): array {
		return array_map( static fn( $l ) => View::license( $l ), \BeltoftLicenses\Account\Endpoint::licenses_for_user( $user ) );
	}

	private function others( int $user ): array {
		return array_map( static fn( $v ) => (int) $v['license']['id'], Servers::sort( $this->views( $user ) )['others'] );
	}

	private function counts( int $user ): array {
		MenuCounts::reset();

		return MenuCounts::of( $user );
	}

	public function test_an_ended_add_on_with_no_live_resource_is_not_listed(): void {
		$user = $this->user();

		foreach ( array( 'expired', 'revoked' ) as $status ) {
			list( $id ) = $this->disk( $user, $status, 'closed' );
			$this->assertNotContains( $id, $this->others( $user ), $status );
		}

		$this->assertSame( array(), $this->others( $user ) );

		wp_set_current_user( $user );
		ob_start();
		Servers::render( $this->views( $user ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Manage subscription', $html );
		$this->assertStringNotContainsString( 'Expired', $html );
	}

	public function test_an_ended_add_on_that_is_expired_by_its_date_is_not_listed(): void {
		$user       = $this->user();
		list( $id ) = $this->disk( $user, 'active', 'closed' );
		Licenses::update( $id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );

		$this->assertSame( array(), $this->others( $user ) );
	}

	public function test_a_live_add_on_and_one_still_being_paid_are_listed(): void {
		$user = $this->user();
		list( $active ) = $this->disk( $user, 'active' );
		list( $held )   = $this->disk( $user, 'suspended' );
		list( $lapsed ) = $this->disk( $user, 'expired' );

		$this->assertEqualsCanonicalizing( array( $active, $held, $lapsed ), $this->others( $user ) );
	}

	public function test_an_ended_main_service_with_a_deleted_resource_is_in_no_list_and_not_counted(): void {
		$user = $this->user();
		$this->db( $user, 'expired', true );
		$this->db( $user, 'revoked', true, 'deleted' );
		$live = $this->db( $user, 'active' );

		$area   = ServiceArea::get( \BeltoftServices\Account\Databases::class );
		$sorted = $area->sort( $this->views( $user ) );

		$this->assertSame( array( $live ), array_keys( $sorted['members'] ) );
		$this->assertSame( 1, $this->counts( $user )['databases'] ?? 0 );
	}

	public function test_a_service_paused_after_a_lapse_that_still_exists_is_listed_and_counted(): void {
		$user   = $this->user();
		$lapsed = $this->db( $user, 'expired', false, 'paused' );

		$area   = ServiceArea::get( \BeltoftServices\Account\Databases::class );
		$sorted = $area->sort( $this->views( $user ) );

		$this->assertSame( array( $lapsed ), array_keys( $sorted['members'] ) );
		$this->assertSame( 1, $this->counts( $user )['databases'] ?? 0 );
	}

	public function test_a_suspended_service_is_listed_and_counted(): void {
		$user = $this->user();
		$held = $this->db( $user, 'suspended' );

		$area = ServiceArea::get( \BeltoftServices\Account\Databases::class );

		$this->assertSame( array( $held ), array_keys( $area->sort( $this->views( $user ) )['members'] ) );
		$this->assertSame( 1, $this->counts( $user )['databases'] ?? 0 );
	}

	public function test_an_ended_add_on_is_not_counted_on_the_servers_tab(): void {
		$user = $this->user();
		$this->disk( $user, 'expired', 'closed' );
		list( $live ) = $this->disk( $user, 'active' );

		$this->assertSame( array( $live ), $this->others( $user ) );
		$this->assertSame( 1, $this->counts( $user )[ \BeltoftServices\Account\ServerLinks::slug() ] ?? 0 );
	}

	public function test_the_address_of_a_hidden_add_on_is_a_not_found_line_with_the_list(): void {
		$user       = $this->user();
		list( $id ) = $this->disk( $user, 'expired', 'closed' );
		wp_set_current_user( $user );
		$GLOBALS['wp']->query_vars['vps'] = (string) $id;

		ob_start();
		Servers::render( $this->views( $user ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'That server was not found. Here are your servers.', $html );
		$this->assertSame( '', Servers::redirect_target() );
	}

	private function domain( int $user, string $name, string $status, string $state = 'ready', bool $closed = false ): int {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new \BeltoftServices\Domains\DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => $state, 'desired' => 'deleted' === $state ? 'deleted' : 'ready' ) );

		if ( $closed ) {
			Resource::find( $r->id() )->close();
		}

		Licenses::update( (int) $license['id'], array( 'status' => $status ) );

		return (int) $license['id'];
	}

	public function test_an_ended_and_removed_domain_is_hidden_and_not_counted_a_removed_one_with_a_live_licence_stays(): void {
		$user = $this->user();
		$this->domain( $user, 'ended-removed.example', 'expired', 'deleted' );
		$this->domain( $user, 'ended-closed.example', 'revoked', 'deleted', true );
		$removed = $this->domain( $user, 'removed-live.example', 'active', 'deleted' );
		$paused  = $this->domain( $user, 'lapsed.example', 'expired', 'paused' );
		$live    = $this->domain( $user, 'live.example', 'active' );

		$area   = ServiceArea::get( \BeltoftServices\Account\Domains::class );
		$sorted = $area->sort( $this->views( $user ) );

		$this->assertEqualsCanonicalizing( array( $removed, $paused, $live ), array_keys( $sorted['members'] ) );
		$this->assertSame( 3, $this->counts( $user )['domains'] ?? 0 );

		wp_set_current_user( $user );
		ob_start();
		$area->render( $this->views( $user ) );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'ended-removed.example', $html );
		$this->assertStringContainsString( 'live.example', $html );

		\BeltoftServices\Account\Overview::reset();
		$names = array_column( \BeltoftServices\Account\Overview::data()['domains'], 'name' );
		$this->assertNotContains( 'ended-removed.example', $names );
		$this->assertContains( 'removed-live.example', $names );
	}

	public function test_the_address_of_a_hidden_domain_is_the_not_found_line(): void {
		$user = $this->user();
		$id   = $this->domain( $user, 'ended-removed.example', 'expired', 'deleted' );
		wp_set_current_user( $user );
		$GLOBALS['wp']->query_vars['domains'] = (string) $id;

		ob_start();
		ServiceArea::get( \BeltoftServices\Account\Domains::class )->render( $this->views( $user ) );
		$html = (string) ob_get_clean();
		unset( $GLOBALS['wp']->query_vars['domains'] );

		$this->assertStringContainsString( 'not found', strtolower( $html ) );
	}
}
