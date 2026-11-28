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

	public function test_a_name_that_does_not_exist_is_an_empty_answer_for_the_watch(): void {
		add_filter( 'beltoft_services_dns_rcode', static fn() => 3 );
		$this->assertSame( array(), PublicDns::resolve( 'gone.example', 'TXT', true ) );
		$this->assertSame( array(), \BeltoftServices\Domains\DnsQuery::addresses( 'gone.example', 'A' ), 'The watch\'s reader tells NXDOMAIN from a failure.' );
		$this->assertSame( array(), PublicDns::lookup( 'gone.example', 'CNAME' ), 'So does the lookup of mail and certificates.' );
	}

	public function test_the_other_callers_make_no_rcode_probe_and_get_the_old_error(): void {
		$asked = 0;
		add_filter(
			'beltoft_services_dns_rcode',
			static function () use ( &$asked ) {
				++$asked;
				return 3;
			}
		);

		$this->assertWPError( PublicDns::resolve( 'free.example', 'NS' ) );
		$this->assertWPError( PublicDns::resolve( 'free.example', 'A' ) );
		$this->assertSame( array(), \BeltoftServices\Domains\DnsAddress::all_public_ips( 'free.example' ) );

		// Domain search: one name server lookup per free name, and no second question.
		set_transient( \BeltoftServices\Domains\QuickCheck::BOOTSTRAP_KEY, array(), HOUR_IN_SECONDS );
		$this->assertSame( array( 'free.example' => 'checking' ), \BeltoftServices\Domains\QuickCheck::status( array( 'free.example' ) ) );
		delete_transient( \BeltoftServices\Domains\QuickCheck::BOOTSTRAP_KEY );

		$this->assertSame( 0, $asked, 'No probe outside the watch.' );
	}

	public function test_a_servfail_or_no_answer_stays_an_error(): void {
		add_filter( 'beltoft_services_dns_rcode', static fn() => 2 );
		$this->assertWPError( PublicDns::resolve( 'broken.example', 'TXT' ) );
	}

	public function test_without_an_answer_to_the_probe_it_is_an_error(): void {
		$this->assertWPError( PublicDns::resolve( 'unknown.example', 'A' ) );
	}
}
