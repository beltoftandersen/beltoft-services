<?php
/** Registrar balance alert tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Health;
use BeltoftServices\Tests\TestCase;

class HealthTest extends TestCase {
	public function test_low_balance_alerts_once_per_day(): void {
		delete_option( Health::LOW_ALERT );
		$this->settings( array( 'domain_low_balance' => 50, 'admin_email' => 'admin@example.com' ) );
		$op = new FakeClient();
		$op->reply( 'GET', '/resellers', array( 'balance' => 12.5, 'currency' => 'EUR' ) );
		$op->reply( 'GET', '/resellers', array( 'balance' => 12.5, 'currency' => 'EUR' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$this->assertSame( 12.5, Health::balance() );
		$this->assertSame( 12.5, Health::balance() );
		$this->assertSame( gmdate( 'Y-m-d' ), get_option( Health::LOW_ALERT ) );
	}
}
