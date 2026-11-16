<?php
/**
 * The restore-done email, the admin node health alerts and the weekly restore test. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Emails\Emails;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\NodeHealth;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\RestoreTest;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

/**
 * HostingRestoreDone, NodeHealth, RestoreTest.
 */
class OpsTest extends HostingCase {

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter( 'pre_wp_mail', function ( $pre, $args ) {
			$this->mails[] = $args;

			return true;
		}, 10, 2 );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_hosting_restore_pick' );
		delete_option( RestoreTest::OPTION );
		parent::tear_down();
	}

	/**
	 * Mails to the admin, whitespace folded.
	 *
	 * @param string $needle Text in the subject.
	 * @return string[]
	 */
	private function admin_mails( string $needle ): array {
		return array_values( array_map( static fn( $m ) => (string) preg_replace( '/\s+/', ' ', (string) $m['subject'] . ' ' . (string) $m['message'] ), array_filter( $this->mails, static fn( $m ) => 'christian.beltoft@gmail.com' === ( is_array( $m['to'] ) ? $m['to'][0] : $m['to'] ) && false !== strpos( (string) $m['subject'] . (string) $m['message'], $needle ) ) ) );
	}

	/**
	 * A ready account with a nightly backup.
	 */
	private function with_backup(): Resource {
		$r = $this->resource( array( 'backup_days' => 14 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		( new HostingBackups( Resource::find( $r->id() ) ) )->start( 'nightly' );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();

		return Resource::find( $r->id() );
	}

	// -- HostingRestoreDone ----------------------------------------------------------------------

	public function test_the_customer_is_told_when_a_restore_has_finished_with_the_undo_time_and_no_detail(): void {
		$this->assertContains( 'beltoft_services_hosting_restore_done', Emails::ids() );
		$r      = $this->with_backup();
		$volid  = HostingBackups::stored( $r )[0]['volid'];
		$slug   = 'w' . $r->id();

		$this->change( $r, 'restore', array( 'files', $volid, $slug ) );

		$to_customer = array_values( array_filter( $this->mails, static fn( $m ) => false !== stripos( (string) $m['subject'], 'restore has finished' ) ) );
		$this->assertCount( 1, $to_customer );
		$body = (string) preg_replace( '/\s+/', ' ', (string) $to_customer[0]['message'] );
		$this->assertStringContainsString( 'The files of the website ' . $slug . ' were restored', $body );
		$this->assertStringContainsString( 'you can undo it in My Account until', $body );
		$this->assertStringNotContainsString( "\u{2014}", $body );
		$this->assertStringNotContainsString( 'safety' . '1', $body, 'No internal names.' );
	}

	public function test_undo_and_a_failed_restore_have_their_own_words(): void {
		$r     = $this->with_backup();
		$volid = HostingBackups::stored( $r )[0]['volid'];
		$this->change( $r, 'restore', array( 'database', $volid, 'w' . $r->id() ) );
		$this->mails = array();

		$this->change( $r, 'undo' );
		$undo = (string) preg_replace( '/\s+/', ' ', (string) array_values( array_filter( $this->mails, static fn( $m ) => false !== stripos( (string) $m['subject'], 'restore has finished' ) ) )[0]['message'] );
		$this->assertStringContainsString( 'back to the state it had before your last restore', $undo );

		$this->mails = array();
		$this->agent->fail_next( 'backup.prepare', 'dump failed' );
		$this->change( $r, 'restore', array( 'account', $volid, '' ) );
		$failed = array_values( array_filter( $this->mails, static fn( $m ) => false !== stripos( (string) $m['subject'], 'restore has finished' ) ) );
		$this->assertCount( 1, $failed );
		$text = (string) preg_replace( '/\s+/', ' ', (string) $failed[0]['message'] );
		$this->assertStringContainsString( 'could not be completed', $text );
		$this->assertStringNotContainsString( 'dump failed', $text, 'The agent text does not reach the customer.' );
	}

	public function test_the_email_renders_in_html_and_plain_for_every_kind(): void {
		$r = $this->with_backup();

		foreach ( array( 'html', 'plain' ) as $type ) {
			foreach ( \BeltoftServices\Emails\HostingRestoreDone::KINDS as $kind ) {
				foreach ( array( true, false ) as $ok ) {
					$this->mails       = array();
					$email             = Emails::get( 'beltoft_services_hosting_restore_done' );
					$email->email_type = $type;
					$this->assertTrue( $email->trigger( $r, array( 'hosting_restore' => $kind, 'ok' => $ok, 'until' => '1 March 2027, 12:00', 'site' => 'shop' ) ), $type . $kind );
					$this->assertNotSame( '', trim( wp_strip_all_tags( (string) $this->mails[0]['message'] ) ) );
				}
			}
		}
	}

	// -- NodeHealth ------------------------------------------------------------------------------

	public function test_a_healthy_node_sends_nothing(): void {
		$this->assertSame( array(), NodeHealth::run() );
		$this->assertSame( array(), $this->admin_mails( 'attention' ) );
		$this->assertCount( 1, $this->agent->calls_of( 'agent.health' ) );
	}

	public function test_an_unreachable_agent_is_mailed_once_a_day(): void {
		$this->agent->fail_next( 'agent.health', 'down' );

		$found = NodeHealth::run();

		$this->assertStringContainsString( 'did not answer', $found['n1'][0] );
		$mails = $this->admin_mails( 'attention' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Hosting node n1', $mails[0] );

		$this->agent->fail_next( 'agent.health', 'down' );
		NodeHealth::run();
		$this->assertCount( 1, $this->admin_mails( 'attention' ), 'Once a day per problem.' );
	}

	public function test_agent_alerts_and_loader_problems_are_each_reported(): void {
		$this->agent->set_loaders( array( '1000/shop' => array( 'state' => 'refused:symlink', 'ts' => 1 ), '1000/ok' => array( 'state' => 'ok', 'ts' => 2 ) ) );
		$this->agent->set_health_extra(
			array(
				'alerts'    => array( array( 'message' => 'db-shared CPU above 80 percent for 15 minutes' ) ),
			)
		);

		$found = NodeHealth::run();

		$this->assertCount( 2, $found['n1'] );
		$all = implode( ' | ', $this->admin_mails( 'attention' ) );
		$this->assertStringContainsString( 'db-shared CPU above 80 percent', $all );
		$this->assertStringContainsString( '1 site(s) have a refused or failed loader file: 1000/shop (refused:symlink)', $all );
		$this->assertCount( 2, $this->admin_mails( 'attention' ) );
	}

	private const GB = 1073741824;

	public function test_a_node_with_room_and_a_guard_that_is_off_or_ok_sends_nothing(): void {
		$this->agent->set_health_extra(
			array(
				'storage'  => array(
					'node' => array( 'path' => '/hostingpool/ct', 'total_bytes' => 200 * self::GB, 'free_bytes' => 100 * self::GB ),
					'db'   => array( 'total_bytes' => 20 * self::GB, 'free_bytes' => 15 * self::GB ),
				),
				'db_guard' => array( 'state' => 'ok', 'floor_mb' => 2048, 'readonly' => array(), 'free_bytes' => 15 * self::GB ),
			)
		);
		NodeHealth::run();
		$this->assertSame( array(), $this->admin_mails( 'attention' ) );

		$this->agent->set_health_extra( array( 'storage' => array( 'node' => array( 'path' => '/', 'error' => 'no such path' ), 'db' => array( 'error' => 'no db' ) ), 'db_guard' => array( 'state' => 'off' ) ) );
		NodeHealth::run();
		$this->assertSame( array(), $this->admin_mails( 'attention' ), 'A reading that failed is not a low reading.' );
	}

	public function test_little_free_space_on_the_node_or_the_database_server_is_mailed_once_a_day(): void {
		$this->agent->set_health_extra(
			array(
				'storage' => array(
					'node' => array( 'path' => '/hostingpool/ct', 'total_bytes' => 200 * self::GB, 'free_bytes' => 10 * self::GB ),
					'db'   => array( 'total_bytes' => 20 * self::GB, 'free_bytes' => 1 * self::GB ),
				),
			)
		);

		$found = NodeHealth::run();
		NodeHealth::run();

		$this->assertCount( 2, $found['n1'] );
		$all = implode( ' | ', $this->admin_mails( 'attention' ) );
		$this->assertStringContainsString( 'Container storage on the node has 10.0 GB free of 200.0 GB.', $all );
		$this->assertStringContainsString( 'The database server has 1.0 GB free of 20.0 GB.', $all );
		$this->assertCount( 2, $this->admin_mails( 'attention' ), 'Once a day per problem.' );
		$this->assertStringNotContainsString( '—', $all );
	}

	public function test_a_tripped_database_guard_names_the_accounts_it_set_read_only(): void {
		$this->agent->set_health_extra( array( 'db_guard' => array( 'state' => 'tripped', 'floor_mb' => 2048, 'readonly' => array( 'c1001', 'c1002', 'bad name!' ), 'free_bytes' => 100 ) ) );

		$found = NodeHealth::run();

		$this->assertCount( 1, $found['n1'] );
		$mails = $this->admin_mails( 'attention' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'The database guard on the node set these accounts read only: c1001, c1002.', $mails[0] );
		$this->assertStringNotContainsString( 'bad name', $mails[0] );
	}

	public function test_an_older_agent_without_the_new_members_is_quiet(): void {
		$this->agent->set_health_extra( array( 'isolation' => array( '1001' => 'off' ), 'host' => array( 'mem_total_mb' => 100, 'mem_free_mb' => 1 ) ) );
		$this->assertSame( array(), NodeHealth::run() );
	}

	public function test_a_health_job_still_running_is_read_again_by_the_same_id_and_its_alerts_are_mailed(): void {
		$this->agent->set_health_extra( array( 'alerts' => array( array( 'message' => 'db-shared CPU above 80 percent for 15 minutes' ) ) ) );
		$this->agent->pending( 'agent.health', 3 );

		$this->assertSame( array(), NodeHealth::run() );
		$this->assertSame( array(), $this->admin_mails( 'attention' ) );
		$ids = array_unique( array_column( $this->agent->calls_of( 'agent.health' ), 'job_id' ) );
		$this->assertCount( 1, $ids );
		$this->assertTrue( \BeltoftServices\Jobs::pending_action( NodeHealth::READ, array( 'n1', $ids[0], 1 ) ), 'A follow-up read is queued.' );

		for ( $i = 1; $i <= 4 && array() === $this->admin_mails( 'attention' ); $i++ ) {
			NodeHealth::read( 'n1', $ids[0], $i );
		}

		$this->assertStringContainsString( 'db-shared CPU above 80 percent', implode( ' ', $this->admin_mails( 'attention' ) ) );
		$this->assertCount( 1, array_unique( array_column( $this->agent->calls_of( 'agent.health' ), 'job_id' ) ), 'Never a second job.' );
	}

	public function test_the_bridge_and_a_failed_agent_loop_the_real_health_answer_carries_are_reported(): void {
		$this->agent->set_health_extra(
			array(
				'bridge' => false,
				'loops'  => array(
					'isolation' => array( 'last' => 1, 'ok' => false, 'error' => 'OSError' ),
					'scan'      => array( 'last' => 1, 'ok' => true ),
				),
			)
		);

		$found = NodeHealth::run();

		$all = implode( ' | ', $this->admin_mails( 'attention' ) );
		$this->assertCount( 2, $found['n1'] );
		$this->assertStringContainsString( 'The network bridge of the containers is missing', $all );
		$this->assertStringContainsString( 'Agent loop(s) failed: isolation (OSError)', $all );
		$this->assertStringNotContainsString( 'scan', $all );
	}

	public function test_a_disabled_node_is_skipped_and_the_kill_switch_stops_the_job(): void {
		$this->save_node( array( 'enabled' => '' ) );
		$this->assertSame( array(), NodeHealth::run() );
		$this->assertCount( 0, $this->agent->calls_of( 'agent.health' ) );

		$this->save_node();
		$this->settings( array( 'pause_all' => true ) );
		$this->assertSame( array(), NodeHealth::run() );
		$this->assertCount( 0, $this->agent->calls_of( 'agent.health' ) );
	}

	public function test_the_capacity_alert_runs_with_the_health_job(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '20', 'hosting_alert_percent' => '80' ) );
		$r = $this->resource( array( 'memory_mb' => 10000, 'db_buffer_mb' => 0 ) );
		$this->provision_fully( $r );

		NodeHealth::run();

		$this->assertCount( 1, $this->admin_mails( 'is filling up' ) );
	}

	public function test_the_jobs_are_hooked_and_scheduled_once(): void {
		foreach ( array( NodeHealth::HOOK => NodeHealth::class, RestoreTest::HOOK => RestoreTest::class ) as $hook => $class ) {
			$this->assertNotFalse( has_action( $hook, array( $class, 'run' ) ), $hook );
			as_unschedule_all_actions( $hook, array(), 'beltoft-services' );
			$class::schedule();
			$class::schedule();
			$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => $hook, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ), $hook );
			as_unschedule_all_actions( $hook, array(), 'beltoft-services' );
		}
	}

	// -- RestoreTest -----------------------------------------------------------------------------

	public function test_the_restore_test_is_off_without_a_scratch_container_and_by_the_setting(): void {
		$this->with_backup();
		$this->assertSame( array(), RestoreTest::run(), 'No scratch id on the node.' );
		$this->assertCount( 0, $this->agent->calls_of( 'backup.restore_test' ) );

		$this->save_node( array( 'scratch_vmid' => 9500 ) );
		$this->settings( array( 'hosting_restore_test' => 0 ) );
		$this->assertSame( array(), RestoreTest::run() );
		$this->assertCount( 0, $this->agent->calls_of( 'backup.restore_test' ) );
	}

	public function test_the_scratch_container_cannot_be_the_template(): void {
		$err = \BeltoftServices\Hosting\Nodes::save( 'n1', array_merge( (array) \BeltoftServices\Hosting\Nodes::get( 'n1' ), array( 'token_secret' => '', 'agent_key' => '', 'scratch_vmid' => (int) \BeltoftServices\Hosting\Nodes::get( 'n1' )['template'] ) ) );
		$this->assertWPError( $err );
		$this->assertStringEndsWith( 'scratch_vmid', $err->get_error_code() );

		// A node record that got there anyway (an older save): the test refuses, never touches the template.
		$all                         = get_option( \BeltoftServices\Hosting\Nodes::OPTION );
		$all['n1']['scratch_vmid']   = $all['n1']['template'];
		update_option( \BeltoftServices\Hosting\Nodes::OPTION, $all, false );
		$before = count( $this->agent->calls_of( 'backup.restore_test' ) );
		$result = RestoreTest::test_node( 'n1' );
		$this->assertFalse( $result['ok'] );
		$this->assertCount( $before, $this->agent->calls_of( 'backup.restore_test' ) );
	}

	public function test_a_passing_test_names_the_backup_and_the_scratch_container_and_is_remembered(): void {
		$r = $this->with_backup();
		$this->save_node( array( 'scratch_vmid' => 9500 ) );

		$results = RestoreTest::run();

		$this->assertTrue( $results['n1']['ok'] );
		$this->assertSame( $r->id(), $results['n1']['resource'] );
		$calls = $this->agent->calls_of( 'backup.restore_test' );
		$this->assertCount( 1, $calls );
		$this->assertSame( 1000, $calls[0]['args']['vmid'] );
		$this->assertSame( 9500, $calls[0]['args']['scratch_vmid'] );
		$this->assertMatchesRegularExpression( '/^b\d+$/D', $calls[0]['args']['snapshot'] );
		$this->assertSame( 'b' . HostingBackups::stored( $r )[0]['ctime'], $calls[0]['args']['snapshot'], 'The newest backup.' );
		$this->assertTrue( RestoreTest::last()['nodes']['n1']['ok'] );
		$this->assertCount( 0, $this->admin_mails( 'restore test failed' ) );
		$this->assertSame( 'christian.beltoft@gmail.com', (string) Settings::get( 'admin_email' ) );
	}

	public function test_a_failing_test_alerts_the_admin_once_a_day(): void {
		$this->with_backup();
		$this->save_node( array( 'scratch_vmid' => 9500 ) );
		$this->agent->set_restore_test_result( array( 'status' => 502, 'title_match' => false ) );

		$results = RestoreTest::run();

		$this->assertFalse( $results['n1']['ok'] );
		$this->assertStringContainsString( 'HTTP 502, title differs', $results['n1']['why'] );
		$mails = $this->admin_mails( 'restore test failed' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'no backup', $mails[0] );

		RestoreTest::run();
		$this->assertCount( 1, $this->admin_mails( 'restore test failed' ), 'Once a day per node.' );
		$this->assertFalse( RestoreTest::last()['nodes']['n1']['ok'] );
	}

	public function test_a_failed_job_is_a_failed_test_and_the_same_week_asks_the_same_job(): void {
		$this->with_backup();
		$this->save_node( array( 'scratch_vmid' => 9500 ) );
		$this->agent->fail_next( 'backup.restore_test', 'scratch container did not start' );

		$failed = RestoreTest::run();
		$this->assertFalse( $failed['n1']['ok'] );
		$this->assertStringContainsString( 'scratch container did not start', $failed['n1']['why'] );

		$again = RestoreTest::run();
		$this->assertFalse( $again['n1']['ok'], 'The agent returns the first job for the same id: no second run this week.' );
		$ids = array_unique( array_column( $this->agent->calls_of( 'backup.restore_test' ), 'job_id' ) );
		$this->assertCount( 1, $ids );
	}

	public function test_a_job_still_running_is_pending_not_a_failure(): void {
		$this->with_backup();
		$this->save_node( array( 'scratch_vmid' => 9500 ) );
		$this->agent->pending( 'backup.restore_test', 3 );

		$pending = RestoreTest::run();

		$this->assertTrue( $pending['n1']['pending'] );
		$this->assertCount( 0, $this->admin_mails( 'restore test failed' ) );

		$this->agent->job( array_column( $this->agent->calls_of( 'backup.restore_test' ), 'job_id' )[0] );
		$this->agent->job( array_column( $this->agent->calls_of( 'backup.restore_test' ), 'job_id' )[0] );
		$done = RestoreTest::run();
		$this->assertTrue( $done['n1']['ok'] );
		$this->assertArrayNotHasKey( 'pending', $done['n1'] );
	}

	public function test_a_restore_test_still_running_is_read_again_and_a_failure_then_is_mailed(): void {
		$this->with_backup();
		$this->save_node( array( 'scratch_vmid' => 9500 ) );
		$this->agent->set_restore_test_result( array( 'status' => 502, 'title_match' => false ) );
		$this->agent->pending( 'backup.restore_test', 3 );

		$pending = RestoreTest::run();
		$this->assertTrue( $pending['n1']['pending'] );
		$call = $this->agent->calls_of( 'backup.restore_test' )[0];
		$week = gmdate( 'oW', \BeltoftServices\Jobs::now() );
		$this->assertSame( array( 'n1', (int) $pending['n1']['resource'], (int) substr( $call['args']['snapshot'], 1 ), 1, $week ), $this->queued_read( RestoreTest::READ ), 'A follow-up read is queued.' );

		$args = $this->queued_read( RestoreTest::READ );
		RestoreTest::read( ...$args );
		RestoreTest::read( 'n1', $args[1], $args[2], 2, $week );
		RestoreTest::read( 'n1', $args[1], $args[2], 3, $week );

		$this->assertCount( 1, $this->admin_mails( 'restore test failed' ), 'The failure of a job that was running is reported.' );
		$this->assertFalse( RestoreTest::last()['nodes']['n1']['ok'] );
		$this->assertCount( 1, array_unique( array_column( $this->agent->calls_of( 'backup.restore_test' ), 'job_id' ) ), 'Always the same job.' );
	}

	/**
	 * Arguments of the one waiting follow-up of a hook.
	 *
	 * @return array<int, mixed>
	 */
	private function queued_read( string $hook ): array {
		$ids = as_get_scheduled_actions( array( 'hook' => $hook, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' );
		$this->assertCount( 1, $ids );

		return \ActionScheduler::store()->fetch_action( (int) $ids[0] )->get_args();
	}

	public function test_a_scratch_id_inside_the_customer_range_is_refused_and_nothing_is_sent(): void {
		$this->with_backup();
		$this->save_node( array( 'scratch_vmid' => 1500 ) );

		$results = RestoreTest::run();

		$this->assertFalse( $results['n1']['ok'] );
		$this->assertStringContainsString( 'inside the customer range', $results['n1']['why'] );
		$this->assertCount( 0, $this->agent->calls_of( 'backup.restore_test' ) );
	}

	public function test_no_account_with_a_backup_skips_and_the_pick_is_random_but_injectable(): void {
		$this->save_node( array( 'scratch_vmid' => 9500 ) );
		$this->assertTrue( RestoreTest::run()['n1']['skipped'] > '' ? true : false );

		$a = $this->with_backup();
		$b = $this->with_backup();
		add_filter( 'beltoft_services_hosting_restore_pick', fn() => 1 );

		$result = RestoreTest::test_node( 'n1' );
		$this->assertSame( $b->id(), $result['resource'], 'The injected index picks the second account.' );
		remove_all_filters( 'beltoft_services_hosting_restore_pick' );
		$this->assertNotSame( $a->id(), $b->id() );
	}

	public function test_the_node_record_takes_the_scratch_id_and_the_setting_exists(): void {
		$this->save_node( array( 'scratch_vmid' => 9500 ) );
		$this->assertSame( 9500, Nodes::get( 'n1' )['scratch_vmid'] );
		$this->assertSame( 'checkbox', Settings::fields()['hosting_restore_test']['type'] );
		$this->assertSame( 1, (int) Settings::fields()['hosting_restore_test']['default'] );
	}
}
