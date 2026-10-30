<?php
/**
 * The dashboard's firewall rules after a rollback. Fake client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\Provision;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class FirewallRestoreTest extends SnapshotsTestCase {

	public function test_a_rollback_writes_the_managed_rules_again_and_keeps_the_smtp_block(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$server->set( array( Firewall::MANAGED => true, Firewall::ON => true, Firewall::RULES => Firewall::standard(), Firewall::REV => 1 ) );

		$this->act( $server, 'snapshot_rollback', array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' ) );

		$fresh = Resource::find( $server->id() );
		$this->assertNull( $fresh->get( 'snapshot_rollback' ), (string) $fresh->get( 'last_error' ) );
		$this->assertSame( 'DROP', $this->fake->vm( 5000 )['firewall_options']['policy_in'] );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 22, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 3306, '198.51.100.1' ) );
		$comments = array_map( static fn( $rule ) => (string) $rule['comment'], $this->fake->vm( 5000 )['firewall_rules'] );
		$this->assertContains( Provision::SMTP_COMMENT, $comments );
	}

	public function test_a_rollback_of_a_server_the_dashboard_does_not_manage_writes_no_inbound_policy(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );

		$this->act( $server, 'snapshot_rollback', array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' ) );

		$this->assertArrayNotHasKey( 'policy_in', $this->fake->vm( 5000 )['firewall_options'] );
	}
}
