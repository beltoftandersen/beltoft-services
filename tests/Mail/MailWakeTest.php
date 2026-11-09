<?php
/**
 * A mail resource waiting for its domain is stepped at once when the domain is ready.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailWake;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailWakeTest extends MailTestCase {

	private function waiting_mail( string $domain, int $user ): Resource {
		$r = $this->mail_resource( $domain, array( 'mail_wait_until' => time() + DAY_IN_SECONDS ), array(), $user );
		$r->set( array( 'state' => 'provisioning', 'op' => 'provision', 'attempts' => 1, 'next_attempt_at' => time() + 600 ) );

		return Resource::find( $r->id() );
	}

	public function test_a_ready_domain_wakes_the_mail_resource_waiting_for_it_and_only_that_one(): void {
		$user    = self::factory()->user->create();
		$waiting = $this->waiting_mail( 'wake.example', $user );
		$other   = $this->waiting_mail( 'other-wake.example', $user );
		$domain  = $this->our_zone( $user, 'wake.example' );

		MailWake::on_state_changed( $domain, 'provisioning', 'ready' );

		$this->assertEmpty( Resource::find( $waiting->id() )->get( 'next_attempt_at' ), 'The retry timer is lifted.' );
		$this->assertNotEmpty( Jobs::pending( $waiting->id() ) );
		$this->assertSame( array(), Jobs::pending( $other->id() ) );
		$this->assertGreaterThan( time(), (int) Resource::find( $other->id() )->get( 'next_attempt_at' ) );
	}

	public function test_waking_twice_queues_one_job_and_other_states_do_nothing(): void {
		$user    = self::factory()->user->create();
		$waiting = $this->waiting_mail( 'twice.example', $user );
		$domain  = $this->our_zone( $user, 'twice.example' );

		MailWake::on_state_changed( $domain, 'ready', 'paused' );
		$this->assertSame( array(), Jobs::pending( $waiting->id() ) );

		MailWake::on_state_changed( $domain, 'provisioning', 'ready' );
		MailWake::on_state_changed( $domain, 'provisioning', 'ready' );
		$this->assertCount( 1, Jobs::pending( $waiting->id() ) );
	}

	public function test_a_domain_of_another_customer_wakes_nothing(): void {
		$user    = self::factory()->user->create();
		$waiting = $this->waiting_mail( 'mine.example', $user );
		$domain  = $this->our_zone( self::factory()->user->create(), 'mine.example' );

		MailWake::on_state_changed( $domain, 'provisioning', 'ready' );
		$this->assertSame( array(), Jobs::pending( $waiting->id() ) );
	}

	public function test_the_woken_resource_becomes_ready_without_waiting_for_its_timer(): void {
		$user    = self::factory()->user->create();
		$waiting = $this->waiting_mail( 'fast.example', $user );
		$domain  = $this->our_zone( $user, 'fast.example' );

		do_action( 'beltoft_services_state_changed', $domain, 'provisioning', 'ready' );
		$this->run_jobs();
		$this->assertSame( 'ready', Resource::find( $waiting->id() )->get( 'state' ) );
	}

	public function test_a_dns_only_zone_turning_ready_wakes_too(): void {
		$user    = self::factory()->user->create();
		$waiting = $this->waiting_mail( 'dnsonly.example', $user );
		$product = $this->service_product( 'dns', array(), array( 'slug' => 'dns-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$zone    = Resource::create( $license, new \BeltoftServices\Domains\DnsDriver(), array(), array( 'domain' => 'dnsonly.example', 'mode' => 'dns' ) );
		MailWake::on_state_changed( $zone, 'provisioning', 'ready' );
		$this->assertNotEmpty( Jobs::pending( $waiting->id() ) );
	}

	public function test_a_child_mail_resource_waiting_for_its_domain_wakes_too(): void {
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root-wake.example', array(), $user );
		$kid  = \BeltoftServices\Mail\MailDomains::add( $root, 'kid-wake.example' );
		$this->assertInstanceOf( Resource::class, $kid );
		$kid->set( array( 'state' => 'provisioning', 'op' => 'provision', 'attempts' => 1, 'next_attempt_at' => time() + 900, 'mail_wait_until' => time() + DAY_IN_SECONDS ) );
		foreach ( Jobs::pending( $kid->id() ) as $action_id => $at ) {
			\ActionScheduler::store()->cancel_action( $action_id );
		}
		$this->assertSame( array(), Jobs::pending( $kid->id() ) );

		MailWake::on_state_changed( $this->our_zone( $user, 'kid-wake.example' ), 'provisioning', 'ready' );
		$this->assertNotEmpty( Jobs::pending( $kid->id() ) );
		$this->assertEmpty( Resource::find( $kid->id() )->get( 'next_attempt_at' ) );
	}
}
