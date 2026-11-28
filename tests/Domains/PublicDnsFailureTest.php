<?php
/** A lookup that fails is told apart from a name that does not exist. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\PublicDns;
use BeltoftServices\Tests\TestCase;

final class PublicDnsFailureTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		PublicDns::$reader = static fn( string $fqdn, int $flag ) => false;
	}

	protected function tearDown(): void {
		PublicDns::$reader = null;
		remove_all_filters( 'beltoft_services_dns_rcode' );
		parent::tearDown();
	}

	public function test_a_name_that_does_not_exist_is_an_empty_answer(): void {
		add_filter( 'beltoft_services_dns_rcode', static fn() => 3 );
		$this->assertSame( array(), PublicDns::resolve( 'gone.example', 'TXT' ) );
	}

	public function test_a_servfail_or_no_answer_stays_an_error(): void {
		add_filter( 'beltoft_services_dns_rcode', static fn() => 2 );
		$this->assertWPError( PublicDns::resolve( 'broken.example', 'TXT' ) );
	}

	public function test_without_an_answer_to_the_probe_it_is_an_error(): void {
		$this->assertWPError( PublicDns::resolve( 'unknown.example', 'A' ) );
	}
}
