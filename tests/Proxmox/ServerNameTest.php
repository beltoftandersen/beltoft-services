<?php
/**
 * The server name: the VM name and hostname, the checkout field, rename, display and the disk labels.
 * Fake cluster only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\DiskMeta;
use BeltoftServices\Proxmox\Provision;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class ServerNameTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_items' );
		// phpcs:enable
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		unset( $_POST[ ServerName::POST_KEY ] );
		WC()->cart->empty_cart();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function route( int $id, int $user, string $action, array $args = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/actions/' . $action );
		$request->set_param( 'args', $args );

		return rest_get_server()->dispatch( $request );
	}

	private function user_of( Resource $r ): int {
		return (int) $r->license()['user_id'];
	}

	// -- Validation ---------------------------------------------------------------------------

	/**
	 * @dataProvider names
	 * @param mixed       $input    Entry.
	 * @param string|null $expected Canonical name, '' or null for refused.
	 */
	public function test_normalise( $input, ?string $expected ): void {
		$this->assertSame( $expected, ServerName::normalise( $input ) );
	}

	public static function names(): array {
		return array(
			'plain'                 => array( 'web-01', 'web-01' ),
			'uppercase lowered'     => array( 'Web-01', 'web-01' ),
			'spaces to hyphens'     => array( 'My Server', 'my-server' ),
			'underscores'           => array( 'my_server_2', 'my-server-2' ),
			'trimmed'               => array( "  db1 \n", 'db1' ),
			'empty is allowed'      => array( '', '' ),
			'blank is empty'        => array( '   ', '' ),
			'one character'         => array( 'a', 'a' ),
			'digits only'           => array( '123', '123' ),
			'63 characters'         => array( str_repeat( 'a', 63 ), str_repeat( 'a', 63 ) ),
			'64 characters'         => array( str_repeat( 'a', 64 ), null ),
			'starts with hyphen'    => array( '-web', null ),
			'ends with hyphen'      => array( 'web-', null ),
			'a space at the end is trimmed first' => array( 'web ', 'web' ),
			'only a hyphen'         => array( '-', null ),
			'dot'                   => array( 'web.example', null ),
			'danish letter'         => array( "bl\u{00E5}b\u{00E6}r", null ),
			'inner newline'         => array( "a\nb", null ),
			'inner tab'             => array( "a\tb", null ),
			'tag'                   => array( '<b>x</b>', null ),
			'null byte'             => array( "a\0b", null ),
			'invalid utf8'          => array( "a\xffb", null ),
			'not a string'          => array( 5, null ),
			'array'                 => array( array( 'a' ), null ),
			'null'                  => array( null, null ),
		);
	}

	// -- Checkout -----------------------------------------------------------------------------

	private function vm_product(): \WC_Product {
		return $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
	}

	private function html(): string {
		ob_start();
		ServerName::render_classic();

		return (string) ob_get_clean();
	}

	private function check( $value ): array {
		do_action( 'woocommerce_checkout_process' );

		if ( null === $value ) {
			unset( $_POST[ ServerName::POST_KEY ] );
		} else {
			$_POST[ ServerName::POST_KEY ] = $value;
		}

		$errors = new \WP_Error();
		ServerName::validate_classic( array(), $errors );

		return $errors->get_error_messages();
	}

	public function test_the_field_is_optional_and_asked_only_for_a_new_server(): void {
		$this->assertSame( '', $this->html(), 'Empty cart.' );

		$vm = $this->vm_product();
		WC()->cart->add_to_cart( $vm->get_id() );
		$html = $this->html();
		$this->assertStringContainsString( 'name="' . ServerName::POST_KEY . '"', $html );
		$this->assertStringContainsString( 'Server name', $html );
		$this->assertStringContainsString( 'hostname', $html, 'The hint says what the name is used for.' );
		$this->assertStringNotContainsString( 'validate-required', $html );

		// A renewal is not a new server.
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $vm->get_id(), 1, 0, array(), array( 'subscription_renewal' => array( 'subscription_id' => 1, 'item_id' => 0, 'next_payment_timestamp' => 0, 'upgraded_or_downgraded' => '' ) ) );
		$this->assertSame( '', $this->html() );
	}

	public function test_classic_validation_refuses_a_bad_name_and_accepts_empty_and_good_ones(): void {
		WC()->cart->add_to_cart( $this->vm_product()->get_id() );

		$this->assertSame( array(), $this->check( null ) );
		$this->assertSame( array(), $this->check( '' ) );
		$this->assertSame( array(), $this->check( 'Web 01' ) );
		$this->assertCount( 1, $this->check( 'web.01' ) );
		$this->assertCount( 1, $this->check( str_repeat( 'a', 64 ) ) );
		$this->assertStringContainsString( 'hostname', $this->check( '-x' )[0] );
		$this->assertStringNotContainsString( '-x', $this->check( '-x' )[0], 'The entry is not echoed.' );
	}

	public function test_the_name_is_saved_normalised_on_the_server_line_only(): void {
		$vm = $this->vm_product();
		WC()->cart->add_to_cart( $vm->get_id() );
		$_POST[ ServerName::POST_KEY ] = 'My Server';

		$probe = new \WC_Order_Item_Product();
		$probe->set_product( $vm );
		ServerName::save_on_item( $probe );
		$this->assertSame( '', $probe->get_meta( ServerName::ITEM_META ), 'Outside WooCommerce\'s checkout run the post is ignored.' );

		do_action( 'woocommerce_checkout_process' );
		$item = new \WC_Order_Item_Product();
		$item->set_product( $vm );
		ServerName::save_on_item( $item );
		$this->assertSame( 'my-server', $item->get_meta( ServerName::ITEM_META ) );

		$other = new \WC_Order_Item_Product();
		$other->set_product( $this->service_product( 'fake' ) );
		ServerName::save_on_item( $other );
		$this->assertSame( '', $other->get_meta( ServerName::ITEM_META ) );

		$_POST[ ServerName::POST_KEY ] = 'bad.name';
		$bad = new \WC_Order_Item_Product();
		$bad->set_product( $vm );
		ServerName::save_on_item( $bad );
		$this->assertSame( '', $bad->get_meta( ServerName::ITEM_META ), 'A refused name is never stored.' );
	}

	private function license_with( array $item_meta, array $order_meta = array() ): array {
		$vm    = $this->vm_product();
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $vm );
		$item->set_quantity( 1 );

		foreach ( $item_meta as $k => $v ) {
			$item->add_meta_data( $k, $v );
		}

		$order->add_item( $item );

		foreach ( $order_meta as $k => $v ) {
			$order->update_meta_data( $k, $v );
		}

		$order->save();

		return $this->service_license( $vm, array( 'fire' => false, 'order_id' => $order->get_id() ) );
	}

	public function test_the_checkout_name_reaches_the_resource_and_junk_does_not(): void {
		$classic = $this->license_with( array( ServerName::ITEM_META => 'web-01' ) );
		Lifecycle::provide( $classic );
		$this->assertSame( 'web-01', Resource::for_license( (int) $classic['id'] )->get( ServerName::META ) );

		$block = $this->license_with( array(), array( '_wc_other/' . ServerName::BLOCK_FIELD_ID => 'Block Name' ) );
		Lifecycle::provide( $block );
		$this->assertSame( 'block-name', Resource::for_license( (int) $block['id'] )->get( ServerName::META ) );

		foreach ( array( 'bad.name', '-x', '', str_repeat( 'a', 64 ) ) as $junk ) {
			$license = $this->license_with( array( ServerName::ITEM_META => $junk ) );
			Lifecycle::provide( $license );
			$this->assertNull( Resource::for_license( (int) $license['id'] )->get( ServerName::META ), 'Refused: ' . $junk );
		}
	}

	public function test_the_block_field_is_optional_and_validates(): void {
		$args = ServerName::block_field_args();
		$this->assertFalse( $args['required'] );
		$this->assertSame( 'order', $args['location'] );
		$this->assertTrue( ServerName::block_validate( '' ) );
		$this->assertTrue( ServerName::block_validate( 'web-01' ) );
		$this->assertInstanceOf( \WP_Error::class, ServerName::block_validate( 'web.01' ) );
		$this->assertSame( 'web-01', ServerName::block_sanitize( ' Web 01 ' ) );
	}

	// -- The VM name --------------------------------------------------------------------------

	public function test_the_clone_is_named_with_the_server_name(): void {
		$r = $this->ready_vm( array(), array( ServerName::META => 'web-01' ) );

		$clones = $this->calls( 'POST', '/clone' );
		$this->assertSame( 'web-01', $clones[0]['params']['name'] );
		$this->assertSame( 'web-01', $this->fake->vm( (int) $r->get( 'vmid' ) )['name'] );
	}

	public function test_without_a_name_the_clone_keeps_the_vm_uuid_name(): void {
		$r = $this->ready_vm();

		$clones = $this->calls( 'POST', '/clone' );
		$this->assertSame( 'vm-' . substr( (string) $r->get( 'uuid' ), 0, 8 ), $clones[0]['params']['name'] );
		$this->assertSame( $clones[0]['params']['name'], Provision::vm_name( $r ) );
	}

	public function test_a_reinstall_clones_again_with_the_stored_name(): void {
		$r = $this->ready_vm( array(), array( ServerName::META => 'web-01' ) );
		Resource::find( $r->id() )->set( array( ServerName::META => 'db-02' ) );

		Jobs::act( $r->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );

		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
			$this->run_jobs();
		}

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$clones = $this->calls( 'POST', '/clone' );
		$this->assertCount( 2, $clones );
		$this->assertSame( 'db-02', $clones[1]['params']['name'] );
		$this->assertNull( Resource::find( $r->id() )->get( ServerName::PENDING ), 'The rebuilt server has its name.' );
	}

	// -- Rename -------------------------------------------------------------------------------

	public function test_rename_sends_one_config_put_with_the_digest_and_waits_for_a_reboot(): void {
		$r      = $this->ready_vm( array(), array( ServerName::META => 'web-01' ) );
		$vmid   = (int) $r->get( 'vmid' );
		$digest = (string) $this->fake->request( 'GET', '/nodes/node-a/qemu/' . $vmid . '/config' )['digest'];
		$before = count( $this->calls( 'PUT', '/config' ) );

		$this->assertArrayHasKey( ServerName::ACTION, Actions::offered( $r ) );
		$this->assertArrayNotHasKey( ServerName::ACTION, Actions::buttons( Actions::offered( $r ), $r ), 'The card draws its own form.' );

		Jobs::act( $r->id(), 'rename', array( 'name' => 'Web 02' ) );

		$puts = $this->calls( 'PUT', '/config' );
		$this->assertCount( $before + 1, $puts, 'Exactly one config write.' );
		$last = end( $puts );
		$this->assertSame( array( 'name' => 'web-02', 'digest' => $digest ), $last['params'] );
		$this->assertSame( 'web-02', $this->fake->vm( $vmid )['name'] );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'web-02', $fresh->get( ServerName::META ) );
		$this->assertSame( 'web-02', $fresh->get( ServerName::PENDING ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertEmpty( $this->calls( 'POST', '/status/reboot' ), 'Never rebooted automatically.' );
		$this->assertEmpty( $this->calls( 'POST', '/status/shutdown' ) );

		ob_start();
		\BeltoftServices\Proxmox\Panels::account( $fresh );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'The new name is used as the hostname after the next reboot. Your SSH client may warn that the server&#039;s host key changed.', str_replace( "'", '&#039;', $html ) );
		$this->assertMatchesRegularExpression( '#data-hostname-note.*data-action="reboot"#s', $html, 'The existing Reboot action is offered beside it.' );
		$this->assertStringContainsString( 'value="web-02"', $html );
	}

	public function test_a_reboot_ends_the_pending_notice(): void {
		$r = $this->ready_vm();
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertSame( 'web-02', Resource::find( $r->id() )->get( ServerName::PENDING ) );

		Jobs::act( $r->id(), 'reboot' );
		$this->assertNull( Resource::find( $r->id() )->get( ServerName::PENDING ) );
	}

	public function test_a_digest_conflict_is_retried_not_stored_as_done(): void {
		$r = $this->ready_vm();
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 500, 'message' => 'detected modified configuration' ) );

		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );

		$this->assertNull( Resource::find( $r->id() )->get( ServerName::PENDING ) );
	}

	public function test_rename_is_refused_for_a_linked_vm_without_a_write(): void {
		$r = $this->ready_vm();
		$r->set( array( 'linked' => true ) );
		$linked = Resource::find( $r->id() );
		$seen   = count( $this->fake->calls() );

		$this->assertNotNull( Actions::check( $linked, 'rename', array( 'name' => 'web-02' ) ) );
		$this->assertSame( 422, $this->route( $r->id(), $this->user_of( $r ), 'rename', array( 'name' => 'web-02' ) )->get_status() );
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );

		$this->assertCount( $seen, $this->fake->calls(), 'No Proxmox call.' );
		$this->assertNull( Resource::find( $r->id() )->get( ServerName::META ) );

		ob_start();
		\BeltoftServices\Proxmox\Panels::account( $linked );
		$this->assertStringNotContainsString( 'data-action="rename"', (string) ob_get_clean() );
	}

	public function test_rename_refuses_strangers_visitors_paused_servers_and_bad_names(): void {
		$r        = $this->ready_vm();
		$owner    = $this->user_of( $r );
		$stranger = self::factory()->user->create();
		$seen     = count( $this->fake->calls() );

		$this->assertSame( 401, $this->route( $r->id(), 0, 'rename', array( 'name' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->route( $r->id(), $stranger, 'rename', array( 'name' => 'x' ) )->get_status() );

		foreach ( array( '', 'web.01', '-x', str_repeat( 'a', 64 ) ) as $bad ) {
			$response = $this->route( $r->id(), $owner, 'rename', array( 'name' => $bad ) );
			$this->assertSame( 422, $response->get_status(), 'Bad name: ' . $bad );
			$this->assertStringContainsString( 'hostname', $response->get_data()['message'] );
		}

		$r->set( array( 'state' => 'paused' ) );
		$this->assertSame( 403, $this->route( $r->id(), $owner, 'rename', array( 'name' => 'web-02' ) )->get_status(), 'A paused server.' );

		$this->assertCount( $seen, $this->fake->calls() );
		$this->assertNull( Resource::find( $r->id() )->get( ServerName::META ) );

		$r->set( array( 'state' => 'ready' ) );
		$this->assertSame( 202, $this->route( $r->id(), $owner, 'rename', array( 'name' => 'web-02' ) )->get_status() );
	}

	// -- Display ------------------------------------------------------------------------------

	public function test_the_card_title_is_the_name_with_the_product_name_as_fallback(): void {
		$r       = $this->ready_vm();
		$license = $r->license();

		$this->assertSame( 'VPS Starter', ServerName::title( 'VPS Starter', $license ) );
		$this->assertSame( \BeltoftLicenses\Account\View::product_name( $license ), ServerName::display( $r, $license ) );

		$r->set( array( ServerName::META => 'web-01' ) );
		$this->assertSame( 'web-01', ServerName::title( 'VPS Starter', $license ) );
		$this->assertSame( 'web-01', ServerName::display( Resource::find( $r->id() ), $license ) );
		$this->assertSame( 'web-01', apply_filters( 'beltoft_licenses_account_title', 'VPS Starter', $license ) );

		// A name on something that is not a VM is not used.
		$this->assertSame( 'Plugin', ServerName::title( 'Plugin', array( 'id' => 0 ) ) );
	}

	public function test_emails_and_the_thank_you_row_name_the_server(): void {
		$r = $this->ready_vm( array(), array( ServerName::META => 'web-01' ) );

		$email = Emails::get( 'beltoft_services_ready' );
		$this->mails = array();
		$email->trigger( Resource::find( $r->id() ) );
		$this->assertNotEmpty( $this->mails );
		$this->assertSame( 'Your web-01 is ready', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Your web-01 is ready to use.', wp_strip_all_tags( (string) $this->mails[0]['message'] ) );

		$this->assertSame( 'web-01', \BeltoftServices\ThankYou::row( Resource::find( $r->id() ) )['name'] );

		$plain = $this->ready_vm();
		$this->mails = array();
		$email->trigger( $plain );
		$this->assertStringStartsWith( 'Your ', (string) $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( 'web-01', (string) $this->mails[0]['subject'] );
	}

	// -- Disk names ---------------------------------------------------------------------------

	private function disk( Resource $server, string $slot ): Resource {
		$license = $server->license();
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$dl      = $this->service_license( $product, array( 'fire' => false, 'user_id' => $license['user_id'] ) );
		$disk    = Resource::create( $dl, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $license['id'], 'slot' => $slot ) );
		$this->assertInstanceOf( Resource::class, $disk );

		return $disk;
	}

	public function test_extra_disks_are_named_data_disk_n_in_slot_order(): void {
		$server = $this->ready_vm();
		$ten    = $this->disk( $server, 'scsi10' );
		$two    = $this->disk( $server, 'scsi2' );
		$one    = $this->disk( $server, 'scsi1' );
		$none   = $this->disk( $server, '' );

		$labels = DiskMeta::labels( $server );
		$this->assertSame( 'Data disk 1', $labels[ $one->id() ] );
		$this->assertSame( 'Data disk 2', $labels[ $two->id() ] );
		$this->assertSame( 'Data disk 3', $labels[ $ten->id() ] );
		$this->assertSame( 'Data disk 4', $labels[ $none->id() ], 'A disk with no slot yet comes last.' );
		$this->assertSame( 'Data disk 2', DiskMeta::label( Resource::find( $two->id() ) ) );

		// The server card prints the name, never the slot.
		ob_start();
		\BeltoftServices\Proxmox\Panels::account( Resource::find( $server->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Data disk 1: 50 GB', $html );
		$this->assertStringNotContainsString( 'scsi1', $html );
		$this->assertStringNotContainsString( 'scsi2', $html );

		// The disk's own card too.
		ob_start();
		\BeltoftServices\Proxmox\DiskPanels::account( Resource::find( $one->id() ) );
		$card = (string) ob_get_clean();
		$this->assertStringContainsString( 'Data disk 1', $card );
		$this->assertStringNotContainsString( 'scsi1', $card );

		// The admin view keeps the slot.
		ob_start();
		\BeltoftServices\Proxmox\DiskPanels::admin( Resource::find( $one->id() ) );
		$this->assertStringContainsString( 'scsi1', (string) ob_get_clean() );
	}

	public function test_a_disk_without_a_known_server_is_just_a_data_disk(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk( $server, 'scsi1' );
		$disk->set( array( 'server_license' => 0 ) );

		$this->assertSame( 'Data disk', DiskMeta::label( Resource::find( $disk->id() ) ) );
	}
}
