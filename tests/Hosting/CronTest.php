<?php
/**
 * The scheduled jobs of a website: what a job may be (the validators, with hostile input), the plan's limit, the whole-list save through the shared
 * controller, a failed or an unknown operation on the node, the server cron of WordPress, the follow-up after a restore and the page. Everything
 * runs against the FakeAgent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingView;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteCron;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Resource;

/**
 * SiteCron, CronActions, the routes and the page.
 */
class CronTest extends HostingCase {

	private const BASE = '/' . Routes::NAMESPACE . '/resources/';

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * A ready account.
	 *
	 * @param array<string, mixed> $site Ordered first website.
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function account( array $site = array(), array $plan = array() ): Resource {
		$r = $this->resource( array_merge( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ), $plan ), array( 'site' => array_merge( array( 'slug' => 'first', 'install' => true ), $site ) ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function vmid( Resource $r ): int {
		return (int) Records::for_resource( $r->id() )['vmid'];
	}

	private function slug( Resource $r ): string {
		return (string) Sites::for_resource( $r->id() )[0]['slug'];
	}

	private function call( string $method, string $path, int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, self::BASE . $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A script job as a customer sends it.
	 *
	 * @param array<string, mixed> $over Changes.
	 * @return array<string, mixed>
	 */
	private function script( array $over = array() ): array {
		return array_merge( array( 'label' => 'Tasks', 'enabled' => true, 'schedule' => '*/15 * * * *', 'kind' => 'script', 'php' => '8.3', 'path' => 'cron/run.php', 'args' => array( 'run' ), 'timeout' => 120 ), $over );
	}

	/**
	 * Context of the validators.
	 *
	 * @param array<string, mixed> $over Changes.
	 * @return array<string, mixed>
	 */
	private function ctx( array $over = array() ): array {
		return array_merge( array( 'limit' => 5, 'php_versions' => array( '8.3', '8.4' ), 'names' => array( 'first.preview.example.test', 'example.org' ), 'wordpress' => true, 'keep' => array() ), $over );
	}

	// -- The schedule -----------------------------------------------------------------------------------------

	/**
	 * @dataProvider good_schedules
	 */
	public function test_these_schedules_are_allowed( string $schedule ): void {
		$this->assertSame( '', SiteCron::schedule_problem( $schedule ), $schedule );
		$this->assertIsArray( SiteCron::normalise( array( $this->script( array( 'schedule' => $schedule ) ) ), $this->ctx() ), $schedule );
	}

	public static function good_schedules(): array {
		$list = array( '*/5 * * * *', '*/15 * * * *', '*/30 * * * *', '0 * * * *', '0 3 * * *', '45 23 * * *', '30 4 * * 0', '30 4 * * 7', '15 6 1 * *', '15 6 28 12 *', '0,30 * * * *', '5-55/10 * * * *', '10 0-23/6 * * *', '0 9 * * 1-5', '0,10,20,30,40,50 * * * *', '57 23 * * *', '5 0 * * *' );

		return array_combine( $list, array_map( static fn( $s ) => array( $s ), $list ) );
	}

	/**
	 * @dataProvider bad_schedules
	 */
	public function test_these_schedules_are_refused( string $schedule, string $why ): void {
		$this->assertSame( $why, SiteCron::schedule_problem( $schedule ), $schedule );
		$out = SiteCron::normalise( array( $this->script( array( 'schedule' => $schedule ) ) ), $this->ctx() );
		$this->assertWPError( $out, $schedule );
		$this->assertSame( 'invalid', $out->get_error_code() );
	}

	public static function bad_schedules(): array {
		$often = array( '* * * * *', '*/4 * * * *', '*/1 * * * *', '0,3 * * * *', '0,4 * * * *', '1-10 * * * *', '0,2 0 * * *', '0,58 0,23 * * *', '58,59 23 * * *', '0-59/2 * * * *', '5,6 7 * * *' );
		$form  = array(
			'newline injected'  => "*/5 * * * *\n* * * * * root id",
			'a sixth field'     => '*/5 * * * * echo',
			'four fields'       => '*/5 * * *',
			'empty'             => '',
			'reboot'            => '@reboot',
			'daily alias'       => '@daily',
			'a name'            => '0 3 * * mon',
			'a month name'      => '0 3 1 jan *',
			'step of a number'  => '5/10 * * * *',
			'step zero'         => '*/0 * * * *',
			'minute 60'         => '60 * * * *',
			'hour 24'           => '0 24 * * *',
			'day 0'             => '0 3 0 * *',
			'day 32'            => '0 3 32 * *',
			'month 13'          => '0 3 1 13 *',
			'weekday 8'         => '0 3 * * 8',
			'a letter'          => 'a * * * *',
			'a semicolon'       => '*/5 * * * ; id',
			'a backtick'        => '0 3 * * `id`',
			'a dollar'          => '$(id) 3 * * *',
			'a quote'           => "0 3 * * '1'",
			'unicode digits'    => "\u{FF10} 3 * * *",
			'arabic digits'     => "\u{0660} 3 * * *",
			'reversed range'    => '10-5 * * * *',
			'open range'        => '5- * * * *',
			'empty item'        => ', 3 * * *',
			'two spaces'        => '*/5  * * * *',
			'a tab'             => "*/5\t* * * *",
			'a question mark'   => '0 3 ? * *',
			'a hash'            => '0 3 * * * # x',
			'range step on one' => '5/2 * * * *',
			'huge'              => str_repeat( '1', 60 ) . ' * * * *',
		);
		$out = array();

		foreach ( $often as $schedule ) {
			$out[ 'often ' . $schedule ] = array( $schedule, 'often' );
		}

		foreach ( $form as $name => $schedule ) {
			$out[ 'form ' . $name ] = array( $schedule, 'form' );
		}

		return $out;
	}

	public function test_the_gap_is_looked_at_across_midnight(): void {
		$this->assertSame( 'often', SiteCron::schedule_problem( '58,1 23,0 * * *' ), '23:58, 00:01 is 3 minutes.' );
		$this->assertSame( '', SiteCron::schedule_problem( '55,0 23,0 * * *' ), '23:55, 00:00 is exactly 5 minutes.' );
		$this->assertSame( 'often', SiteCron::schedule_problem( '57,0 23,0 * * *' ), '23:57, 00:00 is 3 minutes.' );
	}

	public function test_a_schedule_is_described_for_the_form_and_back(): void {
		$this->assertSame( 'every5', SiteCron::describe( '*/5 * * * *' )['preset'] );
		$this->assertSame( 'hourly', SiteCron::describe( '0 * * * *' )['preset'] );
		$this->assertSame( array( 'preset' => 'daily', 'time' => '03:05', 'weekday' => 1, 'day' => 1, 'custom' => '5 3 * * *' ), SiteCron::describe( '5 3 * * *' ) );
		$this->assertSame( array( 'weekly', '04:30', 0 ), array_values( array_intersect_key( SiteCron::describe( '30 4 * * 0' ), array_flip( array( 'preset', 'time', 'weekday' ) ) ) ) );
		$this->assertSame( array( 'monthly', 15 ), array( SiteCron::describe( '0 6 15 * *' )['preset'], SiteCron::describe( '0 6 15 * *' )['day'] ) );
		$this->assertSame( 'custom', SiteCron::describe( '0,30 * * * *' )['preset'] );
		$this->assertSame( 'custom', SiteCron::describe( '0 6 31 * *' )['preset'], 'Day 31 is not a monthly preset.' );
	}

	// -- A job --------------------------------------------------------------------------------------------------

	public function test_a_script_job_is_cleaned_and_gets_an_id(): void {
		$out = SiteCron::normalise( array( $this->script( array( 'label' => "<b>Nightly</b>\x07 run", 'args' => 'schedule:run  --quiet' ) ) ), $this->ctx() );

		$this->assertIsArray( $out );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/D', $out[0]['id'] );
		$this->assertSame( 'Nightly run', $out[0]['label'], 'No markup and no control characters in a label.' );
		$this->assertSame( array( 'schedule:run', '--quiet' ), $out[0]['args'], 'Arguments given as one text are split at spaces.' );
		$this->assertSame( 'script', $out[0]['kind'] );
		$this->assertSame( 120, $out[0]['timeout'] );

		$long = SiteCron::normalise( array( $this->script( array( 'label' => str_repeat( 'é', 60 ) ) ) ), $this->ctx() );
		$this->assertSame( 40, mb_strlen( $long[0]['label'] ) );
	}

	/**
	 * @dataProvider bad_paths
	 */
	public function test_these_script_paths_are_refused( $path ): void {
		$out = SiteCron::normalise( array( $this->script( array( 'path' => $path ) ) ), $this->ctx() );
		$this->assertWPError( $out, wp_json_encode( $path ) );
		$this->assertFalse( SiteCron::valid_path( is_string( $path ) ? $path : '' ) );
	}

	public static function bad_paths(): array {
		$list = array( '', '/etc/passwd', '../x.php', 'a/../b.php', '..', 'a/..', 'a//b.php', './run.php', 'a/./b.php', 'a b.php', "a\nb.php", "a.php\n", 'a;b.php', 'a`id`.php', 'a$(id).php', 'a|b', 'a&b', "a'b", 'a"b', '-r', '-', 'ä.php', 'a\\b.php', 'a*.php', str_repeat( 'a', 201 ), "run.php\0", 'a/', '~/run.php', 'run.php ' );

		return array_combine( array_map( static fn( $p ) => 'path ' . json_encode( $p ), $list ), array_map( static fn( $p ) => array( $p ), $list ) );
	}

	public function test_a_plain_path_is_allowed(): void {
		foreach ( array( 'artisan', 'cron/run.php', 'a-b/c_d/e.f.php', 'public/wp-cron.php', str_repeat( 'a', 200 ) ) as $path ) {
			$this->assertTrue( SiteCron::valid_path( $path ), $path );
		}
	}

	/**
	 * @dataProvider bad_args
	 */
	public function test_these_script_arguments_are_refused( $args ): void {
		$this->assertWPError( SiteCron::normalise( array( $this->script( array( 'args' => $args ) ) ), $this->ctx() ), wp_json_encode( $args ) );
	}

	public static function bad_args(): array {
		return array(
			'a space inside'   => array( array( 'a b' ) ),
			'a command'        => array( array( '$(id)' ) ),
			'a backtick'       => array( array( '`id`' ) ),
			'a semicolon'      => array( array( 'a;b' ) ),
			'a pipe'           => array( array( 'a|b' ) ),
			'a newline'        => array( array( "a\nb" ) ),
			'an empty one'     => array( array( '' ) ),
			'a number'         => array( array( 5 ) ),
			'six of them'      => array( array( 'a', 'b', 'c', 'd', 'e', 'f' ) ),
			'a long one'       => array( array( str_repeat( 'a', 101 ) ) ),
			'not a list'       => array( array( 'x' => 'a' ) ),
			'a nested list'    => array( array( array( 'a' ) ) ),
			'text with a quote' => array( "a 'b'" ),
			'text with a semi' => array( 'run; id' ),
		);
	}

	public function test_a_php_version_that_is_not_offered_is_refused(): void {
		foreach ( array( '5.6', '8.5', '8', '8.3 ', '', "8.3\n", '8.3; id', null, 83 ) as $php ) {
			$this->assertWPError( SiteCron::normalise( array( $this->script( array( 'php' => $php ) ) ), $this->ctx() ), wp_json_encode( $php ) );
		}
	}

	/**
	 * @dataProvider bad_urls
	 */
	public function test_these_addresses_are_refused( $url ): void {
		$this->assertWPError( SiteCron::normalise( array( array( 'label' => '', 'enabled' => true, 'schedule' => '0 3 * * *', 'kind' => 'url', 'url' => $url ) ), $this->ctx() ), wp_json_encode( $url ) );
	}

	public static function bad_urls(): array {
		return array(
			'plain http'         => array( 'http://example.org/cron' ),
			'another site'       => array( 'https://other.example.test/cron' ),
			'a suffix attack'    => array( 'https://example.org.evil.test/cron' ),
			'a prefix attack'    => array( 'https://evilexample.org/cron' ),
			'a port'             => array( 'https://example.org:8443/cron' ),
			'a user'             => array( 'https://admin@example.org/cron' ),
			'a user and a name'  => array( 'https://example.org@evil.test/cron' ),
			'a fragment'         => array( 'https://example.org/cron#x' ),
			'a space'            => array( 'https://example.org/cron job' ),
			'a newline'          => array( "https://example.org/cron\nHost: evil" ),
			'a trailing newline' => array( "https://example.org/cron\n" ),
			'a backtick'         => array( 'https://example.org/`id`' ),
			'a dollar'           => array( 'https://example.org/$(id)' ),
			'a quote'            => array( "https://example.org/a'b" ),
			'a semicolon'        => array( 'https://example.org/a;b' ),
			'a pipe'             => array( 'https://example.org/a|b' ),
			'a backslash'        => array( 'https://example.org/a\\b' ),
			'a localhost'        => array( 'https://localhost/cron' ),
			'an address'         => array( 'https://127.0.0.1/cron' ),
			'no host'            => array( 'https:///cron' ),
			'empty'              => array( '' ),
			'not text'           => array( array( 'https://example.org/' ) ),
			'too long'           => array( 'https://example.org/' . str_repeat( 'a', 600 ) ),
			'an @ in the host'   => array( 'https://exam@ple.org/' ),
			'a unicode name'     => array( "https://exampl\u{0435}.org/cron" ),
		);
	}

	public function test_an_address_of_the_website_is_allowed(): void {
		foreach ( array( 'https://example.org', 'https://example.org/', 'https://example.org/cron.php?key=a1-b2&x=1', 'https://first.preview.example.test/wp-cron.php?doing_wp_cron' ) as $url ) {
			$out = SiteCron::normalise( array( array( 'label' => '', 'enabled' => true, 'schedule' => '0 3 * * *', 'kind' => 'url', 'url' => $url ) ), $this->ctx() );
			$this->assertIsArray( $out, $url );
			$this->assertSame( $url, $out[0]['url'] );
		}
	}

	public function test_these_timeouts_are_refused_or_taken(): void {
		foreach ( array( 0, -1, 901, 5000, 'abc', '1e3', 1.5, array( 1 ), '', null ) as $timeout ) {
			$job = $this->script();
			$job['timeout'] = $timeout;
			$out = SiteCron::normalise( array( $job ), $this->ctx() );

			if ( null === $timeout ) {
				$this->assertIsArray( $out, 'No timeout is the default.' );
				continue;
			}

			$this->assertWPError( $out, wp_json_encode( $timeout ) );
		}

		$this->assertSame( 900, SiteCron::normalise( array( $this->script( array( 'timeout' => 900 ) ) ), $this->ctx() )[0]['timeout'] );
		$this->assertSame( 60, SiteCron::normalise( array( $this->script( array( 'timeout' => '60' ) ) ), $this->ctx() )[0]['timeout'] );
		$job = $this->script();
		unset( $job['timeout'] );
		$this->assertSame( 300, SiteCron::normalise( array( $job ), $this->ctx() )[0]['timeout'] );
	}

	public function test_an_unknown_kind_and_a_job_that_is_not_an_object_are_refused(): void {
		foreach ( array( 'shell', 'command', '', 'SCRIPT', null ) as $kind ) {
			$this->assertWPError( SiteCron::normalise( array( $this->script( array( 'kind' => $kind ) ) ), $this->ctx() ), wp_json_encode( $kind ) );
		}

		$this->assertWPError( SiteCron::normalise( array( 'a job' ), $this->ctx() ) );
		$this->assertWPError( SiteCron::normalise( array( 'x' => $this->script() ), $this->ctx() ), 'A list, not a map.' );
	}

	// -- Ids, the limit and the WordPress job -------------------------------------------------------------------

	public function test_ids_are_kept_only_for_jobs_that_exist_and_made_new_otherwise(): void {
		$ctx = $this->ctx( array( 'keep' => array( 'a1b2c3d4' ) ) );
		$out = SiteCron::normalise( array( $this->script( array( 'id' => 'a1b2c3d4' ) ), $this->script( array( 'id' => 'a1b2c3d4' ) ), $this->script( array( 'id' => 'ffffffff' ) ), $this->script( array( 'id' => '../../x' ) ) ), $ctx );

		$this->assertSame( 'a1b2c3d4', $out[0]['id'] );
		$ids = array_column( $out, 'id' );
		$this->assertCount( 4, array_unique( $ids ), 'Every job has its own id.' );
		$this->assertNotContains( 'ffffffff', $ids, 'A client cannot choose a new id.' );
		$this->assertNotContains( '../../x', $ids );

		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/D', $id );
		}
	}

	public function test_the_plan_limit_is_per_website_and_a_plan_without_jobs_refuses_all(): void {
		$five = array_fill( 0, 5, $this->script() );
		$this->assertIsArray( SiteCron::normalise( $five, $this->ctx( array( 'limit' => 5 ) ) ) );

		$six = SiteCron::normalise( array_fill( 0, 6, $this->script() ), $this->ctx( array( 'limit' => 5 ) ) );
		$this->assertWPError( $six );
		$this->assertSame( 'limit', $six->get_error_code() );
		$this->assertStringContainsString( 'up to 5 scheduled jobs', $six->get_error_message() );

		$none = SiteCron::normalise( array( $this->script() ), $this->ctx( array( 'limit' => 0 ) ) );
		$this->assertSame( 'limit', $none->get_error_code() );
		$this->assertSame( 'Your plan has no scheduled jobs.', $none->get_error_message() );
		$this->assertSame( array(), SiteCron::normalise( array(), $this->ctx( array( 'limit' => 0 ) ) ), 'An empty list is always fine: it removes the jobs.' );
	}

	public function test_the_plan_field_defaults_to_five_and_older_plans_have_it_too(): void {
		$this->assertArrayHasKey( 'cron_jobs', Plan::fields() );
		$this->assertSame( 5, Plan::typed( array() )['cron_jobs'], 'A plan that was stored before the field existed.' );
		$this->assertSame( 2, Plan::typed( array( 'cron_jobs' => '2' ) )['cron_jobs'] );
		$this->assertSame( 0, Plan::typed( array( 'cron_jobs' => '0' ) )['cron_jobs'] );
		$this->assertSame( 0, Plan::typed( array( 'cron_jobs' => '-3' ) )['cron_jobs'] );
		$this->assertSame( 5, SiteCron::limit( Plan::typed( array() ) ) );
	}

	public function test_the_server_cron_of_wordpress_is_for_a_website_with_wordpress_and_only_one(): void {
		$wp = array( 'label' => '', 'enabled' => true, 'schedule' => '*/5 * * * *', 'kind' => 'wordpress', 'timeout' => 300 );

		$ok = SiteCron::normalise( array( $wp ), $this->ctx() );
		$this->assertSame( 'wordpress', $ok[0]['kind'] );
		$this->assertArrayNotHasKey( 'path', $ok[0] );
		$this->assertArrayNotHasKey( 'url', $ok[0] );

		$plain = SiteCron::normalise( array( $wp ), $this->ctx( array( 'wordpress' => false ) ) );
		$this->assertWPError( $plain );
		$this->assertSame( 'no_wordpress', $plain->get_error_code() );

		$this->assertWPError( SiteCron::normalise( array( $wp, $wp ), $this->ctx() ), 'One server cron.' );
		$this->assertWPError( SiteCron::normalise( array( array_merge( $wp, array( 'schedule' => '* * * * *' ) ) ), $this->ctx() ), 'It keeps the 5 minute gap.' );
	}

	public function test_what_goes_to_the_agent_is_only_what_it_knows(): void {
		$jobs = SiteCron::normalise(
			array(
				$this->script( array( 'args' => array() ) ),
				$this->script( array( 'label' => '' ) ),
				array( 'label' => 'Ping', 'enabled' => false, 'schedule' => '0 3 * * *', 'kind' => 'url', 'url' => 'https://example.org/cron' ),
				array( 'label' => '', 'enabled' => true, 'schedule' => '*/5 * * * *', 'kind' => 'wordpress' ),
			),
			$this->ctx()
		);
		$sent = SiteCron::for_agent( $jobs );

		$this->assertArrayNotHasKey( 'args', $sent[0], 'No arguments, no key.' );
		$this->assertSame( array( 'run' ), $sent[1]['args'] );
		$this->assertArrayNotHasKey( 'label', $sent[1], 'An empty label is not sent.' );
		$this->assertSame( array( 'id', 'kind', 'enabled', 'schedule', 'timeout', 'label', 'url' ), array_keys( $sent[2] ) );
		$this->assertFalse( $sent[2]['enabled'] );
		$this->assertSame( 'https://example.org/cron', $sent[2]['url'] );
		$this->assertSame( array( 'id', 'kind', 'enabled', 'schedule', 'timeout' ), array_keys( $sent[3] ) );
		$this->assertSame( 'script', $sent[0]['kind'] );
		$this->assertSame( array( 'id', 'kind', 'enabled', 'schedule', 'timeout', 'php', 'path', 'args' ), array_keys( $sent[1] ) );
		$this->assertSame( array( 'id', 'kind', 'enabled', 'schedule', 'timeout', 'label', 'php', 'path' ), array_keys( SiteCron::for_agent( SiteCron::normalise( array( $this->script( array( 'args' => array() ) ) ), $this->ctx() ) )[0] ) );
	}

	// -- Saving ------------------------------------------------------------------------------------------------------

	public function test_a_save_goes_through_the_shared_controller_and_replaces_the_whole_list_on_the_node(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$a    = new HostingActions( $r );

		$out = $a->queue( 'set_cron', array( array( $this->script(), $this->script( array( 'label' => 'Second', 'path' => 'cron/two.php' ) ) ), $slug ) );
		$this->assertIsArray( $out );
		$this->assertSame( 'pending', $out['status'] );
		$this->assertSame( 'set_cron', $out['task']['action'] );
		$this->assertSame( 'Saving the scheduled jobs', $out['task']['label'] );
		$this->assertSame( $slug, $out['task']['slug'], 'The task is about the website, not about the list.' );
		$this->assertSame( array(), $this->agent->calls_of( 'site.cron_set' ), 'Nothing is sent from the request.' );
		$this->assertSame( array(), Sites::find( $r->id(), $slug )['flags']['cron'] ?? array(), 'Nothing is stored before the node has taken it.' );

		$this->run_account( $r );
		$calls = $this->agent->calls_of( 'site.cron_set' );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'vmid', 'slug', 'jobs' ), array_keys( $calls[0]['args'] ) );
		$this->assertCount( 2, $calls[0]['args']['jobs'] );
		$this->assertSame( 'cron/run.php', $calls[0]['args']['jobs'][0]['path'] );

		$stored = Sites::find( $r->id(), $slug )['flags']['cron'];
		$this->assertSame( array_column( $calls[0]['args']['jobs'], 'id' ), array_column( $stored, 'id' ) );
		$this->assertCount( 2, $this->agent->state()['sites'][ $this->vmid( $r ) ][ $slug ]['cron'] );
		$this->assertNull( Steps::view( Resource::find( $r->id() ) ), 'The change is over.' );

		// The next save replaces the list: one job less, and the one that stays keeps its id.
		$keep = $stored[1];
		$this->change( $r, 'set_cron', array( array( array_merge( $keep, array( 'label' => 'Renamed' ) ) ), $slug ) );
		$now = Sites::find( $r->id(), $slug )['flags']['cron'];
		$this->assertCount( 1, $now );
		$this->assertSame( $keep['id'], $now[0]['id'] );
		$this->assertSame( 'Renamed', $now[0]['label'] );
		$this->assertCount( 1, $this->agent->state()['sites'][ $this->vmid( $r ) ][ $slug ]['cron'], 'The node has the same list.' );

		// An empty list removes them all.
		$this->change( $r, 'set_cron', array( array(), $slug ) );
		$this->assertSame( array(), Sites::find( $r->id(), $slug )['flags']['cron'] );
		$this->assertSame( array(), $this->agent->state()['sites'][ $this->vmid( $r ) ][ $slug ]['cron'] );
	}

	public function test_a_refused_list_queues_nothing_and_sends_nothing(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$a    = new HostingActions( $r );

		foreach ( array( $this->script( array( 'path' => '../x' ) ), $this->script( array( 'schedule' => '* * * * *' ) ), $this->script( array( 'schedule' => "*/5 * * * *\n* * * * *" ) ), $this->script( array( 'args' => array( '$(id)' ) ) ) ) as $job ) {
			$out = $a->queue( 'set_cron', array( array( $job ), $slug ) );
			$this->assertWPError( $out );
		}

		$this->assertWPError( $a->queue( 'set_cron', array( array( $this->script() ), 'nothere' ) ) );
		$this->assertNull( Steps::view( Resource::find( $r->id() ) ), 'No change was queued.' );
		$this->assertSame( array(), $this->agent->calls_of( 'site.cron_set' ) );
	}

	public function test_the_plan_limit_stops_the_sixth_job_and_a_smaller_plan_stops_the_third(): void {
		$r    = $this->account( array(), array( 'cron_jobs' => 2 ) );
		$slug = $this->slug( $r );
		$a    = new HostingActions( $r );

		$this->assertWPError( $a->queue( 'set_cron', array( array( $this->script(), $this->script(), $this->script() ), $slug ) ) );
		$this->assertIsArray( $this->change( $r, 'set_cron', array( array( $this->script(), $this->script() ), $slug ) ) );

		// Another website has its own two.
		$this->change( $r, 'add_site', array( 'other', '', '8.3', false ) );
		$this->assertIsArray( $this->change( $r, 'set_cron', array( array( $this->script(), $this->script() ), 'other' ) ), 'The limit is for each website.' );
		$this->assertCount( 2, Sites::find( $r->id(), $slug )['flags']['cron'] );
		$this->assertCount( 2, Sites::find( $r->id(), 'other' )['flags']['cron'] );
	}

	public function test_a_failed_call_to_the_node_leaves_the_old_list_stored_and_says_so(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->change( $r, 'set_cron', array( array( $this->script( array( 'label' => 'Old' ) ) ), $slug ) );
		$old = Sites::find( $r->id(), $slug )['flags']['cron'];

		$this->agent->fail_next( 'site.cron_set', 'container not running' );
		$this->mails_off();
		$this->change( $r, 'set_cron', array( array( $this->script( array( 'label' => 'New' ) ) ), $slug ) );

		$this->assertSame( $old, Sites::find( $r->id(), $slug )['flags']['cron'], 'The stored list is as it was.' );
		$last = Steps::last( Resource::find( $r->id() ) );
		$this->assertNotNull( $last );
		$this->assertSame( 'Saving the scheduled jobs', $last['label'] );
		$this->assertSame( 'Saving the scheduled jobs did not work. Your jobs were not changed.', $last['text'] );
		$this->assertStringNotContainsString( 'container not running', $last['text'], 'What the node said stays with staff.' );
	}

	private function mails_off(): void {
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function test_an_old_agent_leaves_everything_working_and_the_section_says_it_is_not_there_yet(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->agent->set_unsupported( array( 'site.cron_set', 'site.cron_status' ) );
		$this->mails_off();

		$this->change( $r, 'set_cron', array( array( $this->script() ), $slug ) );
		$last = Steps::last( Resource::find( $r->id() ) );
		$this->assertSame( 'Scheduled jobs are not available on your server yet. Your jobs were not changed.', $last['text'] );
		$this->assertSame( array(), Sites::find( $r->id(), $slug )['flags']['cron'] ?? array() );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( SiteCron::UNSUPPORTED ) );

		// The section says so; the rest of the page and the other changes work.
		$html = $this->panel( $r );
		$this->assertStringContainsString( 'Scheduled jobs are not available on your server yet.', $html );
		$this->assertMatchesRegularExpression( '/data-cron-unavailable>/', $html );
		$this->assertMatchesRegularExpression( '/data-cron-main hidden/', $html );
		$this->assertIsArray( $this->change( $r, 'set_php', array( $slug, '8.4' ) ) );
		$this->assertSame( '8.4', Sites::find( $r->id(), $slug )['php'] );

		// The read says it too, and does not fail.
		$out = ( new HostingActions( Resource::find( $r->id() ) ) )->cron_status( $slug );
		$this->assertSame( false, $out['available'] );
	}

	public function test_a_new_agent_clears_the_note_and_reads_the_last_runs(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$r->set( array( SiteCron::UNSUPPORTED => Jobs::now() ) );
		$this->change( $r, 'set_cron', array( array( $this->script() ), $slug ) );
		$id = Sites::find( $r->id(), $slug )['flags']['cron'][0]['id'];
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( SiteCron::UNSUPPORTED ), 'A save that works clears the note.' );

		$this->agent->set_cron_run( $this->vmid( $r ), $slug, $id, array( 'started' => 1700000000, 'ended' => 1700000005, 'exit' => 1, 'tail' => "boom\n" ) );
		$out = ( new HostingActions( Resource::find( $r->id() ) ) )->cron_status( $slug );

		$this->assertTrue( $out['available'] );
		$run = (array) $out['jobs'];
		$this->assertSame( array( 'started' => 1700000000, 'ended' => 1700000005, 'exit' => 1, 'timed_out' => false, 'skipped' => false, 'tail' => "boom\n" ), $run[ $id ] );
	}

	public function test_the_last_runs_are_read_once_a_minute_and_a_save_clears_the_read(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->change( $r, 'set_cron', array( array( $this->script() ), $slug ) );
		$id = Sites::find( $r->id(), $slug )['flags']['cron'][0]['id'];
		$this->agent->set_cron_run( $this->vmid( $r ), $slug, $id, array( 'started' => 1700000000, 'ended' => 1700000005, 'exit' => 0, 'tail' => "one\n" ) );
		$read = fn() => ( new HostingActions( Resource::find( $r->id() ) ) )->cron_status( $slug );

		$first = $read();
		$calls = count( $this->agent->calls_of( 'site.cron_status' ) );
		$this->assertGreaterThan( 0, $calls );
		$this->agent->set_cron_run( $this->vmid( $r ), $slug, $id, array( 'started' => 1700000100, 'ended' => 1700000105, 'exit' => 0, 'tail' => "two\n" ) );
		$again = $read();
		$this->assertSame( $calls, count( $this->agent->calls_of( 'site.cron_status' ) ), 'The node was not asked again.' );
		$this->assertEquals( $first, $again );

		$this->change( $r, 'set_cron', array( array( $this->script( array( 'label' => 'Other' ) ) ), $slug ) );
		$id = Sites::find( $r->id(), $slug )['flags']['cron'][0]['id'];
		$this->agent->set_cron_run( $this->vmid( $r ), $slug, $id, array( 'started' => 1700000200, 'ended' => 1700000205, 'exit' => 0, 'tail' => "three\n" ) );
		$read();
		$this->assertGreaterThan( $calls, count( $this->agent->calls_of( 'site.cron_status' ) ), 'A save clears it.' );
	}

	public function test_the_list_never_travels_in_the_arguments_of_the_job_or_the_results(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$key  = 'sekret-key-8f3a1c9d';
		$job  = array( 'label' => 'Ping', 'enabled' => true, 'schedule' => '0 3 * * *', 'kind' => 'url', 'url' => 'https://' . \BeltoftServices\Hosting\EdgeMap::preview_host( $slug ) . '/cron?key=' . $key );

		$out = ( new HostingActions( $r ) )->queue( 'set_cron', array( array( $job ), $slug ) );
		$this->assertIsArray( $out );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection of the queue.
		$args = (string) $wpdb->get_var( 'SELECT GROUP_CONCAT(args SEPARATOR " ") FROM ' . $wpdb->prefix . 'actionscheduler_actions' );
		// phpcs:enable
		$this->assertStringNotContainsString( $key, $args, 'Not in the arguments of the queued job.' );
		$memory = Steps::state( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $memory ), 'Not in the step memory (the secret is encrypted).' );
		$this->assertNotSame( '', $memory['secret'] );
		$this->assertSame( '', $memory['args'][0] );

		$this->run_account( $r );
		$fresh = Resource::find( $r->id() );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $fresh->get( Jobs::RESULT ) ) );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $fresh->get( Steps::META ) ) );
		$this->assertStringContainsString( $key, (string) wp_json_encode( $this->agent->calls_of( 'site.cron_set' ) ), 'The node gets it.' );
	}

	public function test_a_hosting_change_by_a_crash_is_repeated_without_a_second_agent_job(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->agent->pending( 'site.cron_set', 2 );

		( new HostingActions( $r ) )->queue( 'set_cron', array( array( $this->script() ), $slug ) );
		$this->run_account( $r );

		$this->assertCount( 1, $this->agent_jobs( 'site.cron_set' ), 'One agent job, however often the change was looked at.' );
		$this->assertCount( 1, Sites::find( $r->id(), $slug )['flags']['cron'] );
	}

	// -- WordPress and the server cron --------------------------------------------------------------------------------

	public function test_the_server_cron_of_wordpress_is_one_of_the_jobs_and_switches_wordpresss_own_cron_off(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$wp   = array( 'label' => '', 'enabled' => true, 'schedule' => '*/5 * * * *', 'kind' => 'wordpress', 'timeout' => 300 );

		$this->change( $r, 'set_cron', array( array( $wp ), $slug ) );
		$sent = $this->agent->calls_of( 'site.cron_set' )[0]['args']['jobs'][0];
		$this->assertSame( 'wordpress', $sent['kind'] );
		$this->assertSame( '*/5 * * * *', $sent['schedule'] );
		$this->assertTrue( Sites::find( $r->id(), $slug )['flags']['cron_wp']['wp_cron_disabled'] );

		// It counts in the limit: a plan with one job has no room for another.
		$small = $this->account( array(), array( 'cron_jobs' => 1 ) );
		$this->change( $small, 'set_cron', array( array( $wp ), $this->slug( $small ) ) );
		$this->assertWPError( ( new HostingActions( Resource::find( $small->id() ) ) )->queue( 'set_cron', array( array( $wp, $this->script() ), $this->slug( $small ) ) ) );

		// Switching it off is saving the list without it: WordPress's own cron is on again.
		$this->change( $r, 'set_cron', array( array(), $slug ) );
		$this->assertSame( array(), $this->agent->state()['sites'][ $this->vmid( $r ) ][ $slug ]['cron'] );
	}

	public function test_a_website_without_wordpress_has_no_server_cron(): void {
		$r    = $this->account( array( 'install' => false ) );
		$slug = $this->slug( $r );
		$wp   = array( 'label' => '', 'enabled' => true, 'schedule' => '*/5 * * * *', 'kind' => 'wordpress', 'timeout' => 300 );

		$out = ( new HostingActions( $r ) )->queue( 'set_cron', array( array( $wp ), $slug ) );
		$this->assertWPError( $out );
		$this->assertSame( 'no_wordpress', $out->get_error_code() );

		$html = $this->panel( $r );
		$this->assertStringNotContainsString( 'Server cron', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="cron_wp_on"', $html );
		$this->assertStringNotContainsString( 'WordPress', $html );
		$this->assertStringContainsString( 'data-hosting-action="cron_save"', $html, 'Every website has scheduled jobs.' );
	}

	public function test_a_wordpress_website_is_offered_the_switch_with_its_explanation_and_then_the_way_back(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$html = $this->panel( $r );

		$this->assertStringContainsString( 'data-hosting-action="cron_wp_on"', $html );
		$this->assertStringContainsString( 'Switch on server cron', $html );
		$this->assertStringContainsString( 'every 5 minutes', $html );
		$this->assertStringContainsString( 'answered from the cache', $html, 'It says why a cached website needs it.' );
		$this->assertStringContainsString( 'shop', $html );
		$this->assertStringContainsString( 'switched off', $html, 'It says WordPress\'s own cron is switched off while it is on.' );

		$this->change( $r, 'set_cron', array( array( array( 'label' => '', 'enabled' => true, 'schedule' => '*/5 * * * *', 'kind' => 'wordpress', 'timeout' => 300 ) ), $slug ) );
		$html = $this->panel( $r );
		$this->assertStringNotContainsString( 'cron_wp_on', $html );
		$this->assertStringContainsString( 'data-hosting-action="cron_wp_off"', $html );
		$this->assertStringContainsString( 'WordPress server cron', $html, 'The job is a row of the list, with a name of its own, editable.' );
		$this->assertStringContainsString( 'data-kind="wordpress"', $html );
		$this->assertStringNotContainsString( 'could not be switched off', $html );

		// The agent could not change wp-config.php: both crons would run, and the page says so.
		$this->agent->set_wp_config( $this->vmid( $r ), $slug, false );
		$this->change( $r, 'set_cron', array( array( Sites::find( $r->id(), $slug )['flags']['cron'][0] ), $slug ) );
		$this->assertStringContainsString( 'could not be switched off', $this->panel( $r ) );
	}

	// -- The page -------------------------------------------------------------------------------------------------------

	/**
	 * The website panel as the account page prints it.
	 */
	private function panel( Resource $r ): string {
		$fresh   = Resource::find( $r->id() );
		$actions = new HostingActions( $fresh );
		$node    = (array) ( \BeltoftServices\Hosting\Nodes::get( (string) Records::for_resource( $r->id() )['node'] ) ?? array() );

		return HostingView::capture( 'site', HostingView::site( $fresh, $actions->sites()[0], false, true, array(), $node, $actions->limits() ) );
	}

	public function test_the_section_lists_the_saved_jobs_with_their_fields_and_a_blank_row_to_add(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->change( $r, 'set_cron', array( array( $this->script( array( 'label' => 'Nightly', 'schedule' => '30 2 * * *' ) ) ), $slug ) );
		$html = $this->panel( $r );

		$this->assertStringContainsString( 'data-sec="cron"', $html );
		$this->assertStringContainsString( 'Scheduled jobs', $html );
		$this->assertStringContainsString( '1 job', $html );
		$this->assertStringContainsString( 'value="Nightly"', $html );
		$this->assertStringContainsString( 'value="cron/run.php"', $html );
		$this->assertStringContainsString( 'value="02:30"', $html );
		$this->assertStringContainsString( '<option value="daily" selected', $html );
		$this->assertStringContainsString( '<template data-cron-template>', $html );
		$this->assertStringContainsString( 'data-hosting-action="cron_add"', $html );
		$this->assertStringContainsString( 'up to 5 scheduled jobs', $html );
		$this->assertStringContainsString( 'at most every 5 minutes', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
		$this->assertStringNotContainsString( 'beltoft-services-vm__more', $html );
		$this->agent->set_cron_run( $this->vmid( $r ), $slug, Sites::find( $r->id(), $slug )['flags']['cron'][0]['id'], array( 'started' => 1, 'ended' => 2, 'exit' => 0, 'tail' => 'OUTPUT-OF-A-RUN-42' ) );
		$this->assertStringNotContainsString( 'OUTPUT-OF-A-RUN-42', $this->panel( $r ), 'No output of a run is printed by the server.' );
	}

	public function test_the_section_of_a_plan_without_jobs_says_so_and_offers_no_add(): void {
		$r    = $this->account( array(), array( 'cron_jobs' => 0 ) );
		$html = $this->panel( $r );

		$this->assertStringContainsString( 'Your plan has no scheduled jobs.', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="cron_add"', $html );
	}

	public function test_the_names_an_address_job_may_use_are_the_ones_the_website_serves(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		( new HostingActions( $r ) )->add_domain( $slug, 'example.org' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'example.org', 'active' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'www.example.org', 'active' );
		$site  = Sites::find( $r->id(), $slug );
		$names = SiteCron::own_names( $r->id(), $site );
		$this->assertContains( \BeltoftServices\Hosting\EdgeMap::preview_host( $slug ), $names );
		$this->assertContains( 'example.org', $names );

		// With a main address chosen, the other name of the pair only redirects: it is not one of the names.
		( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'set_main_address', array( 'example.org' ) );
		$this->run_account( $r );
		$names = SiteCron::own_names( $r->id(), Sites::find( $r->id(), $slug ) );
		$this->assertContains( 'example.org', $names );
		$this->assertNotContains( 'www.example.org', $names );
		$this->assertStringContainsString( 'value="example.org"', $this->panel( $r ) );
	}

	public function test_an_address_job_for_a_name_the_website_does_not_serve_is_refused_by_the_action(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$job  = array( 'label' => '', 'enabled' => true, 'schedule' => '0 3 * * *', 'kind' => 'url', 'url' => 'https://other.example.test/cron' );

		$out = ( new HostingActions( $r ) )->queue( 'set_cron', array( array( $job ), $slug ) );
		$this->assertWPError( $out );
		$this->assertSame( 'A scheduled job can fetch an address of this website only.', $out->get_error_message() );
		$this->assertSame( array(), $this->agent->calls_of( 'site.cron_set' ) );
	}

	// -- The routes ---------------------------------------------------------------------------------------------------------

	public function test_the_routes_save_and_read_for_the_owner_only(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$user = (int) $r->license()['user_id'];
		$path = $r->id() . '/hosting/sites/' . $slug . '/cron';

		$saved = $this->call( 'PUT', $path, $user, array( 'jobs' => array( $this->script() ) ) );
		$this->assertSame( 202, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 'set_cron', $saved->get_data()['task']['action'] );
		$this->run_jobs();
		$this->run_account( $r );
		$this->assertCount( 1, Sites::find( $r->id(), $slug )['flags']['cron'] );

		$read = $this->call( 'GET', $path, $user );
		$this->assertSame( 200, $read->get_status() );
		$this->assertTrue( $read->get_data()['available'] );
		$this->assertStringContainsString( 'no-store', (string) $read->get_headers()['Cache-Control'] );

		$bad = $this->call( 'PUT', $path, $user, array( 'jobs' => array( $this->script( array( 'path' => '../../etc/passwd' ) ) ) ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', $path, $user, array( 'jobs' => 'a string' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', $path, $user, array() )->get_status() );

		$stranger = self::factory()->user->create();
		$this->assertSame( 403, $this->call( 'PUT', $path, $stranger, array( 'jobs' => array() ) )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', $path, $stranger )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', $path, 0 )->get_status() );
		$this->assertCount( 1, Sites::find( $r->id(), $slug )['flags']['cron'], 'The stranger changed nothing.' );
	}

	public function test_the_overview_lists_the_jobs_read_only_and_no_tool_of_the_mcp_server_sets_them(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$user = (int) $r->license()['user_id'];
		$this->change( $r, 'set_cron', array( array( $this->script() ), $slug ) );

		$data = $this->call( 'GET', $r->id() . '/hosting', $user )->get_data();
		$this->assertCount( 1, $data['sites'][0]['cron'] );
		$this->assertSame( 'cron/run.php', $data['sites'][0]['cron'][0]['path'] );
		$this->assertStringNotContainsString( 'tail', (string) wp_json_encode( $data['sites'][0]['cron'] ) );
		$this->assertSame( 1, $data['sites'][0]['cron'][0]['args_count'] );
		$this->assertArrayNotHasKey( 'args', $data['sites'][0]['cron'][0], 'An argument can carry a key.' );

		$key = 'key-in-an-address-5d41402a';
		$this->change( $r, 'set_cron', array( array( array( 'label' => '', 'enabled' => true, 'schedule' => '0 3 * * *', 'kind' => 'url', 'url' => 'https://' . \BeltoftServices\Hosting\EdgeMap::preview_host( $slug ) . '/cron?token=' . $key ), $this->script( array( 'args' => array( '--token=' . $key ) ) ) ), $slug ) );
		$data = $this->call( 'GET', $r->id() . '/hosting', $user )->get_data();
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $data ), 'The overview, and so hosting_info, shows no key.' );
		$this->assertTrue( $data['sites'][0]['cron'][0]['url_has_query'] );
		$this->assertStringContainsString( $key, $this->panel( $r ), 'The owner sees it on the page.' );

		foreach ( array( 'PUT /resources/1/hosting/sites/x/cron', 'POST /resources/1/hosting/sites/x/cron', 'DELETE /resources/1/hosting/sites/x/cron' ) as $route ) {
			foreach ( ActionMap::ROUTES as $tool => $patterns ) {
				foreach ( $patterns as $pattern ) {
					list( $method, $regex ) = explode( ' ', $pattern, 2 );
					$this->assertFalse( 0 === strpos( $route, $method . ' ' ) && 1 === preg_match( $regex, substr( $route, strlen( $method ) + 1 ) ), $tool . ' must not reach ' . $route );
				}
			}
		}

		$this->assertStringStartsWith( 'never', ActionMap::CAPABILITIES_EXCLUDED['hosting_scheduled_jobs'] );
	}

	// -- A website that goes, a restore ---------------------------------------------------------------------------------------

	public function test_the_list_goes_with_its_website(): void {
		$r = $this->account();
		$this->change( $r, 'add_site', array( 'extra', '', '8.3', false ) );
		$this->change( $r, 'set_cron', array( array( $this->script() ), 'extra' ) );
		$this->assertCount( 1, Sites::find( $r->id(), 'extra' )['flags']['cron'] );

		$this->change( $r, 'remove_site', array( 'extra', 'extra' ) );
		$this->assertNull( Sites::find( $r->id(), 'extra' ), 'The website, and with it its list, is gone.' );
		$this->assertArrayNotHasKey( 'extra', $this->agent->state()['sites'][ $this->vmid( $r ) ], 'The node dropped the website, and its jobs with it.' );

		$this->change( $r, 'add_site', array( 'extra', '', '8.3', false ) );
		$this->assertSame( array(), Sites::find( $r->id(), 'extra' )['flags']['cron'] ?? array(), 'A new website of the same name starts with none.' );
	}

	public function test_a_restore_of_the_whole_account_sends_the_stored_lists_again(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->change( $r, 'add_site', array( 'two', '', '8.3', false ) );
		$this->change( $r, 'set_cron', array( array( $this->script() ), $slug ) );
		$list = Sites::find( $r->id(), $slug )['flags']['cron'];

		// The container went back to a time before the list: the node has none.
		$vmid = $this->vmid( $r );
		$this->agent->submit( 'site.cron_set', array( 'vmid' => $vmid, 'slug' => $slug, 'jobs' => array() ), 'test-clear-1' );
		$this->assertSame( array(), $this->agent->state()['sites'][ $vmid ][ $slug ]['cron'] );
		$mark = count( $this->agent->calls() );
		as_unschedule_all_actions( SiteCron::RESEND_HOOK );

		Steps::done( Resource::find( $r->id() ), 'restore_backup', array( 'account', 'x', '' ) );
		$this->assertTrue( as_has_scheduled_action( SiteCron::RESEND_HOOK, array( $r->id(), 0 ) ), 'The follow-up is queued.' );

		// The country block goes first (it is queued at once); the follow-up for the jobs then finds the account free.
		$this->run_account( $r );
		SiteCron::resend_run( $r->id(), 0 );
		$this->run_account( $r );

		$sent = array_values( array_filter( array_slice( $this->agent->calls(), $mark ), static fn( $c ) => 'site.cron_set' === $c['op'] ) );
		$this->assertCount( 1, $sent, 'Only the website that has a stored list is sent: "two" never had one.' );
		$this->assertSame( $slug, $sent[0]['args']['slug'] );
		$this->assertSame( array_column( $list, 'id' ), array_column( $sent[0]['args']['jobs'], 'id' ) );
		$this->assertCount( 1, $this->agent->state()['sites'][ $vmid ][ $slug ]['cron'] );
		$this->assertNull( Steps::last( Resource::find( $r->id() ) ), 'A follow-up the customer did not ask for is not shown to the customer.' );
	}

	public function test_a_restore_of_files_or_a_database_leaves_the_jobs_alone(): void {
		$r = $this->account();
		as_unschedule_all_actions( SiteCron::RESEND_HOOK );

		Steps::done( Resource::find( $r->id() ), 'restore_backup', array( 'files', 'x', $this->slug( $r ) ) );
		Steps::done( Resource::find( $r->id() ), 'restore_backup', array( 'database', 'x', $this->slug( $r ) ) );
		$this->assertFalse( as_has_scheduled_action( SiteCron::RESEND_HOOK, array( $r->id(), 0 ) ) );

		Steps::done( Resource::find( $r->id() ), 'restore_undo', array() );
		$this->assertTrue( as_has_scheduled_action( SiteCron::RESEND_HOOK, array( $r->id(), 0 ) ), 'An undo puts the whole container back.' );
	}

	public function test_the_follow_up_waits_for_a_change_under_way_and_asks_again(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->change( $r, 'set_cron', array( array( $this->script() ), $slug ) );
		( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'set_php', array( $slug, '8.4' ) );
		as_unschedule_all_actions( SiteCron::RESEND_HOOK );

		SiteCron::resend_run( $r->id(), 0 );
		$this->assertTrue( as_has_scheduled_action( SiteCron::RESEND_HOOK, array( $r->id(), 1 ) ), 'Busy: it looks again in a moment.' );

		as_unschedule_all_actions( SiteCron::RESEND_HOOK );
		SiteCron::resend_run( $r->id(), SiteCron::RESEND_TRIES );
		$this->assertFalse( as_has_scheduled_action( SiteCron::RESEND_HOOK, array( $r->id(), SiteCron::RESEND_TRIES + 1 ) ), 'It gives up after its tries.' );
	}

	public function test_a_follow_up_with_an_old_agent_does_not_fail(): void {
		$r    = $this->account();
		$slug = $this->slug( $r );
		$this->change( $r, 'set_cron', array( array( $this->script() ), $slug ) );
		$this->agent->set_unsupported( array( 'site.cron_set' ) );
		$this->mails_off();

		$this->assertTrue( \BeltoftServices\Hosting\Actions\Steps::start_follow( Resource::find( $r->id() ), 'resend_cron', array() ) );
		$this->run_account( $r );
		$last = ( Resource::find( $r->id() ) )->get( Jobs::RESULT );
		$this->assertNotEmpty( $last['ok'], 'The job ended well: an agent that cannot is left alone.' );
		$this->assertSame( $slug, Sites::find( $r->id(), $slug )['slug'] );
	}
}
