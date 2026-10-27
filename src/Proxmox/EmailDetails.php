<?php
/**
 * The lines of the VM ready email.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the address, the login command, the operating system and the size of the server to the
 * ready email (beltoft_services_email_details). The password is never part of it: the email
 * only says that it is shown once in the account, while that is still possible. No Proxmox call.
 */
final class EmailDetails {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_email_details', array( __CLASS__, 'details' ), 10, 3 );
	}

	/**
	 * The lines for a VM's ready email.
	 *
	 * @param mixed $details  Lines so far.
	 * @param mixed $email_id Email id.
	 * @param mixed $r        Resource.
	 * @return mixed
	 */
	public static function details( $details, $email_id, $r ) {
		if ( ! is_array( $details ) || 'beltoft_services_ready' !== $email_id || ! $r instanceof Resource ) {
			return $details;
		}
		if ( DiskDriver::ID === $r->get( 'driver' ) ) {
			$server = Resource::for_license( (int) $r->get( 'server_license' ) );
			$details[ __( 'Extra disk', 'beltoft-services' ) ] = (int) $r->get( 'size_gb' ) . ' GB';
			if ( $server ) {
				$details[ __( 'Server', 'beltoft-services' ) ] = (string) $server->get( 'ip' );
			}
			$details[ __( 'Backups', 'beltoft-services' ) ] = __( 'Extra disks are not included in nightly backups.', 'beltoft-services' );
			return $details;
		}
		if ( SshKeyField::DRIVER !== $r->get( 'driver' ) ) {
			return $details;
		}

		$plan   = (array) $r->get( 'plan' );
		$ip     = (string) $r->get( 'ip' );
		$record = VmRecords::for_resource( $r->id() );
		$list   = null === $record ? array() : OsField::options_for( $record['cluster'] );
		$chosen = (int) $r->get( OsField::META );
		$chosen = $chosen > 0 ? $chosen : (int) ( $plan['template'] ?? 0 );

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$details[ __( 'IP address', 'beltoft-services' ) ] = $ip;
			$details[ __( 'Log in', 'beltoft-services' ) ]     = 'ssh ' . Provision::LOGIN_USER . '@' . $ip;
		}

		if ( isset( $list[ $chosen ] ) ) {
			$details[ __( 'Operating system', 'beltoft-services' ) ] = (string) $list[ $chosen ];
		}

		$details[ __( 'Size', 'beltoft-services' ) ] = sprintf(
			/* translators: 1: number of vCPUs, 2: memory in MB, 3: disk in GB */
			__( '%1$d vCPU, %2$d MB RAM, %3$d GB disk', 'beltoft-services' ),
			(int) ( $plan['cores'] ?? 0 ),
			(int) ( $plan['memory_mb'] ?? 0 ),
			Panels::disk_gb( $r )
		);

		if ( Access::revealable( $r ) ) {
			$details[ __( 'Password', 'beltoft-services' ) ] = sprintf(
				/* translators: %d: number of days the password can be revealed */
				_n( 'Shown once in your account, within %d day: use the link below, open your server and click "Show password once".', 'Shown once in your account, within %d days: use the link below, open your server and click "Show password once".', Access::REVEAL_DAYS, 'beltoft-services' ),
				Access::REVEAL_DAYS
			);
		}

		return $details;
	}
}
