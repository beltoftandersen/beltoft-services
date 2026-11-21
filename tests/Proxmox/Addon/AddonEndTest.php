<?php
/**
 * An add-on closed with its deleted parent has its licence ended, is never a row of the server list, and the repair command ends
 * the leftovers. Fake clients only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Addon;

use BeltoftLicenses\Account\View;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\Addon\AddonEnd;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Ipv4\Ipv4TestCase;

require_once dirname( __DIR__ ) . '/Ipv4/Ipv4TestCase.php';

final class AddonEndTest extends Ipv4TestCase {

	private function delete_server( Resource $server ): void {
		$this->assertTrue( $server->transition( 'deleted', 'Deleted for the test' ) );
		Reconciler::notify( $server, 'ready', 'deleted' );
		Resource::memo_flush();
	}

	private function status( Resource $addon ): string {
		return (string) Licenses::find( (int) $addon->license()['id'] )['status'];
	}

	private function events( int $license_id ): string {
		return wp_json_encode( \BeltoftLicenses\Events::for_license( $license_id ) );
	}

	private function others( int $user, Resource ...$ignored ): array {
		unset( $ignored );
		$views = array_map( static fn( $l ) => View::license( $l ), \BeltoftLicenses\Account\Endpoint::licenses_for_user( $user ) );

		return array_map( static fn( $v ) => (int) $v['license']['id'], Servers::sort( $views )['others'] );
	}

	public function test_a_deleted_server_ends_the_licence_of_its_ipv4_address_like_a_customer_delete(): void {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$id     = (int) $addon->license()['id'];
		$this->assertSame( 'active', $this->status( $addon ) );

		$this->delete_server( $server );

		$this->assertFalse( Resource::find( $addon->id() )->is_live() );
		$this->assertSame( 'revoked', $this->status( $addon ) );
		$this->assertStringContainsString( 'License ended with the deletion', $this->events( $id ) );

		// A second notice changes nothing.
		Reconciler::notify( $server, 'ready', 'deleted' );
		$this->assertSame( 1, substr_count( $this->events( $id ), 'License ended with the deletion' ) );
	}

	public function test_the_flat_subscription_of_an_add_on_is_cancelled_when_nothing_else_is_on_it(): void {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$user   = (int) $server->license()['user_id'];
		$sub    = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		Licenses::update( (int) $addon->license()['id'], array( 'subscription_id' => $sub->get_id() ) );

		$this->delete_server( $server );

		$this->assertSame( 'cancelled', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_a_pay_as_you_go_add_on_leaves_the_usage_subscription_alone(): void {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$user   = (int) $server->license()['user_id'];
		$sub    = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		Licenses::update( (int) $addon->license()['id'], array( 'subscription_id' => $sub->get_id(), 'source' => 'usage' ) );

		$this->delete_server( $server );

		$this->assertSame( 'revoked', $this->status( $addon ) );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_a_subscription_with_another_live_licence_is_kept_and_the_admin_told(): void {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$user   = (int) $server->license()['user_id'];
		$sub    = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		Licenses::update( (int) $addon->license()['id'], array( 'subscription_id' => $sub->get_id() ) );
		Licenses::update( (int) $server->license()['id'], array( 'subscription_id' => $sub->get_id() ) );

		$this->delete_server( $server );

		$this->assertSame( 'revoked', $this->status( $addon ) );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_an_ipv4_address_never_has_its_own_row_in_the_server_list(): void {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$user   = (int) $server->license()['user_id'];
		$id     = (int) $addon->license()['id'];

		// With a live server in the list, and with the parent not in the list at all.
		$this->assertNotContains( $id, $this->others( $user ) );
		$addon->set( array( 'server_license' => 0 ) );
		Resource::memo_flush();
		$this->assertNotContains( $id, $this->others( $user ) );
	}

	public function test_an_active_add_on_licence_with_a_closed_service_is_not_a_row(): void {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$user   = (int) $server->license()['user_id'];
		$id     = (int) $addon->license()['id'];

		// The state of production before the fix: the service closed, the licence still active.
		$addon->set( array( 'state' => 'deleted', 'desired' => 'deleted' ) );
		$addon->close();
		Resource::memo_flush();

		$this->assertSame( 'active', $this->status( $addon ) );
		$this->assertNotContains( $id, $this->others( $user ) );
	}

	public function test_the_repair_lists_and_ends_only_orphans(): void {
		$gone    = $this->server( 1 );
		$orphan  = $this->addon( $gone );
		$live    = $this->server( 1 );
		$fine    = $this->addon( $live );
		$orphan_id = (int) $orphan->license()['id'];

		// The licence of the first server ended and its add-on was closed without ending (the old behaviour).
		$orphan->set( array( 'state' => 'deleted', 'desired' => 'deleted' ) );
		$orphan->close();
		Licenses::update( (int) $gone->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		Resource::memo_flush();

		$found = AddonEnd::orphans();
		$this->assertSame( array( $orphan_id ), array_column( $found, 'license_id' ) );
		$this->assertSame( 'ipv4', $found[0]['kind'] );
		$this->assertSame( (int) $gone->license()['id'], $found[0]['parent_license'] );
		$this->assertSame( (int) $gone->license()['user_id'], $found[0]['user_id'] );
		$this->assertSame( 'active', $this->status( $orphan ), 'Listing changes nothing.' );

		$this->assertTrue( AddonEnd::end( $found[0]['resource'] ) );
		$this->assertSame( 'revoked', $this->status( $orphan ) );
		$this->assertSame( 'active', $this->status( $fine ) );
		$this->assertSame( array(), AddonEnd::orphans(), 'Idempotent.' );
		$this->assertFalse( AddonEnd::end( $found[0]['resource'] ) );
	}
}
