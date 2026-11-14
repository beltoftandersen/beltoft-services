<?php
/**
 * The Proxmox features' hooks into the core's extension points.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Apps\AppLicenceDriver;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Disk\DiskGuide;
use BeltoftServices\Proxmox\Network\PrivateAttach;
use BeltoftServices\Proxmox\Snapshot\SnapshotDriver;
use BeltoftServices\Proxmox\Snapshot\SnapshotMeta;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Core (Jobs, Alerts, Product, Reconciler) knows nothing of Proxmox: it exposes filters and actions, and the
 * Proxmox drivers attach here: default retention, state_changed clean-up, parking of half-done network actions
 * and the paid-disk lines of the failure mail.
 */
final class CoreHooks {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 2 );
		add_filter( 'beltoft_services_default_retention', array( ProxmoxDriver::class, 'default_retention' ), 10, 2 );
		add_filter( 'beltoft_services_default_retention', array( DiskDriver::class, 'default_retention' ), 10, 2 );
		add_filter( 'beltoft_services_default_retention', array( AppLicenceDriver::class, 'default_retention' ), 10, 2 );
		add_action( 'beltoft_services_state_changed', array( Access::class, 'on_state_changed' ), 10, 3 );
		add_action( 'beltoft_services_state_changed', array( Operations::class, 'on_state_changed' ), 10, 3 );
		add_filter( 'beltoft_services_park_action', array( __CLASS__, 'park_action' ), 10, 3 );
		add_filter( 'beltoft_services_failure_alert_lines', array( DiskDriver::class, 'failure_alert_lines' ), 10, 3 );
		add_filter( 'beltoft_services_thankyou_row', array( __CLASS__, 'thankyou_row' ), 10, 3 );
		add_filter( 'beltoft_services_thankyou_dashboard_url', array( __CLASS__, 'dashboard_url' ), 10, 2 );
		add_action( 'beltoft_services_product_panel_after_driver', array( __CLASS__, 'picker_field' ), 10, 2 );
		add_action( 'beltoft_services_product_save', array( __CLASS__, 'save_picker' ) );
		add_filter( 'beltoft_services_line_label', array( __CLASS__, 'line_label' ), 10, 3 );
		add_filter( 'beltoft_services_line_rows', array( __CLASS__, 'line_rows' ), 10, 2 );
	}

	/**
	 * The name of an extra disk line says which server it is for.
	 *
	 * @param mixed $label          The name so far.
	 * @param mixed $driver_id      The driver of the product.
	 * @param mixed $disk_server_id The licence id of the server, when known.
	 * @return mixed
	 */
	public static function line_label( $label, $driver_id = '', $disk_server_id = 0 ) {
		if ( DiskDriver::ID !== $driver_id || ! $disk_server_id ) {
			return $label;
		}

		$server = is_numeric( $disk_server_id ) ? Resource::for_license( (int) $disk_server_id ) : null;
		$on     = $server ? ServerName::of( $server ) : '';

		if ( '' === $on ) {
			return $label;
		}

		/* translators: 1: "Extra disk 50 GB", 2: server name. */
		return sprintf( __( '%1$s for %2$s', 'beltoft-services' ), $label, $on );
	}

	/**
	 * The server name a customer chose is a row of the order line.
	 *
	 * @param mixed $rows Label => html.
	 * @param mixed $item The order line.
	 * @return mixed
	 */
	public static function line_rows( $rows, $item = null ) {
		if ( ! is_array( $rows ) || ! $item instanceof \WC_Order_Item ) {
			return $rows;
		}

		$server = trim( (string) $item->get_meta( ServerName::ITEM_META ) );

		if ( '' !== $server ) {
			$rows[ __( 'Server name', 'beltoft-services' ) ] = esc_html( $server );
		}

		return $rows;
	}

	/**
	 * The "Show in the server picker" box of a server product.
	 *
	 * @param mixed $product   The product being edited, null for a new one.
	 * @param mixed $driver_id The saved driver id.
	 */
	public static function picker_field( $product = null, $driver_id = '' ): void {
		if ( ProxmoxDriver::ID !== $driver_id ) {
			return;
		}

		woocommerce_wp_checkbox(
			array(
				'id'          => Product::META_PICKER,
				'label'       => __( 'Show in the server picker', 'beltoft-services' ),
				'description' => __( 'Offer this product on the customer\'s New server page in My account. Its variations without backups are the plans.', 'beltoft-services' ),
				'value'       => $product && 'no' === $product->get_meta( Product::META_PICKER ) ? 'no' : 'yes',
			)
		);
		echo '<input type="hidden" name="' . esc_attr( Product::FIELD_PICKER ) . '" value="1">';
	}

	/**
	 * Saves the picker box, only when the panel printed it (a product that just became a server shows it after this save).
	 *
	 * @param mixed $product The product.
	 */
	public static function save_picker( $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		if ( $product instanceof \WC_Product && isset( $_POST[ Product::FIELD_PICKER ] ) ) {
			$product->update_meta_data( Product::META_PICKER, isset( $_POST[ Product::META_PICKER ] ) ? 'yes' : 'no' );
		}
		// phpcs:enable
	}

	/**
	 * Where "Go to your dashboard" leads for a server: the page of the new server.
	 *
	 * @param mixed $url     The address so far.
	 * @param mixed $license The server's licence row.
	 * @return mixed
	 */
	public static function dashboard_url( $url, $license = null ) {
		return is_array( $license ) ? ServerLinks::url( $license ) : $url;
	}

	/**
	 * The thank-you row: the server name and the card address of every service, and the keys of a snapshot pack and
	 * an extra disk (where to find them).
	 *
	 * @param mixed $row     The row so far.
	 * @param mixed $r       Resource.
	 * @param mixed $license Licence row.
	 * @return mixed
	 */
	public static function thankyou_row( $row, $r, $license ) {
		if ( ! is_array( $row ) || ! $r instanceof Resource || ! is_array( $license ) ) {
			return $row;
		}

		$row['name'] = ServerName::display( $r, $license );
		$row['url']  = DiskGuide::card_url( $r );

		// A server opens on its overview, an app licence on its server's App tab; the Disks tab is for an extra disk.
		if ( ProxmoxDriver::ID === (string) $r->get( 'driver' ) ) {
			$row['url'] = ServerLinks::url( $license ) . '#beltoft-service-' . $r->id();
		} elseif ( AppLicenceDriver::ID === (string) $r->get( 'driver' ) ) {
			$server     = Resource::for_license( (int) $r->get( 'server_license' ) );
			$row['url'] = $server ? DiskGuide::card_url( $server, '', \BeltoftServices\Apps\AppTab::SLUG ) : $row['url'];
		}

		if ( SnapshotDriver::ID === (string) $r->get( 'driver' ) ) {
			$row['url']      = DiskGuide::server_url( $r );
			$row['snapshot'] = array(
				'slots'      => SnapshotMeta::slots_of( $r ),
				'server_url' => $row['url'],
			);
		}

		if ( DiskDriver::ID === (string) $r->get( 'driver' ) ) {
			$row['disk'] = array(
				'server_url' => DiskGuide::server_url( $r ),
				'guide_url'  => DiskGuide::guide_url( $r ),
			);
		}

		return $row;
	}

	/**
	 * Adds the Proxmox drivers to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( ! is_array( $drivers ) ) {
			return $drivers;
		}

		if ( ! isset( $drivers[ ProxmoxDriver::ID ] ) ) {
			$drivers[ ProxmoxDriver::ID ] = new ProxmoxDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-db' ] ) ) {
			$drivers[ 'proxmox-db' ] = new DbDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-disk' ] ) ) {
			$drivers[ 'proxmox-disk' ] = new DiskDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-snapshots' ] ) ) {
			$drivers[ 'proxmox-snapshots' ] = new SnapshotDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-app' ] ) ) {
			$drivers[ 'proxmox-app' ] = new AppLicenceDriver();
		}

		return $drivers;
	}

	/**
	 * A private network attach or detach whose work is half done waits instead of being dropped.
	 *
	 * @param mixed $parkable Earlier answer.
	 * @param mixed $action   Action name.
	 * @param mixed $r        Resource.
	 */
	public static function park_action( $parkable, $action, $r ): bool {
		if ( $parkable ) {
			return true;
		}

		return $r instanceof Resource && in_array( $action, PrivateAttach::ACTIONS, true ) && PrivateAttach::in_flight( $r );
	}
}
