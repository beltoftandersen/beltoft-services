<?php
/**
 * Read-only report for the IP filter rollout: what each VM should have, what Proxmox has, and whether the filter is on.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Only GET calls. Run before and after turning the cluster's fw_ipfilter on.
 */
final class IpfilterReport {

	/**
	 * One row per VM of the cluster.
	 *
	 * @param string $cluster_id Cluster id.
	 * @param Client $client     Client of the cluster.
	 * @return array<int, array<string, string>>
	 */
	public static function rows( string $cluster_id, Client $client ): array {
		$out = array();

		foreach ( VmRecords::rows_for_cluster( $cluster_id ) as $record ) {
			$r    = Resource::find( (int) $record['resource_id'] );
			$base = '/nodes/' . rawurlencode( $record['node'] ) . '/qemu/' . (int) $record['vmid'];
			$row  = array(
				'vmid'     => (string) $record['vmid'],
				'license'  => $r ? (string) ( $r->license()['id'] ?? '' ) : '',
				'expected' => '',
				'actual'   => '',
				'ipfilter' => '',
				'status'   => '',
			);

			$config = $client->request( 'GET', $base . '/config' );

			if ( null === $r || is_wp_error( $config ) || ! is_array( $config ) ) {
				$row['status'] = 'unreadable: no resource or VM config';
				$out[]         = $row;
				continue;
			}

			$expected = Firewall::expected_ipsets( $r, ! empty( $config['net1'] ) );
			$actual   = self::actual( $client, $base . '/firewall' );
			$options  = $client->request( 'GET', $base . '/firewall/options' );

			if ( null === $actual || is_wp_error( $options ) ) {
				$row['status'] = 'unreadable: firewall';
				$out[]         = $row;
				continue;
			}

			$on = is_array( $options ) && 1 === (int) ( $options['ipfilter'] ?? 0 );

			$row['expected'] = null === $expected ? '-' : self::text( $expected );
			$row['actual']   = $actual ? self::text( $actual ) : '-';
			$row['ipfilter'] = $on ? 'on' : 'off';

			if ( null === $expected ) {
				$row['status'] = $on ? 'mismatch: filter on but no address known' : 'no filter possible (address not known)';
			} elseif ( $expected !== $actual ) {
				$row['status'] = $on ? 'mismatch: filter on and the sets differ (traffic is cut)' : 'mismatch: sets differ';
			} else {
				$row['status'] = $on ? 'ok' : 'sets ready, filter off';
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The ipfilter sets Proxmox holds: name => the one address, or the entries joined when they are not one.
	 *
	 * @param Client $client Client.
	 * @param string $base   Firewall path.
	 * @return array<string, string>|null Null when unreadable.
	 */
	private static function actual( Client $client, string $base ): ?array {
		$list = $client->request( 'GET', $base . '/ipset' );

		if ( is_wp_error( $list ) ) {
			return null;
		}

		$sets = array();

		foreach ( (array) $list as $set ) {
			$name = (string) ( $set['name'] ?? '' );

			if ( 0 !== strpos( $name, 'ipfilter-net' ) ) {
				continue;
			}

			$entries = $client->request( 'GET', $base . '/ipset/' . $name );

			if ( is_wp_error( $entries ) ) {
				return null;
			}

			$cidrs = array();

			foreach ( (array) $entries as $entry ) {
				$cidrs[] = preg_replace( '#/32$#', '', (string) ( $entry['cidr'] ?? '' ) );
			}

			$sets[ $name ] = $cidrs ? implode( ',', $cidrs ) : '(empty)';
		}

		ksort( $sets );

		return $sets;
	}

	/**
	 * Set text for the table.
	 *
	 * @param array<string, string> $sets Name => address.
	 */
	private static function text( array $sets ): string {
		$parts = array();

		foreach ( $sets as $name => $address ) {
			$parts[] = substr( $name, 9 ) . '=' . $address;
		}

		return implode( ' ', $parts );
	}
}
