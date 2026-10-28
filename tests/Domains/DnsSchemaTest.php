<?php
/** DNS hosting schema tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsClaimsFeature;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Locks;
use BeltoftServices\Tests\TestCase;

class DnsSchemaTest extends TestCase {
	public function test_tables_exist_keys_verified_and_ready(): void {
		global $wpdb;
		foreach ( DnsSchema::tables() as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
		$this->assertSame( array(), DnsSchema::missing_keys() );
		$this->assertSame( DnsSchema::DB_VERSION, get_option( DnsSchema::OPTION_DB_VERSION ) );
		$this->assertTrue( DnsSchema::ready() );
	}

	public function test_failed_verification_keeps_the_feature_off(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		$this->assertTrue( DnsClaimsFeature::enabled() );

		add_filter( 'beltoft_services_dns_missing_keys', static fn() => array( 'assignments.PRIMARY' ) );
		$this->assertFalse( DnsSchema::ready() );
		$this->assertFalse( DnsClaimsFeature::enabled() );
	}

	public function test_old_version_is_not_ready(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		update_option( DnsSchema::OPTION_DB_VERSION, '0' );
		$this->assertFalse( DnsSchema::ready() );
		$this->assertFalse( DnsClaimsFeature::enabled() );
	}

	public function test_setting_off_means_disabled(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		$this->assertFalse( DnsClaimsFeature::enabled() );
	}

	public function test_domain_lock_name_is_short_and_case_insensitive(): void {
		$name = Locks::domain( 'Example.COM' );
		$this->assertLessThanOrEqual( 64, strlen( $name ) );
		$this->assertSame( $name, Locks::domain( 'example.com' ) );
		$this->assertNotSame( $name, Locks::domain( 'example.org' ) );
		$this->assertLessThanOrEqual( 64, strlen( Locks::domain( str_repeat( 'a', 253 ) ) ) );
	}
}
