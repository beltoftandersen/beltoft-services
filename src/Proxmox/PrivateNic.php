<?php
/**
 * A private second NIC on an owned Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * This writer is deliberately not exposed as a customer action yet. The host bridge and guest
 * configuration need live checks before a running customer's VM can use it.
 */
final class PrivateNic {

	/**
	 * The NIC parameters for an allocated address. No gateway goes on the private interface.
	 *
	 * @param array<string,mixed> $net A PrivateNets row.
	 * @return array{net1:string,ipconfig1:string}|\WP_Error
	 */
	public static function params( array $net, string $address, string $bridge ) {
		$cidr = (string) ( $net['cidr'] ?? '' );
		$vlan = (int) ( $net['vlan'] ?? 0 );
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_.-]{0,31}$/', $bridge ) || $vlan < 1 || $vlan > 4094 || ! preg_match( '#^([0-9.]+)/28$#', $cidr, $match ) || false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return new \WP_Error( 'invalid_private_network', 'The private network is not configured.' );
		}
		$base    = (int) sprintf( '%u', ip2long( $match[1] ) );
		$ip      = (int) sprintf( '%u', ip2long( $address ) );
		$private = ( $base & 0xff000000 ) === 0x0a000000 || ( $base & 0xfff00000 ) === 0xac100000 || ( $base & 0xffff0000 ) === 0xc0a80000;
		if ( ! $private || 0 !== ( $base & 15 ) || $ip < $base + 2 || $ip > $base + 14 ) {
			return new \WP_Error( 'invalid_private_address', 'The address is outside the private network.' );
		}
		return array(
			'net1'      => 'virtio,bridge=' . $bridge . ',tag=' . $vlan . ',firewall=1',
			'ipconfig1' => 'ip=' . $address . '/28',
		);
	}

	/**
	 * Attach the NIC to a stopped, owned VM. A retry after a timeout accepts the exact configuration
	 * PVE already applied. An occupied net1 or ipconfig1 is never overwritten.
	 *
	 * @return array{network:array<string,mixed>,address:string,changed:bool}|\WP_Error
	 */
	public static function attach( Resource $resource, Client $client ) {
		if ( ! $resource->is_valid() || 'deleted' === $resource->get( 'state' ) ) {
			return new \WP_Error( 'invalid_resource', 'The VM resource is not active.' );
		}
		$record = VmRecords::for_resource( $resource->id() );
		if ( null === $record ) {
			return new \WP_Error( 'not_recorded', 'The VM is not recorded for this service.' );
		}
		$cluster = Clusters::get( (string) $record['cluster'] );
		$bridge  = is_array( $cluster ) ? (string) ( $cluster['private_bridge'] ?? '' ) : '';
		if ( '' === $bridge ) {
			return new \WP_Error( 'private_bridge_missing', 'The cluster has no private network bridge configured.' );
		}
		$verified = Ownership::verify( $client, $record, $resource );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( '' !== $verified['lock'] ) {
			return new \WP_Error( 'vm_locked', 'The VM has a Proxmox task lock.' );
		}
		$license = $resource->license();
		$user_id = (int) ( $license['user_id'] ?? 0 );
		if ( $user_id < 1 ) {
			return new \WP_Error( 'no_owner', 'The VM has no customer owner.' );
		}
		$config = $verified['config'];
		$occupied = ! empty( $config['net1'] ) || ! empty( $config['ipconfig1'] );
		if ( ! $occupied && 'stopped' !== $verified['status'] ) {
			return new \WP_Error( 'vm_running', 'Stop the VM before attaching its private network.' );
		}
		// A pre-existing, foreign net1 must not consume a fresh customer VLAN or address.
		// Only a retry of a prior attach can already have our address reservation.
		if ( $occupied && null === PrivateNets::address_for_resource( $resource->id() ) ) {
			return new \WP_Error( 'private_nic_occupied', 'The VM already has a different second network interface.' );
		}
		try {
			$net = PrivateNets::hold( $user_id );
			$address = $net ? PrivateNets::address( $user_id, $resource->id() ) : null;
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'network_busy', 'The private network pool is busy.' );
		}
		if ( null === $net || null === $address ) {
			return new \WP_Error( 'network_unavailable', 'No private network or address is available.' );
		}
		$params = self::params( $net, $address, $bridge );
		if ( is_wp_error( $params ) ) {
			return $params;
		}
		if ( self::matches( $config, $params ) ) {
			return array( 'network' => $net, 'address' => $address, 'changed' => false );
		}
		if ( ! empty( $config['net1'] ) || ! empty( $config['ipconfig1'] ) ) {
			return new \WP_Error( 'private_nic_occupied', 'The VM already has a different second network interface.' );
		}
		$path = '/nodes/' . rawurlencode( $verified['node'] ) . '/qemu/' . $record['vmid'] . '/config';
		$answer = $client->request( 'PUT', $path, $params + array( 'digest' => (string) ( $config['digest'] ?? '' ) ) );
		// A timeout may mean PVE accepted the write. Verify the final state before returning an error.
		$after = Ownership::verify( $client, $record, $resource );
		if ( ! is_wp_error( $after ) && self::matches( $after['config'], $params ) ) {
			return array( 'network' => $net, 'address' => $address, 'changed' => true );
		}
		return is_wp_error( $answer ) ? $answer : new \WP_Error( 'private_nic_unconfirmed', 'The private NIC change could not be verified.' );
	}

	/** PVE may fill in the generated VirtIO MAC, so compare the effective properties. */
	private static function matches( array $config, array $wanted ): bool {
		if ( (string) ( $config['ipconfig1'] ?? '' ) !== $wanted['ipconfig1'] ) {
			return false;
		}
		$parts = array();
		foreach ( explode( ',', (string) ( $config['net1'] ?? '' ) ) as $part ) {
			$pair = explode( '=', $part, 2 );
			$parts[ $pair[0] ] = $pair[1] ?? '';
		}
		$expected = array();
		foreach ( explode( ',', $wanted['net1'] ) as $part ) {
			$pair = explode( '=', $part, 2 );
			$expected[ $pair[0] ] = $pair[1] ?? '';
		}
		return isset( $parts['virtio'] ) && ( $parts['bridge'] ?? '' ) === $expected['bridge'] && ( $parts['tag'] ?? '' ) === $expected['tag'] && ( $parts['firewall'] ?? '' ) === '1' && ! isset( $parts['trunks'] ) && ! isset( $parts['link_down'] );
	}
}
