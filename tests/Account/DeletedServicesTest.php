<?php
/**
 * A deleted pay-as-you-go service is not listed as live. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\Databases;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Servers;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Usage\UsageTestCase;
use BeltoftServices\Usage\CustomerDelete;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

/**
 * Lists, Overview, detail pages and the renewal line after a customer delete.
 */
final class DeletedServicesTest extends UsageTestCase {

	public function tear_down(): void {
		Overview::reset();
		unset( $GLOBALS['wp']->query_vars['vps'], $GLOBALS['wp']->query_vars['databases'] );
		parent::tear_down();
	}

	private function views( Resource ...$resources ): array {
		return array_map( static fn( Resource $r ): array => View::license( Licenses::find( (int) $r->license()['id'] ) ), $resources );
	}

	private function db( int $user, int $account, string $name ): Resource {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'email' => self::MAIL, 'source' => 'usage', 'subscription_id' => $account ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'server_name' => $name ) );
		$this->activated_ago( $r->id(), 5 );

		return Resource::find( $r->id() );
	}

	/**
	 * @return array{0: int, 1: Resource, 2: Resource, 3: int} user, deleted server, live server, account.
	 */
	private function servers(): array {
		$v       = $this->usage_product();
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$gone    = $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() );
		$gone->set( array( 'server_name' => 'gone-vps' ) );
		$live = $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() );
		$live->set( array( 'server_name' => 'live-vps' ) );
		$this->assertTrue( CustomerDelete::run( Resource::find( $gone->id() ), $user, 'gone-vps' ) );
		$this->run_jobs();
		wp_set_current_user( $user );
		Overview::reset();

		return array( $user, $gone, $live, $account->get_id() );
	}

	public function test_a_deleted_usage_server_is_not_listed_and_an_active_one_is(): void {
		list( , $gone, $live ) = $this->servers();

		ob_start();
		Servers::render( $this->views( $gone, $live ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'live-vps', $html );
		$this->assertStringNotContainsString( 'gone-vps', $html );
		$this->assertStringNotContainsString( 'Working', $html );

		$names = array_column( Overview::data()['servers'], 'name' );
		$this->assertSame( array( 'live-vps' ), $names );
		$this->assertCount( 1, Servers::rows( $this->views( $gone, $live ) ) );
	}

	public function test_a_deleted_server_that_is_still_open_but_in_state_deleted_is_not_listed(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );
		$r->set( array( 'state' => 'deleted', 'desired' => 'deleted' ) );
		$this->assertSame( array(), Servers::rows( $this->views( Resource::find( $r->id() ) ) ) );
	}

	public function test_a_deleted_database_is_not_listed_and_an_active_one_is(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$gone    = $this->db( $user, $account->get_id(), 'gone-db' );
		$live    = $this->db( $user, $account->get_id(), 'live-db' );
		$this->assertTrue( CustomerDelete::run( $gone, $user, 'DELETE' ) );
		$this->run_jobs();
		wp_set_current_user( $user );
		Overview::reset();

		$area = ServiceArea::get( Databases::class );
		ob_start();
		$area->render( $this->views( $gone, $live ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="beltoft-service-' . $live->id() . '"', $html );
		$this->assertStringNotContainsString( 'id="beltoft-service-' . $gone->id() . '"', $html );
		$this->assertCount( 1, Overview::data()['databases'] );
	}

	public function test_the_address_of_a_deleted_server_says_so_with_a_link_back(): void {
		list( , $gone, $live ) = $this->servers();
		$license               = Licenses::find( (int) $gone->license()['id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $license['id'];

		ob_start();
		Servers::render( $this->views( $gone, $live ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'This server was deleted.', $html );
		$this->assertStringContainsString( 'All servers', $html );
		$this->assertStringNotContainsString( 'Working', $html );
		$this->assertStringNotContainsString( 'live-vps', $html );
		$this->assertStringNotContainsString( 'That server was not found', $html );
	}

	public function test_the_address_of_a_deleted_database_says_so(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$gone    = $this->db( $user, $account->get_id(), 'gone-db' );
		$this->assertTrue( CustomerDelete::run( $gone, $user, 'DELETE' ) );
		$this->run_jobs();
		wp_set_current_user( $user );
		$GLOBALS['wp']->query_vars['databases'] = (string) $gone->license()['id'];

		ob_start();
		ServiceArea::get( Databases::class )->render( $this->views( $gone ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'This database was deleted.', $html );
		$this->assertStringContainsString( 'All databases', $html );
		$this->assertStringNotContainsString( 'Working', $html );
	}

	public function test_a_revoked_license_shows_no_renewal_line_but_an_active_one_does(): void {
		list( , $gone, $live, $account ) = $this->servers();
		$sub = wcs_get_subscription( $account );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
		$sub->save();

		$this->assertSame( '', ServerLinks::renewal( Licenses::find( (int) $gone->license()['id'] ) ) );
		$this->assertStringStartsWith( 'Renews', ServerLinks::renewal( Licenses::find( (int) $live->license()['id'] ) ) );
	}

	public function test_the_heads_of_pay_as_you_go_services_show_no_renewal_and_have_the_state_line_and_the_buttons(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$sub     = wcs_get_subscription( $account->get_id() );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
		$sub->save();
		$db = $this->db( $user, $account->get_id(), 'live-db' );
		wp_set_current_user( $user );
		$this->assertStringStartsWith( 'Renews', ServerLinks::renewal( Licenses::find( (int) $db->license()['id'] ) ) );

		$GLOBALS['wp']->query_vars['databases'] = (string) $db->license()['id'];
		ob_start();
		ServiceArea::get( Databases::class )->render( $this->views( $db ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'beltoft-services-page__renews', $html, 'A pay as you go database shows no renewal date.' );
		$this->assertStringContainsString( 'data-bs-inline', $html );
		$this->assertSame( 1, substr_count( $html, 'data-bs-inline' ), 'One state line per page.' );
		$this->assertMatchesRegularExpression( '#beltoft-services-page__bar.*All databases.*beltoft-services-page__name#s', $html, 'The back link is on the row above the name.' );
		$this->assertMatchesRegularExpression( '#data-badge>Ready</span>\s*<span class="bs-inline" data-bs-inline#', $html, 'The line follows the state indicator.' );

		// A monthly (not pay as you go) database keeps its renewal date.
		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'source' => 'order' ), array( 'id' => (int) $db->license()['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		ob_start();
		ServiceArea::get( Databases::class )->render( $this->views( $db ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'beltoft-services-page__renews">Renews', $html );
	}

	public function test_the_head_of_a_pay_as_you_go_server_has_the_buttons_beside_the_back_link_and_no_renewal(): void {
		list( , , $live, $account ) = $this->servers();
		$sub = wcs_get_subscription( $account );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
		$sub->save();
		$live = Resource::find( $live->id() );
		\BeltoftServices\Proxmox\Vm\Operations::remember_power( $live->id(), 'running' );
		$license = Licenses::find( (int) $live->license()['id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $license['id'];

		ob_start();
		Servers::render( $this->views( $live ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Renews', $html );
		$this->assertSame( 1, substr_count( $html, 'data-bs-inline' ), 'One state line per page.' );
		$this->assertMatchesRegularExpression( '#beltoft-services-page__bar.*All servers.*beltoft-services-page__actions.*data-power-action="reboot".*beltoft-services-page__name#s', $html );
		$this->assertMatchesRegularExpression( '#data-power-line>Running</span>\s*<span class="bs-inline" data-bs-inline#', $html );
		$this->assertStringContainsString( 'data-rename-open', $html, 'The name opens the rename field.' );
	}

	public function test_an_expired_monthly_server_is_still_listed(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['m-None'], $user, 5 );
		$r->set( array( 'server_name' => 'monthly-vps' ) );
		Licenses::update( (int) $r->license()['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$rows = Servers::rows( $this->views( Resource::find( $r->id() ) ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'monthly-vps', $rows[0]['name'] );
	}

	public function test_a_service_being_deleted_is_gone_for_the_lists_at_once(): void {
		$v       = $this->usage_product();
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$r       = $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() );
		$r->set( array( 'server_name' => 'going-vps' ) );

		// Asked to delete, jobs not run yet: still live, still in a working state.
		$this->assertTrue( CustomerDelete::run( Resource::find( $r->id() ), $user, 'going-vps' ) );
		$still = Resource::find( $r->id() );
		$this->assertNotNull( $still );
		$this->assertTrue( Resource::gone( $still ), 'Asked to delete: not listed while the deletion runs.' );

		wp_set_current_user( $user );
		ob_start();
		Servers::render( $this->views( $still ) );
		$this->assertStringNotContainsString( 'going-vps', (string) ob_get_clean() );
	}
}
