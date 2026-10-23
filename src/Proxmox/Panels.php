<?php
/**
 * The VM panels: what the customer and the admin see.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Renders ProxmoxDriver::render_account() and render_admin(). Neither makes a Proxmox call: the
 * power state is the one kept by the last status refresh, everything else is resource meta and
 * this plugin's tables. The customer panel never holds the password.
 */
final class Panels {

	/**
	 * Script handle of the panel's VM script.
	 */
	public const HANDLE = 'beltoft-services-vm';

	/**
	 * Hooks the script.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Enqueues the VM script after the generic panel script.
	 *
	 * @param mixed $parent Handle of the generic panel script.
	 */
	public static function assets( $parent ): void {
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/vm.js', BELTOFT_SERVICES_FILE ), array( is_string( $parent ) ? $parent : '' ), BELTOFT_SERVICES_VERSION, true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesVm',
			array(
				'strings' => array(
					'queued'    => __( 'Your request is on its way. This can take a minute.', 'beltoft-services' ),
					'failed'    => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'expired'   => __( 'Your session has expired. Please reload the page.', 'beltoft-services' ),
					'tooMany'   => __( 'Too many requests. Please wait a while and try again.', 'beltoft-services' ),
					'gone'      => __( 'The password has already been shown or is no longer available.', 'beltoft-services' ),
					'reinstall' => __( 'Reinstalling erases everything on the server. Continue?', 'beltoft-services' ),
					'restore'   => __( 'Restoring replaces everything on the server with this backup, and the password and SSH key go back to the backup\'s. Continue?', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * The boot disk size to show, in GB: the larger of the size recorded after the last build or
	 * resize and the plan's. A disk is never shrunk and a plan never asks for less than the VM
	 * has (servers cannot be downgraded), so the larger one is the disk the VM has now, and a
	 * stale record can never hide an upgrade.
	 *
	 * @param Resource $r Resource.
	 */
	public static function disk_gb( Resource $r ): int {
		return max( (int) $r->get( 'disk_gb' ), (int) ( ( (array) $r->get( 'plan' ) )['disk_gb'] ?? 0 ) );
	}

	/**
	 * The customer's part of the panel.
	 *
	 * @param Resource $r Resource.
	 */
	public static function account( Resource $r ): void {
		$plan   = (array) $r->get( 'plan' );
		$record = VmRecords::for_resource( $r->id() );
		$ip     = (string) $r->get( 'ip' );
		$status = Operations::cached_status( $r->id() );
		$list   = null === $record ? array() : OsField::options_for( $record['cluster'] );
		$chosen = (int) $r->get( OsField::META );
		$chosen = $chosen > 0 ? $chosen : (int) ( $plan['template'] ?? 0 );
		// A template that is no longer listed cannot be preselected: the cluster's default is.
		$select = isset( $list[ $chosen ] ) || null === $record ? $chosen : Clusters::default_template( $record['cluster'] );
		$state  = array(
			'ip'         => false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '',
			'plan'       => array(
				'cores'     => (int) ( $plan['cores'] ?? 0 ),
				'memory_mb' => (int) ( $plan['memory_mb'] ?? 0 ),
				'disk_gb'   => self::disk_gb( $r ),
			),
			'power'      => null === $status ? '' : (string) $status['power'],
			'os_label'   => (string) ( $list[ $chosen ] ?? '' ),
			'os_options' => $list,
			'os_current' => $select,
			'has_key'    => Actions::has_key( $r ),
			'can_reveal' => Access::revealable( $r ),
			'forms'      => $r->accepts_actions(),
			'ssh_user'   => Provision::LOGIN_USER,
			'backups'    => Backups::panel( $r ),
		);

		( static function ( array $vars ): void {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, a fixed list built above.
			extract( $vars, EXTR_SKIP );
			include BELTOFT_SERVICES_PATH . 'templates/account/vm.php';
		} )( $state );
	}

	/**
	 * One line for the admin: whether backups are on, the last good one, how many are kept, the last error.
	 *
	 * @param Resource $r Resource.
	 */
	private static function backups_line( Resource $r ): string {
		$enabled = Backups::enabled( $r );

		if ( ! $enabled && ! $r->get( 'backup_ok_at' ) && ! $r->get( 'backup_error' ) ) {
			return '';
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$ok     = (int) $r->get( 'backup_ok_at' );
		$kept   = $r->get( 'backup_list' );
		$parts  = array(
			$enabled
				/* translators: %d: days of backups kept */
				? sprintf( _n( 'daily, kept %d day', 'daily, kept %d days', Backups::days( $r ), 'beltoft-services' ), Backups::days( $r ) )
				: __( 'off', 'beltoft-services' ),
			$ok > 0
				/* translators: %s: date and time */
				? sprintf( __( 'last OK %s', 'beltoft-services' ), wp_date( $format, $ok ) )
				: __( 'no backup yet', 'beltoft-services' ),
		);

		if ( is_array( $kept ) ) {
			/* translators: %d: number of backups kept */
			$parts[] = sprintf( __( '%d kept', 'beltoft-services' ), count( $kept ) );
		}

		if ( '' !== (string) $r->get( 'backup_error' ) ) {
			/* translators: %s: error text */
			$parts[] = sprintf( __( 'last error: %s', 'beltoft-services' ), (string) $r->get( 'backup_error' ) );
		}

		return implode( ', ', $parts );
	}

	/**
	 * The admin's part: where the VM is and what it is doing. No password, no Proxmox call.
	 *
	 * @param Resource $r Resource.
	 */
	public static function admin( Resource $r ): void {
		$record   = VmRecords::for_resource( $r->id() );
		$status   = Operations::cached_status( $r->id() );
		$problems = Alerts::problems();
		$rows     = array(
			__( 'Cluster', 'beltoft-services' )       => null === $record ? '' : $record['cluster'],
			__( 'Node', 'beltoft-services' )          => null === $record ? '' : $record['node'],
			__( 'VMID', 'beltoft-services' )          => null === $record ? '' : (string) $record['vmid'],
			__( 'IP address', 'beltoft-services' )    => (string) $r->get( 'ip' ),
			__( 'Ownership tag', 'beltoft-services' ) => Ownership::tag( $r ),
			__( 'Power', 'beltoft-services' )         => null === $status ? '' : (string) $status['power'],
			__( 'Size', 'beltoft-services' )          => sprintf(
				/* translators: 1: number of vCPUs, 2: memory in MB, 3: disk in GB */
				__( '%1$d vCPU, %2$d MB RAM, %3$d GB disk', 'beltoft-services' ),
				(int) ( ( (array) $r->get( 'plan' ) )['cores'] ?? 0 ),
				(int) ( ( (array) $r->get( 'plan' ) )['memory_mb'] ?? 0 ),
				self::disk_gb( $r )
			),
			__( 'Template', 'beltoft-services' )      => (string) ( (int) $r->get( OsField::META ) > 0 ? $r->get( OsField::META ) : ( (array) $r->get( 'plan' ) )['template'] ?? '' ),
			__( 'Backups', 'beltoft-services' )       => self::backups_line( $r ),
			__( 'Last error', 'beltoft-services' )    => (string) ( $problems[ $r->id() ]['message'] ?? $r->get( 'last_error' ) ),
		);

		echo '<h3>' . esc_html__( 'Virtual machine', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
