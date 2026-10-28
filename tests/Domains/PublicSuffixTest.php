<?php
/** Public suffix list tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\PublicSuffix;
use BeltoftServices\Tests\TestCase;

class PublicSuffixTest extends TestCase {
	protected function tearDown(): void {
		remove_all_filters( 'beltoft_services_public_suffix_file' );
		PublicSuffix::flush();
		parent::tearDown();
	}

	public function test_registrable_domains_and_suffixes(): void {
		$this->assertTrue( PublicSuffix::is_registrable_apex( 'example.co.uk' ) );
		$this->assertTrue( PublicSuffix::is_registrable_apex( 'example.com' ) );
		$this->assertFalse( PublicSuffix::is_registrable_apex( 'www.example.co.uk' ) );
		$this->assertFalse( PublicSuffix::is_registrable_apex( 'co.uk' ) );
		$this->assertFalse( PublicSuffix::is_registrable_apex( 'com' ) );
		$this->assertSame( 'example.co.uk', PublicSuffix::registrable( 'a.b.example.co.uk' ) );
		$this->assertNull( PublicSuffix::registrable( 'co.uk' ) );
	}

	public function test_unknown_endings_are_not_registrable(): void {
		$this->assertNull( PublicSuffix::registrable( 'example.notarealtld' ) );
		$this->assertFalse( PublicSuffix::is_registrable_apex( 'example.notarealtld' ) );
		$this->assertNull( PublicSuffix::registrable( '' ) );
	}

	public function test_wildcard_and_exception_rules(): void {
		// *.ck makes every second-level name a suffix; !www.ck makes www.ck a registrable name.
		$this->assertNull( PublicSuffix::registrable( 'foo.ck' ) );
		$this->assertSame( 'a.foo.ck', PublicSuffix::registrable( 'a.foo.ck' ) );
		$this->assertSame( 'www.ck', PublicSuffix::registrable( 'www.ck' ) );
		$this->assertSame( 'www.ck', PublicSuffix::registrable( 'sub.www.ck' ) );
		$this->assertNull( PublicSuffix::registrable( 'ck' ) );
	}

	public function test_idn_rules_are_converted_to_ascii(): void {
		// 公司.cn is a rule; its ASCII form is xn--55qx5d.cn.
		$this->assertTrue( PublicSuffix::is_registrable_apex( 'example.xn--55qx5d.cn' ) );
		$this->assertFalse( PublicSuffix::is_registrable_apex( 'xn--55qx5d.cn' ) );
	}

	public function test_missing_file_fails_closed(): void {
		add_filter( 'beltoft_services_public_suffix_file', static fn() => '/nonexistent/public-suffix.dat' );
		PublicSuffix::flush();
		$this->assertNull( PublicSuffix::registrable( 'example.com' ) );
		$this->assertFalse( PublicSuffix::is_registrable_apex( 'example.com' ) );
	}
}
