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

// The DNS hosting tables. Claims are only proofs and go always. Assignments are kept while a name
// may still point at our servers (active or release_pending), and then so is the schema version.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'beltoft_services_dns_claims' ) );
$beltoft_services_assignments = $wpdb->prefix . 'beltoft_services_dns_assignments';
// A missing table counts as empty (the query then fails quietly and returns null).
$beltoft_services_quiet = $wpdb->suppress_errors( true );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
$beltoft_services_open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)', $beltoft_services_assignments, 'active', 'release_pending' ) );
$wpdb->suppress_errors( $beltoft_services_quiet );
if ( 0 === $beltoft_services_open ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $beltoft_services_assignments ) );
	delete_option( 'beltoft_services_dns_db_version' );
}
// The release job's alert flag, cursor and log. (Its schema retry transient goes with the transients above.)
delete_option( 'beltoft_services_dns_release_alerted' );
delete_option( 'beltoft_services_dns_release_cursor' );
delete_option( 'beltoft_services_dns_release_log' );

// The customer emails' WooCommerce settings.
foreach ( array( 'ready', 'paused', 'deletion_warning', 'deleted', 'limit_exceeded' ) as $beltoft_services_email ) {
	delete_option( 'woocommerce_beltoft_services_' . $beltoft_services_email . '_settings' );
}
