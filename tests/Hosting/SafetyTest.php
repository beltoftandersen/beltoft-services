<?php
/**
 * The safety point of an account restore as a protected backup, and the safety point before an automatic PHP
 * switch with a rollback in the account. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\HostingUsage;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

/**
 * HostingRestore safety modes and the PHP switch.
 */
class SafetyTest extends HostingCase {

	/**
	 * The clock.
	 *
	 * @var int
	 */
	private $clock;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		$this->move_to( strtotime( '2027-03-01 03:00:00 UTC' ) );
		add_filter( 'beltoft_services_now', fn() => $this->clock );
		add_filter( 'pre_wp_mail', function ( $pre, $args ) {
			$this->mails[] = $args;

			return true;
		}, 10, 2 );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_now' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Moves the clock of the plugin and of the fake node.
	 *
	 * @param int $time Unix time.
	 */
	private function move_to( int $time ): void {
		$this->clock = $time;
		$this->node->set_clock( $time );
	}

	/**
	 * A ready account with one nightly backup, one hour old.
	 *
	 * @return array{0: Resource, 1: string}
	 */
	private function with_backup(): array {
		$r = $this->resource( array( 'backup_days' => 14 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$b = new HostingBackups( Resource::find( $r->id() ) );
		$b->start( 'nightly' );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$r = Resource::find( $r->id() );
		$this->move_to( $this->clock + HOUR_IN_SECONDS );

		return array( $r, HostingBackups::stored( $r )[0]['volid'] );
	}

	/**
	 * The backups of the container in the fake storage.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function all_backups(): array {
		return $this->node->state()['backups'];
	}

	public function test_the_node_option_defaults_to_the_safe_vzdump_and_only_takes_two_values(): void {
		$this->assertSame( 'vzdump', Nodes::get( 'n1' )['restore_safety'] );
		$this->assertSame( 'vzdump', Nodes::defaults()['restore_safety'] );

		$this->save_node( array( 'restore_safety' => 'snapshot' ) );
		$this->assertSame( 'snapshot', Nodes::get( 'n1' )['restore_safety'] );

		$base = array( 'label' => 'x' );
		$this->assertWPError( Nodes::save( 'n1', array_merge( $base, array( 'restore_safety' => 'nothing' ) ) ) );
	}

	public function test_an_account_restore_uses_a_protected_backup_as_its_safety_point_by_default(): void {
		list( $r, $volid ) = $this->with_backup();
		$vmid = Records::for_resource( $r->id() )['vmid'];

		( new HostingRestore( $r ) )->start( 'account', $volid );
		HostingRestore::run( $r->id() );
		$r      = Resource::find( $r->id() );
		$safety = HostingRestore::safety( $r );

		$this->assertTrue( $r->get( 'restore_last' )['ok'] );
		$this->assertSame( 'vzdump', $safety['mode'] );
		$this->assertMatchesRegularExpression( '#^pbs-store:backup/ct/1000/#', $safety['volid'] );
		$this->assertSame( array(), $this->node->state()['cts'][ $vmid ]['snapshots'], 'No snapshot: a forced restore may drop them.' );

		$safety_backup = array_values( array_filter( $this->all_backups(), static fn( $b ) => $b['volid'] === $safety['volid'] ) )[0];
		$this->assertSame( 1, $safety_backup['protected'] );
		$this->assertStringContainsString( 'safety', $safety_backup['notes'] );

		$this->assertSame( array( $volid ), array_column( HostingBackups::stored( $r ), 'volid' ), 'The safety backup is not in the customer list.' );
		$vzdumps = $this->node->calls_to( '#/vzdump$#', 'POST' );
		$this->assertSame( '{{guestname}} safety', end( $vzdumps )['params']['notes-template'] );
	}

	public function test_undo_restores_the_container_from_the_safety_backup_and_the_databases_from_its_dump(): void {
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'account', $volid );
		HostingRestore::run( $r->id() );
		$r      = Resource::find( $r->id() );
		$safety = HostingRestore::safety( $r );
		$before = count( $this->agent->state()['restores'] );

		$this->assertSame( array( 'started' => true ), ( new HostingRestore( $r ) )->undo() );
		HostingRestore::run( $r->id() );

		$vmid = Records::for_resource( $r->id() )['vmid'];
		$this->assertSame( $safety['volid'], $this->node->state()['cts'][ $vmid ]['restored_from'] );
		$this->assertSame( 'running', $this->node->state()['cts'][ $vmid ]['status'] );
		$new = array_slice( $this->agent->state()['restores'], $before );
		$this->assertSame( array( $safety['name'] ), array_values( array_unique( array_column( $new, 'snapshot' ) ) ) );
		$this->assertTrue( Resource::find( $r->id() )->get( 'restore_last' )['ok'] );
		$restores = $this->node->calls_to( '#/nodes/pve1/lxc$#', 'POST' );
		$this->assertCount( 2, $restores, 'The first restore and the undo.' );
		$this->assertSame( $safety['volid'], $restores[1]['params']['ostemplate'] );
		$this->assertSame( 'local-zfs', $restores[1]['params']['storage'], 'The container disks go to the plan storage, not the node default.' );
		$this->assertSame( 'hosting-pool', $restores[1]['params']['pool'] );
	}

	public function test_a_safety_backup_survives_the_nightly_prune_and_is_removed_after_24_hours(): void {
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'account', $volid );
		HostingRestore::run( $r->id() );
		$safety = HostingRestore::safety( Resource::find( $r->id() ) );

		// Several nightly rounds on the same day would prune all but the newest: the protected one stays.
		$this->move_to( $this->clock + HOUR_IN_SECONDS );
		$night = new HostingBackups( Resource::find( $r->id() ) );
		$night->start( 'nightly' );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$this->assertContains( $safety['volid'], array_column( $this->all_backups(), 'volid' ) );

		$this->move_to( $this->clock + 25 * HOUR_IN_SECONDS );
		HostingRestore::expire_all();

		$this->assertNull( HostingRestore::safety( Resource::find( $r->id() ) ) );
		$this->assertNotContains( $safety['volid'], array_column( $this->all_backups(), 'volid' ), 'Unprotected and deleted.' );
		$this->assertNotSame( array(), $this->all_backups(), 'The customer backups are left alone.' );
	}

	public function test_a_new_restore_replaces_the_old_safety_backup(): void {
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'account', $volid );
		HostingRestore::run( $r->id() );
		$first = HostingRestore::safety( Resource::find( $r->id() ) );

		$this->move_to( $this->clock + HOUR_IN_SECONDS );
		( new HostingRestore( Resource::find( $r->id() ) ) )->start( 'account', $volid );
		HostingRestore::run( $r->id() );
		$second = HostingRestore::safety( Resource::find( $r->id() ) );

		$this->assertNotSame( $first['volid'], $second['volid'] );
		$this->assertNotContains( $first['volid'], array_column( $this->all_backups(), 'volid' ) );
		$this->assertContains( $second['volid'], array_column( $this->all_backups(), 'volid' ) );
	}

	public function test_a_failed_safety_backup_stops_the_restore_before_the_container_is_touched(): void {
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'account', $volid );
		$this->node->next_task( array( 'exitstatus' => 'storage offline' ) );

		HostingRestore::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertFalse( $r->get( 'restore_last' )['ok'] );
		$this->assertNull( HostingRestore::safety( $r ) );
		$this->assertCount( 0, $this->node->calls_to( '#/nodes/pve1/lxc$#', 'POST' ), 'The container was not restored.' );
		$this->assertSame( 'running', $this->node->state()['cts'][1000]['status'] );
	}

	public function test_files_and_database_restores_still_use_a_snapshot(): void {
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'files', $volid, 'w' . $r->id() );
		HostingRestore::run( $r->id() );

		$safety = HostingRestore::safety( Resource::find( $r->id() ) );
		$this->assertSame( 'snapshot', $safety['mode'] );
		$this->assertArrayHasKey( $safety['name'], $this->node->state()['cts'][1000]['snapshots'] );
	}

	// -- PHP -------------------------------------------------------------------------------------------

	/**
	 * A ready account with a site on PHP 8.3 whose support ends 2027-02-10, with the clock after that.
	 */
	private function account_past_php_end(): Resource {
		$this->settings( array( 'hosting_php_default' => '8.4', 'hosting_php_eol' => '8.3=2027-02-10' ) );
		$r = $this->resource( array( 'backup_days' => 14 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		Sites::update( $r->id(), 'w' . $r->id(), array( 'php' => '8.3' ) );
		$this->move_to( strtotime( '2027-02-11 08:00:00 UTC' ) );

		return Resource::find( $r->id() );
	}

	private function sample( Resource $r ): Resource {
		$this->node->set_usage( 1000, array( 'disk' => 1073741824 ) );
		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();

		return Resource::find( $r->id() );
	}

	public function test_the_automatic_php_switch_makes_a_safety_point_first(): void {
		$r    = $this->account_past_php_end();
		$slug = 'w' . $r->id();

		$r = $this->sample( $r );

		$safety = HostingRestore::safety( $r );
		$this->assertNotNull( $safety, 'A safety point was made.' );
		$this->assertSame( 'snapshot', $safety['mode'] );
		$this->assertSame( 'php', $safety['scope'] );
		$this->assertArrayHasKey( $safety['name'], $this->node->state()['cts'][1000]['snapshots'] );

		$prepare = $this->agent->calls_of( 'backup.prepare' );
		$this->assertSame( $safety['name'], end( $prepare )['args']['label'], 'With a fresh dump of the databases.' );

		$this->assertSame( '8.4', Sites::find( $r->id(), $slug )['php'] );
		$order = array_column( array_merge( $this->agent->calls_of( 'backup.prepare' ), $this->agent->calls_of( 'site.php' ) ), 'op' );
		$this->assertSame( array( 'backup.prepare', 'site.php' ), array_slice( $order, -2 ), 'The dump came before the switch.' );
	}

	public function test_a_safety_point_of_the_last_24_hours_is_reused_not_replaced(): void {
		$r = $this->account_past_php_end();
		$r->set( array( 'safety' => array( 'name' => 'safetymine', 'at' => $this->clock - HOUR_IN_SECONDS, 'expires' => $this->clock + 23 * HOUR_IN_SECONDS, 'scope' => 'account', 'mode' => 'snapshot', 'volid' => '' ) ) );

		$r = $this->sample( $r );

		$this->assertSame( 'safetymine', HostingRestore::safety( $r )['name'], 'The customer own Undo is kept.' );
		$this->assertSame( '8.4', Sites::find( $r->id(), 'w' . $r->id() )['php'] );
	}

	public function test_without_a_safety_point_the_site_is_not_switched_and_the_admin_is_told(): void {
		$r = $this->account_past_php_end();
		$this->agent->fail_next( 'backup.prepare', 'dump failed' );

		$r = $this->sample( $r );

		$this->assertSame( '8.3', Sites::find( $r->id(), 'w' . $r->id() )['php'], 'Not switched.' );
		$this->assertCount( 0, $this->agent->calls_of( 'site.php' ) );
		$this->assertTrue( (bool) array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'was not switched' ) ) );

		$r = $this->sample( $r );
		$this->assertSame( '8.4', Sites::find( $r->id(), 'w' . $r->id() )['php'], 'The next run switches it.' );
	}

	public function test_a_round_in_progress_delays_the_switch(): void {
		$r = $this->account_past_php_end();
		( new HostingBackups( $r ) )->start( 'nightly' );

		$r = $this->sample( $r );

		$this->assertSame( '8.3', Sites::find( $r->id(), 'w' . $r->id() )['php'] );
	}

	public function test_the_customer_can_go_back_for_seven_days_and_sees_a_warning(): void {
		$r    = $this->sample( $this->account_past_php_end() );
		$slug = 'w' . $r->id();
		$this->assertSame( '8.4', Sites::find( $r->id(), $slug )['php'] );

		ob_start();
		HostingAccount::render( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-hosting-action="php_rollback"', $html );
		$this->assertStringContainsString( 'Go back to PHP 8.3', $html );

		$back = ( new HostingActions( $r ) )->php_rollback( $slug );
		$this->assertSame( array( 'slug' => $slug, 'php' => '8.3' ), $back );
		$this->assertSame( '8.3', Sites::find( $r->id(), $slug )['php'] );
		$this->assertSame( '8.3', $this->agent->state()['sites'][1000][ $slug ]['php'] );

		$r = Resource::find( $r->id() );
		$this->assertArrayNotHasKey( $slug, (array) $r->get( 'php_rollback' ) );
		ob_start();
		HostingAccount::render( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'no longer gets security updates', $html );
		$this->assertStringContainsString( 'color:#b32d2e', $html );

		$this->sample( $r );
		$this->assertSame( '8.3', Sites::find( $r->id(), $slug )['php'], 'It is not switched a second time.' );
		$this->assertCount( 2, $this->agent->calls_of( 'site.php' ), 'The switch and the rollback only.' );

		$this->assertSame( 'not_found', ( new HostingActions( Resource::find( $r->id() ) ) )->php_rollback( $slug )->get_error_code(), 'Nothing left to go back to.' );
	}

	public function test_the_rollback_ends_after_seven_days_and_for_unknown_sites(): void {
		$r    = $this->sample( $this->account_past_php_end() );
		$slug = 'w' . $r->id();

		$this->assertSame( 'not_found', ( new HostingActions( $r ) )->php_rollback( 'ghost' )->get_error_code() );

		$this->move_to( $this->clock + 8 * DAY_IN_SECONDS );
		$this->assertSame( 'not_found', ( new HostingActions( Resource::find( $r->id() ) ) )->php_rollback( $slug )->get_error_code() );

		$this->sample( Resource::find( $r->id() ) );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'php_rollback' ), 'The expired marker is forgotten.' );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'php_rollback', (string) ob_get_clean() );
	}

	public function test_the_rollback_route_belongs_to_the_owner(): void {
		$r    = $this->sample( $this->account_past_php_end() );
		$slug = 'w' . $r->id();
		$path = '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/sites/' . $slug . '/php/rollback';

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $path ) )->get_status() );

		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->assertSame( 200, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $path ) )->get_status() );
		$this->assertSame( 404, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $path ) )->get_status() );
	}
}
