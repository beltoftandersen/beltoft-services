<?php
/**
 * The in-memory alias DNS writer for tests and staging.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Records in one option. It never reaches a network.
 */
final class FakeAliasDns implements AliasDns {

	public const OPTION = 'beltoft_services_storage_fake_dns';

	/**
	 * The records, keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function records(): array {
		$rows = get_option( self::OPTION, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Adds a record as it is, for a test that needs a foreign record.
	 *
	 * @param string $name    Name.
	 * @param string $type    Type.
	 * @param string $content Content.
	 */
	public static function seed( string $name, string $type, string $content ): void {
		$rows = self::records();
		$id   = 'r' . ( count( $rows ) + 1 );
		$rows[ $id ] = array( 'id' => $id, 'name' => strtolower( $name ), 'type' => $type, 'content' => strtolower( $content ), 'proxied' => false );
		update_option( self::OPTION, $rows, false );
	}

	public function find( string $name ) {
		return array_values( array_filter( self::records(), static fn( $r ) => $r['name'] === strtolower( $name ) ) );
	}

	public function create( string $name, string $target ) {
		$rows = self::records();
		$id   = 'r' . ( count( $rows ) + 1 );
		$rows[ $id ] = array( 'id' => $id, 'name' => strtolower( $name ), 'type' => 'CNAME', 'content' => strtolower( rtrim( $target, '.' ) ), 'proxied' => false );
		update_option( self::OPTION, $rows, false );

		return $rows[ $id ];
	}

	public function update( string $id, string $name, string $target ) {
		$rows = self::records();

		if ( ! isset( $rows[ $id ] ) ) {
			return new \WP_Error( 'alias_dns_not_found', 'There is no such record.' );
		}

		$rows[ $id ]['name']    = strtolower( $name );
		$rows[ $id ]['content'] = strtolower( rtrim( $target, '.' ) );
		update_option( self::OPTION, $rows, false );

		return true;
	}

	public function delete( string $id ) {
		$rows = self::records();
		unset( $rows[ $id ] );
		update_option( self::OPTION, $rows, false );

		return true;
	}
}
