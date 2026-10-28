<?php
/** Stored extra-disk data for My Account. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DiskPanels {
	public static function server( Resource $server ): array {
		$disks = array();
		$volumes = array();
		$located = VmRecords::for_resource( $server->id() );
		$where = $located ? Clusters::get( $located['cluster'] ) : null;
		if ( $located && $where && Snapshots::tracked( $server ) ) {
			foreach ( DiskSteps::volumes( $server, (string) $where['storage'], (int) $located['vmid'], false ) as $volume ) {
				$volumes[ $volume['id'] ] = $volume['volid'];
			}
		}
		foreach ( DiskMeta::for_server( $server ) as $disk ) {
			$license = $disk->license();
			$grow_url = 'ready' === $disk->get( 'state' ) ? DiskCart::grow_url( $disk ) : '';
			$grow_price = '';
			if ( $grow_url ) {
				$query = array();
				wp_parse_str( (string) wp_parse_url( $grow_url, PHP_URL_QUERY ), $query );
				$from = wc_get_product( (int) ( $license['variation_id'] ?? 0 ) );
				$to = wc_get_product( (int) ( $query['variation_id'] ?? 0 ) );
				if ( $from instanceof \WC_Product_Variation && $to instanceof \WC_Product_Variation ) {
					$grow_price = Backups::price_difference( $from, $to );
				}
			}
			$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $license['subscription_id'] ?? 0 ) ) : null;
			$actions = $subscription instanceof \WC_Subscription && function_exists( 'wcs_get_all_user_actions_for_subscription' )
				? wcs_get_all_user_actions_for_subscription( $subscription, get_current_user_id() ) : array();
			$disks[] = array(
				'retention_days' => Product::retention_days( (int) ( $license['product_id'] ?? 0 ) ),
				'size' => max( (int) $disk->get( 'size_gb' ), (int) ( ( (array) $disk->get( 'plan' ) )['disk_gb'] ?? 0 ) ),
				'slot' => (string) $disk->get( 'slot' ),
				'state' => Resource::state_label( (string) $disk->get( 'state' ) ),
				'grow_url' => $grow_url,
				'grow_price' => $grow_price,
				'paused' => 'paused' === $disk->get( 'state' ),
				'snapshots_covering' => isset( $volumes[ $disk->id() ] ) ? count( Snapshots::covering( $server, $volumes[ $disk->id() ] ) ) : 0,
				'remove_url' => 'ready' === $disk->get( 'state' ) ? (string) ( $actions['cancel']['url'] ?? '' ) : '',
			);
		}
		$add = array();
		if ( count( $disks ) < max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
			$record = VmRecords::for_resource( $server->id() );
			$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
			foreach ( DiskField::sizes() as $variation ) {
				$size = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['disk_gb'] ?? 0 );
				if ( ! $record || ! $cluster || ! DiskCapacity::fits( $record['cluster'], $record['node'], (string) $cluster['storage'], $size ) ) {
					continue;
				}
				$url = DiskCart::add_url( $server, $variation );
				if ( $url ) {
					$add[] = array( 'size' => $size, 'url' => $url, 'price' => wp_strip_all_tags( wc_price( wc_get_price_excluding_tax( $variation ) ) ) );
				}
			}
		}
		return array( 'items' => $disks, 'add' => $add, 'mode' => (string) Settings::get( 'deletion_mode' ) );
	}

	public static function account( Resource $disk ): void {
		$server = Resource::for_license( (int) $disk->get( 'server_license' ) );
		$state = array( 'size' => max( (int) $disk->get( 'size_gb' ), (int) ( ( (array) $disk->get( 'plan' ) )['disk_gb'] ?? 0 ) ), 'slot' => (string) $disk->get( 'slot' ), 'server' => $server, 'needs_reboot' => (bool) $disk->get( 'needs_reboot' ), 'paused' => 'paused' === $disk->get( 'state' ), 'guide' => DiskGuide::guide( DiskGuide::os_label( $server ) ), 'guide_id' => DiskGuide::anchor( $disk->id() ) );
		( static function ( array $vars ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed template data.
			include BELTOFT_SERVICES_PATH . 'templates/account/disk.php';
		} )( $state );
	}

	public static function admin( Resource $disk ): void {
		$license = $disk->license();
		$rows = array(
			__( 'Server license', 'beltoft-services' ) => (string) $disk->get( 'server_license' ),
			__( 'Slot', 'beltoft-services' ) => (string) $disk->get( 'slot' ),
			__( 'Volume name', 'beltoft-services' ) => (string) $disk->get( 'volname' ),
			__( 'Size', 'beltoft-services' ) => (int) $disk->get( 'size_gb' ) . ' GB',
			__( 'Completed steps', 'beltoft-services' ) => implode( ', ', (array) $disk->get( 'steps' ) ),
		);
		$reservation = DiskCapacity::reservation( $disk );
		if ( $reservation ) {
			$rows[ __( 'Reserved capacity', 'beltoft-services' ) ] = (int) ( $reservation['gb'] ?? 0 ) . ' GB on ' . (string) ( $reservation['cluster'] ?? '' ) . '/' . (string) ( $reservation['node'] ?? '' ) . '/' . (string) ( $reservation['storage'] ?? '' );
		}
		if ( 'failed' === $disk->get( 'state' ) && (int) ( $license['order_id'] ?? 0 ) > 0 ) {
			/* translators: %d: WooCommerce order ID to review for a refund. */
			$rows[ __( 'Paid but not delivered', 'beltoft-services' ) ] = sprintf( __( 'Check and refund order #%d if the disk could not be delivered.', 'beltoft-services' ), (int) $license['order_id'] );
		}
		echo '<h3>' . esc_html__( 'Extra disk', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
