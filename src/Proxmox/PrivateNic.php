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
		$nic = self::nic_params( $net, $address, $bridge );
		return is_wp_error( $nic ) ? $nic : $nic + array( 'ipconfig1' => 'ip=' . $address . '/28' );
	}

	/**
	 * Only the NIC, never ipconfig1. A customer server is not given cloud-init network config for
	 * the private NIC: changing cloud-init on a booted server regenerates its SSH host keys (T4).
	 * The guest address is written into the guest instead (PrivateAttach).
	 *
	 * @param array<string,mixed> $net A PrivateNets row.
	 * @return array{net1:string}|\WP_Error
	 */
	public static function nic_params( array $net, string $address, string $bridge ) {
		$cidr = (string) ( $net['cidr'] ?? '' );
		$vlan = (int) ( $net['vlan'] ?? 0 );
		if ( ! preg_match( Clusters::BRIDGE_PATTERN, $bridge ) || $vlan < 1 || $vlan > 4094 || PrivateNets::public_vlan_in_use( $vlan ) || ! PrivateNets::contains_host( $cidr, $address ) ) {
			return new \WP_Error( 'invalid_private_network', 'The private network is not configured.' );
		}
		return array( 'net1' => 'virtio,bridge=' . $bridge . ',tag=' . $vlan . ',firewall=1' );
	}

	/**
	 * Attach the NIC to a stopped, owned VM. A retry after a timeout accepts the exact configuration
	 * PVE already applied. A foreign occupied net1 is never overwritten; an old owner's matching
	 * private net1 is removed before a stopped VM is moved to its new owner's VLAN.
	 *
	 * With $ipconfig false (customer servers, plan B) only net1 is written, never ipconfig1, and a
	 * running VM is accepted: the caller restarts it afterwards, so whether PVE hot-plugs the NIC
	 * or keeps it pending does not matter.
	 *
	 * @return array{network:array<string,mixed>,address:string,changed:bool}|\WP_Error
	 */
	public static function attach( Resource $resource, Client $client, bool $ipconfig = true ) {
		$result = null;
		$locked = $resource->locked( static function () use ( $resource, $client, $ipconfig, &$result ) {
			$result = self::attach_locked( $resource, $client, $ipconfig );
			return true;
		} );
		return null === $locked ? new \WP_Error( 'network_busy', 'The VM resource is busy.' ) : $result;
	}

	/** Called only while holding the resource lock. */
	private static function attach_locked( Resource $resource, Client $client, bool $ipconfig ) {
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
		if ( $bridge === (string) ( $cluster['bridge'] ?? '' ) ) {
			return new \WP_Error( 'private_bridge_public', 'The private bridge matches the public bridge.' );
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
		$reservation = PrivateNets::for_resource( $resource->id() );
		if ( null !== $reservation && $reservation['user_id'] !== $user_id ) {
			if ( 'stopped' !== $verified['status'] ) {
				return new \WP_Error( 'private_transfer_requires_stop', 'Stop the VM before moving its private network to the new owner.' );
			}
			if ( $occupied ) {
				$old_params = self::params( $reservation, $reservation['address'], $bridge );
				if ( is_wp_error( $old_params ) || ! self::matches( $config, $old_params ) ) {
					return new \WP_Error( 'private_transfer_mismatch', 'The existing private NIC does not match the old owner reservation.' );
				}
			}
			// This read-only check avoids disconnecting the old NIC when the new owner has no
			// network/address at all. The later atomic reserve still handles a concurrent claim.
			if ( ! PrivateNets::capacity_for_user( $user_id ) ) {
				return new \WP_Error( 'network_unavailable', 'No private network is available for the new owner.' );
			}
			if ( $occupied ) {
				$path = '/nodes/' . rawurlencode( $verified['node'] ) . '/qemu/' . $record['vmid'] . '/config';
				$removed = $client->request( 'PUT', $path, array( 'delete' => 'net1,ipconfig1', 'digest' => (string) ( $config['digest'] ?? '' ) ) );
				$after_remove = Ownership::verify( $client, $record, $resource );
				if ( is_wp_error( $after_remove ) || ! empty( $after_remove['config']['net1'] ) || ! empty( $after_remove['config']['ipconfig1'] ) ) {
					return is_wp_error( $removed ) ? $removed : new \WP_Error( 'private_transfer_unconfirmed', 'The old private NIC could not be confirmed removed.' );
				}
				$config = $after_remove['config'];
				$occupied = false;
			}
			try {
				if ( ! PrivateNets::release_and_retire( $resource->id() ) ) {
					return new \WP_Error( 'network_busy', 'The old private reservation could not be released.' );
				}
			} catch ( \RuntimeException $e ) {
				return new \WP_Error( 'network_busy', 'The private network pool is busy.' );
			}
		}
		if ( $ipconfig && ! $occupied && 'stopped' !== $verified['status'] ) {
			return new \WP_Error( $reservation ? 'private_nic_pending' : 'vm_running', 'Stop the VM before attaching its private network.' );
		}
		// A pre-existing, foreign net1 must not consume a fresh customer VLAN or address.
		// Only a retry of a prior attach can already have our address reservation.
		if ( $occupied && null === PrivateNets::address_for_resource( $resource->id() ) ) {
			return new \WP_Error( 'private_nic_occupied', 'The VM already has a different second network interface.' );
		}
		try {
			$reserved = PrivateNets::reserve( $user_id, $resource->id() );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'network_busy', 'The private network pool is busy.' );
		}
		if ( null === $reserved ) {
			return new \WP_Error( 'network_unavailable', 'No private network or address is available.' );
		}
		$net = $reserved['network'];
		$address = $reserved['address'];
		$params = $ipconfig ? self::params( $net, $address, $bridge ) : self::nic_params( $net, $address, $bridge );
		if ( is_wp_error( $params ) ) {
			return $params;
		}
		if ( self::matches( $config, $params ) ) {
			if ( $resource->get( 'private_nic_pending' ) && 'stopped' !== $verified['status'] ) {
				return new \WP_Error( 'private_nic_pending', 'The private NIC change waits for the VM to stop and boot again.' );
			}
			if ( 'stopped' !== $verified['status'] ) {
				$current = $client->request( 'GET', '/nodes/' . rawurlencode( $verified['node'] ) . '/qemu/' . $record['vmid'] . '/config', array( 'current' => 1 ) );
				if ( is_wp_error( $current ) || ! self::matches( $current, $params ) ) {
					return new \WP_Error( 'private_nic_pending', 'The private NIC change waits for the VM to stop and boot again.' );
				}
			}
			if ( $resource->get( 'private_nic_pending' ) ) {
				$resource->set( array( 'private_nic_pending' => null ) );
			}
			return array( 'network' => $net, 'address' => $address, 'changed' => false );
		}
		if ( ! empty( $config['net1'] ) || ! empty( $config['ipconfig1'] ) ) {
			return new \WP_Error( 'private_nic_occupied', 'The VM already has a different second network interface.' );
		}
		$path = '/nodes/' . rawurlencode( $verified['node'] ) . '/qemu/' . $record['vmid'] . '/config';
		$answer = $client->request( 'PUT', $path, $params + array( 'digest' => (string) ( $config['digest'] ?? '' ) ) );
		// A timeout may mean PVE accepted the write. Verify the final state before returning an error.
		$after = Ownership::verify( $client, $record, $resource );
		if ( $ipconfig && ! is_wp_error( $after ) && 'stopped' !== $after['status'] ) {
			$resource->set( array( 'private_nic_pending' => 1 ) );
			return new \WP_Error( 'private_nic_pending', 'The VM started during the private NIC change; stop it before retrying.' );
		}
		if ( ! is_wp_error( $after ) && self::matches( $after['config'], $params ) ) {
			if ( $resource->get( 'private_nic_pending' ) ) {
				$resource->set( array( 'private_nic_pending' => null ) );
			}
			return array( 'network' => $net, 'address' => $address, 'changed' => true );
		}
		// A 400/403/404/422 is a definite refusal. A timeout or server error may have
		// applied the write, so preserve its reservation for a later readback/retry.
		if ( ! $resource->get( 'private_nic_pending' ) && is_wp_error( $answer ) && in_array( $answer->get_error_code(), array( 'http_400', 'http_403', 'http_404', 'http_422' ), true ) && ! is_wp_error( $after ) && empty( $after['config']['net1'] ) && empty( $after['config']['ipconfig1'] ) ) {
			try {
				if ( ! PrivateNets::abandon_address( $user_id, $resource->id() ) ) {
					return new \WP_Error( 'network_busy', 'The rejected private NIC reservation could not be released yet.' );
				}
			} catch ( \RuntimeException $e ) {
				return new \WP_Error( 'network_busy', 'The rejected private NIC reservation could not be released yet.' );
			}
			if ( $resource->get( 'private_nic_pending' ) ) {
				$resource->set( array( 'private_nic_pending' => null ) );
			}
		} else {
			// Ambiguous replies retain the reservation and make the retry obligation explicit.
			$resource->set( array( 'private_nic_pending' => 1 ) );
		}
		return is_wp_error( $answer ) ? $answer : new \WP_Error( 'private_nic_unconfirmed', 'The private NIC change could not be verified.' );
	}

	/** PVE may fill in the generated VirtIO MAC, so compare the effective properties. */
	public static function matches( array $config, array $wanted ): bool {
		if ( (string) ( $config['ipconfig1'] ?? '' ) !== (string) ( $wanted['ipconfig1'] ?? '' ) ) {
			return false;
		}
		$parts = NetConfig::parse( (string) ( $config['net1'] ?? '' ) );
		$expected = NetConfig::parse( $wanted['net1'] );
		return array_key_exists( 'virtio', $parts ) && ( $parts['bridge'] ?? '' ) === $expected['bridge'] && ( $parts['tag'] ?? '' ) === $expected['tag'] && ( $parts['firewall'] ?? '' ) === '1' && ! array_key_exists( 'trunks', $parts ) && ! array_key_exists( 'link_down', $parts );
	}
}
