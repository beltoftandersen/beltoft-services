<?php
/**
 * The fake node agent: the fixed operation list, schemas, jobs, documented replies.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Hosting\FakeAgent;
use BeltoftServices\Tests\TestCase;

/**
 * FakeAgent on its own.
 */
class FakeAgentTest extends TestCase {

	private const PASSWORD = 'a-long-password-0001';

	/**
	 * Arguments of ct.prepare.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_args(): array {
		return array(
			'vmid'      => 1000,
			'account'   => 'c1',
			'hostname'  => 'c1',
			'ip'        => '10.200.0.10',
			'ssh_port'  => 22001,
			'valkey_mb' => 64,
			'workers'   => 10,
		);
	}

	public function test_the_operation_list_is_the_plans_list_plus_site_verify(): void {
		$expected = array(
			'ct.prepare', 'ct.stats',
			'site.create', 'site.delete', 'site.php', 'site.domains', 'site.cache_purge', 'site.wp_install', 'site.verify', 'site.wp_login_link', 'site.staging_create', 'site.staging_push', 'site.cron', 'site.workers',
			'db.create', 'db.drop', 'db.password', 'db.dump', 'db.size', 'db.readonly',
			'ssh.keys', 'ssh.password',
			'edge.apply', 'edge.cert_push', 'edge.storage_backup',
			'backup.prepare', 'backup.file_restore', 'backup.db_restore', 'backup.export',
			'mail.smtp', 'agent.health', 'agent.update',
		);

		$this->assertSame( $expected, array_keys( FakeAgent::OPERATIONS ) );
	}

	public function test_an_operation_outside_the_list_is_refused(): void {
		$a = new FakeAgent();

		foreach ( array( 'shell', 'ct.exec', 'site.create; rm', '' ) as $op ) {
			$r = $a->submit( $op, array(), 'job-1' . md5( $op ) );
			$this->assertWPError( $r, $op );
			$this->assertSame( 'unknown_op', $r->get_error_code() );
			$this->assertSame( 400, $r->get_error_data()['status'] );
		}
	}

	public function test_arguments_are_validated_and_identifiers_cannot_be_paths(): void {
		$a   = new FakeAgent();
		$bad = array(
			'path slug'      => array( 'site.delete', array( 'vmid' => 1000, 'slug' => '../etc' ) ),
			'upper slug'     => array( 'site.delete', array( 'vmid' => 1000, 'slug' => 'Shop' ) ),
			'string vmid'    => array( 'site.delete', array( 'vmid' => '1000', 'slug' => 'shop' ) ),
			'small vmid'     => array( 'site.delete', array( 'vmid' => 5, 'slug' => 'shop' ) ),
			'missing'        => array( 'site.delete', array( 'vmid' => 1000 ) ),
			'unknown'        => array( 'site.delete', array( 'vmid' => 1000, 'slug' => 'shop', 'path' => '/etc' ) ),
			'bad host'       => array( 'site.create', array( 'vmid' => 1000, 'slug' => 's', 'php' => '8.3', 'workers' => 2, 'host' => 'bad_host' ) ),
			'bad ip'         => array( 'ct.prepare', array_merge( $this->prepare_args(), array( 'ip' => '10.200.0' ) ) ),
			'short password' => array( 'db.password', array( 'account' => 'c1', 'password' => 'short' ) ),
			'db name'        => array( 'db.create', array( 'account' => 'c1', 'vmid' => 1000, 'ip' => '10.200.0.10', 'databases' => array( 'a b' ), 'password' => self::PASSWORD ) ),
			'empty dbs'      => array( 'db.drop', array( 'account' => 'c1', 'databases' => array() ) ),
			'route shape'    => array( 'edge.apply', array( 'account' => 'c1', 'routes' => array( array( 'host' => 'a.example.test' ) ) ) ),
			'bool'           => array( 'site.cron', array( 'vmid' => 1000, 'slug' => 'shop', 'enabled' => 'yes' ) ),
		);

		$i = 0;
		foreach ( $bad as $name => $case ) {
			$r = $a->submit( $case[0], $case[1], 'bad-' . ( ++$i ) );
			$this->assertWPError( $r, $name );
			$this->assertSame( 'invalid', $r->get_error_code(), $name );
		}

		$this->assertSame( array(), $a->state()['jobs'], 'Nothing ran.' );
	}

	public function test_a_bad_job_id_is_refused(): void {
		$this->assertWPError( ( new FakeAgent() )->submit( 'ct.stats', array( 'vmid' => 1000 ), 'has space' ) );
	}

	public function test_the_same_job_id_twice_returns_the_first_job_and_runs_nothing(): void {
		$a = new FakeAgent();

		$first = $a->submit( 'ct.prepare', $this->prepare_args(), 'job-a' );
		$this->assertSame( 'done', $first['status'] );

		$changed = array_merge( $this->prepare_args(), array( 'workers' => 99 ) );
		$again   = $a->submit( 'ct.prepare', $changed, 'job-a' );

		$this->assertSame( $first, $again );
		$this->assertSame( 10, $a->state()['cts'][1000]['workers'], 'The second submit changed nothing.' );
		$this->assertSame( $first, $a->job( 'job-a' ) );
		$this->assertSame( 404, $a->job( 'job-none' )->get_error_data()['status'] );
	}

	public function test_documented_replies(): void {
		$a = new FakeAgent();

		$this->assertSame( array( 'prepared' => true, 'hostname' => 'c1' ), $a->submit( 'ct.prepare', $this->prepare_args(), 'j1' )['result'] );
		$this->assertSame( array( 'memory_mb' => 0, 'disk_mb' => 0 ), $a->submit( 'ct.stats', array( 'vmid' => 1000 ), 'j2' )['result'] );

		$site = $a->submit( 'site.create', array( 'vmid' => 1000, 'slug' => 'shop', 'php' => '8.3', 'workers' => 4, 'host' => 'shop.example.test' ), 'j3' );
		$this->assertSame( array( 'slug' => 'shop', 'root' => '/home/web/sites/shop' ), $site['result'] );

		$this->assertSame( array( 'installed' => true, 'slug' => 'shop' ), $a->submit( 'site.wp_install', array( 'vmid' => 1000, 'slug' => 'shop', 'title' => 'Shop', 'admin_user' => 'owner', 'admin_email' => 'christian.beltoft@gmail.com' ), 'j4' )['result'] );
		$this->assertSame( array( 'url' => 'https://shop.example.test/?fake-login=1' ), $a->submit( 'site.wp_login_link', array( 'vmid' => 1000, 'slug' => 'shop' ), 'j5' )['result'] );

		$db = $a->submit( 'db.create', array( 'account' => 'c1', 'vmid' => 1000, 'ip' => '10.200.0.10', 'databases' => array( 'c1_shop' ), 'password' => self::PASSWORD ), 'j6' );
		$this->assertSame( array( 'user' => 'c1', 'databases' => array( 'c1_shop' ) ), $db['result'] );
		$this->assertSame( array( 'sizes' => array( 'c1_shop' => 0 ) ), $a->submit( 'db.size', array( 'account' => 'c1' ), 'j7' )['result'] );
		$this->assertSame( array( 'dropped' => array( 'c1_shop' ) ), $a->submit( 'db.drop', array( 'account' => 'c1', 'databases' => array( 'c1_shop', 'c1_other' ) ), 'j8' )['result'] );
		$this->assertSame( array( 'dropped' => array() ), $a->submit( 'db.drop', array( 'account' => 'c1', 'databases' => array( 'c1_shop' ) ), 'j9' )['result'], 'Dropping again is fine.' );

		$route = array( 'host' => 'shop.example.test', 'ip' => '10.200.0.10', 'suspended' => false );
		$this->assertSame( array( 'routes' => 1 ), $a->submit( 'edge.apply', array( 'account' => 'c1', 'routes' => array( $route ) ), 'j10' )['result'] );
		$this->assertSame( array( $route ), $a->state()['edge']['c1'] );
		$this->assertSame( array( 'routes' => 0 ), $a->submit( 'edge.apply', array( 'account' => 'c1', 'routes' => array() ), 'j11' )['result'] );
		$this->assertArrayNotHasKey( 'c1', $a->state()['edge'] );

		$this->assertSame( array( 'ok' => true ), $a->submit( 'agent.health', array(), 'j12' )['result'] );
		$this->assertSame( array( 'ok' => true ), $a->submit( 'backup.prepare', array( 'vmid' => 1000 ), 'j13' )['result'] );
	}

	public function test_site_jobs_need_a_prepared_container_and_a_created_site(): void {
		$a = new FakeAgent();

		$early = $a->submit( 'site.create', array( 'vmid' => 1000, 'slug' => 'shop', 'php' => '8.3', 'workers' => 4, 'host' => 'shop.example.test' ), 'k1' );
		$this->assertSame( 'failed', $early['status'] );
		$this->assertSame( 'container not prepared', $early['error'] );

		$a->submit( 'ct.prepare', $this->prepare_args(), 'k2' );

		$unknown = $a->submit( 'site.php', array( 'vmid' => 1000, 'slug' => 'nope', 'php' => '8.4' ), 'k3' );
		$this->assertSame( 'failed', $unknown['status'] );
		$this->assertSame( 'unknown site', $unknown['error'] );

		$a->submit( 'site.create', array( 'vmid' => 1000, 'slug' => 'shop', 'php' => '8.3', 'workers' => 4, 'host' => 'shop.example.test' ), 'k4' );
		$this->assertSame( 'done', $a->submit( 'site.php', array( 'vmid' => 1000, 'slug' => 'shop', 'php' => '8.4' ), 'k5' )['status'] );
		$this->assertSame( '8.4', $a->state()['sites'][1000]['shop']['php'] );

		$this->assertSame( 'done', $a->submit( 'site.delete', array( 'vmid' => 1000, 'slug' => 'shop' ), 'k6' )['status'] );
		$this->assertSame( 'done', $a->submit( 'site.delete', array( 'vmid' => 1000, 'slug' => 'shop' ), 'k7' )['status'], 'Deleting a missing site is fine.' );
	}

	public function test_scripted_failures_and_waits(): void {
		$a = new FakeAgent();

		$a->fail_next( 'ct.stats', 'disk error' );
		$failed = $a->submit( 'ct.stats', array( 'vmid' => 1000 ), 's1' );
		$this->assertSame( 'failed', $failed['status'] );
		$this->assertSame( 'disk error', $failed['error'] );
		$this->assertSame( 'done', $a->submit( 'ct.stats', array( 'vmid' => 1000 ), 's2' )['status'], 'The failure was consumed.' );

		$a->pending( 'ct.stats', 2 );
		$this->assertSame( 'queued', $a->submit( 'ct.stats', array( 'vmid' => 1000 ), 's3' )['status'] );
		$this->assertSame( 'running', $a->job( 's3' )['status'] );
		$this->assertSame( 'done', $a->job( 's3' )['status'] );
		$this->assertSame( 'done', $a->job( 's3' )['status'] );
	}

	public function test_secrets_are_masked_in_the_call_log_and_never_in_the_state(): void {
		$a = new FakeAgent();
		$a->submit( 'db.create', array( 'account' => 'c1', 'vmid' => 1000, 'ip' => '10.200.0.10', 'databases' => array( 'c1_shop' ), 'password' => self::PASSWORD ), 'm1' );
		$a->submit( 'ssh.password', array( 'vmid' => 1000, 'password' => self::PASSWORD . '2' ), 'm2' );
		$a->submit( 'ssh.keys', array( 'vmid' => 1000, 'keys' => array( 'ssh-ed25519 AAAA test' ) ), 'm3' );
		$a->submit( 'mail.smtp', array( 'vmid' => 1000, 'host' => 'mail.example.test', 'user' => 'u', 'password' => self::PASSWORD . '3' ), 'm4' );

		$log = (string) wp_json_encode( $a->calls() );
		$this->assertStringNotContainsString( self::PASSWORD, $log );
		$this->assertStringNotContainsString( 'ssh-ed25519', $log, 'Keys are masked too (the agent rule: *key*).' );
		$this->assertStringContainsString( '***', $log );
		$this->assertStringNotContainsString( self::PASSWORD, (string) wp_json_encode( $a->state() ) );
		$this->assertCount( 4, $a->calls() );
		$this->assertCount( 1, $a->calls_of( 'ssh.keys' ) );
	}

	public function test_persist_shares_the_state_between_instances(): void {
		$a = new FakeAgent( true );
		$a->submit( 'ct.prepare', $this->prepare_args(), 'p1' );

		$b = new FakeAgent( true );
		$this->assertTrue( $b->state()['cts'][1000]['prepared'] );
		$this->assertSame( 'done', $b->submit( 'ct.prepare', $this->prepare_args(), 'p1' )['status'], 'The job is known to the other instance.' );
		$this->assertCount( 0, ( new FakeAgent() )->state()['cts'] );

		delete_option( FakeAgent::OPTION );
	}
}
