<?php
/**
 * Removes the plugin's options, but only when "Delete the settings when the plugin is deleted" is on.
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

// Left on purpose: the option 'beltoft_services_service_slugs' (it keeps service license keys hidden
// from the public license API while the plugin is gone, and is harmless), and the Action Scheduler
// actions in the group 'beltoft-services' (pending ones are not run without the plugin, and
// Action Scheduler removes finished ones itself).

// Desired states that were waiting for a resource lock.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of the plugin's own options.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'beltoft_services_pending_desire_' ) . '%' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of the plugin's own options.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'beltoft_services_driver_conflict_' ) . '%' ) );
// The per-license breach marks.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of the plugin's own options.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'beltoft_services_breach_' ) . '%' ) );
delete_option( 'beltoft_services_connections' );
delete_option( 'beltoft_services_proxmox_clusters' );
delete_option( 'beltoft_services_fake_proxmox' );
delete_option( 'beltoft_services_db_version' );

// Every transient of the plugin (and its timeout): capacity, power, abuse notes, rate limits, alert and screen notices.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of the plugin's own transients.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_beltoft_services_' ) . '%', $wpdb->esc_like( '_transient_timeout_beltoft_services_' ) . '%' ) );

// The IP pool and the VM records. The warning is on the setting's description.
foreach ( array( 'beltoft_services_ips', 'beltoft_services_vms' ) as $beltoft_services_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $beltoft_services_table ) );
}

// The customer emails' WooCommerce settings.
foreach ( array( 'ready', 'paused', 'deletion_warning', 'deleted', 'limit_exceeded' ) as $beltoft_services_email ) {
	delete_option( 'woocommerce_beltoft_services_' . $beltoft_services_email . '_settings' );
}
