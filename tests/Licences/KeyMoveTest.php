<?php
/** Changing the server address of a standalone licence key: the job, its limit, its checks, add-ons that follow, the page. @package BeltoftServices */

namespace BeltoftServices\Tests\Licences;

use BeltoftServices\Apps\PleskLicence;
use BeltoftServices\Domains\FakeClient as Registrar;
use BeltoftServices\Jobs;
use BeltoftServices\JobActions;
use BeltoftServices\Licences\KeyDriver;
use BeltoftServices\Licences\KeyMove;
use BeltoftServices\Licences\KeyOrder;
use BeltoftServices\Licences\KeyPanel;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * No network: the registrar is the fake client. Nothing is ordered, charged or deleted by a move.
 */
final class KeyMoveTest extends TestCase {

	private Registrar $op;

	/** @var array<int, array<string, mixed>> Mails sent. */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		delete_option( Registrar::OPTION );
		$this->op = new Registrar( true );
		add_filter( 'beltoft_services_domains_client', fn() => $this->op );
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;
				return true;
			},
			10,
			2
		);
		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		KeyOrder::register();
		KeyMove::register();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_client' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_licence_moves_per_day' );
		delete_option( Registrar::OPTION );
		parent::tear_down();
	}

	/**
	 * A provisioned, ready licence.
	 *
	 * @param array<string, mixed> $o tier, kind, ip, user.
	 */
	private function ready( array $o = array() ): Resource {
		$o      += array( 'tier' => 'pro', 'kind' => 'edition', 'ip' => '203.0.113.10', 'user' => 0 );
		$plan    = array( 'lic_kind' => $o['kind'], 'lic_tier' => $o['tier'], 'lic_server' => 'edition' === $o['kind'] ? 'vps' : '', 'lic_label' => '' );
		$product = $this->service_product( KeyDriver::ID, $plan, array( 'slug' => 'lic-' . wp_generate_password( 6, false ) ) );
		$user    = $o['user'] > 0 ? (int) $o['user'] : (int) self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Licence', 'quantity' => 1, 'subtotal' => 30.0, 'total' => 30.0 ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$r       = Resource::create( $license, new KeyDriver(), $plan, array( 'lic_ip' => $o['ip'], 'lic_attach' => 0, 'order_item_id' => (int) $line->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		$result = ( new KeyDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$r->set( $result->meta() + array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Calls the fake registrar saw.
	 *
	 * @param string $method Method.
	 * @param string $path   Path (empty: any).
	 * @return array<int, array<string, mixed>>
	 */
	private function calls( string $method, string $path = '' ): array {
		return array_values( array_filter( $this->op->calls(), static fn( $c ) => $c['method'] === $method && ( '' === $path || $c['path'] === $path ) ) );
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	// -- The move ----------------------------------------------------------------------------------

	public function test_an_edition_moves_without_an_order_and_resets_the_hardware_id(): void {
		$r      = $this->ready();
		$key_id = (int) $r->get( 'lic_key_id' );
		$from   = count( $this->op->calls() );

		$result = ( new KeyDriver() )->act( $r, KeyMove::ACTION, array( 'ip' => '203.0.113.50' ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$fresh = $this->fresh( $r );
		$this->assertSame( '203.0.113.50', $fresh->get( 'lic_ip' ) );
		$this->assertCount( 1, (array) $fresh->get( KeyMove::MOVES ) );
		$seen = array_slice( $this->op->calls(), $from );
		$puts = array_values( array_filter( $seen, static fn( $c ) => 'PUT' === $c['method'] ) );
		$this->assertCount( 1, $puts );
		$this->assertSame( '/licenses/plesk/' . $key_id, $puts[0]['path'] );
		$this->assertSame( array( 'ip_address_binding' => '203.0.113.50', 'restrict_ip_binding' => true ), $puts[0]['body'], 'Only the address: no items and no period, which would order.' );
		$this->assertCount( 0, array_filter( $seen, static fn( $c ) => 'POST' === $c['method'] && '/licenses/plesk' === $c['path'] ), 'Nothing is ordered.' );
		$this->assertCount( 0, array_filter( $seen, static fn( $c ) => 'DELETE' === $c['method'] ), 'Nothing is deleted.' );
		$this->assertCount( 1, array_filter( $seen, static fn( $c ) => 'POST' === $c['method'] && '/licenses/hwids/reset/plesk/' . $key_id === $c['path'] ) );
		$state = (array) get_option( Registrar::OPTION );
		$this->assertSame( '203.0.113.50', $state['licences'][ $key_id ]['ip_address_binding'] );
	}

	public function test_an_addon_moves_without_a_hardware_reset(): void {
		$r      = $this->ready( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo' ) );
		$key_id = (int) $r->get( 'lic_key_id' );

		$result = ( new KeyDriver() )->act( $r, KeyMove::ACTION, array( 'ip' => '203.0.113.51' ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( '203.0.113.51', $this->fresh( $r )->get( 'lic_ip' ) );
		$this->assertCount( 0, $this->calls( 'POST', '/licenses/hwids/reset/plesk/' . $key_id ) );
	}

	public function test_the_customer_action_runs_through_the_shared_controller_and_says_so(): void {
		$r = $this->ready();
		$this->assertArrayHasKey( KeyMove::ACTION, ( new KeyDriver() )->customer_actions( $r ) );

		$this->assertTrue( Jobs::start_action( $r, KeyMove::ACTION, 'Change server address', array( 'ip' => '203.0.113.52' ) ) );
		$this->run_jobs();

		$fresh = $this->fresh( $r );
		$this->assertSame( '203.0.113.52', $fresh->get( 'lic_ip' ) );
		$result = (array) $fresh->get( Jobs::RESULT );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'The server address was changed.', $result['text'] );
		$this->assertNull( $fresh->get( \BeltoftServices\Progress::MARKER ) );
	}

	public function test_a_temporary_error_is_retried_and_stores_nothing(): void {
		$r = $this->ready();
		$this->op->reply( 'PUT', '/licenses/plesk/' . (int) $r->get( 'lic_key_id' ), new \WP_Error( 'transport', 'down' ) );

		$result = ( new KeyDriver() )->act( $r, KeyMove::ACTION, array( 'ip' => '203.0.113.53' ) );

		$this->assertTrue( $result->is_retry() );
		$this->assertSame( '203.0.113.10', $this->fresh( $r )->get( 'lic_ip' ) );
		$this->assertEmpty( $this->fresh( $r )->get( KeyMove::MOVES ) );
	}

	public function test_a_final_refusal_keeps_the_old_address_tells_the_customer_and_staff_once(): void {
		$r = $this->ready();
		$this->op->reply( 'PUT', '/licenses/plesk/' . (int) $r->get( 'lic_key_id' ), new \WP_Error( 'op_3108', 'Registrar request failed.', array( 'status' => 404, 'desc' => 'License not found.' ) ) );
		$this->mails = array();

		$this->assertTrue( Jobs::start_action( $r, KeyMove::ACTION, 'Change server address', array( 'ip' => '203.0.113.54' ) ) );
		$this->run_jobs();

		$fresh = $this->fresh( $r );
		$this->assertSame( '203.0.113.10', $fresh->get( 'lic_ip' ), 'The old address stays stored.' );
		$this->assertEmpty( $fresh->get( KeyMove::MOVES ), 'A refused change does not use up the limit.' );
		$result = (array) $fresh->get( Jobs::RESULT );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'was not changed', $result['text'] );
		$this->assertCount( 1, $this->mails, 'Staff are told once.' );
		$this->assertStringNotContainsString( 'FAKE-KEY', (string) $this->mails[0]['message'] );
		$this->assertCount( 0, $this->calls( 'POST', '/licenses/hwids/reset/plesk/' . (int) $r->get( 'lic_key_id' ) ), 'No hardware reset after a refused move.' );
	}

	public function test_a_refused_hardware_reset_does_not_stop_the_move(): void {
		$r = $this->ready();
		$this->op->reply( 'POST', '/licenses/hwids/reset/plesk/' . (int) $r->get( 'lic_key_id' ), new \WP_Error( 'op_3000', 'nothing to reset', array( 'status' => 400 ) ) );

		$this->assertTrue( ( new KeyDriver() )->act( $r, KeyMove::ACTION, array( 'ip' => '203.0.113.55' ) )->is_done() );
		$this->assertSame( '203.0.113.55', $this->fresh( $r )->get( 'lic_ip' ) );
	}

	// -- The limit -----------------------------------------------------------------------------------

	public function test_one_change_a_day_by_default_and_the_sentence_says_when_it_works_again(): void {
		$r = $this->ready();
		$this->assertTrue( ( new KeyDriver() )->act( $r, KeyMove::ACTION, array( 'ip' => '203.0.113.60' ) )->is_done() );

		$fresh   = $this->fresh( $r );
		$refused = apply_filters( 'beltoft_services_action_refusal', null, $fresh, KeyMove::ACTION, array( 'ip' => '203.0.113.61' ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'lickey_move_limit', $refused->get_error_code() );
		$again = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( (array) $fresh->get( KeyMove::MOVES ) )[0] + DAY_IN_SECONDS );
		$this->assertStringContainsString( $again, $refused->get_error_message() );
		$this->assertTrue( ( new KeyDriver() )->act( $fresh, KeyMove::ACTION, array( 'ip' => '203.0.113.61' ) )->is_failed(), 'The job checks again.' );

		// A day later it is possible again.
		$fresh->set( array( KeyMove::MOVES => array( time() - DAY_IN_SECONDS - 5 ) ) );
		$this->assertNull( apply_filters( 'beltoft_services_action_refusal', null, $this->fresh( $r ), KeyMove::ACTION, array( 'ip' => '203.0.113.61' ) ) );
	}

	public function test_the_limit_follows_the_filter(): void {
		add_filter( 'beltoft_services_licence_moves_per_day', fn() => 2 );
		$r = $this->ready();
		$this->assertTrue( ( new KeyDriver() )->act( $r, KeyMove::ACTION, array( 'ip' => '203.0.113.62' ) )->is_done() );
		$this->assertTrue( ( new KeyDriver() )->act( $this->fresh( $r ), KeyMove::ACTION, array( 'ip' => '203.0.113.63' ) )->is_done() );
		$this->assertTrue( ( new KeyDriver() )->act( $this->fresh( $r ), KeyMove::ACTION, array( 'ip' => '203.0.113.64' ) )->is_failed() );
	}

	// -- The address checks are the checkout's -------------------------------------------------------

	public function test_the_address_is_checked_as_at_checkout(): void {
		$user = (int) self::factory()->user->create();
		$r    = $this->ready( array( 'user' => $user ) );
		$this->ready( array( 'user' => (int) self::factory()->user->create(), 'ip' => '203.0.113.70' ) );
		$refuse = fn( array $args ) => apply_filters( 'beltoft_services_action_refusal', null, $this->fresh( $r ), KeyMove::ACTION, $args );

		$this->assertSame( 'lickey_ip', $refuse( array( 'ip' => '10.0.0.5' ) )->get_error_code(), 'A private address.' );
		$this->assertSame( 'lickey_ip', $refuse( array( 'ip' => 'not an address' ) )->get_error_code() );
		$this->assertSame( 'lickey_duplicate', $refuse( array( 'ip' => '203.0.113.70' ) )->get_error_code(), 'Another live Plesk licence is on that address.' );
		$this->assertSame( 'lickey_move_same', $refuse( array( 'ip' => '203.0.113.10' ) )->get_error_code() );
		IpPool::add( '198.51.100.20' );
		$this->assertSame( 'lickey_ours', $refuse( array( 'ip' => '198.51.100.20' ) )->get_error_code(), 'An edition is bought with our server.' );
		$this->assertNull( $refuse( array( 'ip' => '203.0.113.71' ) ), 'The licence is not a duplicate of itself and needs no second confirmation.' );
	}

	public function test_an_addon_may_move_to_an_own_server_and_not_to_another_customers(): void {
		$user    = (int) self::factory()->user->create();
		$product = $this->service_product( 'proxmox', array(), array( 'slug' => 'srv-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$server  = Resource::create( $license, new \BeltoftServices\Proxmox\Vm\ProxmoxDriver(), array(), array( 'app_id' => 'plesk', 'app_version' => '18', 'state' => 'ready' ) );
		IpPool::add( '198.51.100.31' );
		$this->assertTrue( IpPool::take( '198.51.100.31', $server->id() ) );
		$addon = $this->ready( array( 'kind' => 'addon', 'tier' => 'kernelcare', 'user' => $user ) );

		$checked = KeyMove::check( $addon, array( 'source' => 'own:' . (int) $license['id'] ) );
		$this->assertSame( array( 'ip' => '198.51.100.31', 'attach' => (int) $license['id'] ), $checked );

		$other = $this->ready( array( 'kind' => 'addon', 'tier' => 'kernelcare', 'user' => (int) self::factory()->user->create() ) );
		$this->assertWPError( KeyMove::check( $other, array( 'source' => 'own:' . (int) $license['id'] ) ) );

		$this->assertTrue( ( new KeyDriver() )->act( $addon, KeyMove::ACTION, array( 'source' => 'own:' . (int) $license['id'] ) )->is_done() );
		$this->assertSame( (int) $license['id'], (int) $this->fresh( $addon )->get( 'lic_attach' ) );
	}

	public function test_it_is_not_offered_while_the_licence_is_not_ready_or_ended(): void {
		$r = $this->ready();
		$r->set( array( 'lic_key' => null ) );
		$this->assertSame( array(), ( new KeyDriver() )->customer_actions( $this->fresh( $r ) ), 'No key yet.' );
		$r2 = $this->ready( array( 'ip' => '203.0.113.80' ) );
		$r2->set( array( 'state' => 'deleting' ) );
		$this->assertSame( array(), ( new KeyDriver() )->customer_actions( $this->fresh( $r2 ) ) );
	}

	// -- Parent and children -------------------------------------------------------------------------

	public function test_the_addons_of_an_edition_follow_it_in_their_own_jobs(): void {
		$user    = (int) self::factory()->user->create();
		$edition = $this->ready( array( 'user' => $user ) );
		$addon   = $this->ready( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'cloudlinux-admin' ) );
		$addon->set( array( 'lic_parent' => (int) $edition->get( 'lic_key_id' ), 'lic_ip' => '203.0.113.10' ) );
		$stray = $this->ready( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'kernelcare', 'ip' => '203.0.113.99' ) );

		$this->assertTrue( Jobs::start_action( $edition, KeyMove::ACTION, 'Change server address', array( 'ip' => '203.0.113.90' ) ) );
		$this->run_jobs();

		$this->assertSame( '203.0.113.90', $this->fresh( $edition )->get( 'lic_ip' ) );
		$this->assertSame( '203.0.113.90', $this->fresh( $addon )->get( 'lic_ip' ), 'The add-on follows.' );
		$this->assertSame( '203.0.113.99', $this->fresh( $stray )->get( 'lic_ip' ), 'An unrelated add-on stays.' );
		$puts = $this->calls( 'PUT', '/licenses/plesk/' . (int) $addon->get( 'lic_key_id' ) );
		$this->assertSame( array( 'ip_address_binding' => '203.0.113.90', 'restrict_ip_binding' => true ), $puts[0]['body'] );
		$this->assertCount( 0, $this->calls( 'POST', '/licenses/hwids/reset/plesk/' . (int) $addon->get( 'lic_key_id' ) ) );
		$this->assertCount( 3, $this->calls( 'POST', '/licenses/plesk' ), 'Only the three orders of the set-up: a move orders nothing.' );
		$this->assertEmpty( $this->fresh( $addon )->get( KeyMove::MOVES ), 'Following is not a change of the customer.' );
	}

	public function test_an_addon_with_a_live_parent_cannot_move_alone_but_one_without_can(): void {
		$user    = (int) self::factory()->user->create();
		$edition = $this->ready( array( 'user' => $user ) );
		$addon   = $this->ready( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'cloudlinux-admin' ) );
		$addon->set( array( 'lic_parent' => (int) $edition->get( 'lic_key_id' ) ) );

		$refused = KeyMove::check( $this->fresh( $addon ), array( 'ip' => '203.0.113.91' ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'lickey_move_parent', $refused->get_error_code() );
		$this->assertSame( array(), ( new KeyDriver() )->customer_actions( $this->fresh( $addon ) ), 'The page offers no form for it.' );

		// The parent is gone: the add-on is its own.
		$edition->set( array( 'state' => 'deleted' ) );
		$this->assertIsArray( KeyMove::check( $this->fresh( $addon ), array( 'ip' => '203.0.113.91' ) ) );

		$alone = $this->ready( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'ip' => '203.0.113.92' ) );
		$this->assertIsArray( KeyMove::check( $alone, array( 'ip' => '203.0.113.93' ) ) );
	}

	public function test_a_kept_app_licence_on_a_new_server_takes_its_addons_along(): void {
		$user   = (int) self::factory()->user->create();
		$addon  = $this->ready( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'ip' => '203.0.113.20' ) );
		$addon->set( array( 'lic_parent' => 777, 'lic_attach' => 5 ) );
		$licence = $this->ready( array( 'user' => $user, 'ip' => '203.0.113.21' ) );
		$licence->set( array( 'plesk_key_id' => 777 ) );
		$product = $this->service_product( 'proxmox', array(), array( 'slug' => 'srv-' . wp_generate_password( 5, false ) ) );
		$row     = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$server  = Resource::create( $row, new \BeltoftServices\Proxmox\Vm\ProxmoxDriver(), array(), array( 'state' => 'ready' ) );

		do_action( PleskLicence::MOVED, $this->fresh( $licence ), '192.0.2.77', $server );
		$this->run_jobs();

		$fresh = $this->fresh( $addon );
		$this->assertSame( '192.0.2.77', $fresh->get( 'lic_ip' ) );
		$this->assertSame( (int) $row['id'], (int) $fresh->get( 'lic_attach' ) );
		$puts = $this->calls( 'PUT', '/licenses/plesk/' . (int) $addon->get( 'lic_key_id' ) );
		$this->assertCount( 1, $puts );
		$this->assertCount( 0, $this->calls( 'DELETE' ) );
	}

	public function test_a_follow_job_for_the_wrong_parent_does_nothing(): void {
		$addon = $this->ready( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo' ) );
		$addon->set( array( 'lic_parent' => 5 ) );
		$from = count( $this->op->calls() );

		$result = KeyMove::follow( $this->fresh( $addon ), array( 'parent' => 6, 'ip' => '203.0.113.40', 'attach' => 0 ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertCount( $from, $this->op->calls() );
		$this->assertSame( '203.0.113.10', $this->fresh( $addon )->get( 'lic_ip' ) );
	}

	// -- The page ------------------------------------------------------------------------------------

	public function test_the_page_offers_the_change_and_no_longer_says_to_order_a_new_licence(): void {
		$user = (int) self::factory()->user->create();
		wp_set_current_user( $user );
		$r = $this->ready( array( 'user' => $user ) );

		ob_start();
		KeyPanel::account( $this->fresh( $r ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'order a new licence', $html );
		$this->assertStringContainsString( 'You can change the address below.', $html );
		$this->assertStringContainsString( 'data-lickey-move', $html );
		$this->assertStringContainsString( 'data-resource="' . $r->id() . '"', $html );

		( new KeyDriver() )->act( $this->fresh( $r ), KeyMove::ACTION, array( 'ip' => '203.0.113.66' ) );
		ob_start();
		KeyPanel::account( $this->fresh( $r ) );
		$after = (string) ob_get_clean();
		$this->assertStringContainsString( 'was changed recently', $after );
		$this->assertStringNotContainsString( 'data-lickey-go', $after );
	}

	public function test_the_form_is_never_shown_to_another_customer(): void {
		$r = $this->ready();
		wp_set_current_user( (int) self::factory()->user->create() );
		ob_start();
		KeyPanel::account( $r );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_generic_button_is_not_drawn_and_the_route_names_the_action_and_where_to_follow_it(): void {
		$r = $this->ready();

		$this->assertArrayHasKey( KeyMove::ACTION, ( new \BeltoftServices\Licences\KeyDriver() )->customer_actions( $r ) );
		$this->assertSame( array(), apply_filters( 'beltoft_services_account_buttons', array( KeyMove::ACTION => 'x' ), $r, 'card' ) );

		$reply = \BeltoftServices\Api\Routes::queued( $r, KeyMove::ACTION )->get_data();
		$this->assertSame( KeyMove::ACTION, $reply['action'] );
		$this->assertSame( '/resources/' . $r->id(), $reply['status'] );
	}
}
