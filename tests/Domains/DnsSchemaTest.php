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
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 1 ) );
		$this->assertTrue( DnsClaimsFeature::enabled() );

		add_filter( 'beltoft_services_dns_missing_keys', static fn() => array( 'assignments.PRIMARY' ) );
		$this->assertFalse( DnsSchema::ready() );
		$this->assertFalse( DnsClaimsFeature::enabled() );
	}

	public function test_old_version_is_not_ready(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 1 ) );
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

	public function test_the_feature_needs_two_name_servers(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'domain_nameservers' => 'ns1.example.net' ) );
		$this->assertFalse( DnsClaimsFeature::enabled() );
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->assertTrue( DnsClaimsFeature::enabled() );
	}

	public function test_ready_runs_the_index_check_once_and_checks_again_when_told(): void {
		global $wpdb;

		DnsSchema::forget_verified();
		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 7; $i++ ) {
			$this->assertTrue( DnsSchema::ready() );
		}

		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before );

		DnsSchema::forget_verified();
		$before = $wpdb->num_queries;
		DnsSchema::ready();
		$this->assertSame( 1, $wpdb->num_queries - $before, 'Forgotten: the check runs again.' );

		$before = $wpdb->num_queries;
		DnsSchema::create_tables();
		DnsSchema::ready();
		$this->assertGreaterThanOrEqual( 2, $wpdb->num_queries - $before, 'create_tables() verifies afresh.' );
	}

	public function test_a_failed_check_is_never_remembered(): void {
		DnsSchema::forget_verified();
		$this->assertSame( array(), DnsSchema::missing_keys() );

		add_filter( 'beltoft_services_dns_missing_keys', static fn() => array( 'assignments.PRIMARY' ) );
		$this->assertSame( array( 'assignments.PRIMARY' ), DnsSchema::missing_keys() );
		remove_all_filters( 'beltoft_services_dns_missing_keys' );
		$this->assertSame( array(), DnsSchema::missing_keys() );
	}
}
