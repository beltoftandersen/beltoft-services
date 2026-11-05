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
		$this->assertContains( 'beltoft_services_hosting_restore_done', Emails::IDS );
		$r      = $this->with_backup();
		$volid  = HostingBackups::stored( $r )[0]['volid'];
		$slug   = 'w' . $r->id();

		( new HostingRestore( $r ) )->start( 'files', $volid, $slug );
		HostingRestore::run( $r->id() );

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
		( new HostingRestore( $r ) )->start( 'database', $volid, 'w' . $r->id() );
		HostingRestore::run( $r->id() );
		$this->mails = array();

		( new HostingRestore( Resource::find( $r->id() ) ) )->undo();
		HostingRestore::run( $r->id() );
		$undo = (string) preg_replace( '/\s+/', ' ', (string) array_values( array_filter( $this->mails, static fn( $m ) => false !== stripos( (string) $m['subject'], 'restore has finished' ) ) )[0]['message'] );
		$this->assertStringContainsString( 'back to the state it had before your last restore', $undo );

		$this->mails = array();
		$this->agent->fail_next( 'backup.prepare', 'dump failed' );
		( new HostingRestore( Resource::find( $r->id() ) ) )->start( 'account', $volid );
		HostingRestore::run( $r->id() );
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

	public function test_agent_alerts_isolation_and_loader_problems_are_each_reported(): void {
		$this->agent->set_loaders( array( '1000/shop' => array( 'state' => 'refused:symlink', 'ts' => 1 ), '1000/ok' => array( 'state' => 'ok', 'ts' => 2 ) ) );
		$this->agent->set_health_extra(
			array(
				'alerts'    => array( array( 'message' => 'db-shared CPU above 80 percent for 15 minutes' ) ),
				'isolation' => array( '1000' => 'ok', '1001' => 'off', 'bad key' => 'off' ),
			)
		);

		$found = NodeHealth::run();

		$this->assertCount( 3, $found['n1'] );
		$all = implode( ' | ', $this->admin_mails( 'attention' ) );
		$this->assertStringContainsString( 'db-shared CPU above 80 percent', $all );
		$this->assertStringContainsString( 'Port isolation is not on for container(s): 1001 (off)', $all );
		$this->assertStringNotContainsString( 'bad key', $all );
		$this->assertStringContainsString( '1 site(s) have a refused or failed loader file: 1000/shop (refused:symlink)', $all );
		$this->assertCount( 3, $this->admin_mails( 'attention' ) );
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
