<?php
/** Add one extra disk per WooCommerce subscription. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonCart;
use BeltoftServices\Proxmox\Pve\Capacity;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\DiskCapacity;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

final class DiskCart {
	private const NONCE = 'beltoft-services-disk';

	private static function addon(): Addon {
		return Addon::for_driver( DiskDriver::ID );
	}

	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		AddonCart::register_line_hooks( self::addon() );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_validation' ), 10, 2 );
	}

	public static function add_url( Resource $server, \WC_Product_Variation $size ): string {
		if ( ! function_exists( 'wcs_get_subscription' ) || 'ready' !== $server->get( 'state' ) || $server->get( 'linked' ) || ProxmoxDriver::ID !== $server->get( 'driver' ) || count( DiskMeta::for_server( $server ) ) >= max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
			return '';
		}
		$parent = $size->get_parent_id();
		$driver = Product::driver_for( $parent );
		if ( ! $driver || DiskDriver::ID !== $driver->id() || ! $size->is_purchasable() || Rates::is_usage( $size ) !== self::usage_server( $server->license() ) ) {
			return '';
		}
		$license = $server->license();
		$args = array_merge( array(
			'add-to-cart' => $parent,
			'variation_id' => $size->get_id(),
			'beltoft_services_disk_for' => (int) $license['id'],
			'beltoft_services_disk_nonce' => wp_create_nonce( self::NONCE . ':' . (int) $license['id'] ),
		), $size->get_variation_attributes() );
		return add_query_arg( $args, wc_get_checkout_url() );
	}

	/** Is this server licence billed pay as you go? A disk bought for it follows (an hourly disk for an hourly server). */
	public static function usage_server( array $license ): bool {
		return 'usage' === (string) ( $license['source'] ?? '' );
	}

	private static function disk_product( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );
		return $driver && DiskDriver::ID === $driver->id();
	}

	private static function link( array $data ) {
		return AddonCart::link( self::addon(), $data, static function ( int $id ): bool {
			$license = Licenses::find( $id );
			$server  = $license ? Resource::for_license( $id ) : null;

			return $server && $license && (int) ( $license['user_id'] ?? 0 ) === get_current_user_id() && ProxmoxDriver::ID === $server->get( 'driver' ) && 'ready' === $server->get( 'state' ) && ! $server->get( 'linked' );
		} );
	}

	public static function validate( $valid, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ): bool {
		if ( ! $valid || ! self::disk_product( (int) $product_id ) ) {
			return (bool) $valid;
		}
		$data = is_array( $data ) ? $data : array();
		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		$usage = $product && Rates::is_usage( $product );
		if ( ! $product || ( ! $usage && ( ! class_exists( '\\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) ) ) {
			wc_add_notice( __( 'This extra disk must be sold as a subscription.', 'beltoft-services' ), 'error' );
			return false;
		}
		if ( CartFlow::has_value( $data, CartFlow::ALL ) || AddonCart::valid_switch( (int) $product_id ) ) {
			return true;
		}
		$link = self::link( $data );
		if ( null === $link || 1 !== (int) $quantity ) {
			wc_add_notice( __( 'Choose one disk for a server in your account.', 'beltoft-services' ), 'error' );
			return false;
		}
		// Billing follows the server: an hourly disk goes with an hourly server only, and an hourly server gets no monthly disk.
		$server_usage = is_int( $link ) ? self::usage_server( (array) Licenses::find( $link ) ) : DiskField::cart_server_usage();
		if ( $usage !== $server_usage ) {
			wc_add_notice( __( 'This extra disk is not sold with this server\'s billing. Choose the disk on the server page.', 'beltoft-services' ), 'error' );
			return false;
		}
		if ( is_int( $link ) ) {
			$server = Resource::for_license( $link );
			$in_cart = 0;
			foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
				$in_cart += (int) ( ( $item['beltoft_services_disk_for'] ?? null ) === $link );
			}
			if ( count( DiskMeta::for_server( $server ) ) + $in_cart >= max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
				wc_add_notice( __( 'This server has reached its extra disk limit.', 'beltoft-services' ), 'error' );
				return false;
			}
		}
		return true;
	}

	/** Add a unique key before WooCommerce computes the cart ID. */
	public static function item_data( $data, $product_id ): array {
		$data = is_array( $data ) ? $data : array();
		if ( ! self::disk_product( (int) $product_id ) || CartFlow::has_value( $data, CartFlow::ALL ) ) {
			return $data;
		}
		$link = self::link( $data );
		if ( null === $link ) {
			throw new \Exception( esc_html__( 'Choose a disk for a server in your account first.', 'beltoft-services' ) );
		}
		$data['beltoft_services_disk_for'] = $link;
		$data['beltoft_services_disk_uid'] = wp_generate_uuid4();
		return $data;
	}

	public static function checkout_validation( array $data, \WP_Error $errors ): void {
		$requested = array();
		$new_server_disk_gb = 0;
		$new_server_disk_count = 0;
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ! isset( $item['beltoft_services_disk_uid'] ) || ! self::disk_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				continue;
			}
			$link = $item['beltoft_services_disk_for'] ?? null;
			if ( is_int( $link ) ) {
				$license = Licenses::find( $link );
				$server = $license ? Resource::for_license( $link ) : null;
				if ( ! $server || ! $license || (int) $license['user_id'] !== get_current_user_id() || 'ready' !== $server->get( 'state' ) || $server->get( 'linked' ) ) {
					$errors->add( 'disk_server_unavailable', __( 'The server for this disk is no longer available.', 'beltoft-services' ) );
					continue;
				}
				$in_cart = 0;
				foreach ( WC()->cart->get_cart() as $other ) {
					$in_cart += (int) ( ( $other['beltoft_services_disk_for'] ?? null ) === $link );
				}
				if ( count( DiskMeta::for_server( $server ) ) + $in_cart > max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
					$errors->add( 'disk_limit', __( 'This server has reached its extra disk limit.', 'beltoft-services' ) );
				}
				$record = VmRecords::for_resource( $server->id() );
				$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
				$size = (int) ( Product::plan( (int) $item['product_id'], (int) $item['variation_id'] )['disk_gb'] ?? 0 );
				$key = $record && $cluster ? $record['cluster'] . '|' . $record['node'] . '|' . $cluster['storage'] : '';
				$requested[ $key ] = (int) ( $requested[ $key ] ?? 0 ) + $size;
				if ( ! $record || ! $cluster || ! DiskCapacity::fits( $record['cluster'], $record['node'], (string) $cluster['storage'], $requested[ $key ] ) ) {
					$errors->add( 'disk_capacity', __( 'This disk size is no longer available for the server.', 'beltoft-services' ) );
				}
			} elseif ( 'cart_server' === $link ) {
				++$new_server_disk_count;
				$new_server_disk_gb += (int) ( Product::plan( (int) $item['product_id'], (int) $item['variation_id'] )['disk_gb'] ?? 0 );
			}
		}
		if ( $new_server_disk_count > 1 ) {
			$errors->add( 'disk_limit', __( 'Choose only one extra disk with a new server.', 'beltoft-services' ) );
		}
		if ( $new_server_disk_gb > 0 ) {
			$entry = DiskField::cart_server();
			$server_item = $entry['item'] ?? null;
			$plan = $server_item ? Product::plan( (int) $server_item['product_id'], (int) ( $server_item['variation_id'] ?? 0 ) ) : array();
			if ( ! self::new_server_fits( $plan, $new_server_disk_gb ) ) {
				$errors->add( 'disk_capacity', __( 'The server and extra disk cannot be delivered with the available capacity.', 'beltoft-services' ) );
			}
		}
	}

	/**
	 * Can a new server of this plan and an extra disk of $gb be delivered: a node with the memory free and the room for both disks?
	 *
	 * @param array<string, mixed> $plan Plan numbers (cluster, memory_mb, disk_gb).
	 * @param int                  $gb   Extra disk in GB.
	 */
	public static function new_server_fits( array $plan, int $gb ): bool {
		$cluster_id = (string) ( $plan['cluster'] ?? '' );
		$cluster = Clusters::get( $cluster_id );
		$memory = max( 0, (int) ( $plan['memory_mb'] ?? 0 ) ) * 1048576;
		$space = max( 0, (int) ( $plan['disk_gb'] ?? 0 ) ) + $gb;
		foreach ( $cluster ? (array) Capacity::free_by_node( $cluster_id ) : array() as $node => $free ) {
			if ( $free >= $memory && DiskCapacity::fits( $cluster_id, (string) $node, (string) $cluster['storage'], $space ) ) {
				return true;
			}
		}
		return false;
	}

	/** WooCommerce Subscriptions' own switch link, limited to the next larger size. */
	public static function grow_url( Resource $disk ): string {
		if ( ! class_exists( '\\WC_Subscriptions_Switcher' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}
		$license = $disk->license();
		// A pay as you go disk has no subscription of its own (its licence sits on the usage account): never a switch of that. See UsageDisk.
		if ( self::usage_server( (array) $license ) ) {
			return '';
		}
		$subscription = wcs_get_subscription( (int) ( $license['subscription_id'] ?? 0 ) );
		$current = (int) $disk->get( 'size_gb' );
		$next = null;
		foreach ( DiskField::sizes() as $variation ) {
			if ( $variation->get_parent_id() !== (int) ( $license['product_id'] ?? 0 ) ) {
				continue;
			}
			$size = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['disk_gb'] ?? 0 );
			if ( $size > $current && ( null === $next || $size < $next['size'] ) ) {
				$next = array( 'size' => $size, 'variation' => $variation );
			}
		}
		if ( ! $subscription instanceof \WC_Subscription || null === $next ) {
			return '';
		}
		foreach ( $subscription->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() !== (int) $license['product_id'] || ! \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription ) ) {
				continue;
			}
			return \BeltoftServices\SwitchLink::for_item( (int) $item_id, $item, $subscription, $next['variation'] );
		}
		return '';
	}
}
