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
delete_option( 'beltoft_services_alert_problem' );

// Desired states that were waiting for a resource lock.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of the plugin's own options.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'beltoft_services_pending_desire_' ) . '%' ) );
