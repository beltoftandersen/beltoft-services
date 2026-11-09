<?php
/**
 * Plan-wide counts over a root mail resource and its child domains.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Children;
use BeltoftServices\Mail\Aliases;
use BeltoftServices\Mail\MailPlan;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailPlanTest extends MailTestCase {

	/** A root with a ready child domain attached. */
	private function pair( array $plan = array() ): array {
		$root  = $this->ready_mail( 'root.example', $plan );
		$child = $this->ready_mail( 'child.example', $plan );
		$this->assertTrue( Children::attach( $root, $child ) );

		return array( Resource::find( $root->id() ), Resource::find( $child->id() ) );
	}

	public function test_counts_add_up_over_the_root_and_its_children(): void {
		list( $root, $child ) = $this->pair();

		foreach ( array( 'a', 'b', 'c' ) as $local ) {
			$this->assertIsArray( Mailboxes::create( $root, $local ) );
		}

		foreach ( array( 'd', 'e' ) as $local ) {
			$this->assertIsArray( Mailboxes::create( $child, $local ) );
		}

		$this->assertSame( $root->id(), MailPlan::root( $child )->id() );
		$this->assertSame( $root->id(), MailPlan::root( $root )->id() );
		$this->assertCount( 2, MailPlan::members( $root ) );
		$this->assertSame( array( 'mailboxes' => 5, 'aliases' => 0, 'domains' => 2 ), MailPlan::used( $root ) );
		$this->assertSame( 5, MailPlan::limits( $child )['mailboxes'] );
	}

	public function test_a_sixth_mailbox_on_any_domain_is_refused(): void {
		list( $root, $child ) = $this->pair();

		foreach ( array( 'a', 'b', 'c' ) as $local ) {
			$this->assertIsArray( Mailboxes::create( $root, $local ) );
		}

		foreach ( array( 'd', 'e' ) as $local ) {
			$this->assertIsArray( Mailboxes::create( $child, $local ) );
		}

		$on_child = Mailboxes::create( $child, 'f' );
		$this->assertWPError( $on_child );
		$this->assertSame( 'limit', $on_child->get_error_code() );

		$on_root = Mailboxes::create( $root, 'f' );
		$this->assertWPError( $on_root );
		$this->assertSame( 'limit', $on_root->get_error_code() );
	}

	public function test_aliases_share_the_plan_limit(): void {
		list( $root, $child ) = $this->pair( array( 'aliases' => 3 ) );

		$this->assertIsArray( Aliases::create( $root, 'one', 'x@root.example' ) );
		$this->assertIsArray( Aliases::create( $root, 'two', 'x@root.example' ) );
		$this->assertIsArray( Aliases::create( $child, 'three', 'x@child.example' ) );

		$res = Aliases::create( $child, 'four', 'x@child.example' );
		$this->assertWPError( $res );
		$this->assertSame( 'limit', $res->get_error_code() );
		$this->assertSame( 3, MailPlan::used( $root )['aliases'] );
	}

	public function test_a_single_domain_plan_counts_as_before(): void {
		$r = $this->ready_mail( 'solo.example', array( 'mailboxes' => 2 ) );

		$this->assertSame( $r->id(), MailPlan::root( $r )->id() );
		$this->assertSame( array( $r->id() ), array_map( static fn( $m ) => $m->id(), MailPlan::members( $r ) ) );
		$this->assertIsArray( Mailboxes::create( $r, 'a' ) );
		$this->assertIsArray( Mailboxes::create( $r, 'b' ) );
		$res = Mailboxes::create( $r, 'c' );
		$this->assertWPError( $res );
		$this->assertSame( 'limit', $res->get_error_code() );
		$this->assertSame( 1, MailPlan::used( $r )['domains'] );
	}

	public function test_deleted_and_failed_children_are_not_members(): void {
		list( $root, $child ) = $this->pair();
		$this->assertTrue( $child->set( array( 'state' => 'deleted' ) ) );

		$this->assertCount( 1, MailPlan::members( $root ) );
		$this->assertSame( 1, MailPlan::used( $root )['domains'] );
	}
}
