<?php
/**
 * The hosting part of the "Service" column of WooCommerce, Licenses (moved here from AdminLicenceServiceColumnTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftLicenses\Licenses;
use BeltoftServices\Tests\TestCase;

/**
 * Site addresses and detail rows of a hosting licence.
 */
final class AdminColumnTest extends TestCase {

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
}
