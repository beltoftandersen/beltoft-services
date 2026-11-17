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
			'ct.prepare', 'ct.stats', 'ct.memory', 'ct.release', 'ct.refresh',
			'site.create', 'site.delete', 'site.php', 'site.domains', 'site.cache_purge', 'site.cache_setup', 'site.cache_status', 'site.wp_address', 'site.wp_install', 'site.verify', 'site.wp_login_link', 'site.wp_password_link', 'site.tool_link', 'site.scan_results', 'site.scan_now', 'site.country_block', 'site.staging_create', 'site.staging_push', 'site.staging_refresh', 'site.staging_undo', 'site.staging_delete', 'site.cron', 'site.facts', 'site.cron_set', 'site.cron_status', 'site.workers',
			'db.create', 'db.drop', 'db.password', 'db.dump', 'db.size', 'db.sizes', 'db.limit', 'db.readonly',
			'ssh.keys', 'ssh.password',
			'edge.apply', 'edge.cert_push', 'edge.storage_backup',
			'backup.prepare', 'backup.file_restore', 'backup.db_restore', 'backup.restore_test', 'backup.export',
			'mail.smtp', 'agent.health', 'agent.update',
		);

		$this->assertSame( $expected, array_keys( FakeAgent::OPERATIONS ) );
	}

	/**
	 * A prepared container with one site and its database.
	 */
	private function with_site( FakeAgent $a ): void {
		$a->submit( 'ct.prepare', $this->prepare_args(), 'p1' );
		$a->submit( 'site.create', array( 'vmid' => 1000, 'slug' => 'shop', 'php' => '8.3', 'workers' => 4, 'host' => 'shop.example.test' ), 'p2' );
		$a->submit( 'db.create', array( 'account' => 'c1', 'vmid' => 1000, 'ip' => '10.200.0.10', 'databases' => array( 'c1_shop' ), 'password' => self::PASSWORD ), 'p3' );
	}

	public function test_site_facts_say_whether_a_folder_has_wordpress_and_name_the_database(): void {
		$a = new FakeAgent();
		$this->with_site( $a );

		$plain = $a->submit( 'site.facts', array( 'vmid' => 1000, 'slug' => 'shop' ), 'f1' );
		$this->assertSame( array( 'slug' => 'shop', 'wordpress' => false, 'db_name' => 'c1_shop' ), $plain['result'] );

		$a->submit( 'site.wp_install', array( 'vmid' => 1000, 'slug' => 'shop', 'title' => 'Shop', 'admin_user' => 'owner', 'admin_email' => 'christian.beltoft@gmail.com' ), 'f2' );
		$this->assertTrue( $a->submit( 'site.facts', array( 'vmid' => 1000, 'slug' => 'shop' ), 'f3' )['result']['wordpress'] );

		$a->set_wordpress( 1000, 'shop', false );
		$this->assertFalse( $a->submit( 'site.facts', array( 'vmid' => 1000, 'slug' => 'shop' ), 'f4' )['result']['wordpress'], 'WordPress deleted by hand.' );

		$unknown = $a->submit( 'site.facts', array( 'vmid' => 1000, 'slug' => 'nothere' ), 'f5' );
		$this->assertSame( 'failed', $unknown['status'] );

		$a->set_unsupported( array( 'site.facts' ) );
		$this->assertSame( 'unknown_op', $a->submit( 'site.facts', array( 'vmid' => 1000, 'slug' => 'shop' ), 'f6' )->get_error_code() );
	}

	public function test_cron_set_replaces_the_whole_list_and_status_reports_each_job(): void {
		$a = new FakeAgent();
		$this->with_site( $a );

		$script = array( 'id' => 'a1b2c3d4', 'kind' => 'script', 'enabled' => true, 'schedule' => '*/5 * * * *', 'label' => 'Laravel', 'php' => '8.3', 'path' => 'artisan', 'args' => array( 'schedule:run' ), 'timeout' => 300 );
		$url    = array( 'id' => '0a0b0c0d', 'kind' => 'url', 'enabled' => false, 'schedule' => '0 3 * * *', 'url' => 'https://shop.example.test/cron?k=1' );
		$wp     = array( 'id' => '11111111', 'kind' => 'wordpress', 'enabled' => true, 'schedule' => '*/5 * * * *' );

		$set = $a->submit( 'site.cron_set', array( 'vmid' => 1000, 'slug' => 'shop', 'jobs' => array( $script, $url, $wp ) ), 'c1' )['result'];
		$this->assertSame( array( 'ok' => true, 'slug' => 'shop', 'count' => 3, 'wp_config' => true, 'wp_cron_disabled' => true ), $set );
		$this->assertSame( array( $script, $url, $wp ), $a->state()['sites'][1000]['shop']['cron'] );

		$a->set_cron_run( 1000, 'shop', 'a1b2c3d4', array( 'started' => 100, 'ended' => 105, 'exit' => 0, 'tail' => 'done' ) );
		$status = $a->submit( 'site.cron_status', array( 'vmid' => 1000, 'slug' => 'shop' ), 'c2' )['result'];
		$this->assertSame( array( 'started' => 100, 'ended' => 105, 'exit' => 0, 'timed_out' => false, 'skipped' => false, 'tail' => 'done' ), $status['jobs']['a1b2c3d4'] );
		$this->assertSame( array( 'started' => null, 'ended' => null, 'exit' => null, 'timed_out' => false, 'skipped' => false, 'tail' => '' ), $status['jobs']['0a0b0c0d'], 'A job that never ran.' );
		$this->assertTrue( $status['wp_config'] );
		$this->assertTrue( $status['wp_cron_disabled'] );

		// A disabled WordPress job leaves WordPress's own cron on.
		$off = array_merge( $wp, array( 'enabled' => false ) );
		$this->assertFalse( $a->submit( 'site.cron_set', array( 'vmid' => 1000, 'slug' => 'shop', 'jobs' => array( $off ) ), 'c3' )['result']['wp_cron_disabled'] );

		// A wp-config.php the agent cannot change: the call works and says so.
		$a->set_wp_config( 1000, 'shop', false );
		$set = $a->submit( 'site.cron_set', array( 'vmid' => 1000, 'slug' => 'shop', 'jobs' => array( $wp ) ), 'c3b' )['result'];
		$this->assertFalse( $set['wp_config'] );
		$this->assertFalse( $set['wp_cron_disabled'] );

		$this->assertCount( 1, $a->state()['sites'][1000]['shop']['cron'], 'The list is replaced, not added to.' );
		$this->assertSame( 0, $a->submit( 'site.cron_set', array( 'vmid' => 1000, 'slug' => 'shop', 'jobs' => array() ), 'c4' )['result']['count'] );
		$this->assertSame( array(), $a->state()['sites'][1000]['shop']['cron'] );
	}

	public function test_cron_set_refuses_a_malformed_job_and_a_stranger_address(): void {
		$a = new FakeAgent();
		$this->with_site( $a );

		$good = array( 'id' => 'a1b2c3d4', 'kind' => 'script', 'enabled' => true, 'schedule' => '*/5 * * * *', 'php' => '8.3', 'path' => 'cron.php' );
		$bad  = array(
			'newline schedule' => array_merge( $good, array( 'schedule' => "*/5 * * * *\n* * * * *" ) ),
			'six fields'       => array_merge( $good, array( 'schedule' => '*/5 * * * * echo' ) ),
			'path with space'  => array_merge( $good, array( 'path' => 'a b.php' ) ),
			'dot dot'          => array_merge( $good, array( 'path' => 'a/../b.php' ) ),
			'leading slash'    => array_merge( $good, array( 'path' => '/etc/passwd' ) ),
			'bad id'           => array_merge( $good, array( 'id' => 'xx' ) ),
			'unknown key'      => array_merge( $good, array( 'command' => 'id' ) ),
			'string bool'      => array_merge( $good, array( 'enabled' => 'yes' ) ),
			'unknown kind'     => array_merge( $good, array( 'kind' => 'shell' ) ),
			'plain http url'   => array( 'id' => 'a1b2c3d4', 'kind' => 'url', 'enabled' => true, 'schedule' => '0 3 * * *', 'url' => 'http://x.example.test/' ),
			'url with port'    => array( 'id' => 'a1b2c3d4', 'kind' => 'url', 'enabled' => true, 'schedule' => '0 3 * * *', 'url' => 'https://x.example.test:8443/' ),
			'timeout too long' => array_merge( $good, array( 'timeout' => 9000 ) ),
			'label too long'   => array_merge( $good, array( 'label' => str_repeat( 'a', 41 ) ) ),
		);

		$i = 0;
		foreach ( $bad as $why => $job ) {
			$r = $a->submit( 'site.cron_set', array( 'vmid' => 1000, 'slug' => 'shop', 'jobs' => array( $job ) ), 'bad' . ++$i );
			$this->assertWPError( $r, $why );
			$this->assertSame( 'invalid', $r->get_error_code(), $why );
		}

		$this->assertWPError( $a->submit( 'site.cron_set', array( 'vmid' => 1000, 'slug' => 'shop', 'jobs' => array( $good, $good ) ), 'bad-twice' ), 'The same id twice.' );

		$stranger = array( 'id' => 'a1b2c3d4', 'kind' => 'url', 'enabled' => true, 'schedule' => '0 3 * * *', 'url' => 'https://other.example.test/cron' );
		$failed   = $a->submit( 'site.cron_set', array( 'vmid' => 1000, 'slug' => 'shop', 'jobs' => array( $stranger ) ), 'stranger' );
		$this->assertSame( 'failed', $failed['status'] );
		$this->assertStringContainsString( 'not one of the website\'s own names', $failed['error'] );
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

		// The install needs the site's database first, as on the real agent.
		$refused = $a->submit( 'site.wp_install', array( 'vmid' => 1000, 'slug' => 'shop', 'title' => 'Shop', 'admin_user' => 'owner', 'admin_email' => 'christian.beltoft@gmail.com' ), 'j3b' );
		$this->assertSame( 'failed', $refused['status'] );
		$this->assertStringContainsString( 'no database for this site', $refused['error'] );
		$a->submit( 'db.create', array( 'account' => 'c1', 'vmid' => 1000, 'ip' => '10.200.0.10', 'databases' => array( 'c1_shop' ), 'password' => self::PASSWORD ), 'j3c' );

		$this->assertSame( array( 'installed' => true, 'slug' => 'shop' ), $a->submit( 'site.wp_install', array( 'vmid' => 1000, 'slug' => 'shop', 'title' => 'Shop', 'admin_user' => 'owner', 'admin_email' => 'christian.beltoft@gmail.com' ), 'j4' )['result'] );
		$this->assertSame( array( 'url' => 'https://shop.example.test/?fake-login=1', 'user' => 'owner', 'email' => 'christian.beltoft@gmail.com' ), $a->submit( 'site.wp_login_link', array( 'vmid' => 1000, 'slug' => 'shop', 'admin_user' => 'owner' ), 'j5' )['result'] );
		$this->assertSame( array( 'url' => 'https://shop.example.test/?fake-password=1', 'user' => 'owner', 'email' => 'christian.beltoft@gmail.com' ), $a->submit( 'site.wp_password_link', array( 'vmid' => 1000, 'slug' => 'shop' ), 'j5b' )['result'] );
		$redirects = array( array( 'from' => 'www.shop.test', 'to' => 'shop.test' ) );
		$this->assertSame( array( 'ok' => true, 'slug' => 'shop' ), $a->submit( 'site.domains', array( 'vmid' => 1000, 'slug' => 'shop', 'domains' => array( 'shop.test', 'www.shop.test' ), 'redirects' => $redirects ), 'j5c' )['result'] );
		$this->assertSame( $redirects, $a->state()['sites'][1000]['shop']['redirects'] );
		foreach ( array( array( array( 'from' => 'a.test', 'to' => 'a.test' ) ), array( array( 'from' => 'www.shop.test', 'to' => 'elsewhere.test' ) ), array( array( 'from' => 'a' ) ), array(), 'shop.test' ) as $i => $bad ) {
			$this->assertSame( 'invalid', $a->submit( 'site.domains', array( 'vmid' => 1000, 'slug' => 'shop', 'domains' => array( 'shop.test', 'www.shop.test' ), 'redirects' => $bad ), 'j5d' . $i )->get_error_code() );
		}

		$db = $a->submit( 'db.create', array( 'account' => 'c1', 'vmid' => 1000, 'ip' => '10.200.0.10', 'databases' => array( 'c1_shop' ), 'password' => self::PASSWORD ), 'j6' );
		$this->assertSame( array( 'user' => 'c1', 'databases' => array( 'c1_shop' ) ), $db['result'] );
		$this->assertSame( array( 'sizes' => array( 'c1_shop' => 0 ) ), $a->submit( 'db.size', array( 'account' => 'c1' ), 'j7' )['result'] );
		$a->set_db_size( 'c1_shop', 2048 );
		$sizes = $a->submit( 'db.sizes', array(), 'j7b' )['result'];
		$this->assertSame( array( 'bytes' => 2048, 'databases' => array( 'c1_shop' => 2048 ), 'limit_mb' => null, 'readonly' => false, 'readonly_by' => '' ), json_decode( wp_json_encode( $sizes['accounts'] ), true )['c1'] );
		$this->assertSame( array( 'account' => 'c1', 'limit_mb' => 15360 ), $a->submit( 'db.limit', array( 'account' => 'c1', 'limit_mb' => 15360 ), 'j7c' )['result'] );
		$this->assertSame( 15360, json_decode( wp_json_encode( $a->submit( 'db.sizes', array(), 'j7d' )['result']['accounts'] ), true )['c1']['limit_mb'] );
		$this->assertSame( array( 'account' => 'c1', 'limit_mb' => null ), $a->submit( 'db.limit', array( 'account' => 'c1', 'limit_mb' => 0 ), 'j7e' )['result'], '0 removes the limit.' );
		$this->assertSame( array( 'dropped' => array( 'c1_shop' ) ), $a->submit( 'db.drop', array( 'account' => 'c1', 'databases' => array( 'c1_shop', 'c1_other' ) ), 'j8' )['result'] );
		$this->assertSame( array( 'dropped' => array() ), $a->submit( 'db.drop', array( 'account' => 'c1', 'databases' => array( 'c1_shop' ) ), 'j9' )['result'], 'Dropping again is fine.' );

		$route = array( 'host' => 'shop.example.test', 'ip' => '10.200.0.10', 'suspended' => false );
		$this->assertSame( array( 'routes' => 1 ), $a->submit( 'edge.apply', array( 'account' => 'c1', 'routes' => array( $route ) ), 'j10' )['result'] );
		$this->assertSame( array( $route ), $a->state()['edge']['c1'] );
		$this->assertSame( array( 'routes' => 0 ), $a->submit( 'edge.apply', array( 'account' => 'c1', 'routes' => array() ), 'j11' )['result'] );
		$this->assertArrayNotHasKey( 'c1', $a->state()['edge'] );

		$this->assertEquals( array( 'ok' => true, 'loaders' => (object) array(), 'backup_slots' => (object) array() ), $a->submit( 'agent.health', array(), 'j12' )['result'] );
		$this->assertSame( array( 'dumped' => true, 'label' => 'nightly', 'databases' => array(), 'failed' => array() ), $a->submit( 'backup.prepare', array( 'vmid' => 1000 ), 'j13' )['result'] );
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

	public function test_backup_prepare_reports_databases_and_a_labelled_run_fails_on_any_failed_dump(): void {
		$a = new FakeAgent();
		$a->submit( 'db.create', array( 'account' => 'c1', 'vmid' => 1000, 'ip' => '10.200.0.10', 'databases' => array( 'c1_a', 'c1_b' ), 'password' => self::PASSWORD ), 'd1' );

		$ok = $a->submit( 'backup.prepare', array( 'vmid' => 1000 ), 'p1' )['result'];
		$this->assertSame( array( 'c1_a', 'c1_b' ), $ok['databases'] );

		$a->set_dump_fails( array( 'c1_b' ) );
		$nightly = $a->submit( 'backup.prepare', array( 'vmid' => 1000 ), 'p2' );
		$this->assertSame( 'done', $nightly['status'], 'A nightly run reports the failure and goes on.' );
		$this->assertSame( array( 'c1_b' ), $nightly['result']['failed'] );
		$this->assertSame( array( 'c1_a' ), $nightly['result']['databases'] );

		$safety = $a->submit( 'backup.prepare', array( 'vmid' => 1000, 'label' => 'safety1700000000' ), 'p3' );
		$this->assertSame( 'failed', $safety['status'], 'A labelled run fails the job.' );
		$this->assertStringContainsString( 'c1_b', $safety['error'] );
		$this->assertSame( 'invalid', $a->submit( 'backup.prepare', array( 'vmid' => 1000, 'label' => 'Bad Label' ), 'p4' )->get_error_code() );
	}

	public function test_file_restore_takes_a_backup_name_and_refuses_a_safety_point_and_health_reports_slots(): void {
		$a = new FakeAgent();
		$a->submit( 'ct.prepare', $this->prepare_args(), 'f1' );
		$a->submit( 'site.create', array( 'vmid' => 1000, 'slug' => 'shop', 'php' => '8.3', 'workers' => 4, 'host' => 'shop.example.test' ), 'f2' );

		$this->assertSame( 'done', $a->submit( 'backup.file_restore', array( 'vmid' => 1000, 'slug' => 'shop', 'snapshot' => 'b1700000000' ), 'f3' )['status'] );
		$refused = $a->submit( 'backup.file_restore', array( 'vmid' => 1000, 'slug' => 'shop', 'snapshot' => 'safety1700000000' ), 'f4' );
		$this->assertSame( 'failed', $refused['status'] );
		$this->assertStringContainsString( 'safety point', $refused['error'] );

		$a->set_backup_slots( array( 1000 => array( 'at' => '02:15', 'offset_minutes' => 105, 'disk_mb' => 800 ) ) );
		$health = $a->submit( 'agent.health', array(), 'f5' )['result'];
		$this->assertSame( '02:15', ( (array) $health['backup_slots'] )['1000']['at'] );
	}
}
