<?php
/**
 * Virtual machine products are out of stock when nothing can be built.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Billing\ExistingFlow;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Apps\AppLicenceDriver;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Snapshot\SnapshotDriver;

defined( 'ABSPATH' ) || exit;

/**
 * Sold out is WooCommerce's own stock status (`instock` or `outofstock`, the field in the product editor): the
 * owner sets it by hand. Capacity never stops a sale (owner decision 2026-11-11): nothing here asks the node
 * memory or the storage when a page is shown, a product is added to the cart or an order is checked out, and no
 * filter closes `woocommerce_is_purchasable` or `woocommerce_product_is_in_stock` any more.
 *
 * Two things are still written automatically, because they cannot be oversold or cannot work at all, each with the
 * marker META_CLOSED so that only a status this class closed is reopened and a hand-set `outofstock` never is:
 * - the address pool: a machine needs a public address, so the product is closed while the pool is empty;
 * - a configuration fault: a plan naming a cluster that is not saved, and an extra disk product without a size, are
 *   closed until they are fixed (a saved cluster, a size), also when a cluster is deleted or saved.
 * sync() writes `outofstock` (only when it differs, WooCommerce's own hooks fire, page caches purge on
 * `woocommerce_product_set_stock_status`) and `instock` again when the cause is gone. It runs when the pool or a
 * cluster changes or a product is saved, never when a page is shown. Products that manage stock are left to
 * WooCommerce. A product saved from the admin product form with out of stock selected is the owner's choice: the
 * marker is dropped, so it is not reopened by the pool. Products closed by the old capacity rule carry the same
 * marker and are reopened once by a job queued by migrate().
 *
 * The one filter left is `woocommerce_product_is_in_stock`, and it only opens: a renewal, a switch and a resubscribe
 * of a subscription the logged-in customer owns and that holds this product (Billing\ExistingFlow decides) are never
 * blocked by a stored `outofstock`, because WooCommerce reads the stored status in is_in_stock() (add_to_cart(),
 * check_cart_item_stock(), the order-pay check). A renewal and a switch act on a machine that exists and has its
 * address, so they open a product the address pool closed; a resubscribe builds a new machine, so it opens a
 * hand-closed product only while an address is free and the cluster is saved (availability()).
 */
final class Stock {

	/**
	 * Marker on a product whose `outofstock` this class wrote, so only that one is reopened.
	 */
	public const META_CLOSED = '_beltoft_services_stock_closed';

	/**
	 * True while sync() writes: its own saves must not run product_saved() or forget_marker().
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Option that says the products closed by the old capacity rule were reopened.
	 */
	public const MIGRATED = 'beltoft_services_stock_policy';

	/**
	 * Action Scheduler hook of the one-off reopening job.
	 */
	public const MIGRATE_JOB = 'beltoft_services_stock_policy_migrate';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'migrate' ), 20 );
		add_action( self::MIGRATE_JOB, array( __CLASS__, 'run_migration' ) );
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 20, 2 );
		// The status follows a saved plan or driver, and an owner's own change to it is respected (see forget_marker()).
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'product_saved' ) );
		add_action( 'woocommerce_after_product_variation_object_save', array( __CLASS__, 'product_saved' ) );
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'forget_marker' ), 10, 2 );
		// The admin product form: out of stock selected there is the owner's choice, also when it did not change.
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'form_saved' ) );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'form_saved' ) );
		ExistingFlow::register();
		// Paying an existing renewal, switch or resubscribe order (woocommerce/includes/shortcodes/class-wc-shortcode-checkout.php:154).
		add_filter( 'woocommerce_pay_order_product_in_stock', array( __CLASS__, 'pay_order' ), 20, 3 );
	}

	/**
	 * Once, after the stock policy change (2026-11-11): queues the job that reopens the products the old capacity rule
	 * closed. A request only reads one option and queues one action (the catalogue is not loaded on a visitor's
	 * request); with no Action Scheduler the work runs here.
	 */
	public static function migrate(): void {
		if ( '' !== (string) get_option( self::MIGRATED, '' ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			Jobs::schedule_once( time(), self::MIGRATE_JOB );
			return;
		}

		self::run_migration();
	}

	/**
	 * The job: under a lock, one sync of every Proxmox product, then the option that says it ran (the products closed by
	 * the old rule carry the marker and are reopened; one the owner closed by hand has none and stays closed; one closed
	 * for lack of addresses or a fault is closed again by the same sync). A second run, at once or later, does nothing.
	 */
	public static function run_migration(): void {
		Locks::with(
			Locks::name( 'stock_policy', 0 ),
			0,
			static function (): void {
				wp_cache_delete( 'alloptions', 'options' ); // Another process may have finished while this one waited.
				wp_cache_delete( self::MIGRATED, 'options' );

				if ( '' !== (string) get_option( self::MIGRATED, '' ) ) {
					return;
				}

				self::sync();
				update_option( self::MIGRATED, '1' );
			}
		);
	}

	/**
	 * The add-on drivers sold next to a server: an add-on belongs to a server and is not built by itself.
	 *
	 * @return string[]
	 */
	public static function addon_drivers(): array {
		return Addon::driver_ids();
	}

	/**
	 * Every driver whose products this class looks after: the server driver and the add-ons.
	 *
	 * @return string[]
	 */
	public static function drivers(): array {
		return array_merge( array( ProxmoxDriver::ID, DbDriver::ID ), self::addon_drivers() );
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
	 * Filter woocommerce_product_is_in_stock: a renewal, switch or resubscribe of the logged-in customer's own
	 * subscription that holds this product is in stock even when the stored status is `outofstock`. It never closes
	 * anything. A resubscribe builds a new machine, so it opens a product only while availability() holds (a saved cluster and a free
	 * address, whoever closed the product): a hand-closed product with an empty pool stays closed for it.
	 *
	 * @param mixed $value   Value so far.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function in_stock( $value, $product ) {
		if ( ! $value && $product instanceof \WC_Product ) {
			$driver = Product::driver_for( $product->get_id() );

			if ( $driver && in_array( $driver->id(), self::drivers(), true ) ) {
				$kinds = ExistingFlow::kinds( $product );

				if ( array_intersect( $kinds, array( ExistingFlow::RENEWAL, ExistingFlow::SWITCH ) ) ) {
					return true;
				}

				if ( in_array( ExistingFlow::RESUBSCRIBE, $kinds, true ) && self::availability( $product ) ) {
					return true;
				}
			}
		}

		return $value;
	}

	/**
	 * Can this product be sold at all, as far as it cannot be oversold or works at all? Memory and storage are not asked.
	 * A machine needs a plan naming a saved cluster and a public address; a database needs the cluster, and an address
	 * only with the public IP add-on, and never the one kept for servers; an extra disk needs a size.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	private static function availability( \WC_Product $product ): bool {
		$driver = Product::driver_for( $product->get_id() );

		if ( $driver && in_array( $driver->id(), array( SnapshotDriver::ID, AppLicenceDriver::ID ), true ) ) {
			return true; // Slots and app licences need no address.
		}

		if ( $driver && DiskDriver::ID === $driver->id() ) {
			return (int) ( Product::plan( $product->get_id() )['disk_gb'] ?? 0 ) >= 1; // No size, no disk to make.
		}

		$plan = Product::plan( $product->get_id() );

		if ( $driver && in_array( $driver->id(), array( ProxmoxDriver::ID, DbDriver::ID ), true ) && ! isset( Clusters::all()[ (string) ( $plan['cluster'] ?? '' ) ] ) ) {
			return false; // A plan naming no saved cluster cannot be built.
		}

		if ( $driver && DbDriver::ID === $driver->id() ) {
			return ! ( (int) ( $plan['public_ip'] ?? 0 ) > 0 && 0 === IpPool::available_for_db() );
		}

		return 0 !== IpPool::free_count();
	}

	/**
	 * Writes availability to the stock status of every Proxmox product and variation.
	 *
	 * Only a changed status is saved (WooCommerce CRUD, so its hooks fire once, and not at all when
	 * nothing changed). A variable product's own status is synced by WooCommerce afterwards. Never throws:
	 * callers are the address pool and the cluster list, which must not fail on a product.
	 */
	public static function sync(): void {
		self::sync_ids( self::product_ids() );
	}

	/**
	 * Action woocommerce_after_product_object_save / _product_variation_object_save: a saved Proxmox
	 * product (new, or a changed plan or driver) is synced at once, so its stored status is right
	 * without waiting for the next pool or cluster change.
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
			self::sync_ids( array( $id ) );
		}
	}

	/**
	 * Action woocommerce_admin_process_product_object and _variation_object: the admin product form was posted. With out of
	 * stock selected that is the owner's choice, also when the status did not change (so forget_marker() never saw it): the
	 * marker goes, and the address pool or a fixed fault no longer reopens the product.
	 *
	 * @param mixed $product Product or variation, with the posted values set and not yet saved.
	 */
	public static function form_saved( $product ): void {
		if ( ! $product instanceof \WC_Product || 'outofstock' !== $product->get_stock_status( 'edit' ) ) {
			return;
		}

		if ( '' !== (string) $product->get_meta( self::META_CLOSED ) ) {
			$product->delete_meta_data( self::META_CLOSED );
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
	 */
	private static function sync_ids( array $ids ): void {
		if ( self::$writing || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		self::$writing = true;

		try {
			foreach ( $ids as $id ) {
				try {
					self::sync_product( (int) $id );
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
	 */
	private static function sync_product( int $id ): void {
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return;
		}

		if ( ! $product instanceof \WC_Product_Variable ) {
			self::apply( $product );
			return;
		}

		$changed = false;

		foreach ( $product->get_children() as $child_id ) {
			try {
				$variation = wc_get_product( $child_id );
				$changed   = ( $variation && self::apply( $variation ) ) || $changed;
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
	 * @return bool Whether the status was saved.
	 */
	private static function apply( \WC_Product $product ): bool {
		if ( $product->managing_stock() ) {
			return false; // Quantities decide; WooCommerce would reset the status anyway.
		}

		$available = self::availability( $product );

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
}
