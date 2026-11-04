<?php
/**
 * A customer deleting a pay-as-you-go server or database. FakeClient only; every address is the owner's.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Resource;
use BeltoftServices\Usage\CustomerDelete;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Delete server, delete database.
 */
final class CustomerDeleteTest extends UsageTestCase {

	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	private function db_usage_resource( int $user, ?int $account = null ): Resource {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$data    = array( 'fire' => false, 'user_id' => $user, 'email' => self::MAIL, 'source' => 'usage' ) + ( null !== $account ? array( 'subscription_id' => $account ) : array() );
		$license = $this->service_license( $product, $data );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$this->activated_ago( $r->id(), 5 );

		return Resource::find( $r->id() );
	}

	public function test_a_server_is_deleted_and_billing_stops_while_the_other_service_and_the_account_stay(): void {
		$v       = $this->usage_product();
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$one     = $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() );
		$two     = $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() );
		$this->assertSame( 'server', CustomerDelete::kind( $one ) );
		$this->assertSame( 'DELETE', CustomerDelete::word( $one ) );
		$this->assertSame( 'off', \BeltoftServices\Settings::get( 'deletion_mode' ), 'A customer deletion does not wait for deletion mode.' );

		$done = CustomerDelete::run( $one, $user, 'DELETE' );
		$this->assertTrue( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );

		$fresh = Resource::find( $one->id() );
		$this->assertSame( 'deleted', $fresh->get( 'desired' ) );
		$this->assertSame( '', (string) $fresh->get( 'delete_origin' ), 'No retention or breach origin: it is not held and cannot be called off.' );
		$this->assertContains( 'Customer deleted the service', $this->history( $one ) );
		$this->assertSame( Licenses::STATUS_REVOKED, Licenses::find( (int) $one->license()['id'] )['status'] );

		$this->run_jobs();
		$fresh = Resource::find( $one->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertFalse( $fresh->is_live(), 'The activation is closed.' );

		// The meter stops at the hour it was closed in.
		Meter::run( time() + 3 * HOUR_IN_SECONDS );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(hour_bucket) FROM ' . Ledger::table() . ' WHERE resource_id = %d', $one->id() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		$other = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ledger::table() . ' WHERE resource_id = %d AND hour_bucket > %s', $two->id(), gmdate( 'Y-m-d H:00:00', time() ) ) );
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d H:00:00' ), $last, 'Nothing is metered after the close hour.' );
		$this->assertGreaterThan( 0, $other, 'The other server is still metered.' );

		// The other server and the account are as they were.
		$kept = Resource::find( $two->id() );
		$this->assertSame( 'ready', $kept->get( 'state' ) );
		$this->assertSame( 'ready', $kept->get( 'desired' ) );
		$this->assertSame( Licenses::STATUS_ACTIVE, Licenses::find( (int) $two->license()['id'] )['status'] );
		$this->assertTrue( wcs_get_subscription( $account->get_id() )->has_status( 'active' ) );
		$this->assertSame( 'fakecard', wcs_get_subscription( $account->get_id() )->get_payment_method() );
	}

	public function test_nothing_new_is_made_for_the_deleted_license_when_the_account_is_paid_again(): void {
		$v       = $this->usage_product();
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$r       = $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() );
		$license = (int) $r->license()['id'];

		$this->assertTrue( CustomerDelete::run( $r, $user, 'DELETE' ) );
		$this->run_jobs();
		$this->assertNull( Resource::for_license( $license ), 'The deleted service is closed.' );

		// A paid renewal and a reactivation clear the expiry of every license of the account, and an expiry would come back active.
		\BeltoftLicenses\Lifecycle\Subscriptions::activated( wcs_get_subscription( $account->get_id() ) );
		\BeltoftServices\Lifecycle::on_status( Licenses::find( $license ), 'expired' );
		\BeltoftServices\Lifecycle::on_expiry( Licenses::find( $license ), gmdate( 'Y-m-d H:i:s' ) );
		$this->run_jobs();

		$this->assertNull( Resource::for_license( $license ), 'No placeholder or new service appears.' );
		$this->assertSame( Licenses::STATUS_REVOKED, Licenses::find( $license )['status'] );
	}

	public function test_the_customer_is_not_emailed_about_the_revoked_license(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );
		$sent = 0;
		add_filter( 'pre_wp_mail', static function () use ( &$sent ) {
			++$sent;

			return true;
		} );

		$this->assertTrue( CustomerDelete::run( $r, $user, 'DELETE' ) );
		$this->assertSame( 0, $sent );
		remove_all_filters( 'pre_wp_mail' );
	}

	public function test_a_named_server_is_confirmed_with_its_name_and_a_wrong_word_deletes_nothing(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );
		$r->set( array( ServerName::META => 'web-01' ) );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'web-01', CustomerDelete::word( $r ) );

		foreach ( array( '', 'DELETE', 'web', 'WEB-01x' ) as $typed ) {
			$bad = CustomerDelete::run( $r, $user, $typed );
			$this->assertWPError( $bad, $typed );
			$this->assertSame( 'beltoft_services_delete_confirm', $bad->get_error_code() );
		}

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( Licenses::STATUS_ACTIVE, Licenses::find( (int) $r->license()['id'] )['status'] );
		$this->assertTrue( CustomerDelete::run( $r, $user, ' web-01 ' ) );
	}

	public function test_another_user_is_refused(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );

		$bad = CustomerDelete::run( $r, $this->new_user(), 'DELETE' );
		$this->assertWPError( $bad );
		$this->assertSame( 'beltoft_services_forbidden', $bad->get_error_code() );
		$this->assertWPError( CustomerDelete::run( $r, 0, 'DELETE' ) );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ) );
	}

	public function test_a_protected_service_and_one_that_is_busy_are_refused_with_a_message(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );

		$r->set( array( 'protected' => true ) );
		$bad = CustomerDelete::run( Resource::find( $r->id() ), $user, 'DELETE' );
		$this->assertWPError( $bad );
		$this->assertStringContainsString( 'protected', $bad->get_error_message() );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ) );
		$r->set( array( 'protected' => false ) );

		$r->set( array( 'state' => 'provisioning' ) );
		$bad = CustomerDelete::run( Resource::find( $r->id() ), $user, 'DELETE' );
		$this->assertWPError( $bad );
		$this->assertStringContainsString( 'still running', $bad->get_error_message() );
		$r->set( array( 'state' => 'ready' ) );

		$r->set( array( 'breach_at' => time() ) );
		$this->assertStringContainsString( 'on hold', (string) CustomerDelete::blocker( Resource::find( $r->id() ) ) );
		$r->set( array( 'breach_at' => null ) );

		$this->assertTrue( CustomerDelete::run( Resource::find( $r->id() ), $user, 'DELETE' ) );
		$again = CustomerDelete::run( Resource::find( $r->id() ), $user, 'DELETE' );
		$this->assertStringContainsString( 'already being deleted', $again->get_error_message() );
	}

	public function test_a_monthly_service_is_not_offered_and_not_deleted(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$row  = $this->service_license( wc_get_product( $v['m-None']->get_parent_id() ), array( 'variation_id' => $v['m-None']->get_id(), 'user_id' => $user, 'email' => self::MAIL, 'source' => 'order', 'fire' => false ) );
		$r    = Resource::create( $row, Registry::get( 'proxmox' ), array(), array( 'state' => 'ready', 'desired' => 'ready' ) );

		$this->assertSame( '', CustomerDelete::kind( $r ) );
		$bad = CustomerDelete::run( $r, $user, 'DELETE' );
		$this->assertWPError( $bad );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ) );

		$beltoft_services_delete_id = $r->id();
		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/delete.php';
		$this->assertSame( '', trim( (string) ob_get_clean() ), 'No Delete block for a monthly server.' );
	}

	public function test_a_database_is_deleted_the_same_way(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$db      = $this->db_usage_resource( $user, $account->get_id() );
		$other   = $this->db_usage_resource( $user, $account->get_id() );

		$this->assertSame( 'database', CustomerDelete::kind( $db ) );
		$this->assertSame( 'DELETE', CustomerDelete::word( $db ) );
		$this->assertWPError( CustomerDelete::run( $db, $user, 'delete me' ) );
		$this->assertTrue( CustomerDelete::run( $db, $user, 'DELETE' ) );

		$fresh = Resource::find( $db->id() );
		$this->assertSame( 'deleted', $fresh->get( 'desired' ) );
		$this->assertSame( '', (string) $fresh->get( 'delete_origin' ) );
		$this->assertContains( 'Customer deleted the service', $this->history( $db ) );
		$this->assertSame( Licenses::STATUS_REVOKED, Licenses::find( (int) $db->license()['id'] )['status'] );
		$this->assertSame( 'ready', Resource::find( $other->id() )->get( 'desired' ) );
		$this->assertSame( Licenses::STATUS_ACTIVE, Licenses::find( (int) $other->license()['id'] )['status'] );
		$this->assertTrue( wcs_get_subscription( $account->get_id() )->has_status( 'active' ) );
	}

	public function test_the_texts_say_what_is_deleted_and_that_billing_continues_until_then(): void {
		$this->assertSame( 'Deleting removes the server and all its data, including snapshots and backups. It cannot be undone. Billing stops in the hour it is deleted.', CustomerDelete::text( 'server' ) );
		$this->assertSame( 'Deleting removes the database and all its data, including backups. It cannot be undone. Billing stops in the hour it is deleted.', CustomerDelete::text( 'database' ) );
		$this->assertSame( 'A stopped server is still billed. Delete it to stop the charge.', CustomerDelete::billing_note( 'server' ) );
		$this->assertSame( 'The database is billed every hour it exists. Delete it to stop the charge.', CustomerDelete::billing_note( 'database' ) );
	}

	public function test_the_delete_block_and_the_billing_note_are_on_the_pages(): void {
		$v     = $this->usage_product();
		$user  = $this->new_user();
		$r     = $this->usage_vm( $v['u-None'], $user, 5 );
		$db    = $this->db_usage_resource( $user );

		foreach ( array( $r->id() => array( 'Delete server', 'Type DELETE to confirm' ), $db->id() => array( 'Delete database', 'Type DELETE to confirm' ) ) as $id => $needles ) {
			$beltoft_services_delete_id = $id;
			ob_start();
			include BELTOFT_SERVICES_PATH . 'templates/account/delete.php';
			$html = (string) ob_get_clean();

			foreach ( $needles as $needle ) {
				$this->assertStringContainsString( $needle, $html );
			}

			$this->assertStringContainsString( 'data-delete-go', $html );
			$this->assertStringContainsString( 'Billing stops in the hour it is deleted.', $html );

			// A server's note is under its power buttons, once; the delete block only says it for a database.
			if ( $id === $r->id() ) {
				$this->assertStringNotContainsString( 'Delete it to stop the charge.', $html );
				$this->assertStringNotContainsString( 'data-delete-billing', $html );
			} else {
				$this->assertStringContainsString( 'Delete it to stop the charge.', $html );
			}
		}

		// The note next to Shut down and Start on the server's head.
		ob_start();
		\BeltoftServices\Proxmox\Panels::page_head( false, $r, $r->license(), array(), array() );
		$head = (string) ob_get_clean();
		$this->assertStringContainsString( 'A stopped server is still billed. Delete it to stop the charge.', $head );
		$this->assertStringContainsString( 'data-power-action="shutdown"', $head );
		$this->assertSame( 1, substr_count( $head, 'is still billed' ), 'The note is printed once.' );
	}

	public function test_the_route_deletes_for_the_owner_only_and_asks_for_the_word(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );
		$call = static function ( int $id, string $confirm ): \WP_REST_Response {
			$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/delete' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'confirm' => $confirm ) ) );

			return rest_get_server()->dispatch( $request );
		};

		wp_set_current_user( $this->new_user() );
		$this->assertSame( 403, $call( $r->id(), 'DELETE' )->get_status() );

		wp_set_current_user( $user );
		$wrong = $call( $r->id(), 'nope' );
		$this->assertSame( 422, $wrong->get_status() );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ) );

		$ok = $call( $r->id(), 'DELETE' );
		$this->assertSame( 202, $ok->get_status() );
		$this->assertTrue( $ok->get_data()['deleting'] );
		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'desired' ) );
		Jobs::run( $r->id() );
	}
}
