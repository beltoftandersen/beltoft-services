<?php
/** Console feature switch and eligibility. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Console {
	public static function register(): void {
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
		ConsoleSessions::register();
	}

	/** Add an off-by-default setting to the existing Proxmox tab. */
	public static function fields( $fields ) {
		if ( is_array( $fields ) ) {
			$fields['console'] = array(
				'tab' => ProxmoxSettings::TAB,
				'section' => ProxmoxSettings::TAB,
				'type' => 'checkbox',
				'default' => 0,
				'label' => __( 'Customer server console', 'beltoft-services' ),
				'desc' => __( 'Show a browser console for running servers. Keep this off until the console proxy and its permissions have been configured and checked.', 'beltoft-services' ),
			);
		}
		return $fields;
	}

	public static function enabled(): bool {
		return 1 === (int) Settings::get( 'console' );
	}

	/** A quick page check; open() verifies the VM and its current power directly. */
	public static function available( Resource $r ): bool {
		if ( ! self::enabled() || 'proxmox' !== $r->get( 'driver' ) || ! $r->accepts_actions() ) {
			return false;
		}
		$record = VmRecords::for_resource( $r->id() );
		$power = Operations::cached_status( $r->id() );
		return null !== $record && is_array( $power ) && 'running' === ( $power['power'] ?? '' );
	}

	public static function eligible( Resource $r ): bool {
		if ( ! self::available( $r ) ) {
			return false;
		}
		$record = VmRecords::for_resource( $r->id() );
		$cluster = $record ? Clusters::get( (string) $record['cluster'] ) : null;
		return $cluster && null === Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() );
	}
}
