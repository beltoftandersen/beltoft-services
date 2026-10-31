<?php
/**
 * Alias operations.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\Aliases;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class AliasesTest extends MailTestCase {

	public function test_create_list_and_delete(): void {
		$r = $this->ready_mail( 'al.example' );

		$this->assertSame( array( 'address' => 'sales@al.example', 'goto' => array( 'anna@al.example', 'bob@gmail.example' ) ), Aliases::create( $r, 'Sales', 'Anna@al.example, bob@gmail.example, anna@al.example' ) );
		$this->assertSame( array( array( 'id' => 1, 'address' => 'sales@al.example', 'goto' => array( 'anna@al.example', 'bob@gmail.example' ) ) ), Aliases::all( $r ) );

		$this->assertTrue( Aliases::delete( $r, 'SALES@al.example' ) );
		$this->assertSame( array(), Aliases::all( $r ) );
		$this->assertTrue( Aliases::delete( $r, 'sales@al.example' ), 'Gone already is fine.' );
		$this->assertSame( array( array( 'delete_alias', 1 ) ), $this->mailcow->calls_of( 'delete_alias' ), 'Deleted by id.' );
	}

	public function test_there_is_no_catch_all_and_no_odd_names(): void {
		$r = $this->ready_mail( 'ca.example' );
		foreach ( array( '', '*', '@', '@ca.example', 'a@ca.example', '.x', 'a b' ) as $bad ) {
			$this->assertSame( 'invalid', Aliases::create( $r, $bad, 'x@ca.example' )->get_error_code(), $bad );
		}
		$this->assertSame( array(), $this->mailcow->calls_of( 'add_alias' ) );
	}

	public function test_destinations_must_be_addresses_and_not_the_alias_itself(): void {
		$r = $this->ready_mail( 'dest.example' );
		foreach ( array( '', 'not an address', 'a@b.example, nope', array() ) as $bad ) {
			$this->assertSame( 'invalid', Aliases::create( $r, 'a', $bad )->get_error_code() );
		}
		$this->assertSame( 'invalid', Aliases::create( $r, 'a', 'a@dest.example' )->get_error_code(), 'To itself.' );
		$many = implode( ',', array_map( static fn( $i ) => "u$i@x.example", range( 1, 11 ) ) );
		$this->assertSame( 'invalid', Aliases::create( $r, 'a', $many )->get_error_code() );
		$this->assertSame( array(), $this->mailcow->calls_of( 'add_alias' ) );
	}

	public function test_forwarding_outside_the_domain_follows_the_setting(): void {
		$this->settings( array( 'mail_allow_external_forward' => 0 ) );
		$r = $this->ready_mail( 'fw.example' );
		$this->assertSame( 'external', Aliases::create( $r, 'a', 'x@gmail.example' )->get_error_code() );
		$this->assertIsArray( Aliases::create( $r, 'a', 'x@fw.example' ) );

		$this->settings( array( 'mail_allow_external_forward' => 1 ) );
		$this->assertIsArray( Aliases::create( $r, 'b', 'x@gmail.example' ) );
	}

	public function test_the_plan_limit_counts_aliases_but_not_the_entries_mailcow_keeps_for_mailboxes(): void {
		$r = $this->ready_mail( 'cnt.example', array( 'aliases' => 2 ) );
		Mailboxes::create( $r, 'anna' );
		// Mailcow lists a mailbox's own address as an alias that goes to itself.
		$this->mailcow->add_alias( 'anna@cnt.example', 'anna@cnt.example' );

		$this->assertSame( array(), Aliases::all( $r ) );
		$this->assertIsArray( Aliases::create( $r, 'a', 'anna@cnt.example' ) );
		$this->assertIsArray( Aliases::create( $r, 'b', 'anna@cnt.example' ) );
		$this->assertSame( 'limit', Aliases::create( $r, 'c', 'anna@cnt.example' )->get_error_code() );
	}

	public function test_an_address_that_is_taken_is_reported(): void {
		$r = $this->ready_mail( 'tk.example' );
		Mailboxes::create( $r, 'anna' );
		$this->assertSame( 'exists', Aliases::create( $r, 'anna', 'x@tk.example' )->get_error_code() );
		Aliases::create( $r, 'a', 'x@tk.example' );
		$this->assertSame( 'exists', Aliases::create( $r, 'a', 'y@tk.example' )->get_error_code() );
	}

	public function test_another_domains_alias_cannot_be_touched(): void {
		$mine  = $this->ready_mail( 'mine.example' );
		$other = $this->ready_mail( 'theirs.example' );
		Aliases::create( $other, 'a', 'x@theirs.example' );

		$this->assertSame( 'invalid', Aliases::delete( $mine, 'a@theirs.example' )->get_error_code() );
		$this->assertSame( array(), $this->mailcow->calls_of( 'delete_alias' ) );
		$this->assertCount( 1, Aliases::all( Resource::find( $other->id() ) ) );
		$this->assertSame( array(), Aliases::all( Resource::find( $mine->id() ) ) );
	}

	public function test_a_service_that_is_not_ready_changes_nothing(): void {
		$r = $this->ready_mail( 'nr.example' );
		$r->set( array( 'state' => 'paused' ) );
		$this->assertSame( 'not_ready', Aliases::create( Resource::find( $r->id() ), 'a', 'x@nr.example' )->get_error_code() );
		$this->assertSame( 'not_ready', Aliases::delete( Resource::find( $r->id() ), 'a@nr.example' )->get_error_code() );
	}

	public function test_a_mail_server_that_is_down_gives_a_plain_error(): void {
		$r = $this->ready_mail( 'dn.example' );
		$this->mailcow->fail_next( 'add_alias', new \WP_Error( 'mailcow_transport', 'cURL error' ) );
		$e = Aliases::create( $r, 'a', 'x@dn.example' );
		$this->assertSame( 'unavailable', $e->get_error_code() );
		$this->assertStringNotContainsString( 'cURL', $e->get_error_message() );
	}
}
