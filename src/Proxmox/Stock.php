<?php
/**
 * Virtual machine products are out of stock when nothing can be built.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Availability is the product's WooCommerce stock status: sync() writes it (`instock` or `outofstock`,
 * only when it differs) after every capacity cache write and every change of the IP pool, so
 * WooCommerce's own hooks fire (`woocommerce_product_set_stock_status`, which page caches purge on).
 * A product the owner set to out of stock by hand is never reopened: only a status this class closed
 * (marker META_CLOSED) is. Products that manage stock are left to WooCommerce.
 *
 * Two WooCommerce filters (woocommerce/includes/abstracts/abstract-wc-product.php:1843 and :1785;
 * variations reach the second through parent::is_purchasable(), class-wc-product-variation.php:574)
 * stay as a guard for the time between two runs: a Proxmox product is unavailable when the IP pool has
 * no free address, or when no allowed node has the plan's memory free in the capacity cache. With NO
 * cache (an object cache flush) the filters do not close anything: the stored status stands, and a
 * capacity run is queued.
 *
 * A renewal, a switch and a resubscribe need no new address, so they are never blocked, also not by a
 * stored `outofstock`: WooCommerce reads the stored status in is_in_stock() (add_to_cart(),
 * check_cart_item_stock(), the order-pay check), so for those flows the in-stock filter answers true.
 * A cart item with WooCommerce Subscriptions' keys for this product, or the request arguments that start
 * those flows (`switch-subscription`, the switcher reads it before the item is in the cart;
 * `resubscribe`), lets the product through.
 */
final class Stock {

	/**
	 * Marker on a product whose `outofstock` this class wrote, so only that one is reopened.
	 */
	public const META_CLOSED = '_beltoft_services_stock_closed';

	/**
	 * Transient prefix: a capacity run was queued for the cluster lately (see queue()).
	 */
	private const QUEUED_PREFIX = 'beltoft_services_proxmox_stock_queued_';

	/**
	 * Cart item data keys of WooCommerce Subscriptions' flows: the `$cart_item_key` properties of
	 * WCS_Cart_Renewal (core/class-wcs-cart-renewal.php:18), WCS_Cart_Switch (switching/class-wcs-cart-switch.php:11)
	 * and WCS_Cart_Resubscribe (core/class-wcs-cart-resubscribe.php:19). Early renewal is a renewal cart too.
	 */
	private const CART_KEYS = array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe', 'subscription_renewal_early' );

	/**
	 * Product and variation id of the item being added now when it belongs to one of those flows.
	 * WooCommerce checks is_purchasable() inside add_to_cart() BEFORE the item is in the cart
	 * (class-wc-cart.php:1367), and WCS adds a renewal through WC()->cart->add_to_cart() with the
	 * keys in the item data (class-wcs-cart-renewal.php:364-370). The flag is set by the item-data
	 * filter, replaced by the next add, and cleared once the item is added.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private static $adding = null;

	/**
	 * True while sync() writes: its own saves must not run product_saved() or forget_marker().
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter' ), 20, 2 );
		// The status follows a saved plan or driver, and an owner's own change to it is respected (see forget_marker()).
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'product_saved' ) );
		add_action( 'woocommerce_after_product_variation_object_save', array( __CLASS__, 'product_saved' ) );
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'forget_marker' ), 10, 2 );
		// After WCS's own item-data filters (priority 10, e.g. the switcher's), so the keys are in place.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'note_flow' ), 999, 3 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'clear_flow' ) );
		// An add can abort after the item-data filter (WooCommerce throws, class-wc-cart.php:1487) and no
		// action follows it: the next add's validation (class-wc-form-handler.php, the Store API) or the
		// end of the request closes the flag instead.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validation' ), 1 );
		add_action( 'shutdown', array( __CLASS__, 'clear_flow' ) );
		// A cart loaded from the session checks is_purchasable() before the item data is applied (class-wc-cart-session.php:198).
		add_filter( 'woocommerce_cart_item_is_purchasable', array( __CLASS__, 'session_item' ), 20, 4 );
		// Paying an existing renewal, switch or resubscribe order (woocommerce/includes/shortcodes/class-wc-shortcode-checkout.php:154).
		add_filter( 'woocommerce_pay_order_product_in_stock', array( __CLASS__, 'pay_order' ), 20, 3 );
	}

	/**
	 * Filter woocommerce_add_cart_item_data: opens the flow flag when the data carries a WCS flow key.
	 *
	 * @param mixed $data         Cart item data.
	 * @param mixed $product_id   Product id.
	 * @param mixed $variation_id Variation id.
	 * @return mixed
	 */
	public static function note_flow( $data, $product_id = 0, $variation_id = 0 ) {
		self::$adding = is_array( $data ) && self::has_flow_key( $data ) ? array( (int) $product_id, (int) $variation_id ) : null;

		return $data;
	}

	/**
	 * Filter woocommerce_add_to_cart_validation, first in line: a new add starts, so a flag left by
	 * an add that aborted is closed. The value passes through untouched.
	 *
	 * @param mixed $valid Whether the add is valid so far.
	 * @return mixed
	 */
	public static function validation( $valid ) {
		self::$adding = null;

		return $valid;
	}

	/**
	 * Action woocommerce_add_to_cart: the item is in the cart, close the flag. Also at the end of the request.
	 */
	public static function clear_flow(): void {
		self::$adding = null;
	}

	/**
	 * The add-on drivers sold next to a server: an add-on belongs to a server and is not built by itself.
	 *
	 * @return string[]
	 */
	public static function addon_drivers(): array {
		return array( DiskDriver::ID, SnapshotDriver::ID, AppLicenceDriver::ID );
	}

	/**
	 * Every driver whose products this class looks after: the server driver and the add-ons.
	 *
	 * @return string[]
	 */
	public static function drivers(): array {
		return array_merge( array( 'proxmox', DbDriver::ID ), self::addon_drivers() );
	}

	/**
	 * Filter woocommerce_cart_item_is_purchasable: an item restored from the session that carries a
	 * flow key stays.
	 *
	 * @param mixed $purchasable Value so far.
	 * @param mixed $key         Cart item key.
	 * @param mixed $values      Session values of the item.
	 * @param mixed $product     Product.
	 * @return mixed
	 */
	public static function session_item( $purchasable, $key = '', $values = array(), $product = null ) {
		if ( $purchasable || ! $product instanceof \WC_Product || ! is_array( $values ) || ! self::has_flow_key( $values ) ) {
			return $purchasable;
		}

		$driver = Product::driver_for( $product->get_id() );

		return $driver && in_array( $driver->id(), self::drivers(), true ) ? true : $purchasable;
	}

	/**
	 * Filter woocommerce_pay_order_product_in_stock: renewal, switch and resubscribe orders can be paid.
	 *
	 * @param mixed $in_stock Value so far.
	 * @param mixed $product  Product.
	 * @param mixed $order    Order.
	 * @return mixed
	 */
	public static function pay_order( $in_stock, $product = null, $order = null ) {
		if ( $in_stock || ! $product instanceof \WC_Product || ! $order instanceof \WC_Abstract_Order || ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return $in_stock;
		}

		$driver = Product::driver_for( $product->get_id() );

		if ( ! $driver || ! in_array( $driver->id(), self::drivers(), true ) ) {
			return $in_stock;
		}

		return wcs_order_contains_subscription( $order, array( 'renewal', 'switch', 'resubscribe' ) ) ? true : $in_stock;
	}

	/**
	 * Does this data carry one of the flow keys?
	 *
	 * @param array<string, mixed> $data Cart item data or session values.
	 */
	private static function has_flow_key( array $data ): bool {
		foreach ( self::CART_KEYS as $key ) {
			if ( isset( $data[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter woocommerce_product_is_in_stock: the guard of `filter()`, and an existing customer's
	 * renewal, switch or resubscribe is in stock even when the stored status is `outofstock`.
	 *
	 * @param mixed $value   Value so far.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function in_stock( $value, $product ) {
		if ( ! $value && $product instanceof \WC_Product ) {
			$driver = Product::driver_for( $product->get_id() );

			if ( $driver && in_array( $driver->id(), self::drivers(), true ) && self::existing_customer_flow( $product ) ) {
				return true;
			}
		}

		return self::filter( $value, $product );
	}

	/**
	 * Both filters: ( $value, $product ). Only ever closes (a false value stays false).
	 *
	 * @param mixed $value   Value so far.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function filter( $value, $product ) {
		if ( ! $value || ! $product instanceof \WC_Product ) {
			return $value;
		}

		$driver = Product::driver_for( $product->get_id() );

		if ( ! $driver || ! in_array( $driver->id(), self::drivers(), true ) || self::existing_customer_flow( $product ) ) {
			return $value;
		}

		$available = self::availability( $product );

		if ( null === $available ) {
			// No capacity cache: not "no room". The stored status stands until the capacity run (queued) has written it.
			self::queue( (string) ( Product::plan( $product->get_id() )['cluster'] ?? '' ) );

			return $value;
		}

		return $available ? $value : false;
	}

	/**
	 * True when it cannot be told whether a machine can be built (no capacity cache). Checkout lets such a plan through on its stored
	 * status, so a picker may offer it.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function unknown( \WC_Product $product ): bool {
		return null === self::availability( $product );
	}

	/**
	 * Can a new machine be built for this product now? False with no capacity cache.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function available( \WC_Product $product ): bool {
		return true === self::availability( $product );
	}

	/**
	 * Can a new machine be built now: true or false, or null when it cannot be told because the
	 * cluster is known but its capacity cache is gone (and the pool has addresses).
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	private static function availability( \WC_Product $product ): ?bool {
		$driver = Product::driver_for( $product->get_id() );
		if ( $driver && in_array( $driver->id(), array( SnapshotDriver::ID, AppLicenceDriver::ID ), true ) ) {
			return true; // Slots and app licences are an entitlement: nothing is built, so nothing runs out.
		}
		if ( $driver && DiskDriver::ID === $driver->id() ) {
			$size = (int) ( Product::plan( $product->get_id() )['disk_gb'] ?? 0 );
			if ( $size < 1 ) {
				return false;
			}
			foreach ( Clusters::all() as $cluster_id => $cluster ) {
				foreach ( (array) $cluster['nodes'] as $node ) {
					if ( DiskCapacity::fits( (string) $cluster_id, (string) $node, (string) $cluster['storage'], $size ) ) {
						return true;
					}
				}
			}
			return false;
		}

		$plan = Product::plan( $product->get_id() );

		if ( $driver && DbDriver::ID === $driver->id() ) {
			// A database needs an address only with the public IP add-on, and never the one kept for servers.
			if ( (int) ( $plan['public_ip'] ?? 0 ) > 0 && 0 === IpPool::available_for_db() ) {
				return false;
			}

			// Extra storage is sold out when the data disk does not fit on a node (a plan without it is not asked).
			if ( DbDisk::extra( $plan ) > 0 && ! DbDisk::fits_new( $plan ) ) {
				return false;
			}
		} elseif ( 0 === IpPool::free_count() ) {
			return false;
		}

		$cluster = (string) ( $plan['cluster'] ?? '' );
		$free    = Capacity::free_by_node( $cluster ); // One read of the cluster list and one of the cache; no secret is decrypted.

		if ( null === $free ) {
			return isset( Clusters::all()[ $cluster ] ) ? null : false; // A saved cluster without a cache waits; an unknown one is closed.
		}

		$bytes = max( 0, (int) ( $plan['memory_mb'] ?? 0 ) ) * 1048576;

		foreach ( $free as $bytes_free ) {
			if ( $bytes_free >= $bytes ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Queues a capacity run for the cluster (ProxmoxJobs::queue_capacity(): async, one waiting run is
	 * enough). At most once in five minutes, so a cluster that cannot be read is not asked on every page view.
	 *
	 * @param string $cluster_id Cluster id.
	 */
	private static function queue( string $cluster_id ): void {
		if ( '' === $cluster_id || false !== get_transient( self::QUEUED_PREFIX . $cluster_id ) ) {
			return;
		}

		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			return;
		}

		set_transient( self::QUEUED_PREFIX . $cluster_id, 1, 5 * MINUTE_IN_SECONDS );
		ProxmoxJobs::queue_capacity( $cluster_id );
	}

	/**
	 * Writes availability to the stock status of every Proxmox product and variation.
	 *
	 * Only a changed status is saved (WooCommerce CRUD, so its hooks fire once, and not at all when
	 * nothing changed). Where it cannot be told (no capacity cache) nothing is written. A variable
	 * product's own status is synced by WooCommerce afterwards. Never throws: callers are the capacity job
	 * and the pool, which must not fail on a product.
	 *
	 * @param string|null $cluster_id Only the plans of this cluster (a capacity write); null for all (the pool changed).
	 */
	public static function sync( ?string $cluster_id = null ): void {
		self::sync_ids( self::product_ids(), $cluster_id );
	}

	/**
	 * Action woocommerce_after_product_object_save / _product_variation_object_save: a saved Proxmox
	 * product (new, or a changed plan, memory or driver) is synced at once, so its stored status is
	 * right without waiting for the next capacity run.
	 *
	 * @param mixed $product Saved product or variation.
	 */
	public static function product_saved( $product ): void {
		if ( self::$writing || ! $product instanceof \WC_Product ) {
			return;
		}

		$parent = $product->get_parent_id();
		$id     = $parent > 0 ? $parent : $product->get_id();
		$driver = Product::driver_for( $id );

		if ( $driver && in_array( $driver->id(), self::drivers(), true ) ) {
			self::sync_ids( array( $id ), null );
		}
	}

	/**
	 * Action woocommerce_product_object_updated_props: a stock status that somebody else changed (the
	 * owner, an import, WooCommerce) ends our claim on it. Otherwise a product we closed, that the owner
	 * reopened and later closed on purpose, would be reopened by the next sync.
	 *
	 * @param mixed $product Saved product or variation.
	 * @param mixed $props   Props that were written.
	 */
	public static function forget_marker( $product, $props = array() ): void {
		if ( self::$writing || ! $product instanceof \WC_Product || ! in_array( 'stock_status', (array) $props, true ) ) {
			return;
		}

		if ( '' !== (string) get_post_meta( $product->get_id(), self::META_CLOSED, true ) ) {
			delete_post_meta( $product->get_id(), self::META_CLOSED );
			$product->delete_meta_data( self::META_CLOSED ); // The object may be saved again.
		}
	}

	/**
	 * The sync of these parent products. A product that fails is logged and skipped: the others go on.
	 *
	 * @param int[]       $ids        Parent product ids.
	 * @param string|null $cluster_id Only the plans of this cluster, or null.
	 */
	private static function sync_ids( array $ids, ?string $cluster_id ): void {
		if ( self::$writing || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		self::$writing = true;

		try {
			foreach ( $ids as $id ) {
				try {
					self::sync_product( (int) $id, $cluster_id );
				} catch ( \Throwable $e ) {
					// A failed write cannot be recorded anywhere else; the next sync tries again.
					error_log( sprintf( 'Beltoft Services: the stock status of product %d could not be synced: %s', (int) $id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * One product, or a variable product's variations, then the variable product itself.
	 *
	 * @param int         $id         Parent product id.
	 * @param string|null $cluster_id Cluster filter.
	 */
	private static function sync_product( int $id, ?string $cluster_id ): void {
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return;
		}

		if ( ! $product instanceof \WC_Product_Variable ) {
			self::apply( $product, $cluster_id );
			return;
		}

		$changed = false;

		foreach ( $product->get_children() as $child_id ) {
			try {
				$variation = wc_get_product( $child_id );
				$changed   = ( $variation && self::apply( $variation, $cluster_id ) ) || $changed;
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'Beltoft Services: the stock status of variation %d could not be synced: %s', (int) $child_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see sync_ids().
			}
		}

		if ( $changed ) {
			// A variation's save does not touch its parent (only the admin screens sync it).
			\WC_Product_Variable::sync( $id );
		}
	}

	/**
	 * Ids of the Proxmox products (parents; a variable product's variations are its children).
	 *
	 * @return int[]
	 */
	private static function product_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => Product::META_DRIVER, 'value' => self::drivers(), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small service-product catalogue.
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Brings one product's stored status in line with its availability.
	 *
	 * @param \WC_Product $product    Simple product or variation.
	 * @param string|null $cluster_id Skip plans of other clusters.
	 * @return bool Whether the status was saved.
	 */
	private static function apply( \WC_Product $product, ?string $cluster_id ): bool {
		if ( $product->managing_stock() ) {
			return false; // Quantities decide; WooCommerce would reset the status anyway.
		}

		$driver = Product::driver_for( $product->get_id() );
		if ( null !== $cluster_id && ( ! $driver || ! in_array( $driver->id(), self::addon_drivers(), true ) ) && (string) ( Product::plan( $product->get_id() )['cluster'] ?? '' ) !== $cluster_id ) {
			return false;
		}

		$available = self::availability( $product );

		if ( null === $available ) {
			// No cache, so nothing can be said (a product closed for lack of an address cannot be reopened): ask for one.
			self::queue( (string) ( Product::plan( $product->get_id() )['cluster'] ?? '' ) );

			return false;
		}

		$stored = $product->get_stock_status( 'edit' );

		if ( $available ) {
			// Reopens only what this class closed; 'onbackorder' is already purchasable.
			if ( 'outofstock' !== $stored || 'yes' !== (string) $product->get_meta( self::META_CLOSED ) ) {
				return false;
			}

			$product->set_stock_status( 'instock' );
			$product->delete_meta_data( self::META_CLOSED );
			$product->save();

			return true;
		}

		if ( 'outofstock' === $stored ) {
			return false;
		}

		$product->set_stock_status( 'outofstock' );
		$product->update_meta_data( self::META_CLOSED, 'yes' );
		$product->save();

		return true;
	}

	/**
	 * Does the current user own this subscription? A made-up id in the address proves nothing.
	 *
	 * @param int $subscription_id Subscription id.
	 */
	private static function owns( int $subscription_id ): bool {
		$user = get_current_user_id();

		if ( $user <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}

		$subscription = wcs_get_subscription( $subscription_id );

		return $subscription && (int) $subscription->get_user_id() === $user;
	}

	/**
	 * Is this a renewal, switch or resubscribe?
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function existing_customer_flow( \WC_Product $product ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Only presence is read, to let an existing customer through; WooCommerce Subscriptions verifies its own nonces.
		$named = array(
			isset( $_REQUEST['switch-subscription'] ) ? absint( wp_unslash( $_REQUEST['switch-subscription'] ) ) : 0,
			isset( $_GET['resubscribe'] ) ? absint( wp_unslash( $_GET['resubscribe'] ) ) : 0,
		);
		// phpcs:enable

		foreach ( $named as $subscription_id ) {
			if ( $subscription_id > 0 && self::owns( $subscription_id ) ) {
				return true;
			}
		}

		if ( null !== self::$adding && in_array( $product->get_id(), self::$adding, true ) ) {
			return true;
		}

		$cart = function_exists( 'WC' ) && isset( WC()->cart ) ? WC()->cart : null;

		if ( ! $cart ) {
			return false;
		}

		// cart_contents, not get_cart(): this runs while the cart is loading from the session.
		foreach ( (array) $cart->cart_contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$same = in_array( $product->get_id(), array( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ), true );

			foreach ( self::CART_KEYS as $key ) {
				if ( $same && isset( $item[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
