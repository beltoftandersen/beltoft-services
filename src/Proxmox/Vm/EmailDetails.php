<?php
/**
 * The lines of the VM ready email.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Emails\ServiceEmail;
use BeltoftServices\Apps\AppLicence;
use BeltoftServices\Apps\AppLicenceDriver;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Proxmox\Db\DbAccess;
use BeltoftServices\Proxmox\Db\DbDisk;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Db\DbEngines;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Disk\DiskGuide;
use BeltoftServices\Proxmox\Disk\DiskMeta;
use BeltoftServices\Proxmox\Snapshot\SnapshotDriver;
use BeltoftServices\Proxmox\Snapshot\SnapshotExpiry;
use BeltoftServices\Proxmox\Snapshot\SnapshotMeta;
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
		add_filter( 'beltoft_services_email_values', array( __CLASS__, 'values' ), 10, 3 );
	}

	/**
	 * The server, disk, snapshot, app and database values of a service email (beltoft_services_email_values):
	 * every key for every email, with its empty default, so the templates never meet a missing one.
	 *
	 * @param mixed $values Values so far.
	 * @param mixed $r      Resource.
	 * @param mixed $data   Trigger data.
	 * @return mixed
	 */
	public static function values( $values, $r, $data ) {
		if ( ! is_array( $values ) || ! $r instanceof Resource ) {
			return $values;
		}

		$data    = is_array( $data ) ? $data : array();
		$driver  = (string) $r->get( 'driver' );
		$license = $r->license();

		if ( $license ) {
			$values['product_name'] = ServerName::email_label( $r, $license );
			$values['account_url']  = self::account_url( $values['account_url'] ?? '', $r, $license, $data );
		}

		$values['extra_disks']      = self::extra_disks( $r );
		$values['disk_server_url']  = DiskDriver::ID === $driver ? DiskGuide::server_url( $r ) : '';
		$values['disk_guide_url']   = DiskDriver::ID === $driver ? DiskGuide::guide_url( $r ) : '';
		$values['monitor_reason']   = in_array( $data['reason'] ?? '', array( 'down', 'disk', 'disk_urgent', 'cpu', 'db_down', 'db_disk' ), true ) ? $data['reason'] : 'down';
		$values['monitor_disk_percent'] = max( 0, min( 100, (int) ( $data['disk_percent'] ?? 0 ) ) );
		$values['monitor_cpu_percent']  = max( 0, min( 100, (int) ( $data['cpu_percent'] ?? 0 ) ) );
		$values['monitor_cpu_hours']    = max( 0, (int) ( $data['cpu_hours'] ?? 0 ) );
		$values['monitor_upgrade_url']  = ProxmoxDriver::ID === $driver ? self::upgrade_link( $r, (string) ( $data['reason'] ?? '' ) ) : '';
		$values['monitor_grow_url']     = DbDriver::ID === $driver && 'db_disk' === ( $data['reason'] ?? '' ) ? DbDisk::grow_url( $r ) : '';
		$values['monitor_disks_url']    = ProxmoxDriver::ID === $driver ? DiskGuide::card_url( $r ) : '';
		$values['monitor_cpu_since']    = isset( $data['cpu_since'] ) ? wp_date( 'H:i', (int) $data['cpu_since'] ) : '';
		$values['monitor_disk_used']    = max( 0, (int) ( $data['disk_used'] ?? 0 ) );
		$values['monitor_disk_total']   = max( 0, (int) ( $data['disk_total'] ?? 0 ) );
		$values['quarantine_signal']    = in_array( $data['quarantine_signal'] ?? '', array( 'scan', 'syn', 'udp' ), true ) ? $data['quarantine_signal'] : 'scan';
		$values['quarantine_counts']    = is_array( $data['quarantine_counts'] ?? null ) ? array_map( 'intval', $data['quarantine_counts'] ) : array();
		$values['quarantine_at']        = isset( $data['quarantine_at'] ) ? wp_date( ServiceEmail::DATE_TIME, (int) $data['quarantine_at'] ) : '';
		$values['app_domain']           = isset( $data['app_domain'] ) && is_string( $data['app_domain'] ) ? HostName::display( $data['app_domain'] ) : '';
		$values['app_www']              = isset( $data['app_www'] ) && is_string( $data['app_www'] ) ? HostName::display( $data['app_www'] ) : '';
		$values['app_www_state']        = isset( $data['app_www_state'] ) && is_string( $data['app_www_state'] ) ? $data['app_www_state'] : '';
		$values['app_ip']               = isset( $data['app_ip'] ) && is_string( $data['app_ip'] ) && false !== filter_var( $data['app_ip'], FILTER_VALIDATE_IP ) ? $data['app_ip'] : '';
		$values['app_name']             = (string) ( Apps::definition( (string) $r->get( 'app_id' ) )['name'] ?? '' );
		$values['snapshot_reason']      = in_array( $data['snapshot_reason'] ?? '', SnapshotExpiry::REASONS, true ) ? $data['snapshot_reason'] : 'expired';

		return $values;
	}

	/**
	 * Where the "view in your account" link of a Proxmox service goes: the page of the server the email is about
	 * (Disks tab for an extra disk, Snapshots for a snapshot pack or a snapshot notice), the Databases page for a
	 * database. A removed server has no page, so its link goes to the licence's tab.
	 *
	 * @param mixed                $url     URL so far.
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $license License row.
	 * @param array<string, mixed> $data    Trigger data.
	 * @return mixed
	 */
	private static function account_url( $url, Resource $r, array $license, array $data ) {
		$driver = (string) $r->get( 'driver' );
		$gone   = in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true );
		$server = null;
		$tab    = '';

		if ( DbDriver::ID === $driver && ! $gone ) {
			return \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Databases::class )->url( $license );
		}

		if ( DiskDriver::ID === $driver || SnapshotDriver::ID === $driver || AppLicenceDriver::ID === $driver ) {
			$server = Resource::for_license( (int) $r->get( 'server_license' ) );
			$tab    = DiskDriver::ID === $driver ? 'disks' : ( SnapshotDriver::ID === $driver ? 'snapshots' : 'app' );
		} elseif ( ProxmoxDriver::ID === $driver && ! $gone ) {
			$server = $r;
			$tab    = isset( $data['snapshot_reason'] ) ? 'snapshots' : ( isset( $data['app_domain'] ) ? 'app' : '' );
		} else {
			return $url;
		}

		$server_license = $server ? $server->license() : null;

		return $server_license ? \BeltoftServices\Account\ServerLinks::url( $server_license, $tab ) : $url;
	}

	/**
	 * The upgrade link of a usage email: the larger plan for what ran short, '' when there is none.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $reason Monitoring reason.
	 */
	private static function upgrade_link( Resource $r, string $reason ): string {
		$need = array( 'disk' => 'disk', 'disk_urgent' => 'disk', 'cpu' => 'cpu' )[ $reason ] ?? '';

		return '' === $need ? '' : Upgrades::link( $r, $need );
	}

	/**
	 * Sizes in GB of the extra disks that are deleted with a server, for its deletion warning.
	 *
	 * @param Resource $r Resource.
	 * @return int[]
	 */
	private static function extra_disks( Resource $r ): array {
		if ( ProxmoxDriver::ID !== $r->get( 'driver' ) ) {
			return array();
		}

		$sizes = array();

		foreach ( DiskMeta::for_server( $r ) as $disk ) {
			$size = max( (int) $disk->get( 'size_gb' ), (int) ( ( (array) $disk->get( 'plan' ) )['disk_gb'] ?? 0 ) );

			if ( $size > 0 ) {
				$sizes[] = $size;
			}
		}

		return $sizes;
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
				$details[ __( 'Server', 'beltoft-services' ) ] = self::server_name( $server );
			}
			$details[ __( 'Backups', 'beltoft-services' ) ] = __( 'Extra disks are not included in nightly backups.', 'beltoft-services' );
			return $details;
		}
		if ( AppLicenceDriver::ID === $r->get( 'driver' ) ) {
			$server = AppLicence::server_of( $r );
			$details[ __( 'App licence', 'beltoft-services' ) ] = AppLicence::tier_label( $r );
			if ( $server ) {
				$details[ __( 'Server', 'beltoft-services' ) ] = self::server_name( $server );
			}
			return $details;
		}
		if ( SnapshotDriver::ID === $r->get( 'driver' ) ) {
			$server = SnapshotMeta::server_of( $r );
			$days   = SnapshotMeta::days_of( $r );
			$days   = $days > 0 ? $days : max( 1, (int) \BeltoftServices\Settings::get( 'snapshot_paid_days' ) );
			$details[ __( 'Snapshot slots', 'beltoft-services' ) ] = '+' . SnapshotMeta::slots_of( $r );
			if ( $server ) {
				$details[ __( 'Server', 'beltoft-services' ) ] = self::server_name( $server );
			}
			/* translators: %d: days a snapshot is kept */
			$details[ __( 'Kept', 'beltoft-services' ) ] = sprintf( _n( 'Snapshots are kept %d day.', 'Snapshots are kept %d days.', $days, 'beltoft-services' ), $days );
			return $details;
		}
		if ( DbDriver::ID === $r->get( 'driver' ) ) {
			return self::database( $details, $r );
		}
		if ( ProxmoxDriver::ID !== $r->get( 'driver' ) ) {
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

	/**
	 * What a customer calls a server: its name, else its product name.
	 *
	 * @param Resource $server Server.
	 */
	private static function server_name( Resource $server ): string {
		$license = $server->license();

		return $license ? ServerName::email_label( $server, $license ) : (string) $server->get( 'ip' );
	}

	/**
	 * The lines of a database's ready email: engine, where to connect and where the password and CA certificate are.
	 * Never the password.
	 *
	 * @param array<string, string> $details Lines so far.
	 * @param Resource              $r       Database.
	 * @return array<string, string>
	 */
	private static function database( array $details, Resource $r ): array {
		$plan    = (array) $r->get( 'plan' );
		$engine  = DbEngines::for_resource( $r );
		$address = (string) $r->get( 'db_address' );
		$public  = DbAccess::public_address( $r );

		$details[ __( 'Engine', 'beltoft-services' ) ] = $engine->label( $plan );

		if ( false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$details[ __( 'Host (private network)', 'beltoft-services' ) ] = $address;
		}

		if ( '' !== $public ) {
			$details[ __( 'Host (public address)', 'beltoft-services' ) ] = $public;
		}

		$details[ __( 'Port', 'beltoft-services' ) ]          = (string) $engine->port();
		$details[ __( 'Database name', 'beltoft-services' ) ] = $engine->database();
		$details[ __( 'User name', 'beltoft-services' ) ]     = $engine->login();
		$details[ __( 'TLS', 'beltoft-services' ) ]           = __( 'Required', 'beltoft-services' );
		$details[ __( 'Password', 'beltoft-services' ) ]      = __( 'Not sent by email. In your account, open Databases, your database, and click "Show password once".', 'beltoft-services' );
		$details[ __( 'CA certificate', 'beltoft-services' ) ] = __( 'In your account, on the page of your database, under "Download CA certificate".', 'beltoft-services' );

		return $details;
	}
}
