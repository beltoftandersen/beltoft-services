<?php
/** Domain email registration and details. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\EmailDetails;
use BeltoftServices\Emails\DomainActionNeeded;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class EmailTest extends TestCase {
	public function test_action_needed_registered_and_ready_details_include_domain(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->assertContains( 'beltoft_services_domain_action_needed', Emails::IDS );
		$this->assertInstanceOf( DomainActionNeeded::class, Emails::get( 'beltoft_services_domain_action_needed' ) );
		$product = $this->service_product( 'domain' );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'email-example.net' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$lines = EmailDetails::details( array(), 'beltoft_services_ready', $r );
		$this->assertContains( 'email-example.net', $lines );
		$this->assertContains( 'ns1.example.net, ns2.example.net', $lines );
	}
}
