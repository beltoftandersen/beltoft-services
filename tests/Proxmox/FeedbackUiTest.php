<?php
/**
 * Waiting and feedback on the server card: the progress line, the confirm dialogs, the done line and
 * the ending extra disk. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Progress;
use BeltoftServices\Proxmox\DiskPanels;
use BeltoftServices\Proxmox\Panels;
use BeltoftServices\Resource;
use BeltoftServices\ThankYou;

require_once __DIR__ . '/SnapshotsTestCase.php';

class FeedbackUiTest extends SnapshotsTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// WordPress' test rollback reuses post IDs; WooCommerce's custom order-item tables persist between tests.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_items' );
		// phpcs:enable
	}

	private function card( Resource $r ): string {
		ob_start();
		Panels::account( $this->fresh( $r ) );

		return (string) ob_get_clean();
	}

	private function subscription( int $user ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->set_requires_manual_renewal( true );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		$sub->save();
		global $wpdb;
		$wpdb->delete( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture reset.

		return $sub;
	}

	/** @return array{0: Resource, 1: Resource, 2: \WC_Subscription} server, disk, the disk's subscription */
	private function server_and_disk(): array {
		$server = $this->server();
		$user   = (int) $server->license()['user_id'];
		wp_set_current_user( $user );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$sub     = $this->subscription( $user );
		$sub->add_product( $product, 1, array( 'subtotal' => '5', 'total' => '5' ) );
		$sub->save();
		$this->assertTrue( \BeltoftServices\Proxmox\DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$disk    = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		Jobs::run( $disk->id() );
		$this->assertSame( 'ready', Resource::find( $disk->id() )->get( 'state' ) );

		return array( $server, Resource::find( $disk->id() ), $sub );
	}

	// -- Item 7: the busy indicator ----------------------------------------------------------------

	public function test_the_busy_indicator_is_three_squares_not_a_turning_one(): void {
		foreach ( array( 'assets/account.css', 'assets/thankyou.css' ) as $file ) {
			$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . $file );

			$this->assertStringNotContainsString( 'beltoft-services-spin', $css, $file );
			$this->assertStringNotContainsString( 'rotate(360deg)', $css, $file );
			$this->assertStringContainsString( '@keyframes beltoft-services-dots', $css, $file );
			$this->assertStringContainsString( 'prefers-reduced-motion', $css, $file );
		}

		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$this->assertMatchesRegularExpression( '/\.is-busy::before[^{]*\{[^}]*beltoft-services-dots/s', $css );
	}

	// -- Items 9 and 13: the dialogs and the card's own line ---------------------------------------

	public function test_the_card_has_one_confirm_dialog_the_flash_line_and_the_power_for_the_done_line(): void {
		$r    = $this->server();
		$html = $this->card( $r );

		$this->assertSame( 1, substr_count( $html, 'data-confirm-dialog' ) );
		$this->assertStringContainsString( 'data-flash role="status" aria-live="polite" hidden', $html );
		$this->assertStringContainsString( 'data-confirm-submit', $html );
		$this->assertStringContainsString( 'data-confirm-cancel', $html );
		$this->assertMatchesRegularExpression( '/class="beltoft-services-vm" data-power="(running|stopped|)"/', $html );
	}

	public function test_the_reinstall_dialog_lists_what_is_lost_and_counts_the_snapshots(): void {
		$r = $this->server();
		$this->assertTrue( $this->fresh( $r )->set( array( 'ssh_key' => '' ) ) );
		$html = $this->card( $r );

		$this->assertStringContainsString( 'data-confirm-title="Reinstall your server?"', $html );
		$this->assertStringContainsString( 'data-confirm-yes="Reinstall now"', $html );
		$this->assertStringContainsString( 'Everything on the server is erased and the system is installed again. All data on it is lost.', $html );
		$this->assertStringContainsString( 'You get a new password.', $html );
		$this->assertStringNotContainsString( 'Your SSH key stays on the server.', $html );
		$this->assertStringNotContainsString( 'snapshots are deleted first', $html );
		$this->assertStringNotContainsString( 'snapshot is deleted first', $html );

		$this->take( $r, 'one' );
		$this->assertStringContainsString( 'Your 1 snapshot is deleted first.', $this->card( $r ) );

		$this->take( $r, 'two' );
		$this->assertStringContainsString( 'All 2 of your snapshots are deleted first.', $this->card( $r ) );

		$this->assertTrue( $this->fresh( $r )->set( array( 'ssh_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA test' ) ) );
		$this->assertStringContainsString( 'You get a new password. Your SSH key stays on the server.', $this->card( $r ) );
	}

	public function test_the_restore_dialog_says_what_is_replaced(): void {
		$r = $this->server();
		$this->assertTrue( $this->fresh( $r )->set( array( 'backup_list' => array( array( 'volid' => 'backup-store:backup/vzdump-qemu-5000.vma', 'ctime' => time(), 'size' => 1048576 ) ) ) ) );
		$html = $this->card( $r );

		$this->assertStringContainsString( 'data-confirm-title="Restore this backup?"', $html );
		$this->assertStringContainsString( 'Everything on the server is replaced with the backup.', $html );
		$this->assertStringNotContainsString( 'The snapshots of this server are deleted.', $html );

		$this->take( $r, 'one' );
		$this->assertStringContainsString( 'The snapshots of this server are deleted.', $this->card( $r ) );
	}

	public function test_the_card_script_gets_the_start_and_done_lines_and_never_asks_the_browser(): void {
		do_action( 'beltoft_services_account_assets', 'beltoft-services-account' );
		$data = (string) wp_scripts()->get_data( 'beltoft-services-vm', 'data' );

		foreach ( array( 'rollbackStart', 'rollbackDone', 'restoreStart', 'restoreDone', 'reinstallStart', 'reinstallDone', 'deleteStart', 'deleteDone', 'running', 'deleteTitle' ) as $key ) {
			$this->assertStringContainsString( $key, $data );
		}

		$this->assertStringContainsString( 'Going back to snapshot %s. The server is off for a moment.', $data );
		$this->assertStringContainsString( 'Back on snapshot %s.', $data );
		$this->assertStringNotContainsString( 'This cannot be undone', $data );

		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$this->assertStringNotContainsString( 'window.confirm', $js );
		$this->assertStringContainsString( 'sessionStorage', $js );
	}

	public function test_an_unnamed_snapshot_is_named_by_its_date_in_the_buttons(): void {
		$r = $this->server();
		$this->act( $r, 'snapshot_take', array( 'label' => '' ) );
		$html = $this->card( $r );

		$this->assertMatchesRegularExpression( '/data-snapshot-rollback="[^"]+" data-snapshot-name="[^"]+\d[^"]*"/', $html );
		$this->assertMatchesRegularExpression( '/data-snapshot-delete="[^"]+" data-snapshot-name="[^"]+\d[^"]*"/', $html );
	}

	// -- Item 11: removing an extra disk ------------------------------------------------------------

	public function test_remove_disk_opens_a_dialog_with_the_date_the_snapshots_and_undo(): void {
		list( $server, , $sub ) = $this->server_and_disk();
		$this->take( $server, 'with disk' );
		$until = date_i18n( get_option( 'date_format' ), (int) $sub->get_time( 'next_payment', 'site' ) );
		$item  = DiskPanels::server( $this->fresh( $server ) )['items'][0];

		$this->assertNotSame( '', $item['remove_url'] );
		$this->assertSame( $until, $item['paid_until'] );
		$this->assertSame( array( 'with disk' ), $item['snapshot_labels'] );
		$this->assertNull( $item['ending'] );

		$html = $this->card( $server );
		$this->assertStringContainsString( 'data-confirm-open="beltoft-vm-disk-confirm-' . $item['id'] . '" data-confirm-url', $html );
		$this->assertStringContainsString( 'id="beltoft-vm-disk-confirm-' . $item['id'] . '"', $html );
		$this->assertStringContainsString( 'The disk stays on your server until its paid period ends on ' . $until . '.', $html );
		$this->assertStringContainsString( 'The snapshots that include this disk are deleted with it:', $html );
		$this->assertStringContainsString( '<li>with disk</li>', $html );
		$this->assertStringContainsString( 'You can undo this until ' . $until . '.', $html );
		$this->assertStringContainsString( 'After that the disk is detached from your server. Its data is kept until we remove the disk.', $html );
		$this->assertStringNotContainsString( 'data-disk-ending', $html );

		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->assertMatchesRegularExpression( '/(The|the) disk and its data are deleted/', $this->card( $server ) );
	}

	public function test_a_disk_without_snapshots_lists_none(): void {
		list( $server ) = $this->server_and_disk();

		$this->assertStringNotContainsString( 'are deleted with it:', $this->card( $server ) );
	}

	public function test_an_ended_disk_subscription_shows_the_date_and_undo_instead_of_remove(): void {
		list( $server, , $sub ) = $this->server_and_disk();
		$sub->update_status( 'pending-cancel' );
		$when = date_i18n( get_option( 'date_format' ), (int) wcs_get_subscription( $sub->get_id() )->get_time( 'end', 'site' ) );
		$item = DiskPanels::server( $this->fresh( $server ) )['items'][0];

		$this->assertSame( '', $item['remove_url'] );
		$this->assertSame( $when, $item['ending']['date'] );
		$this->assertStringContainsString( 'change_subscription_to=active', $item['ending']['undo_url'] );

		$html = $this->card( $server );
		$this->assertStringContainsString( 'data-disk-ending', $html );
		$this->assertStringContainsString( 'The extra disk will be removed on ' . $when . '.', $html );
		$this->assertMatchesRegularExpression( '/data-subscription-link>Undo<\/a>/', $html );
		$this->assertStringNotContainsString( 'data-confirm-url', $html, 'Nothing to remove twice.' );
	}

	public function test_the_new_texts_have_no_em_dash_and_no_rounded_corners(): void {
		foreach ( array( 'assets/vm.js', 'assets/account.css', 'assets/thankyou.css', 'templates/account/vm.php', 'src/Proxmox/DiskPanels.php' ) as $file ) {
			$this->assertStringNotContainsString( "\u{2014}", (string) file_get_contents( BELTOFT_SERVICES_PATH . $file ), $file );
		}

		$css  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$mine = substr( $css, (int) strpos( $css, '@keyframes beltoft-services-dots' ) );
		$this->assertStringNotContainsString( 'border-radius', $mine );
		$this->assertStringNotContainsString( 'border-radius', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/thankyou.css' ) );
	}
}
