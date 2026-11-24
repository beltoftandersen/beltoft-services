<?php
/**
 * The "Service" column of WooCommerce → Licenses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Admin\LicenceServiceColumn;

/**
 * Names per kind, links, ended services, one read per page, and the hooks of the license list.
 */
final class AdminLicenceServiceColumnTest extends TestCase {

	/**
	 * One license holding a resource of the kind.
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $meta Meta.
	 * @return array<string, mixed> License row.
	 */
	private function holding( string $kind, array $meta, bool $close = false ): array {
		$license = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 5 ) ), array( 'fire' => false, 'max_activations' => 5 ) );
		$uuid    = 'col-' . wp_generate_password( 8, false );
		$result  = Licenses::add_resource( $license, $kind, $uuid, array_merge( array( 'uuid' => $uuid, 'driver' => $kind, 'state' => 'ready' ), $meta ) );
		$this->assertTrue( $result['success'] );

		if ( $close ) {
			Licenses::close_resource( $license, $kind, $uuid );
		}

		return $license;
	}

	/**
	 * Cell entries of a license after priming the page.
	 *
	 * @param array<string, mixed>[] $licenses Licenses of the page.
	 * @return array<int, array<int, mixed>[]>
	 */
	private function cells( array $licenses ): array {
		do_action( 'beltoft_licenses_list_page_rows', $licenses );
		$out = array();

		foreach ( $licenses as $license ) {
			$out[ (int) $license['id'] ] = array_filter( (array) apply_filters( 'beltoft_licenses_list_column_service', '', $license ) );
		}

		return $out;
	}

	public function test_the_column_comes_after_the_product(): void {
		$columns = apply_filters( 'beltoft_licenses_list_columns', array( 'key' => 'Key', 'product' => 'Product', 'status' => 'Status' ) );

		$this->assertSame( array( 'key', 'product', 'service', 'status' ), array_keys( $columns ) );
	}

	public function test_each_kind_is_named_and_linked_to_its_panel(): void {
		$cases = array(
			'domain'    => array( array( 'domain' => 'chimkins.com' ), 'chimkins.com' ),
			'dns'       => array( array( 'domain' => 'chimkins.com' ), 'chimkins.com (DNS)' ),
			'vm'        => array( array( 'driver' => 'proxmox', 'server_name' => 'web-01', 'ip' => '10.0.0.5' ), 'web-01 (10.0.0.5)' ),
			'mail'      => array( array( 'mail_domain' => 'chimkins.com' ), 'chimkins.com' ),
			'ssl'       => array( array( 'ssl_name' => 'www.chimkins.com' ), 'www.chimkins.com' ),
			'lickey'    => array( array( 'lic_title' => 'Plesk web', 'lic_ip' => '203.0.113.9' ), 'Plesk web / 203.0.113.9' ),
			'dedicated' => array( array( 'server_name' => 'metal-1' ), 'metal-1' ),
			'storage'   => array( array( 'storage_name' => 'backup7' ), 'backup7' ),
		);
		$licenses = array();

		foreach ( $cases as $kind => $case ) {
			$licenses[ $kind ] = $this->holding( $kind, $case[0] );
		}

		$cells = $this->cells( array_values( $licenses ) );

		foreach ( $cases as $kind => $case ) {
			$entries = $cells[ (int) $licenses[ $kind ]['id'] ];
			$this->assertCount( 1, $entries, $kind );
			$this->assertSame( $case[1], $entries[0][0], $kind );
			$this->assertStringContainsString( 'license=' . $licenses[ $kind ]['id'], $entries[0][1], $kind );
			$this->assertStringContainsString( '#beltoft-service-', $entries[0][1], $kind );
			$this->assertEmpty( $entries[0][2] ?? null, $kind );
		}
	}

	public function test_an_app_licence_names_the_app_and_its_server(): void {
		$server = $this->holding( 'vm', array( 'driver' => 'proxmox', 'server_name' => 'web-02' ) );
		$app    = $this->holding( 'applic', array( 'plan' => array( 'app' => 'plesk', 'tier' => 'web' ), 'server_license' => (int) $server['id'] ) );

		// The server is not on the page: it is read as well, in the same pass.
		$entries = $this->cells( array( $app ) )[ (int) $app['id'] ];

		$this->assertSame( 'Plesk on web-02', $entries[0][0] );
	}

	public function test_a_deleted_service_is_dimmed_with_its_date(): void {
		$license = $this->holding( 'domain', array( 'domain' => 'old.example' ), true );

		$entries = $this->cells( array( $license ) )[ (int) $license['id'] ];

		$this->assertCount( 1, $entries );
		$this->assertStringStartsWith( 'old.example, deleted ', $entries[0][0] );
		$this->assertTrue( $entries[0][2] );
		$this->assertStringNotContainsString( '#beltoft-service-', $entries[0][1] );
	}

	public function test_hosting_shows_the_site_addresses_not_the_preview_when_there_is_a_real_one(): void {
		global $wpdb;

		$license = $this->holding( 'hosting', array() );
		$id      = (int) Licenses::resources( (int) $license['id'] )[0]['id'];
		$table   = \BeltoftServices\Hosting\Schema::tables()['hosting_domains'];

		foreach ( array( array( 'abc.preview.example', 'preview' ), array( 'shop.example', 'external' ) ) as $i => $h ) {
			$wpdb->insert( $table, array( 'resource_id' => $id, 'site_id' => 1, 'host' => $h[0], 'kind' => $h[1], 'state' => 'live', 'created' => gmdate( 'Y-m-d H:i:s' ) ) );
		}

		$entries = $this->cells( array( $license ) )[ (int) $license['id'] ];

		$this->assertSame( array( 'shop.example' ), array_column( $entries, 0 ) );
	}

	public function test_without_an_answer_hosting_shows_no_names_and_no_error(): void {
		$license = $this->holding( 'hosting', array() );

		remove_all_filters( 'beltoft_services_licence_column_names' );

		$entries = $this->cells( array( $license ) )[ (int) $license['id'] ];

		$this->assertSame( array(), $entries );
	}

	public function test_the_bridge_adds_the_container_version_row_only_for_hosting(): void {
		$license = $this->holding( 'hosting', array() );
		$r       = \BeltoftServices\Resource::find( (int) Licenses::resources( (int) $license['id'] )[0]['id'] );

		$rows = \BeltoftServices\Hosting\AdminBridge::detail_rows( array(), $r );
		$this->assertArrayHasKey( 'Container files version', $rows );
		$this->assertSame( array(), \BeltoftServices\Hosting\AdminBridge::detail_rows( array(), 'nope' ) );
	}

	public function test_a_license_without_a_service_has_an_empty_cell(): void {
		$license = $this->service_license( $this->service_product(), array( 'fire' => false ) );

		$this->assertSame( array(), $this->cells( array( $license ) )[ (int) $license['id'] ] );
	}

	public function test_the_page_is_read_with_a_fixed_number_of_queries(): void {
		global $wpdb;

		$small = array( $this->holding( 'domain', array( 'domain' => 'a1.example' ) ), $this->holding( 'domain', array( 'domain' => 'a2.example' ), true ) );
		$big   = array();

		for ( $i = 0; $i < 8; $i++ ) {
			$big[] = $this->holding( 'domain', array( 'domain' => "b$i.example" ), 0 === $i % 2 );
		}

		$before = $wpdb->num_queries;
		$this->cells( $small );
		$one = $wpdb->num_queries - $before;

		$before = $wpdb->num_queries;
		$this->cells( $big );
		$many = $wpdb->num_queries - $before;

		$this->assertSame( $one, $many, 'the same number of reads for one license and for eight' );
	}

	public function test_the_class_is_only_a_listener_of_the_list_hooks(): void {
		$this->assertTrue( has_filter( 'beltoft_licenses_list_columns', array( LicenceServiceColumn::class, 'columns' ) ) !== false );
		$this->assertTrue( has_action( 'beltoft_licenses_list_page_rows', array( LicenceServiceColumn::class, 'prime' ) ) !== false );
	}
}
