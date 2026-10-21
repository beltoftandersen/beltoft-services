<?php
/**
 * Removes the plugin's options, but only when "Delete all data on uninstall" is on.
 *
 * @package BeltoftServices
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$beltoft_services_settings = get_option( 'beltoft_services_settings', array() );

if ( empty( $beltoft_services_settings['delete_data'] ) ) {
	return;
}

delete_option( 'beltoft_services_settings' );
