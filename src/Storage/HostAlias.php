<?php
/**
 * The host name of our own for a storage box.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Alerts;
use BeltoftServices\Domains\ZoneWriter;
use BeltoftServices\Locks;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A CNAME from our own name (backup12.example.com) to the provider's host name, so a customer never reads the provider's
 * name. Off by default (setting storage_alias_enabled). One setting chooses the writer: a Cloudflare zone record (AliasDnsClients,
 * fake unless the DNS live constant, setting and token agree) or Domains\ZoneWriter on our own name servers (fake DNS in tests and staging). A name that is taken by something else is never
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
		$number = self::number( $r );
		$name   = $number > 0 ? StorageSettings::alias_name( $number ) : '';
		$zone = StorageSettings::alias_zone();
		$host = strtolower( rtrim( $host, '.' ) );

		if ( '' === $name || '' === $host || ! self::ours( $name ) ) {
			return '';
		}

		if ( 'cloudflare' === StorageSettings::alias_writer() ) {
			return self::publish_record( $r, $name, $host );
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

	/** Option: the next free alias number (backup1, backup2, ...). */
	public const NEXT_OPTION = 'beltoft_services_storage_alias_next';

	/**
	 * The box's alias number: a running count (backup1, backup2, ...), given once per box and kept (owner 2026-11-18: not the
	 * service id, which jumps). Given under a lock so two boxes built at the same time never share a number. 0 when the lock was
	 * not had (the alias step tries again later).
	 *
	 * @param Resource $r Resource.
	 */
	public static function number( Resource $r ): int {
		$n = (int) $r->get( 'storage_alias_number' );

		if ( $n > 0 ) {
			return $n;
		}

		list( $got, $given ) = Locks::with(
			Locks::name( 'storage-alias-number', 0 ),
			10,
			static function () use ( $r ): int {
				$fresh = Resource::find( $r->id() );
				$have  = $fresh ? (int) $fresh->get( 'storage_alias_number' ) : 0;

				if ( $have > 0 ) {
					return $have;
				}

				$next = max( 1, (int) get_option( self::NEXT_OPTION, 1 ) );
				update_option( self::NEXT_OPTION, $next + 1, false );

				return $r->set( array( 'storage_alias_number' => $next ) ) ? $next : 0;
			}
		);

		return $got ? (int) $given : 0;
	}

	/**
	 * Does a name match the alias pattern (backup{n}.{zone}: only digits for n)? Nothing else is ever written or removed.
	 *
	 * @param string $name Full host name.
	 */
	public static function ours( string $name ): bool {
		$pattern = StorageSettings::alias_pattern();
		$zone    = StorageSettings::alias_zone();

		if ( '' === $pattern || '' === $zone ) {
			return false;
		}

		$regex = '/^' . str_replace( array( preg_quote( '{n}', '/' ), preg_quote( '{zone}', '/' ) ), array( '[0-9]{1,12}', preg_quote( $zone, '/' ) ), preg_quote( $pattern, '/' ) ) . '$/D';

		return 1 === preg_match( $regex, strtolower( $name ) );
	}

	/**
	 * The record path: look up, create, or point an existing CNAME of ours at the new target. Any other record at the name is
	 * somebody else's and is left alone (staff are told).
	 *
	 * @param Resource $r    Resource.
	 * @param string   $name Alias.
	 * @param string   $host Target.
	 * @return string|\WP_Error
	 */
	private static function publish_record( Resource $r, string $name, string $host ) {
		$dns  = AliasDnsClients::for();
		$rows = $dns->find( $name );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( array() === $rows ) {
			$made = $dns->create( $name, $host );

			if ( is_wp_error( $made ) ) {
				return $made;
			}

			// Our own record of what we made: only this record may be changed or removed later.
			$r->set( array( 'storage_alias_record' => (string) $made['id'] ) );

			return $name;
		}

		$row = $rows[0];

		if ( 1 !== count( $rows ) || 'CNAME' !== $row['type'] ) {
			Alerts::admin( $r, sprintf( 'The host name alias %s was not made: another record is at that name. The customer sees the provider host name.', $name ) );

			return '';
		}

		// Update only a record we made for THIS box (its id is stored on the resource). Any other record, even a CNAME, is somebody's.
		$mine = '' !== (string) $r->get( 'storage_alias_record' ) && (string) $r->get( 'storage_alias_record' ) === (string) $row['id'];

		if ( ! $mine && ( $row['content'] !== $host || ! empty( $row['proxied'] ) ) ) {
			Alerts::admin( $r, sprintf( 'The host name alias %s was not made: a CNAME that is not ours is at that name. The customer sees the provider host name.', $name ) );

			return '';
		}

		if ( $mine && ( $row['content'] !== $host || ! empty( $row['proxied'] ) ) ) {
			$done = $dns->update( (string) $row['id'], $name, $host );

			return is_wp_error( $done ) ? $done : $name;
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

		if ( '' === $alias || '' === $zone || '' === $host || ! self::ours( $alias ) ) {
			return;
		}

		if ( 'cloudflare' === StorageSettings::alias_writer() ) {
			$dns  = AliasDnsClients::for();
			$rows = $dns->find( $alias );

			// Only a single CNAME to this box's host is ours.
			if ( ! is_wp_error( $rows ) && 1 === count( $rows ) && 'CNAME' === $rows[0]['type'] && $rows[0]['content'] === $host ) {
				$dns->delete( (string) $rows[0]['id'] );
			}

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
