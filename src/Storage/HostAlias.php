<?php
/**
 * The host name of our own for a storage box.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Alerts;
use BeltoftServices\Domains\ZoneWriter;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A CNAME from our own name (backup12.example.com) to the provider's host name, so a customer never reads the provider's
 * name. Off by default (setting storage_alias_enabled). The only DNS write goes through Domains\ZoneWriter, which in tests
 * and staging runs against the fake DNS (Domains\Clients::dns() chooses). A name that is taken by something else is never
 * touched. A refusal never blocks the box: it keeps working on the provider host.
 */
final class HostAlias {

	/**
	 * Writes the CNAME for a box.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $host The provider's host name of the box.
	 * @return string|\WP_Error The alias, '' when there is none (off, or refused: staff are told), an error to retry.
	 */
	public static function publish( Resource $r, string $host ) {
		$name = StorageSettings::alias_name( $r->id() );
		$zone = StorageSettings::alias_zone();
		$host = strtolower( rtrim( $host, '.' ) );

		if ( '' === $name || '' === $host ) {
			return '';
		}

		$row    = array( 'name' => substr( $name, 0, -strlen( $zone ) - 1 ), 'type' => 'CNAME', 'ttl' => 3600, 'value' => $host );
		$result = ZoneWriter::write( null, $zone, array( $row ), ZoneWriter::cname_policy( $host ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'ok' !== $result['status'] ) {
			Alerts::admin( $r, sprintf( 'The host name alias %1$s was not made (%2$s). The customer sees the provider host name.', $name, $result['reason'] ) );

			return '';
		}

		return $name;
	}

	/**
	 * Removes the CNAME of a box, only while it still points at the box's provider host.
	 *
	 * @param Resource $r Resource.
	 */
	public static function remove( Resource $r ): void {
		$alias = strtolower( (string) $r->get( 'storage_alias' ) );
		$host  = strtolower( rtrim( (string) $r->get( 'storage_host' ), '.' ) );
		$zone  = StorageSettings::alias_zone();

		if ( '' === $alias || '' === $zone || '' === $host || ! str_ends_with( $alias, '.' . $zone ) ) {
			return;
		}

		ZoneWriter::remove(
			null,
			$zone,
			static function ( array $data ) use ( $alias, $host ) {
				foreach ( (array) ( $data['rrsets'] ?? array() ) as $set ) {
					if ( 'CNAME' !== ( $set['type'] ?? '' ) || strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) !== $alias ) {
						continue;
					}

					$records = (array) ( $set['records'] ?? array() );

					if ( 1 !== count( $records ) || strtolower( rtrim( (string) ( $records[0]['content'] ?? '' ), '.' ) ) !== $host ) {
						return array();
					}

					return array( array( 'name' => $set['name'], 'type' => 'CNAME', 'changetype' => 'DELETE' ) );
				}

				return array();
			}
		);
	}
}
