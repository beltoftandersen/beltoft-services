<?php
/** Credential persistence tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Account;
use BeltoftServices\Tests\TestCase;

class AccountTest extends TestCase {
	public function test_secrets_are_encrypted_and_empty_password_keeps_saved(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'k', 32 ) ) );
		Account::save( array( 'username' => 'user', 'password' => 'secret-123', 'dns_key' => 'dns-secret', 'handle' => '123' ) );
		$raw = get_option( Account::OPTION );
		$this->assertStringStartsWith( 'bs1:', $raw['password'] );
		$this->assertStringStartsWith( 'bs1:', $raw['dns_key'] );
		$this->assertStringNotContainsString( 'secret-123', wp_json_encode( $raw ) );
		Account::save( array( 'username' => 'new-user', 'password' => '' ) );
		$this->assertSame( 'secret-123', Account::get()['password'] );
		$this->assertSame( 'new-user', Account::get()['username'] );
	}
}
