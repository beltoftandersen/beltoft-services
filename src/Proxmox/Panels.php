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
				),
			)
		);
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
		$state  = array(
			'ip'         => false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '',
			'plan'       => array(
				'cores'     => (int) ( $plan['cores'] ?? 0 ),
				'memory_mb' => (int) ( $plan['memory_mb'] ?? 0 ),
				'disk_gb'   => (int) ( $r->get( 'disk_gb' ) ? $r->get( 'disk_gb' ) : ( $plan['disk_gb'] ?? 0 ) ),
			),
			'power'      => null === $status ? '' : (string) $status['power'],
			'os_label'   => (string) ( $list[ $chosen ] ?? '' ),
			'os_options' => $list,
			'os_current' => $chosen,
			'has_key'    => Actions::has_key( $r ),
			'can_reveal' => Access::revealable( $r ),
			'forms'      => $r->accepts_actions(),
		);

		( static function ( array $vars ): void {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, a fixed list built above.
			extract( $vars, EXTR_SKIP );
			include BELTOFT_SERVICES_PATH . 'templates/account/vm.php';
		} )( $state );
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
			__( 'Template', 'beltoft-services' )      => (string) ( (int) $r->get( OsField::META ) > 0 ? $r->get( OsField::META ) : ( (array) $r->get( 'plan' ) )['template'] ?? '' ),
			__( 'Last error', 'beltoft-services' )    => (string) ( $problems[ $r->id() ]['message'] ?? $r->get( 'last_error' ) ),
		);

		echo '<h3>' . esc_html__( 'Virtual machine', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
