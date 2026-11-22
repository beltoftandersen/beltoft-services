<?php
/**
 * The routes, the actions and the account page of backup storage.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Storage\Storage;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Resource;
use BeltoftServices\Storage\Boxes;
use BeltoftServices\Storage\FakeStorageClient;
use BeltoftServices\Storage\StorageActions;
use BeltoftServices\Storage\StorageCommands;
use BeltoftServices\Storage\StorageDriver;
use BeltoftServices\Storage\StoragePanel;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageRoutesTest extends StorageTestCase {

	private const BASE = '/beltoft-services/v1/resources/';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		add_filter( 'beltoft_services_step_up_verify', static fn( $ok, $user, $secret, $kind, $purpose ) => str_starts_with( (string) $purpose, 'storage_' ) ? ( '123456' === $secret ) : $ok, 10, 5 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'beltoft_services_step_up_verify' );
		parent::tear_down();
	}

	/** @param array<string, mixed> $params */
	private function call( string $method, string $path, int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, self::BASE . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function page( Resource $r, int $user ): string {
		wp_set_current_user( $user );
		ob_start();
		( new StorageDriver() )->render_account( Resource::find( $r->id() ) );

		return (string) ob_get_clean();
	}

	public function test_only_the_owner_reads_the_facts_and_they_hold_no_password(): void {
		$r    = $this->ready_storage();
		$user = (int) $r->license()['user_id'];
		$ok   = $this->call( 'GET', $r->id() . '/storage', $user );
		$this->assertSame( 200, $ok->get_status() );
		$data = $ok->get_data();
		$this->assertSame( $r->get( 'storage_username' ), $data['connection']['user'] );
		$this->assertArrayNotHasKey( 'tls_host', $data['connection'] );
		$this->assertStringNotContainsString( (string) Boxes::password( $r ), (string) wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'bs1:', (string) wp_json_encode( $data ) );
		$this->assertSame( 403, $this->call( 'GET', $r->id() . '/storage', (int) self::factory()->user->create() )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', $r->id() . '/storage', 0 )->get_status() );
		$other = $this->service_product( 'fake' );
		unset( $other );
	}

	public function test_the_password_needs_the_emailed_code_and_nothing_else_shows_it(): void {
		$r        = $this->ready_storage();
		$user     = (int) $r->license()['user_id'];
		$password = (string) Boxes::password( $r );
		$no       = $this->call( 'POST', $r->id() . '/storage-password', $user );
		$this->assertSame( 403, $no->get_status() );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $no->get_data() ) );
		$bad = $this->call( 'POST', $r->id() . '/storage-password', $user, array( 'step_code' => '000000' ) );
		$this->assertSame( 403, $bad->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/storage-password', (int) self::factory()->user->create(), array( 'step_code' => '123456' ) )->get_status(), 'Not the owner.' );
		$good = $this->call( 'POST', $r->id() . '/storage-password', $user, array( 'step_code' => '123456' ) );
		$this->assertSame( 200, $good->get_status() );
		$this->assertSame( $password, $good->get_data()['password'] );
		$this->assertStringContainsString( 'no-store', (string) $good->get_headers()['Cache-Control'] );
		$this->assertContains( 'storage_password', \BeltoftServices\StepUp::purposes() );
		$this->assertContains( 'storage_restore', \BeltoftServices\StepUp::purposes() );
	}

	public function test_a_service_that_is_not_storage_is_refused(): void {
		$other = $this->ready_storage();
		$fake  = $this->fake_driver();
		$product = $this->service_product( 'fake', array(), array( 'slug' => 'other-x' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, $fake, array(), array() );
		$this->assertInstanceOf( Resource::class, $r );
		$this->assertSame( 404, $this->call( 'POST', $r->id() . '/storage-password', (int) $license['user_id'], array( 'step_code' => '123456' ) )->get_status() );
		unset( $other );
	}

	public function test_the_token_gate_reaches_none_of_these_routes_and_no_action_for_a_token(): void {
		foreach ( array( 'storage', 'storage-password', 'storage-password-reset', 'storage-rollback' ) as $part ) {
			$this->assertFalse( Tokens::reachable( '/beltoft-services/v1/resources/5/' . $part ), $part );
		}

		foreach ( array( StorageActions::SNAPSHOT, StorageActions::SNAPSHOT_DELETE, StorageActions::SNAPSHOT_PLAN, StorageActions::ACCESS ) as $action ) {
			$this->assertContains( $action, Tokens::dashboard_only_actions(), $action . ' is dashboard only.' );
		}
	}

	public function test_a_new_password_needs_the_code_and_changes_it_at_the_provider(): void {
		$r    = $this->ready_storage();
		$user = (int) $r->license()['user_id'];
		$old  = (string) Boxes::password( $r );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/storage-password-reset', $user )->get_status() );
		$ok = $this->call( 'POST', $r->id() . '/storage-password-reset', $user, array( 'step_code' => '123456' ) );
		$this->assertSame( 202, $ok->get_status() );
		$this->run_jobs();
		$fresh = Resource::find( $r->id() );
		$new   = (string) Boxes::password( $fresh );
		$this->assertNotSame( $old, $new );
		$this->assertTrue( $this->client->password_is( Boxes::id( $fresh ), $new ) );
		$this->assertFalse( $this->client->password_is( Boxes::id( $fresh ), $old ) );
		$this->assertSame( '', (string) $fresh->get( 'storage_password_next' ) );
	}

	public function test_a_rollback_needs_the_code_and_a_snapshot_of_this_box(): void {
		$r    = $this->ready_storage();
		$user = (int) $r->license()['user_id'];
		$id   = Boxes::id( $r );
		$one  = $this->client->create_snapshot( $id, 'one' );
		$two  = $this->client->create_snapshot( $id, 'two' );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/storage-rollback', $user, array( 'snapshot' => $one['id'] ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', $r->id() . '/storage-rollback', $user, array( 'snapshot' => 'abc', 'step_code' => '123456' ) )->get_status() );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/storage-rollback', $user, array( 'snapshot' => $one['id'], 'step_code' => '123456' ) )->get_status() );
		$this->run_jobs();
		$ids = array_column( $this->client->snapshots( $id ), 'id' );
		$this->assertContains( $one['id'], $ids );
		$this->assertNotContains( $two['id'], $ids, 'Newer snapshots are removed.' );
		// A snapshot id that is not this box's is never passed on.
		$result = StorageActions::run( Resource::find( $r->id() ), StorageActions::ROLLBACK, array( 'snapshot' => '99999' ) );
		$this->assertTrue( $result->is_failed() );
	}

	public function test_customer_actions_run_through_the_generic_route(): void {
		$r    = $this->ready_storage();
		$user = (int) $r->license()['user_id'];
		$id   = Boxes::id( $r );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/actions/storage_snapshot', $user )->get_status() );
		$this->run_jobs();
		$this->assertCount( 1, $this->client->snapshots( $id ) );
		$snap = $this->client->snapshots( $id )[0]['id'];
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/actions/storage_snapshot_delete', $user, array( 'args' => array( 'snapshot' => 'x' ) ) )->get_status() );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/actions/storage_snapshot_delete', $user, array( 'args' => array( 'snapshot' => $snap ) ) )->get_status() );
		$this->run_jobs();
		$this->assertCount( 0, $this->client->snapshots( $id ) );
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/actions/storage_snapshot_plan', $user, array( 'args' => array( 'every' => 'hour', 'hour' => 3 ) ) )->get_status() );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/actions/storage_snapshot_plan', $user, array( 'args' => array( 'every' => 'week', 'hour' => 5 ) ) )->get_status() );
		$this->run_jobs();
		$this->assertSame( 'week', FakeStorageClient::state()['boxes'][ $id ]['snapshot_plan']['every'] );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/actions/storage_access', $user, array( 'args' => array( 'ssh' => '0' ) ) )->get_status() );
		$this->run_jobs();
		$this->assertFalse( FakeStorageClient::state()['boxes'][ $id ]['access']['ssh_enabled'] );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/actions/storage_snapshot', (int) self::factory()->user->create() )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/actions/storage_password_reset', $user )->get_status(), 'An internal action is not on the generic route.' );
	}

	public function test_smb_and_webdav_are_refused_while_the_setting_is_off(): void {
		$r    = $this->ready_storage();
		$user = (int) $r->license()['user_id'];
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/actions/storage_access', $user, array( 'args' => array( 'smb' => '1' ) ) )->get_status() );
		$this->settings( array( 'storage_extra_protocols' => 1 ) );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/actions/storage_access', $user, array( 'args' => array( 'smb' => '1' ) ) )->get_status() );
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/actions/storage_access', $user, array( 'args' => array( 'ftp' => '0' ) ) )->get_status(), 'FTP is not switchable.' );
	}

	public function test_the_page_has_no_secret_no_provider_name_and_the_right_words(): void {
		$r    = $this->ready_storage();
		$user = (int) $r->license()['user_id'];
		$html = $this->page( $r, $user );
		$this->assertStringContainsString( (string) $r->get( 'storage_username' ), $html );
		$this->assertStringContainsString( 'Germany', $html );
		$this->assertStringContainsString( 'data-storage-reveal', $html );
		$this->assertStringNotContainsString( (string) Boxes::password( $r ), $html );
		$this->assertStringNotContainsString( 'bs1:', $html );
		$this->assertStringContainsString( 'rsync -az', $html );
		$this->assertStringContainsString( 'borg init', $html );
		$this->assertStringContainsString( 'restic', $html );
		$this->assertStringContainsString( 'rclone', $html );
		$this->assertStringContainsString( 'Encrypt your backups', $html );
		$this->assertStringContainsString( 'SFTP and SCP are always on', $html );
		$this->assertDoesNotMatchRegularExpression( '/hetzner|storagebox|your-storage/i', $html . StorageCommands::for( 'h.example.test', 'u1' )['rsync'][0] );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
		$this->assertStringNotContainsString( 'name="smb"', $html, 'SMB is not offered by default.' );
		$this->assertSame( '', $this->page( $r, (int) self::factory()->user->create() ), 'Another customer sees nothing.' );
	}

	public function test_a_paused_page_shows_access_off_and_no_password_buttons(): void {
		$r = $this->ready_storage();
		$paused = ( new StorageDriver() )->pause( $r );
		$r->set( $paused->meta() + array( 'state' => 'paused' ) );
		$html = $this->page( $r, (int) $r->license()['user_id'] );
		$this->assertStringContainsString( 'Access is off', $html );
		$this->assertStringNotContainsString( 'data-storage-reveal', $html );
		$this->assertStringNotContainsString( 'data-storage-rollback', $html );
	}

	public function test_commands_never_hold_a_password_and_refuse_odd_names(): void {
		$lines = implode( "\n", array_merge( ...array_values( StorageCommands::for( 'backup1.example.test', 'u1234' ) ) ) );
		$this->assertStringContainsString( 'u1234@backup1.example.test', $lines );
		$this->assertStringNotContainsStringIgnoringCase( 'password', $lines );
		$this->assertSame( array(), StorageCommands::for( 'x; rm -rf /', 'u1' ) );
		$this->assertSame( array(), StorageCommands::for( 'h.example.test', "u1' $(id)" ) );
	}

	public function test_the_account_area_lists_boxes_state_above_the_name(): void {
		$r    = $this->ready_storage();
		$area = ServiceArea::get( Storage::class );
		$this->assertSame( array( 'storage' ), ( function () { return $this->kinds(); } )->call( $area ) );
		$this->assertContains( $area, ServiceArea::all() );
		$view = array( 'license' => $r->license(), 'product' => 'Backup storage 1 TB' );
		$row  = $area->row( $view, Resource::find( $r->id() ) );
		$this->assertSame( 'Active', $row['state'] );
		$this->assertSame( 'Backup storage 1 TB', $row['name'] );
		$this->assertStringContainsString( 'Germany', $row['details'] );
	}

	public function test_the_ready_email_link_goes_to_the_storage_page(): void {
		$r = $this->ready_storage();
		$values = apply_filters( 'beltoft_services_email_values', array(), Resource::find( $r->id() ), array(), '' );
		$this->assertSame( ServiceArea::get( Storage::class )->url( $r->license() ), $values['account_url'] );
	}

	public function test_a_switch_to_a_smaller_size_or_an_unknown_cost_is_refused(): void {
		$r = $this->ready_storage( array( 'size_gb' => 5000, 'type' => 'bx21', 'total' => 12.0, 'subscription' => 4242 ) );
		$r->set( array( 'storage_size_bytes' => 5000000000000 ) );
		$small = $this->service_product( StorageDriver::ID, array( 'storage_type' => 'bx11', 'storage_size_gb' => 1000 ), array( 'slug' => 's-small' ) );
		$large = $this->service_product( StorageDriver::ID, array( 'storage_type' => 'bx31', 'storage_size_gb' => 10000 ), array( 'slug' => 's-large' ) );
		$sub = new class( 4242 ) { public int $id; public function __construct( int $id ) { $this->id = $id; } public function get_id(): int { return $this->id; } };
		$this->assertNotSame( '', (string) \BeltoftServices\Storage\StorageSwitch::check( '', $small->get_id(), 1, 0, $sub, null ) );
		$this->assertNotSame( '', (string) \BeltoftServices\Storage\StorageSwitch::check( '', $large->get_id(), 1, 0, $sub, null ), 'bx31 has no cost in the table.' );
	}
}
