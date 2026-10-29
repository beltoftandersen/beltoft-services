<?php
/**
 * The VM shape for a privately reachable PostgreSQL service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * Pure configuration until the database driver and guest bootstrap are wired. It deliberately
 * has no public NIC, gateway, password, or live Proxmox call.
 */
final class PostgresProfile {

	/**
	 * Parameters for a VM's private interface and capacity. A bridge name is operator supplied;
	 * an address must be inside the supplied /28, not the reserved .0/.1/.15. The caller must
	 * obtain that address from PrivateNets::address() for the owning resource.
	 *
	 * @param array<string,mixed> $net PrivateNets row.
	 * @return array{config:array<string,int|string>,requirements:array{disk_gb:int}}|\WP_Error
	 */
	public static function vm( array $net, string $address, string $bridge, int $cores, int $memory_mb, int $disk_gb ) {
		$nic = PrivateNic::params( $net, $address, $bridge );
		if ( is_wp_error( $nic ) ) {
			return $nic;
		}

		if ( $cores < 1 || $cores > 32 || $memory_mb < 1024 || $memory_mb > 131072 || $disk_gb < 20 || $disk_gb > 4096 ) {
			return new \WP_Error( 'invalid_database_plan', 'The PostgreSQL VM size is outside the supported range.' );
		}

		return array(
			'config' => array_merge( $nic, array( 'cores' => $cores, 'memory' => $memory_mb ) ),
			'requirements' => array( 'disk_gb' => $disk_gb ),
		);
	}

	/**
	 * A display-only connection string, without a secret. The real password is revealed once
	 * through the existing encrypted credential flow after the guest has been initialized.
	 */
	public static function connection( string $address, string $database, string $role ): ?string {
		if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || ! preg_match( '/^[a-z][a-z0-9_]{0,62}$/', $database ) || ! preg_match( '/^[a-z][a-z0-9_]{0,62}$/', $role ) ) {
			return null;
		}
		return 'postgresql://' . $role . ':PASSWORD@' . $address . ':5432/' . $database . '?sslmode=require';
	}
}
