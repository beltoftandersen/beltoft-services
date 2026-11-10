<?php
/**
 * The shared live/fake gate. No network, and no constant is defined here (LiveWiringTest does that in its
 * own process).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Drivers;

use BeltoftServices\Drivers\LiveGate;
use BeltoftServices\Tests\TestCase;

/**
 * LiveGate.
 */
class LiveGateTest extends TestCase {

	public function test_an_undefined_constant_allows_nothing(): void {
		$gate = new LiveGate( 'BELTOFT_SERVICES_NO_SUCH_LIVE_CONSTANT', '', 'Set %s' );

		$this->assertFalse( LiveGate::constant_true( 'BELTOFT_SERVICES_NO_SUCH_LIVE_CONSTANT' ) );
		$this->assertFalse( $gate->live_allowed() );
		$this->assertSame( 'fake', $gate->kind( array( 'client' => 'live' ), $gate->live_allowed() ) );
		$this->assertFalse( $gate->untagged( array( 'client' => 'live' ), $gate->live_allowed() ) );
		$this->assertNull( $gate->gate( 'c1', array( 'client' => 'live' ), $gate->live_allowed() ) );
		$this->assertFalse( LiveGate::constant_true( 'ABSPATH' ), 'A defined constant that is not true allows nothing.' );
	}

	public function test_untagged_needs_allowed_live_and_an_empty_site_tag(): void {
		$gate = new LiveGate( 'BELTOFT_SERVICES_NO_SUCH_LIVE_CONSTANT', '', 'Set %s' );
		$live = array( 'client' => 'live' );
		$fake = array( 'client' => 'fake' );

		$this->settings( array( 'site_tag' => '' ) );
		$this->assertTrue( $gate->untagged( $live, true ) );
		$this->assertFalse( $gate->untagged( $live, false ), 'Not allowed: nothing live is built, so nothing waits.' );
		$this->assertFalse( $gate->untagged( $fake, true ) );
		$this->assertFalse( $gate->untagged( array(), true ), 'A missing client is fake.' );
		$this->assertSame( 'live', $gate->kind( $live, true ) );
		$this->assertSame( 'fake', $gate->kind( $live, false ) );
		$this->assertSame( 'fake', $gate->kind( $fake, true ) );

		$this->settings( array( 'site_tag' => 'tagone' ) );
		$this->assertFalse( $gate->untagged( $live, true ), 'An explicit tag opens the gate.' );
		$this->assertNull( $gate->gate( 'c1', $live, true ) );
	}

	public function test_the_gate_text_and_alert_prefix_are_the_configured_ones(): void {
		$this->settings( array( 'site_tag' => '', 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$live = array( 'client' => 'live' );

		$plain = new LiveGate( 'BELTOFT_SERVICES_NO_SUCH_LIVE_CONSTANT', '', 'Set the site tag for cluster %s' );
		$this->assertSame( 'Set the site tag for cluster c1', $plain->gate( 'c1', $live, true ) );
		$this->assertNotFalse( get_transient( 'beltoft_services_site_tag_alert_' . md5( 'c1' ) ), 'The alert id has no prefix.' );

		$prefixed = new LiveGate( 'BELTOFT_SERVICES_NO_SUCH_LIVE_CONSTANT', 'hosting ', 'Set the site tag for hosting node %s' );
		$this->assertSame( 'Set the site tag for hosting node n1', $prefixed->gate( 'n1', $live, true ) );
		$this->assertNotFalse( get_transient( 'beltoft_services_site_tag_alert_' . md5( 'hosting n1' ) ), 'The alert id carries the prefix.' );
	}
}
