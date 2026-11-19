<?php
/** The list of parked names goes to the edge of every enabled node. @package BeltoftServices */
namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Cli\ParkingCommand;
use BeltoftServices\Hosting\ParkedEdge;
use BeltoftServices\Jobs;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

class ParkedEdgeTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( ParkedEdge::PENDING );
		as_unschedule_all_actions( ParkedEdge::HOOK, array(), Jobs::GROUP );
	}

	private function park( string $zone ): void {
		update_option( 'beltoft_services_parked_' . sha1( $zone ), array( 'zone' => $zone, 'sets' => array(), 'at' => 1 ), false );
		do_action( 'beltoft_services_parked', $zone );
	}

	private function unpark( string $zone ): void {
		delete_option( 'beltoft_services_parked_' . sha1( $zone ) );
		do_action( 'beltoft_services_unparked', $zone );
	}

	public function test_parking_a_zone_marks_the_list_and_queues_one_job(): void {
		$this->park( 'one.example' );
		$this->park( 'two.example' );
		$this->assertNotFalse( get_option( ParkedEdge::PENDING ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => ParkedEdge::HOOK, 'status' => 'pending', 'per_page' => 10 ) ) );
		$this->assertSame( array( array() ), ParkedEdge::due() );
		$this->assertSame( array(), $this->agent->calls_of( 'edge.parked' ) );
	}

	public function test_the_job_sends_the_apex_and_www_of_every_parked_zone_to_the_node(): void {
		$this->park( 'two.example' );
		$this->park( 'one.example' );
		$this->assertSame( array( 'n1' => 'done' ), ParkedEdge::run() );
		$calls = $this->agent->calls_of( 'edge.parked' );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'one.example', 'two.example', 'www.one.example', 'www.two.example' ), $this->agent->state()['parked'] );
		$this->assertFalse( get_option( ParkedEdge::PENDING, false ), 'The mark is cleared when every node took the list.' );
		$this->assertSame( array(), ParkedEdge::due() );
	}

	public function test_unparking_sends_the_shorter_list_and_a_new_job_id(): void {
		$this->park( 'one.example' );
		$this->park( 'two.example' );
		ParkedEdge::run();
		$this->unpark( 'one.example' );
		ParkedEdge::run();
		$this->assertSame( array( 'two.example', 'www.two.example' ), $this->agent->state()['parked'] );
		$ids = array_map( static fn( $c ) => $c['job_id'] ?? $c['id'] ?? '', $this->agent->calls_of( 'edge.parked' ) );
		$this->assertCount( 2, array_unique( array_filter( $ids ) ) );
		$this->unpark( 'two.example' );
		ParkedEdge::run();
		$this->assertSame( array(), $this->agent->state()['parked'] );
	}

	public function test_a_name_held_by_an_account_is_skipped_by_the_agent_not_replaced(): void {
		$this->park( 'held.example' );
		$this->agent->submit( 'ct.prepare', array(), 'x' ); // The fake needs no container to take routes.
		$this->agent->submit( 'edge.apply', array( 'account' => 'acc1', 'routes' => array( array( 'host' => 'held.example', 'ip' => '10.200.0.10', 'suspended' => false ) ) ), 'route-1' );
		ParkedEdge::run();
		$this->assertSame( array( 'www.held.example' ), $this->agent->state()['parked'] );
	}

	public function test_a_node_that_fails_keeps_the_mark_and_queues_the_job_again(): void {
		$this->park( 'one.example' );
		as_unschedule_all_actions( ParkedEdge::HOOK, array(), Jobs::GROUP );
		$this->agent->fail_next( 'edge.parked', 'caddy refused' );
		$this->assertSame( array( 'n1' => 'failed' ), ParkedEdge::run() );
		$this->assertNotFalse( get_option( ParkedEdge::PENDING ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => ParkedEdge::HOOK, 'status' => 'pending', 'per_page' => 10 ) ) );
		$this->assertSame( array( 'n1' => 'done' ), ParkedEdge::run() );
		$this->assertFalse( get_option( ParkedEdge::PENDING, false ) );
	}

	public function test_a_disabled_node_is_not_called_and_pause_all_stops_the_job(): void {
		$this->park( 'one.example' );
		$this->save_node( array( 'enabled' => 0 ) );
		$this->assertSame( array(), ParkedEdge::run() );
		$this->assertSame( array(), $this->agent->calls_of( 'edge.parked' ) );
		$this->settings( array( 'pause_all' => 1 ) );
		$this->assertSame( array(), ParkedEdge::run() );
	}

	public function test_the_job_is_a_declared_background_hook_so_the_watchdog_and_runners_know_it(): void {
		$hooks = apply_filters( \BeltoftServices\Background::FILTER, array() );
		$this->assertArrayHasKey( ParkedEdge::HOOK, $hooks );
		$this->assertContains( ParkedEdge::HOOK, apply_filters( 'beltoft_services_job_hooks', array() ) );
	}

	public function test_the_cli_pushes_the_list_now(): void {
		$this->park( 'one.example' );
		\WP_CLI::reset();
		( new ParkingCommand() )->push( array(), array() );
		$this->assertStringContainsString( '2 names sent to 1 node', wp_json_encode( \WP_CLI::$lines ) );
		$this->assertSame( array( 'one.example', 'www.one.example' ), $this->agent->state()['parked'] );
	}

	public function test_the_job_reads_each_node_with_its_secrets_decrypted(): void {
		// The agent client needs the decrypted client certificate, key and CA for mutual TLS; Nodes::all() keeps them encrypted.
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Hosting/ParkedEdge.php' );
		$this->assertStringContainsString( 'Nodes::get(', $src );
		$this->assertDoesNotMatchRegularExpression( '/foreach\s*\(\s*Nodes::all\(\)\s+as\s+\$id\s*=>\s*\$node/', $src );
	}
}
