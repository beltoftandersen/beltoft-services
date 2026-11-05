<?php
/**
 * Provisioning a hosting account. Only the fakes are used: nothing here reaches a node, an agent or a network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Crypto;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\CtOwnership;
use BeltoftServices\Hosting\Provision;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

/**
 * Every provision step against the fake node and the fake agent.
 */
class ProvisionTest extends HostingCase {

	public function test_provision_runs_all_steps(): void {
		$r      = $this->resource();
		$result = $this->provision_fully( $r );

		$this->assertSame( Provision::STEPS, $result->meta()['steps'] );

		$record = Records::for_resource( $r->id() );
		$this->assertSame( 'n1', $record['node'] );
		$this->assertSame( 1000, $record['vmid'] );
		$this->assertSame( '10.200.0.10', $record['ip'] );
		$this->assertSame( 22001, $record['ssh_port'] );
		$this->assertSame( '10.200.0.3', $record['db_host'] );

		$fresh = Resource::find( $r->id() );
		$this->assertSame(
			array(
				'node'     => 'n1',
				'vmid'     => 1000,
				'ip'       => '10.200.0.10',
				'ssh_port' => 22001,
			),
			$fresh->get( 'hosting' )
		);
	}

	public function test_the_container_is_a_tagged_linked_clone_in_the_pool(): void {
		$r = $this->resource();
		$this->provision_fully( $r );

		$ct = $this->ct( $r );
		$this->assertSame( 'running', $ct['status'] );
		$this->assertSame( 'hosting-pool', $ct['pool'] );
		$this->assertSame( 'c' . $r->id(), $ct['name'] );
		$this->assertSame( CtOwnership::tag( $r ), $ct['config']['description'] );
		$this->assertStringStartsWith( 'beltoft-services:', $ct['config']['description'] );

		$clone = $this->node->calls_to( '#/lxc/9000/clone$#', 'POST' );
		$this->assertCount( 1, $clone );
		$this->assertSame( 0, $clone[0]['params']['full'], 'A linked clone.' );
	}

	public function test_the_container_is_configured_from_the_plan(): void {
		$r = $this->resource( array( 'disk_gb' => 12, 'cores' => 3, 'memory_mb' => 4096, 'swap_mb' => 256 ) );
		$this->provision_fully( $r );

		$ct = $this->ct( $r );
		$c  = $ct['config'];

		$this->assertSame( 3, $c['cores'] );
		$this->assertSame( 3, $c['cpulimit'] );
		$this->assertSame( 300, $c['cpuunits'] );
		$this->assertSame( 4096, $c['memory'] );
		$this->assertSame( 256, $c['swap'] );
		$this->assertSame( 1, $c['onboot'] );
		$this->assertSame( 'name=eth0,bridge=hbr0,ip=10.200.0.10/16,gw=10.200.0.1,firewall=1', $c['net0'] );
		$this->assertSame( 'local-zfs:subvol-1000-disk-1,mp=/var/backups/db,backup=1,size=3G', $c['mp0'] );
		$this->assertStringContainsString( 'size=12G', $c['rootfs'] );

		$this->assertSame(
			array(
				'enable'    => 1,
				'ipfilter'  => 1,
				'macfilter' => 1,
				'policy_in' => 'DROP',
				'policy_out' => 'DROP',
			),
			$ct['firewall_options']
		);

		// Both policies drop, so the container joins the node's security group (edge, database, DNS), once.
		$this->assertSame( array( 'group' ), array_column( $ct['firewall_rules'], 'type' ) );
		$this->assertSame( array( 'hosting-ct' ), array_column( $ct['firewall_rules'], 'action' ) );
		$this->provision( Resource::find( $r->id() ) );
		$this->assertCount( 1, $this->ct( $r )['firewall_rules'], 'The rule is not added twice.' );
	}

	public function test_a_recorded_container_id_somebody_else_took_since_is_given_up_on_retry(): void {
		$r = $this->resource();
		Records::open( $r->id(), 'n1' ); // What the place step makes.
		$this->assertNotNull( Records::for_resource( $r->id() ) );

		// The first try recorded the lowest id and stopped; before the clone, somebody made a container of their own on it.
		$first = Records::allocate_vmid( $r->id(), 1000, 1999, array(), static fn() => true );
		$this->assertSame( 1000, $first );
		$this->node->add_ct( array( 'vmid' => 1000, 'node' => 'pve1', 'name' => 'foreign', 'config' => array( 'description' => 'something else' ) ) );

		$this->provision_fully( Resource::find( $r->id() ) );

		$this->assertNotSame( 1000, (int) Records::for_resource( $r->id() )['vmid'], 'The foreign container is not adopted and not overwritten.' );
		$this->assertSame( 'foreign', $this->node->state()['cts'][1000]['name'] );
	}

	public function test_the_dump_mount_is_part_of_the_container_backup(): void {
		$r = $this->resource();
		$this->provision_fully( $r );

		// The nightly SQL dumps on this mount are the only copy of the databases (they live on the shared server).
		$this->assertStringContainsString( ',backup=1', (string) $this->ct( $r )['config']['mp0'] );
		$this->assertStringNotContainsString( 'backup=0', (string) $this->ct( $r )['config']['mp0'] );
	}

	public function test_the_network_rate_limit_is_added_when_the_node_has_one(): void {
		$this->save_node( array( 'net_mbit' => 100 ) );
		$r = $this->resource();
		$this->provision_fully( $r );

		$this->assertStringEndsWith( 'firewall=1,rate=100', $this->ct( $r )['config']['net0'] );
	}

	public function test_the_agent_prepares_the_container_and_makes_the_database_and_the_site(): void {
		$r = $this->resource( array( 'valkey_mb' => 64, 'php_workers' => 20 ) );
		$this->provision_fully( $r );

		$account = 'c' . $r->id();
		$state   = $this->agent->state();

		$this->assertTrue( $state['cts'][1000]['prepared'] );
		$this->assertSame( 64, $state['cts'][1000]['valkey_mb'] );
		$this->assertSame( 20, $state['cts'][1000]['workers'] );
		$this->assertSame( 22001, $state['cts'][1000]['ssh_port'] );

		$slug = 'w' . $r->id();
		$this->assertSame( array( $account . '_w' . $r->id() ), $state['dbs'][ $account ]['databases'] );
		$this->assertSame( '10.200.0.10', $state['dbs'][ $account ]['host_ip'] );
		$this->assertArrayHasKey( $slug, $state['sites'][1000] );
		$this->assertSame( $slug . '.preview.example.test', $state['sites'][1000][ $slug ]['host'] );
		$this->assertSame(
			array(
				array(
					'host'      => $slug . '.preview.example.test',
					'ip'        => '10.200.0.10',
					'suspended' => false,
				),
			),
			$state['edge'][ $account ]
		);

		$this->assertCount( 1, $this->agent->calls_of( 'site.wp_install' ) );
		$this->assertNotNull( Sites::find( $r->id(), $slug ) );
	}

	public function test_the_order_can_name_the_first_site(): void {
		$r = $this->resource(
			array(),
			array(
				'site' => array(
					'slug'    => 'my-shop',
					'label'   => 'My shop',
					'php'     => '8.4',
					'install' => false,
				),
			)
		);
		$this->provision_fully( $r );

		$site = Sites::find( $r->id(), 'my-shop' );
		$this->assertSame( 'My shop', $site['label'] );
		$this->assertSame( '8.4', $site['php'] );
		$this->assertCount( 0, $this->agent->calls_of( 'site.wp_install' ), 'install is off.' );
		$this->assertSame( 'my-shop.preview.example.test', $this->agent->state()['sites'][1000]['my-shop']['host'] );
	}

	public function test_the_database_password_is_encrypted_at_rest_and_never_logged(): void {
		$r = $this->resource();
		$this->provision_fully( $r );

		$fresh  = Resource::find( $r->id() );
		$stored = (string) $fresh->get( 'db_secret' );
		$plain  = Crypto::decrypt( $stored );

		$this->assertNotSame( '', $stored );
		$this->assertNotNull( $plain );
		$this->assertGreaterThanOrEqual( 16, strlen( $plain ) );
		$this->assertStringNotContainsString( $plain, $stored );
		$this->assertStringNotContainsString( $plain, (string) wp_json_encode( $this->agent->calls() ) );
		$this->assertStringNotContainsString( $plain, (string) wp_json_encode( $this->agent->state() ) );
		$this->assertSame( '***', $this->agent->calls_of( 'db.create' )[0]['args']['password'] );
	}

	public function test_a_finished_provision_sends_nothing_again(): void {
		$r = $this->resource();
		$this->provision_fully( $r );

		$node_calls  = count( $this->node->calls() );
		$agent_calls = count( $this->agent->calls() );

		$again = $this->provision( $r );

		$this->assertTrue( $again->is_done() );
		$this->assertSame( $node_calls, count( $this->node->calls() ), 'The steps are recorded: nothing runs again.' );
		$this->assertSame( $agent_calls, count( $this->agent->calls() ) );
	}

	public function test_a_run_that_lost_its_step_record_sends_no_second_clone_or_write(): void {
		$r = $this->resource();
		$this->provision_fully( $r );

		$writes = array(
			'POST' => array( '#/clone$#', '#/status/start$#', '#/firewall/rules$#' ),
			'PUT'  => array( '#/config$#', '#/resize$#', '#/firewall/options$#' ),
		);
		$count  = function () use ( $writes ): array {
			$out = array();
			foreach ( $writes as $method => $paths ) {
				foreach ( $paths as $path ) {
					$out[ $method . $path ] = count( $this->node->calls_to( $path, $method ) );
				}
			}
			return $out;
		};
		$before = $count();

		// A crash after the work, before the steps were stored: the remote state is re-read, nothing is doubled.
		Resource::find( $r->id() )->set( array( 'steps' => array( 'place', 'ip', 'vmid' ) ) );
		$this->provision_fully( $r );

		$this->assertSame( $before, $count(), 'The container already matches: no write is sent again.' );
		$this->assertSame( 1, $before['POST#/clone$#'] );
		$this->assertSame( 1000, Records::for_resource( $r->id() )['vmid'] );
	}

	public function test_two_accounts_get_their_own_address_port_and_container_id(): void {
		$a = $this->resource();
		$b = $this->resource();
		$this->provision_fully( $a );
		$this->provision_fully( $b );

		$ra = Records::for_resource( $a->id() );
		$rb = Records::for_resource( $b->id() );

		$this->assertSame( 1001, $rb['vmid'] );
		$this->assertSame( '10.200.0.11', $rb['ip'] );
		$this->assertSame( 22002, $rb['ssh_port'] );
		$this->assertNotSame( $ra['ip'], $rb['ip'] );
	}

	public function test_an_id_the_node_already_lists_is_skipped(): void {
		$this->node->add_ct(
			array(
				'vmid' => 1000,
				'node' => 'pve1',
				'name' => 'someone-else',
			)
		);

		$r = $this->resource();
		$this->provision_fully( $r );

		$this->assertSame( 1001, Records::for_resource( $r->id() )['vmid'] );
		$this->assertSame( 'someone-else', $this->node->state()['cts'][1000]['name'], 'The other container is untouched.' );
	}

	public function test_a_clone_task_still_running_is_polled_not_repeated(): void {
		$this->node->next_task( array( 'polls' => 50 ) );
		$r = $this->resource();

		$first = $this->provision( $r );

		$this->assertTrue( $first->is_poll() );
		$this->assertStringContainsString( 'waiting for the Proxmox task', $first->error() );
		$this->assertCount( 1, $this->node->calls_to( '#/clone$#', 'POST' ) );

		$result = $this->provision_fully( $r, 40 );

		$this->assertTrue( $result->is_done() );
		$this->assertCount( 1, $this->node->calls_to( '#/clone$#', 'POST' ), 'The stored task is polled, never cloned again.' );
	}

	public function test_a_failed_clone_task_is_a_retry_and_nothing_is_assumed(): void {
		$this->node->next_task( array( 'exitstatus' => 'storage error' ) );
		$r = $this->resource();

		$first = $this->provision( $r );

		$this->assertTrue( $first->is_retry() );
		$this->assertStringContainsString( 'clone', $first->error() );
		$this->assertArrayNotHasKey( 1000, $this->node->state()['cts'] );

		$this->provision_fully( $r );

		$this->assertSame( 1000, Records::for_resource( $r->id() )['vmid'], 'The same id again.' );
	}

	public function test_a_refused_clone_fails_for_good(): void {
		$this->node->fail_next( 'POST', '#/clone$#', array( 'status' => 403, 'message' => 'permission check failed' ) );
		$r = $this->resource();

		$result = $this->provision( $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'permission check failed', $result->error() );
	}

	public function test_an_agent_job_that_fails_is_a_retry_with_a_new_job_and_the_step_resumes(): void {
		$this->agent->fail_next( 'site.create', 'php-fpm did not start' );
		$r = $this->resource();

		$first = $this->provision( $r );

		$this->assertTrue( $first->is_retry() );
		$this->assertStringContainsString( 'php-fpm did not start', $first->error() );

		$steps = Resource::find( $r->id() )->get( 'steps' );
		$this->assertContains( 'db', $steps, 'The earlier steps stay recorded.' );
		$this->assertNotContains( 'site', $steps );

		$this->provision_fully( $r );

		$creates = $this->agent->calls_of( 'site.create' );
		$this->assertCount( 2, $creates );
		$this->assertNotSame( $creates[0]['job_id'], $creates[1]['job_id'], 'A failed job is not asked for again with its id.' );
		$this->assertCount( 1, $this->agent->calls_of( 'ct.prepare' ) );
		$this->assertCount( 1, $this->agent->calls_of( 'db.create' ) );
	}

	public function test_an_agent_job_still_running_is_a_poll_with_the_same_job_id(): void {
		$this->agent->pending( 'ct.prepare', 2 );
		$r = $this->resource();

		$first = $this->provision( $r );
		$this->assertTrue( $first->is_poll() );
		$this->assertStringContainsString( 'waiting for the node agent', $first->error() );

		$this->provision_fully( $r );

		$jobs = array_unique( array_column( $this->agent->calls_of( 'ct.prepare' ), 'job_id' ) );
		$this->assertCount( 1, $jobs, 'Every look is the same job, so it ran once.' );
	}

	public function test_an_invalid_preview_zone_is_refused_before_anything_is_sent(): void {
		add_filter( 'beltoft_services_hosting_setting', fn( $v, $k ) => 'preview_zone' === $k ? 'bad_zone.example.test' : $v, 10, 2 );

		$result = $this->provision( $this->resource() );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'preview zone', $result->error() );
		$this->assertCount( 0, $this->node->calls() );
	}

	public function test_the_kill_switch_stops_the_agent_calls(): void {
		$r = $this->resource();
		$this->provision_fully( $r );

		Resource::find( $r->id() )->set( array( 'steps' => array( 'place', 'ip', 'vmid', 'clone', 'configure', 'start' ) ) );
		$this->settings( array( 'pause_all' => true ) );
		$before = count( $this->agent->calls() );

		$result = $this->provision( $r );

		$this->assertTrue( $result->is_retry() );
		$this->assertStringContainsString( 'paused', $result->error() );
		$this->assertSame( $before, count( $this->agent->calls() ), 'Nothing was sent.' );
	}

	public function test_no_node_in_the_group_fails(): void {
		$r      = $this->resource( array( 'node_group' => 'elsewhere' ) );
		$result = $this->provision( $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'elsewhere', $result->error() );
		$this->assertNull( Records::for_resource( $r->id() ) );
	}

	public function test_a_disabled_node_is_not_used(): void {
		$this->save_node( array( 'enabled' => '' ) );
		$result = $this->provision( $this->resource() );

		$this->assertTrue( $result->is_failed() );
	}

	public function test_a_new_account_goes_to_the_node_with_the_fewest_accounts(): void {
		$this->save_node( array( 'label' => 'Node two' ), 'n2' );
		$a = $this->resource();
		$this->provision( $a ); // Place only needs the first run: it picks n1 (nothing on either node, stored order).
		$this->assertSame( 'n1', Records::for_resource( $a->id() )['node'] );

		$b = $this->resource();
		$this->provision( $b );
		$this->assertSame( 'n2', Records::for_resource( $b->id() )['node'], 'n1 holds one account, n2 none.' );
	}

	public function test_a_bad_plan_or_a_missing_preview_zone_or_key_fails_before_anything_is_sent(): void {
		$r = $this->resource( array( 'cores' => 0 ) );
		$this->assertStringContainsString( 'cores', $this->provision( $r )->error() );

		$this->settings( array( 'hosting_preview_zone' => '' ) );
		$this->assertStringContainsString( 'preview zone', $this->provision( $this->resource() )->error() );

		$this->settings( array( 'hosting_preview_zone' => 'preview.example.test' ) );
		remove_all_filters( 'beltoft_services_key' );
		$result = $this->provision( $this->resource() );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'BELTOFT_SERVICES_KEY', $result->error() );

		$this->assertCount( 0, $this->node->calls() );
		$this->assertCount( 0, $this->agent->calls() );
	}

	public function test_a_license_that_ended_builds_nothing(): void {
		$r = $this->resource();
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'status' => 'suspended' ) );

		$result = $this->provision( $r );

		$this->assertTrue( $result->is_retry() );
		$this->assertStringContainsString( 'no longer active', $result->error() );
		$this->assertCount( 0, $this->node->calls_to( '#/clone$#' ) );
	}

	public function test_a_live_node_without_a_live_client_never_gets_a_fake(): void {
		$this->save_node();
		$all = get_option( \BeltoftServices\Hosting\Nodes::OPTION );
		$all['n1']['client'] = 'live';
		update_option( \BeltoftServices\Hosting\Nodes::OPTION, $all );

		// Without the constant a live record reads as fake: the build goes on with the fake.
		$r = $this->resource();
		$this->provision_fully( $r );
		$this->assertCount( 1, $this->node->calls_to( '#/clone$#', 'POST' ) );
	}
}
