<?php
/**
 * Resource's lookups answer what the SQL they replaced answered.
 *
 * Each test runs the old direct query (kept here as a reference copy) and the Resource call on the
 * same seeded rows and compares the ids and their order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;

/**
 * Old SQL against the licences API, on the same data.
 */
final class ResourceLicencesParityTest extends TestCase {

	/**
	 * Seeded activation ids by label.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Seeds live, closed, revoked-licence, null, unicode, list and other-kind rows.
	 */
	protected function setUp(): void {
		parent::setUp();

		$product = $this->service_product( 'fake', array(), array( 'max' => 40 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'max_activations' => 40 ) );
		$n       = 0;
		$add     = function ( string $label, array $meta, string $kind = 'fake' ) use ( $license, &$n ): void {
			$result = Licenses::add_resource( $license, $kind, 'parity-' . ( ++$n ), $meta );
			$this->assertTrue( $result['success'], $label );
			$this->ids[ $label ] = (int) $result['activation_id'];
		};

		$add( 'twelve', array( 'n' => 12 ) );
		$add( 'hundred12', array( 'n' => 112 ) );
		$add( 'string12', array( 'n' => '12' ) );
		$add( 'null', array( 'n' => null, 'other' => 1 ) );
		$add( 'unicode', array( 'u' => "Zo\u{eb} \u{2603} \u{fc}n\u{ef}", 'n' => 12 ) );
		$add( 'emptylist', array( 'l' => array() ) );
		$add( 'list', array( 'l' => array( 1, 2 ) ) );
		$add( 'closed', array( 'n' => 12 ) );
		$add( 'nometa', array() );
		$add( 'float', array( 'n' => 12.5 ) );
		$add( 'true', array( 'n' => true ) );
		$add( 'false', array( 'n' => false ) );
		$add( 'quote', array( 'q' => 'say "hi" 50%_off C:\\dir' ) );
		$add( 'other', array( 'n' => 12 ), 'fake2' );
		$add( 'last', array( 'n' => 12 ) );

		Licenses::close_resource( $license, 'fake', 'parity-' . 8 );

		// A revoked licence keeps its live activation rows: both the old query and the API ignore the licence status.
		$revoked = $this->service_license( $product, array( 'fire' => false, 'max_activations' => 5 ) );
		$result  = Licenses::add_resource( $revoked, 'fake', 'parity-revoked', array( 'n' => 12 ) );
		$this->assertTrue( $result['success'] );
		$this->ids['revoked'] = (int) $result['activation_id'];
		Licenses::update( (int) $revoked['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
	}

	/**
	 * The old query_ids() of Resource, as it was before the licences API.
	 *
	 * @param string[] $likes LIKE patterns, any of them.
	 * @return int[]
	 */
	private function old_query_ids( string $kind, array $likes, int $after, int $limit, string $order ): array {
		global $wpdb;

		$sql  = 'SELECT id FROM %i WHERE kind = %s AND deactivated_at IS NULL';
		$args = array( Install::tables()['activations'], $kind );

		if ( $likes ) {
			$sql .= ' AND (' . implode( ' OR ', array_fill( 0, count( $likes ), 'meta LIKE %s' ) ) . ')';
			$args = array_merge( $args, $likes );
		}

		if ( $after > 0 ) {
			$sql   .= ' AND id > %d';
			$args[] = $after;
		}

		$sql .= 'DESC' === strtoupper( $order ) ? ' ORDER BY id DESC' : ' ORDER BY id ASC';

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- reference copy.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * The old find_by_meta() of Resource.
	 *
	 * @param mixed $value One scalar or a list.
	 * @return int[]
	 */
	private function old_find_by_meta( string $kind, string $key, $value, string $order = 'ASC' ): array {
		global $wpdb;

		$likes = array();

		foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
			$needle = wp_json_encode( $key ) . ':' . wp_json_encode( $one );

			if ( is_string( $one ) ) {
				$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
			} else {
				$likes[] = '%' . $wpdb->esc_like( $needle . ',' ) . '%';
				$likes[] = '%' . $wpdb->esc_like( $needle . '}' ) . '%';
			}
		}

		return $likes ? $this->old_query_ids( $kind, $likes, 0, 0, $order ) : array();
	}

	/**
	 * The old any_meta_set() of Resource.
	 */
	private function old_any_meta_set( string $key ): bool {
		global $wpdb;

		$needle = wp_json_encode( $key ) . ':';

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE deactivated_at IS NULL AND meta LIKE %s AND meta NOT LIKE %s', Install::tables()['activations'], '%' . $wpdb->esc_like( $needle ) . '%', '%' . $wpdb->esc_like( $needle . 'null' ) . '%' ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_live_ids_same_ids_in_the_same_order_for_every_argument(): void {
		$all = $this->old_query_ids( 'fake', array(), 0, 0, 'ASC' );

		$this->assertGreaterThan( 10, count( $all ) );
		$this->assertNotContains( $this->ids['closed'], $all );
		$this->assertContains( $this->ids['revoked'], $all );

		foreach ( array( 'ASC', 'DESC', 'desc' ) as $order ) {
			foreach ( array( 0, $all[3] ) as $after ) {
				foreach ( array( 0, 2 ) as $limit ) {
					$this->assertSame(
						$this->old_query_ids( 'fake', array(), $after, $limit, $order ),
						Resource::live_ids( 'fake', $after, $limit, $order ),
						"order $order after $after limit $limit"
					);
				}
			}
		}

		$this->assertSame( $this->old_query_ids( 'fake2', array(), 0, 0, 'ASC' ), Resource::live_ids( 'fake2' ) );
		$this->assertSame( array(), Resource::live_ids( 'nothing' ) );
	}

	public function test_find_by_meta_same_ids_in_the_same_order(): void {
		$cases = array(
			array( 'n', 12 ),
			array( 'n', 112 ),
			array( 'n', 1 ),
			array( 'n', '12' ),
			array( 'n', 12.5 ),
			array( 'n', true ),
			array( 'n', false ),
			array( 'n', null ),
			array( 'n', array( 12, '12' ) ),
			array( 'n', array( 112, 12, null ) ),
			array( 'n', array() ),
			array( 'u', "Zo\u{eb} \u{2603} \u{fc}n\u{ef}" ),
			array( 'u', "Zo\u{eb}" ),
			array( 'q', 'say "hi" 50%_off C:\\dir' ),
			array( 'q', '50%_off' ),
			array( 'missing', 1 ),
		);

		foreach ( $cases as $case ) {
			foreach ( array( 'ASC', 'DESC' ) as $order ) {
				$this->assertSame(
					$this->old_find_by_meta( 'fake', $case[0], $case[1], $order ),
					Resource::find_by_meta( 'fake', $case[0], $case[1], $order ),
					$case[0] . ' ' . wp_json_encode( $case[1] ) . ' ' . $order
				);
			}
		}

		$this->assertSame( $this->old_find_by_meta( 'fake2', 'n', 12 ), Resource::find_by_meta( 'fake2', 'n', 12 ) );
	}

	public function test_find_with_list_same_ids_in_the_same_order(): void {
		global $wpdb;

		foreach ( array( 'l', 'n', 'missing' ) as $key ) {
			foreach ( array( 'ASC', 'DESC' ) as $order ) {
				$old = $this->old_query_ids( 'fake', array( '%' . $wpdb->esc_like( wp_json_encode( $key ) . ':[' ) . '%' ), 0, 0, $order );

				$this->assertSame( $old, Resource::find_with_list( 'fake', $key, $order ), "$key $order" );
			}
		}

		$this->assertSame( array( $this->ids['emptylist'], $this->ids['list'] ), Resource::find_with_list( 'fake', 'l' ) );
	}

	public function test_any_meta_set_same_answer(): void {
		foreach ( array( 'n', 'u', 'l', 'q', 'other', 'missing', 'o' ) as $key ) {
			$this->assertSame( $this->old_any_meta_set( $key ), Resource::any_meta_set( $key ), $key );
		}

		// Only a value of null does not count.
		$this->assertTrue( Resource::any_meta_set( 'other' ) );
	}

	public function test_any_meta_set_ignores_a_key_that_is_only_null(): void {
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );

		Licenses::add_resource( $license, 'fake', 'only-null', array( 'solo_key' => null ) );

		$this->assertSame( $this->old_any_meta_set( 'solo_key' ), Resource::any_meta_set( 'solo_key' ) );
		$this->assertFalse( Resource::any_meta_set( 'solo_key' ) );
	}

	public function test_meter_rows_same_as_the_joined_query(): void {
		global $wpdb;

		$product = $this->service_product( 'fake', array(), array( 'max' => 5 ) );
		$tables  = Install::tables();
		$horizon = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );

		foreach ( array( 'usage', 'order', 'usage' ) as $i => $source ) {
			$license = $this->service_license( $product, array( 'fire' => false, 'source' => $source ) );

			foreach ( array( 'live', 'closed-old', 'closed-new' ) as $name ) {
				$result = Licenses::add_resource( $license, 'fake', "meter-$i-$name" );
				$this->assertTrue( $result['success'] );
				$id = (int) $result['activation_id'];

				if ( 'closed-old' === $name ) {
					$wpdb->update( $tables['activations'], array( 'deactivated_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
				} elseif ( 'closed-new' === $name ) {
					$wpdb->update( $tables['activations'], array( 'deactivated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $id ) );
				}
			}
		}

		$old = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.activated_at, a.deactivated_at FROM %i a INNER JOIN %i l ON l.id = a.license_id WHERE a.kind <> 'site' AND l.source = 'usage' AND ( a.deactivated_at IS NULL OR a.deactivated_at >= %s ) ORDER BY a.id", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$tables['activations'],
				$tables['licenses'],
				$horizon
			),
			ARRAY_A
		);

		// The API returns the id as an int; Meter casts it anyway.
		$old = array_map( static fn( $r ) => array_merge( $r, array( 'id' => (int) $r['id'] ) ), $old );

		$this->assertCount( 4, $old );
		$this->assertSame( $old, Licenses::resources_of_source( 'usage', $horizon ) );
	}

	public function test_intro_coupon_licenses_of_a_product_same_as_the_slug_query(): void {
		global $wpdb;

		$slug = 'parity-slug';

		// More than one page of the licences list (200 a page), with users, guests and a revoked licence.
		for ( $i = 0; $i < 203; $i++ ) {
			Licenses::create(
				array(
					'product_slug' => $slug,
					'user_id'      => 0 === $i % 2 ? 0 : 1000 + $i,
					'email'        => "Guest$i@Example.com",
					'status'       => 0 === $i % 50 ? Licenses::STATUS_REVOKED : Licenses::STATUS_ACTIVE,
				)
			);
		}

		$old = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, email FROM %i WHERE product_slug = %s', Install::tables()['licenses'], $slug ), ARRAY_A );
		$new = array();

		for ( $page = 1; count( $new ) < count( $old ) && $page < 10; $page++ ) {
			list( $rows ) = Licenses::query( array( 'product_slug' => $slug, 'per_page' => 200, 'page' => $page ) );

			foreach ( $rows as $row ) {
				$new[] = array( 'user_id' => (string) $row['user_id'], 'email' => (string) $row['email'] );
			}
		}

		$key = static fn( array $a, array $b ): int => strcmp( $a['user_id'] . $a['email'], $b['user_id'] . $b['email'] );
		$old = array_map( static fn( $r ) => array( 'user_id' => (string) $r['user_id'], 'email' => (string) $r['email'] ), $old );
		usort( $old, $key );
		usort( $new, $key );

		$this->assertCount( 203, $old );
		$this->assertSame( $old, $new );
	}

	public function test_intro_coupon_held_finds_the_oldest_licence_past_the_first_page(): void {
		$product = $this->service_product( 'fake', array(), array( 'slug' => 'held-slug' ) );

		// The oldest licence is the last of the newest-first list: two full pages stand in front of it.
		Licenses::create( array( 'product_slug' => 'held-slug', 'user_id' => 4242, 'email' => 'Old.Holder@Example.com' ) );

		for ( $i = 0; $i < 401; $i++ ) {
			Licenses::create( array( 'product_slug' => 'held-slug', 'user_id' => 0, 'email' => "other$i@example.com" ) );
		}

		$this->assertTrue( \BeltoftServices\IntroCoupon::held( $product->get_id(), 4242 ) );
		$this->assertTrue( \BeltoftServices\IntroCoupon::held( $product->get_id(), 0, 'old.holder@example.com' ) );
		$this->assertFalse( \BeltoftServices\IntroCoupon::held( $product->get_id(), 4243, 'nobody@example.com' ) );
	}
}
