<?php
/** Extra disks and snapshot packs live on the server page, not on the Servers list. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Servers;
use BeltoftServices\Account\Tabs;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DiskGuide;
use BeltoftServices\Proxmox\SnapshotDriver;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class ServerAddonsTest extends SnapshotsTestCase {

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'], $GLOBALS['wp']->query_vars['servers'] );
		remove_all_filters( 'beltoft_services_servers_slug' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function pack( Resource $server, int $slots = 2 ): Resource {
		$product = $this->service_product( 'proxmox-snapshots', array( 'snapshot_slots' => $slots ), array( 'slug' => 'snap-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server->license()['user_id'], 'email' => 'customer@example.com' ) );
		$pack    = Resource::create( $license, Registry::get( SnapshotDriver::ID ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		Jobs::run( $pack->id() );

		return $this->fresh( $pack );
	}

	/** The views of the customer's licenses, as the Servers tab gets them. */
	private function views( Resource ...$resources ): array {
		wp_set_current_user( (int) $resources[0]->license()['user_id'] );

		return array_map( static fn( Resource $r ): array => View::license( $r->license() ), $resources );
	}

	private function render( array $views, string $value = '' ): string {
		$GLOBALS['wp']->query_vars['vps'] = $value;
		ob_start();
		Servers::render( $views );

		return (string) ob_get_clean();
	}

	public function test_the_list_has_no_card_for_a_disk_or_a_pack_of_a_server_in_the_list(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		$pack   = $this->pack( $server );
		$html   = $this->render( $this->views( $server, $disk, $pack ) );

		$this->assertSame( 1, substr_count( $html, 'class="beltoft-services-srv"' ) );
		$this->assertStringNotContainsString( 'beltoft-licenses', $html, 'No default license card (and so no status chip).' );
		$this->assertStringNotContainsString( 'beltoft-services-badge', $html );
		$this->assertStringNotContainsString( 'beltoft-services-srv--addon', $html );
		$this->assertStringNotContainsString( $disk->license()['id'] . '/', $html );
	}

	public function test_an_add_on_without_its_server_in_the_list_is_a_plain_card_with_no_chip(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		$html   = $this->render( $this->views( $disk ) );

		$this->assertStringContainsString( 'beltoft-services-srv--addon', $html );
		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringNotContainsString( 'beltoft-services-badge', $html );
		$this->assertStringNotContainsString( 'beltoft-licenses', $html );
		$this->assertStringNotContainsString( 'You have no servers yet', $html );
	}

	public function test_the_disks_tab_carries_the_setup_guide_and_the_restart_note(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		$disk->set( array( 'needs_reboot' => true ) );
		$html = $this->render( $this->views( $server, $disk ), $server->license()['id'] . '/disks' );

		$this->assertStringContainsString( '<details class="beltoft-services-disk__guide" id="beltoft-disk-guide-' . $disk->id() . '">', $html );
		$this->assertStringContainsString( 'sudo mkfs.ext4 /dev/DEVICE', $html );
		$this->assertStringContainsString( 'Restart the server if the disk does not appear yet.', $html );
		$this->assertMatchesRegularExpression( '/<section[^>]*data-tab="disks">/', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-tab="disks" hidden/', $html );
	}

	public function test_a_paused_disk_has_no_guide_but_its_note(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		$disk->set( array( 'state' => 'paused' ) );
		$html = $this->render( $this->views( $server, $disk ), $server->license()['id'] . '/disks' );

		$this->assertStringNotContainsString( 'beltoft-disk-guide-', $html );
		$this->assertStringContainsString( 'This disk is detached from your server', $html );
	}

	public function test_the_snapshots_tab_shows_the_slots_a_pack_adds(): void {
		$server = $this->server();
		$this->pack( $server, 2 );
		$html = $this->render( $this->views( $server ), $server->license()['id'] . '/snapshots' );

		$this->assertStringContainsString( 'Snapshot slots: +2', $html );
		$this->assertStringContainsString( 'Added to your server.', $html );
		$this->assertStringNotContainsString( 'Open your server', $html );
	}

	public function test_the_guide_link_is_the_disks_tab_of_the_server(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );

		$this->assertSame( Servers::url( $server->license(), 'disks' ) . '#' . DiskGuide::anchor( $disk->id() ), DiskGuide::guide_url( $disk ) );
		$this->assertSame( Servers::url( $server->license(), 'disks' ) . '#beltoft-service-' . $server->id(), DiskGuide::server_url( $disk ) );
		$this->assertSame( Servers::url( $server->license(), 'disks' ) . '#x', DiskGuide::card_url( $server, 'x' ) );
		$this->assertSame( Servers::url( $server->license(), 'snapshots' ) . '#x', DiskGuide::card_url( $server, 'x', 'snapshots' ) );
	}

	public function test_a_disks_or_packs_own_address_goes_to_the_server_tab(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		$pack   = $this->pack( $server );
		wp_set_current_user( (int) $server->license()['user_id'] );

		$GLOBALS['wp']->query_vars['vps'] = (string) $disk->license()['id'];
		$this->assertSame( Servers::url( $server->license(), 'disks' ) . '#' . DiskGuide::anchor( $disk->id() ), Servers::redirect_target() );
		$GLOBALS['wp']->query_vars['vps'] = (string) $pack->license()['id'];
		$this->assertSame( Servers::url( $server->license(), 'snapshots' ), Servers::redirect_target() );
		$GLOBALS['wp']->query_vars['vps'] = (string) $server->license()['id'];
		$this->assertSame( '', Servers::redirect_target(), 'A server stays.' );
		$GLOBALS['wp']->query_vars['vps'] = '';
		$this->assertSame( '', Servers::redirect_target() );
	}

	public function test_another_customers_add_on_does_not_redirect(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		wp_set_current_user( self::factory()->user->create() );
		$GLOBALS['wp']->query_vars['vps'] = (string) $disk->license()['id'];

		$this->assertSame( '', Servers::redirect_target() );
	}

	public function test_the_slug_filter_moves_the_tab_the_endpoint_and_the_urls_together(): void {
		add_filter( 'beltoft_services_servers_slug', static fn() => 'servers' );
		$server = $this->server();
		$slugs  = array_column( Tabs::groups( array() ), 'slug' );

		$this->assertContains( 'servers', $slugs );
		$this->assertNotContains( 'vps', $slugs );
		$this->assertStringContainsString( 'servers', Servers::url( $server->license(), 'disks' ) );
		$this->assertStringNotContainsString( 'vps', Servers::url( $server->license() ) );
		$GLOBALS['wp']->query_vars['servers'] = $server->license()['id'] . '/disks';
		$this->assertSame( array( 'id' => (int) $server->license()['id'], 'tab' => 'disks' ), Servers::requested() );
	}

	private function mail_for( Resource $r, array $data = array(), string $email = 'beltoft_services_ready' ): string {
		$this->mails = array();
		$mail        = Emails::get( $email );
		$mail->email_type = 'plain';
		$mail->trigger( $r, $data );
		$this->assertNotEmpty( $this->mails, 'The email was sent.' );
		$mail->email_type = 'html';

		return (string) $this->mails[0]['message'];
	}

	public function test_emails_link_to_the_server_page_on_the_right_tab(): void {
		$server = $this->server();
		$server->license();
		$disk = $this->disk_for( $server );
		$pack = $this->pack( $server );
		foreach ( array( $server, $disk, $pack ) as $r ) {
			$lic = $r->license();
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'email' => 'customer@example.com' ), array( 'id' => $lic['id'] ) );
		}
		$this->assertStringContainsString( Servers::url( $server->license() ), $this->mail_for( $this->fresh( $server ) ) );
		$this->assertStringContainsString( Servers::url( $server->license(), 'disks' ), $this->mail_for( $this->fresh( $disk ) ) );
		$this->assertStringContainsString( Servers::url( $server->license(), 'snapshots' ), $this->mail_for( $this->fresh( $pack ) ) );
	}

	public function test_the_pass_through_saves_a_lookup_per_server(): void {
		global $wpdb;
		$server = $this->server();
		$views  = $this->views( $server );
		$known  = array( (int) $views[0]['id'] => $this->fresh( $server ) );

		$before = $wpdb->num_queries;
		Servers::rows( $views, $known );
		$with = $wpdb->num_queries - $before;
		$before = $wpdb->num_queries;
		Servers::rows( $views );
		$without = $wpdb->num_queries - $before;

		$this->assertLessThan( $without, $with );
	}
}
