<?php
/**
 * Abuse lookup: who holds, or held, an address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only. Joins the IP pool, the private network allocations and the holding log to the
 * resource, license, customer and product.
 *
 * An address held before the log existed has no log row: it is reported from the pool, starting at
 * its resource's activation, and flagged in the note.
 */
final class IpWho {

	/**
	 * Holdings of an address.
	 *
	 * @param string $ip Address (IPv4).
	 * @param ?int   $at Unix time: only the holding that covered it. Null lists every holding.
	 * @return array<int, array<string, string>> Oldest first; empty when nobody held it.
	 */
	public static function lookup( string $ip, ?int $at = null ): array {
		$holdings = array();
		$open     = array();

		foreach ( IpHistory::for_address( $ip ) as $row ) {
			$holdings[] = array(
				'kind'        => $row['kind'],
				'resource_id' => $row['resource_id'],
				'from'        => $row['started_at'],
				'until'       => $row['ended_at'],
				'note'        => '',
			);

			if ( null === $row['ended_at'] ) {
				$open[ $row['kind'] . ':' . $row['resource_id'] ] = true;
			}
		}

		// The current holder from the allocation tables; the log may not know it (held before the log began).
		$pool    = IpPool::find( $ip );
		$current = array();

		if ( $pool && ! empty( $pool['resource_id'] ) ) {
			$current[] = array( 'public', (int) $pool['resource_id'] );
		}

		$private = PrivateNets::resource_for_address( $ip );

		if ( null !== $private ) {
			$current[] = array( 'private', $private );
		}

		foreach ( $current as list( $kind, $resource_id ) ) {
			if ( isset( $open[ $kind . ':' . $resource_id ] ) ) {
				continue;
			}

			$activation = Licenses::activation( $resource_id );
			$holdings[] = array(
				'kind'        => $kind,
				'resource_id' => $resource_id,
				'from'        => $activation ? (string) $activation['activated_at'] : '',
				'until'       => null,
				'note'        => 'held before holdings were logged; start is the resource activation',
			);
		}

		$out = array();

		foreach ( $holdings as $holding ) {
			if ( null !== $at ) {
				$from  = '' === $holding['from'] ? 0 : (int) strtotime( $holding['from'] . ' UTC' );
				$until = null === $holding['until'] ? PHP_INT_MAX : (int) strtotime( $holding['until'] . ' UTC' );

				if ( $at < $from || $at >= $until ) {
					continue;
				}
			}

			$out[] = self::describe( $ip, $holding );
		}

		return $out;
	}

	/**
	 * A holding with its resource, license, customer and product.
	 *
	 * @param string               $ip      Address.
	 * @param array<string, mixed> $holding Holding.
	 * @return array<string, string>
	 */
	private static function describe( string $ip, array $holding ): array {
		$resource = Resource::find( (int) $holding['resource_id'] );
		$license  = $resource ? $resource->license() : array();
		$user_id  = (int) ( $license['user_id'] ?? 0 );
		$user     = $user_id > 0 ? get_userdata( $user_id ) : false;
		$product  = (int) ( $license['product_id'] ?? 0 );
		$title    = $product > 0 ? get_the_title( $product ) : '';

		return array(
			'ip'          => $ip,
			'kind'        => (string) $holding['kind'],
			'resource_id' => (string) $holding['resource_id'],
			'license_id'  => (string) ( $license['id'] ?? '' ),
			'customer_id' => $user_id > 0 ? (string) $user_id : '',
			'name'        => $user ? (string) $user->display_name : '',
			'email'       => $user ? (string) $user->user_email : (string) ( $license['email'] ?? '' ),
			'product'     => '' !== $title ? $title : (string) ( $license['product_slug'] ?? '' ),
			'state'       => $resource ? (string) $resource->get( 'state' ) : '',
			'from'        => (string) $holding['from'],
			'until'       => (string) ( $holding['until'] ?? '' ),
			'note'        => (string) $holding['note'],
		);
	}
}
