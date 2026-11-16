<?php
/**
 * The shared REST helper (assets/api.js): registered once, and a dependency of every script that calls it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Status;

/**
 * Api script tests.
 */
final class ApiScriptTest extends TestCase {

	public function test_the_helper_is_registered_with_status(): void {
		wp_deregister_script( Status::API_HANDLE );
		Status::assets();

		$this->assertTrue( wp_script_is( Status::API_HANDLE, 'registered' ) );
		$this->assertStringEndsWith( 'assets/api.js', wp_scripts()->registered[ Status::API_HANDLE ]->src );
	}

	public function test_every_script_that_calls_the_helper_depends_on_it(): void {
		$checked = 0;

		foreach ( glob( BELTOFT_SERVICES_PATH . 'assets/*.js' ) as $file ) {
			$name = basename( $file );

			if ( 'api.js' === $name || false === strpos( (string) file_get_contents( $file ), 'beltoftApi' ) ) {
				continue;
			}

			$lines = array();
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BELTOFT_SERVICES_PATH . 'src', \FilesystemIterator::SKIP_DOTS ) ) as $php ) {
				if ( 'php' !== $php->getExtension() ) {
					continue;
				}
				foreach ( file( $php->getPathname() ) as $line ) {
					if ( false !== strpos( $line, 'wp_enqueue_script' ) && false !== strpos( $line, "'assets/" . $name . "'" ) ) {
						$lines[] = $line;
					}
				}
			}

			$this->assertNotEmpty( $lines, $name . ' is enqueued somewhere.' );

			foreach ( $lines as $line ) {
				$this->assertTrue( false !== strpos( $line, 'Status::API_HANDLE' ) || false !== strpos( $line, 'beltoft-services-api' ), $name . ' must list the api handle as a dependency: ' . trim( $line ) );
			}

			++$checked;
		}

		$this->assertGreaterThanOrEqual( 14, $checked );
	}

	public function test_queued_actions_are_followed_with_the_one_shared_helper(): void {
		$dir = dirname( __DIR__ ) . '/assets/';
		$api = (string) file_get_contents( $dir . 'api.js' );

		$this->assertStringContainsString( 'follow: follow', $api );
		$this->assertStringContainsString( 'last_result', $api );

		foreach ( array( 'domain.js', 'mail.js' ) as $name ) {
			$this->assertStringContainsString( 'beltoftApi.follow(', (string) file_get_contents( $dir . $name ), $name . ' follows its queued actions with the shared helper.' );
		}
	}

	/**
	 * Runs follow() in node against a scripted status route; returns what it resolved to and how many reads it made.
	 *
	 * @param array<int, array<string, mixed>> $replies Status, headers, body per read (the last one repeats).
	 * @param string                           $options JS object literal of follow() options.
	 * @return array<string, mixed>
	 */
	private function run_follow( array $replies, string $options ): array {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->environment_missing( 'node is not installed.' );
		}

		$js  = 'global.window = { setTimeout: function ( fn, ms ) { waits.push( ms ); setImmediate( fn ); }, beltoftServicesAccount: { root: "/r/", nonce: "n" } };' . "\n";
		$js  = 'var waits = [], reads = 0, replies = ' . wp_json_encode( $replies ) . ";\n" . $js;
		$js .= 'window.fetch = function () { var r = replies[ Math.min( reads, replies.length - 1 ) ]; reads += 1; return Promise.resolve( { ok: r.status < 400, status: r.status, headers: { get: function ( k ) { return "Retry-After" === k ? ( r.retry || null ) : null; } }, json: function () { return Promise.resolve( r.body ); } } ); };' . "\n";
		$js .= 'Date.now = ( function ( real ) { var t = 0; return function () { t += 1000; return t; }; }() );' . "\n";
		$js .= file_get_contents( dirname( __DIR__ ) . '/assets/api.js' ) . "\n";
		$js .= 'window.beltoftApi.follow( { status: "/resources/5", action: "x", at: 1 }, ' . $options . ' ).then( function ( out ) { console.log( JSON.stringify( { out: out, reads: reads, waits: waits } ) ); } );';

		$file = wp_tempnam( 'follow.js' );
		file_put_contents( $file, $js );
		$out = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
		unlink( $file );

		$data = json_decode( trim( $out ), true );
		$this->assertIsArray( $data, 'node output: ' . $out );

		return $data;
	}

	public function test_follow_until_idle_ends_when_the_task_is_gone_whatever_the_last_action_was(): void {
		$busy = array( 'status' => 200, 'body' => array( 'working' => false, 'progress' => array( 'active' => true ), 'last_result' => array( 'action' => 'other', 'at' => 5 ) ) );
		$idle = array( 'status' => 200, 'body' => array( 'working' => false, 'progress' => array( 'active' => false ), 'last_result' => array( 'action' => 'other', 'at' => 6 ) ) );
		$out  = $this->run_follow( array( $busy, $busy, $idle ), '{ until: "idle" }' );

		$this->assertSame( 3, $out['reads'] );
		$this->assertTrue( $out['out']['idle'] );
		$this->assertSame( array( 5000, 5000 ), $out['waits'] );

		// The hosting overview says it with `task`.
		$task = array( 'status' => 200, 'body' => array( 'task' => array( 'action' => 'restore_backup' ), 'progress' => array( 'active' => false ) ) );
		$none = array( 'status' => 200, 'body' => array( 'task' => null, 'last' => null ) );
		$out  = $this->run_follow( array( $task, $none ), '{ until: "idle", path: "5/hosting" }' );

		$this->assertSame( 2, $out['reads'] );
		$this->assertTrue( $out['out']['idle'] );

		// The action mode still waits for its own action.
		$out = $this->run_follow( array( $idle, array( 'status' => 200, 'body' => array( 'working' => false, 'last_result' => array( 'action' => 'x', 'ok' => true, 'at' => 2 ) ) ) ), '{}' );
		$this->assertSame( 'x', $out['out']['action'] );
		$this->assertSame( 2, $out['reads'] );
	}

	public function test_follow_until_idle_waits_for_retry_after_and_never_calls_a_timeout_success(): void {
		$limited = array( 'status' => 429, 'retry' => '40', 'body' => array( 'code' => 'rate_limited' ) );
		$idle    = array( 'status' => 200, 'body' => array( 'task' => null ) );
		$out     = $this->run_follow( array( $limited, $idle ), '{ until: "idle", path: "5/hosting" }' );

		$this->assertSame( array( 40000 ), $out['waits'] );
		$this->assertTrue( $out['out']['idle'] );

		$busy = array( 'status' => 200, 'body' => array( 'task' => array( 'action' => 'a' ) ) );
		$out  = $this->run_follow( array( $busy ), '{ until: "idle", path: "5/hosting", timeout: 20000 }' );

		$this->assertNull( $out['out'], 'A timeout resolves to null, never to success.' );
		$this->assertGreaterThan( 2, $out['reads'] );
	}

	public function test_the_hosting_page_follows_with_the_shared_helper_and_asks_the_cache_facts_again_once_on_a_409(): void {
		$hosting = (string) file_get_contents( dirname( __DIR__ ) . '/assets/hosting.js' );

		$this->assertStringContainsString( "until: 'idle'", $hosting );
		$this->assertStringNotContainsString( 'Retry-After', $hosting, 'The back-off lives in api.js.' );
		$this->assertStringContainsString( '409 !== first.status', $hosting );
		$this->assertSame( 1, substr_count( $hosting, 'factsPath )' ) - 1, 'The facts are asked once, and once more after a 409.' );
	}
}
