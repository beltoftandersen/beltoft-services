<?php
/**
 * More email domains on one plan.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Children;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\Aliases;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailDomains;
use BeltoftServices\Mail\MailPlan;
use BeltoftServices\Mail\MailSwitch;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Product;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailDomainsTest extends MailTestCase {

	/** Adds a domain and runs its jobs. */
	private function add_ready( Resource $root, string $name ): Resource {
		$child = MailDomains::add( $root, $name );
		$this->assertInstanceOf( Resource::class, $child );
		$this->run_jobs();

		return Resource::find( $child->id() );
	}

	public function test_a_team_plan_takes_a_second_and_third_domain_and_refuses_a_fourth(): void {
		$root = $this->ready_mail( 'root.example' );
		$this->assertInstanceOf( Resource::class, MailDomains::add( $root, 'two.example' ) );
		$this->assertInstanceOf( Resource::class, MailDomains::add( $root, 'three.example' ) );

		$res = MailDomains::add( $root, 'four.example' );
		$this->assertWPError( $res );
		$this->assertSame( 'no_room', $res->get_error_code() );
		$this->assertCount( 3, MailPlan::members( $root ) );
	}

	public function test_the_child_inherits_the_root_limits_and_provisions_like_any_mail_domain(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = MailDomains::add( $root, 'Child.Example' );
		$this->assertInstanceOf( Resource::class, $child );

		$license = $child->license();
		$this->assertSame( 'child', $license['source'] );
		$this->assertSame( 1, (int) $license['max_activations'] );
		$this->assertStringEndsWith( '-domain', (string) $license['product_slug'] );
		$this->assertSame( $root->id(), Children::parent_of( $child )->id() );
		$this->assertSame( 'child.example', $child->get( MailDomain::META_DOMAIN ) );

		$this->run_jobs();
		$child = Resource::find( $child->id() );
		$this->assertSame( 'ready', $child->get( 'state' ) );
		$this->assertSame( self::PLAN, $child->get( 'mail_limits' ) );
		$this->assertIsArray( $this->mailcow->domain( 'child.example' ) );
	}

	public function test_a_domain_held_by_another_customer_or_already_in_the_plan_is_refused(): void {
		$root = $this->ready_mail( 'root.example' );
		$this->ready_mail( 'other.example' );

		foreach ( array( 'other.example', 'root.example', 'reserved.example', 'not a domain' ) as $name ) {
			$res = MailDomains::add( $root, $name );
			$this->assertWPError( $res, $name );
			$this->assertSame( 'bad_domain', $res->get_error_code(), $name );
		}

		$this->add_ready( $root, 'mine.example' );
		$again = MailDomains::add( $root, 'mine.example' );
		$this->assertWPError( $again );
		$this->assertSame( 'bad_domain', $again->get_error_code() );
		$this->assertCount( 2, MailPlan::members( $root ) );
	}

	public function test_a_paused_root_refuses_new_domains_and_pauses_its_children(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertSame( 'ready', $child->get( 'state' ) );

		$this->assertTrue( $root->desire( 'paused', 'Test' ) );
		\BeltoftServices\Jobs::enqueue( $root->id() );
		$this->run_jobs();

		$this->assertSame( 'paused', Resource::find( $root->id() )->get( 'state' ) );
		$this->assertSame( 'paused', Resource::find( $child->id() )->get( 'state' ) );

		$res = MailDomains::add( Resource::find( $root->id() ), 'late.example' );
		$this->assertWPError( $res );
		$this->assertSame( 'not_ready', $res->get_error_code() );

		$this->assertTrue( Resource::find( $root->id() )->desire( 'ready', 'Test' ) );
		\BeltoftServices\Jobs::enqueue( $root->id() );
		$this->run_jobs();
		$this->assertSame( 'ready', Resource::find( $child->id() )->get( 'state' ) );
	}

	public function test_removing_a_domain_with_mailboxes_is_refused(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertIsArray( Mailboxes::create( $child, 'anna' ) );

		$res = MailDomains::remove( $child );
		$this->assertWPError( $res );
		$this->assertSame( 'has_mailboxes', $res->get_error_code() );

		$this->assertNotWPError( Mailboxes::delete( $child, 'anna@child.example' ) );
		$this->assertTrue( MailDomains::remove( Resource::find( $child->id() ) ) );
		$this->run_jobs();
		$this->assertSame( 'deleted', Resource::find( $child->id() )->get( 'state' ) );
		$this->assertCount( 1, MailPlan::members( Resource::find( $root->id() ) ) );
	}

	public function test_removing_a_domain_is_refused_when_the_mail_server_cannot_be_asked(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertIsArray( Mailboxes::create( $child, 'anna' ) );
		$this->mailcow->fail_next( 'list_mailboxes', new \WP_Error( 'mailcow_transport', 'down' ) );

		$res = MailDomains::remove( Resource::find( $child->id() ) );
		$this->assertWPError( $res );
		$this->assertSame( 'unavailable', $res->get_error_code() );
		$this->assertSame( 'Try again in a moment.', $res->get_error_message() );
		$this->assertSame( 'ready', Resource::find( $child->id() )->get( 'desired' ) );
	}

	public function test_a_domain_with_an_alias_but_no_mailbox_is_not_removed(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertIsArray( Aliases::create( $child, 'info', 'x@elsewhere.example' ) );

		$res = MailDomains::remove( Resource::find( $child->id() ) );
		$this->assertWPError( $res );
		$this->assertSame( 'has_mailboxes', $res->get_error_code() );
		$this->assertSame( 'ready', Resource::find( $child->id() )->get( 'desired' ) );
	}

	public function test_a_child_that_cannot_be_made_leaves_no_active_license(): void {
		$root = $this->ready_mail( 'root.example' );
		$res  = MailDomains::add( $root, 'new.example', array( 'uuid' => 'not a valid uuid!' ) );
		$this->assertWPError( $res );

		$slug  = (string) $root->license()['product_slug'] . '-domain';
		$found = array_values( array_filter( \BeltoftLicenses\Licenses::where( 'user_id', (int) $root->license()['user_id'] ), static fn( $l ) => $slug === $l['product_slug'] ) );
		$this->assertCount( 1, $found );
		$this->assertSame( \BeltoftLicenses\Licenses::STATUS_REVOKED, $found[0]['status'] );
		$this->assertCount( 1, MailPlan::members( $root ) );
	}

	public function test_removing_a_domain_is_refused_while_the_plan_is_paused(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertTrue( $root->set( array( 'state' => 'paused', 'desired' => 'paused' ) ) );

		$res = MailDomains::remove( Resource::find( $child->id() ) );
		$this->assertWPError( $res );
		$this->assertSame( 'not_active', $res->get_error_code() );
		$this->assertSame( 'You can remove a domain when the plan is active again.', $res->get_error_message() );
	}

	public function test_a_plan_upgrade_raises_the_limits_of_every_domain(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$bigger = array( 'mailboxes' => 8, 'quota_mb' => 20480, 'aliases' => 80, 'domains' => 5 );

		$this->assertTrue( $root->change_plan( $bigger, 'Upgrade' ) );
		\BeltoftServices\Jobs::enqueue( $root->id() );
		$this->run_jobs();

		$this->assertSame( $bigger, Resource::find( $root->id() )->get( 'mail_limits' ) );
		$this->assertSame( $bigger, Resource::find( $child->id() )->get( 'mail_limits' ) );
		$this->assertSame( '8', $this->mailcow->domain( 'child.example' )['mailboxes'] );
		$this->assertSame( 8, MailPlan::limits( Resource::find( $child->id() ) )['mailboxes'] );
	}

	public function test_a_switch_to_a_plan_with_fewer_domains_than_in_use_is_refused(): void {
		$product = $this->service_product( 'mail', self::PLAN, array( 'slug' => 'mail-sw' ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'subscription_id' => 7272 ) );
		$root    = Resource::create( $license, Registry::get( 'mail' ), Product::plan( $product->get_id() ), array( MailDomain::META_DOMAIN => 'sw-root.example' ) );
		$this->assertInstanceOf( Resource::class, $root );
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$this->assertTrue( $root->set( array( 'state' => 'ready' ) ) );
		$this->assertTrue( Registry::get( 'mail' )->provision( $root )->is_done() );
		$root = Resource::find( $root->id() );
		$root->set( array( 'steps' => array( 'proof', 'domain' ) ) );
		$this->add_ready( Resource::find( $root->id() ), 'sw-two.example' );

		$target = $this->service_product( 'mail', array( 'mailboxes' => 9, 'quota_mb' => 20480, 'aliases' => 90, 'domains' => 1 ), array( 'slug' => 'mail-one' ) );
		MailSwitch::register();
		$sub = new class( 7272 ) {
			public function __construct( private int $id ) {}
			public function get_id() {
				return $this->id;
			}
		};

		$message = apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $target->get_id(), 1, 0, $sub, null );
		$this->assertSame( 'Your plan has 2 email domains. Choose a plan with at least that many.', $message );
	}

	public function test_a_child_still_being_set_up_does_not_block_mailboxes_on_the_other_domains(): void {
		$root = $this->ready_mail( 'root.example' );
		$this->proof['check_txt'] = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );
		$child = MailDomains::add( $root, 'waiting.example' );
		$this->assertInstanceOf( Resource::class, $child );
		$this->assertNotContains( 'domain', (array) Resource::find( $child->id() )->get( 'steps' ) );

		$res = Mailboxes::create( $root, 'anna' );
		$this->assertIsArray( $res );
		$this->assertSame( array( 'mailboxes' => 1, 'aliases' => 0, 'domains' => 2 ), MailPlan::used( $root ) );
	}
}
