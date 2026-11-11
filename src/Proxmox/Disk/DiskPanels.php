<?php
/** Stored extra-disk data for My Account. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Disk;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\DiskCapacity;
use BeltoftServices\Proxmox\Snapshot\Snapshots;
use BeltoftServices\Proxmox\Vm\Panels;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Usage\CustomerDelete;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\UsageDisk;

defined( 'ABSPATH' ) || exit;

final class DiskPanels {
	public static function server( Resource $server ): array {
		// The sizes and their prices are those of the server's subscription currency.
		$out = \BeltoftServices\Currency::with_resource( $server, static fn() => self::server_now( $server ), null );

		if ( null === $out ) {
			// The subscription's currency cannot be put in force: the disks (no money in them) are still shown, the sizes to add (priced) are not.
			$out        = self::server_now( $server );
			$out['add'] = array();
		}

		return $out;
	}

	/**
	 * The disks of a server and the sizes it could add, in the currency now in force.
	 *
	 * @param Resource $server Server resource.
	 * @return array<string, mixed>
	 */
	private static function server_now( Resource $server ): array {
		$disks = array();
		$volumes = array();
		$located = VmRecords::for_resource( $server->id() );
		$where = $located ? Clusters::get( $located['cluster'] ) : null;
		$kept = Snapshots::entries( $server );
		if ( $located && $where && $kept ) {
			foreach ( DiskSteps::volumes( $server, (string) $where['storage'], (int) $located['vmid'], false ) as $volume ) {
				$volumes[ $volume['id'] ] = $volume['volid'];
			}
		}
		$guide = DiskGuide::guide( DiskGuide::os_label( $server ) );
		$names = DiskMeta::labels( $server );
		$order = array_flip( array_keys( $names ) );
		$live  = DiskMeta::for_server( $server );
		usort( $live, static fn( Resource $a, Resource $b ): int => ( $order[ $a->id() ] ?? PHP_INT_MAX ) <=> ( $order[ $b->id() ] ?? PHP_INT_MAX ) );
		foreach ( $live as $disk ) {
			$license = $disk->license();
			// A pay as you go disk has no subscription of its own: its remove and grow go through the licence and the usage meter (UsageDisk, CustomerDelete), never through WooCommerce Subscriptions.
			$usage = UsageDisk::is_usage( $disk );
			$grow_url = 'ready' === $disk->get( 'state' ) && ! $usage ? DiskCart::grow_url( $disk ) : '';
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
			$subscription = ! $usage && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $license['subscription_id'] ?? 0 ) ) : null;
			$actions = $subscription instanceof \WC_Subscription && function_exists( 'wcs_get_all_user_actions_for_subscription' )
				? wcs_get_all_user_actions_for_subscription( $subscription, get_current_user_id() ) : array();
			$ending = self::ending( $subscription, $actions );
			$labels = array();
			if ( isset( $volumes[ $disk->id() ] ) ) {
				foreach ( $kept as $entry ) {
					if ( in_array( $volumes[ $disk->id() ], (array) ( $entry['covers'] ?? array() ), true ) ) {
						$labels[] = '' !== (string) ( $entry['label'] ?? '' ) ? (string) $entry['label'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $entry['taken_at'] ?? 0 ) );
					}
				}
			}
			$disks[] = array(
				'id' => $disk->id(),
				'ending' => $ending,
				'manage_url' => $subscription instanceof \WC_Subscription ? $subscription->get_view_order_url() : '',
				'paid_until' => self::date_of( $subscription, 'next_payment' ),
				'snapshot_labels' => $labels,
				'retention_days' => Product::retention_days( (int) ( $license['product_id'] ?? 0 ) ),
				'size' => max( (int) $disk->get( 'size_gb' ), (int) ( ( (array) $disk->get( 'plan' ) )['disk_gb'] ?? 0 ) ),
				'name' => $names[ $disk->id() ] ?? __( 'Data disk', 'beltoft-services' ),
				'slot' => (string) $disk->get( 'slot' ),
				'state' => Resource::state_label( (string) $disk->get( 'state' ) ),
				'grow_url' => $grow_url,
				'grow_price' => $grow_price,
				'paused' => 'paused' === $disk->get( 'state' ),
				'guide' => $guide,
				'guide_id' => DiskGuide::anchor( $disk->id() ),
				'needs_reboot' => (bool) $disk->get( 'needs_reboot' ),
				'snapshots_covering' => isset( $volumes[ $disk->id() ] ) ? count( array_filter( $kept, static fn( $e ) => in_array( $volumes[ $disk->id() ], (array) ( $e['covers'] ?? array() ), true ) ) ) : 0,
				'remove_url' => 'ready' === $disk->get( 'state' ) && null === $ending ? (string) ( $actions['cancel']['url'] ?? '' ) : '',
				'usage' => $usage,
				'usage_terms' => $usage ? self::usage_terms( $disk ) : '',
				'delete' => $usage && 'ready' === $disk->get( 'state' ) && null === CustomerDelete::blocker( $disk ),
				'grow_next' => $usage && 'ready' === $disk->get( 'state' ) ? self::grow_next( $disk ) : null,
			);
		}
		$add = array();
		if ( count( $disks ) < max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
			$record = $located;
			$cluster = $where;
			// Billing follows the server: a pay as you go server is offered the pay as you go sizes, a monthly one the monthly sizes.
			$server_usage = DiskCart::usage_server( (array) $server->license() );
			foreach ( DiskField::sizes_for( $server_usage ) as $variation ) {
				$size = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['disk_gb'] ?? 0 );
				if ( ! $record || ! $cluster ) {
					continue;
				}
				$url = DiskCart::add_url( $server, $variation );
				if ( $url ) {
					$price = $server_usage ? self::usage_terms_of( $variation ) : Panels::price_text( $variation );
					// A pay as you go size is taxed on the invoice whatever the product says: its words follow the display alone.
					$vat   = $server_usage ? ( 'incl' === \BeltoftServices\Billing\TaxNote::display() ? 'incl' : 'excl' ) : \BeltoftServices\Billing\TaxNote::vat_mode( $variation );
					$add[] = array( 'size' => $size, 'url' => $url, 'price' => $price, 'vat' => $vat );
				}
			}
		}
		return array( 'items' => $disks, 'add' => $add, 'mode' => (string) Settings::get( 'deletion_mode' ) );
	}

	/**
	 * What a pay as you go size costs at most in a month, for a tile: "+€4.40 per month, billed by the hour".
	 *
	 * @param \WC_Product_Variation $variation Pay as you go disk variation.
	 */
	private static function usage_terms_of( \WC_Product_Variation $variation ): string {
		$rates = Rates::for_usage( $variation );

		/* translators: %s: monthly amount such as +€4.40 per month */
		return null === $rates ? '' : sprintf( __( '%s at most, billed by the hour', 'beltoft-services' ), Rates::plus_per_month( $rates['cap'], $variation ) );
	}

	/**
	 * What a pay as you go disk costs now, the way a tile shows it.
	 *
	 * @param Resource $disk Disk.
	 */
	private static function usage_terms( Resource $disk ): string {
		$variation = wc_get_product( (int) ( $disk->license()['variation_id'] ?? 0 ) );

		return $variation instanceof \WC_Product_Variation ? self::usage_terms_of( $variation ) : '';
	}

	/**
	 * The next larger size of a pay as you go disk: its size and what it adds, or null.
	 *
	 * @param Resource $disk Disk.
	 * @return array{size: int, price: string}|null
	 */
	private static function grow_next( Resource $disk ): ?array {
		$to = UsageDisk::next( $disk );

		return $to ? array( 'size' => (int) ( Product::plan( $to->get_parent_id(), $to->get_id() )['disk_gb'] ?? 0 ), 'price' => UsageDisk::price_difference( $disk ) ) : null;
	}

	/**
	 * The date of a subscription, formatted for the customer, or '' when it has none.
	 *
	 * @param mixed  $subscription Subscription or null.
	 * @param string $which        WCS date type.
	 */
	private static function date_of( $subscription, string $which ): string {
		$time = $subscription instanceof \WC_Subscription ? (int) $subscription->get_time( $which, 'site' ) : 0;

		return $time > 0 ? date_i18n( get_option( 'date_format' ), $time ) : '';
	}

	/**
	 * A disk whose subscription the customer has ended: it stays until the paid period ends. Null when it is not ending.
	 *
	 * @param mixed                $subscription Subscription or null.
	 * @param array<string, mixed> $actions      The customer's actions on it (WCS).
	 * @return array{date: string, undo_url: string}|null
	 */
	private static function ending( $subscription, array $actions ): ?array {
		if ( ! $subscription instanceof \WC_Subscription || ! $subscription->has_status( 'pending-cancel' ) ) {
			return null;
		}

		return array(
			'date'     => self::date_of( $subscription, 'end' ),
			'undo_url' => (string) ( $actions['reactivate']['url'] ?? '' ),
		);
	}

	public static function account( Resource $disk ): void {
		$server = Resource::for_license( (int) $disk->get( 'server_license' ) );
		$state = array( 'size' => max( (int) $disk->get( 'size_gb' ), (int) ( ( (array) $disk->get( 'plan' ) )['disk_gb'] ?? 0 ) ), 'name' => DiskMeta::label( $disk ), 'server' => $server, 'needs_reboot' => (bool) $disk->get( 'needs_reboot' ), 'paused' => 'paused' === $disk->get( 'state' ), 'guide' => DiskGuide::guide( DiskGuide::os_label( $server ) ), 'guide_id' => DiskGuide::anchor( $disk->id() ) );
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
