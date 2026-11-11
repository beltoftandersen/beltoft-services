<?php
/**
 * PHPUnit bootstrap.
 *
 * Runs against the WordPress test suite's own throwaway install (WP_TESTS_DIR). WooCommerce,
 * WooCommerce Subscriptions and beltoft-licenses are loaded by absolute path; the runner
 * provides phpunit and the test library:
 *
 *   /root/plugin-tests/run.sh <path-to-this-plugin>
 *
 * @package BeltoftServices
 */

// Never runnable over HTTP: it requires files by path and boots a test install.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$beltoft_services_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';
$beltoft_services_site      = getenv( 'SITE_PLUGINS' );
$beltoft_services_licenses  = getenv( 'BELTOFT_LICENSES_DIR' ) ? getenv( 'BELTOFT_LICENSES_DIR' ) : dirname( __DIR__, 2 ) . '/beltoft-licenses';

require_once $beltoft_services_tests_dir . '/includes/functions.php';

foreach ( array( 'woocommerce/woocommerce.php', 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) as $beltoft_services_required ) {
	if ( ! $beltoft_services_site || ! file_exists( $beltoft_services_site . '/' . $beltoft_services_required ) ) {
		fwrite( STDERR, "Test bootstrap: SITE_PLUGINS must point at a plugins folder containing {$beltoft_services_required}.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		exit( 1 );
	}
}

if ( ! file_exists( $beltoft_services_licenses . '/beltoft-licenses.php' ) ) {
	fwrite( STDERR, "Test bootstrap: beltoft-licenses not found at {$beltoft_services_licenses} (set BELTOFT_LICENSES_DIR).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $beltoft_services_site, $beltoft_services_licenses ) {
		require $beltoft_services_site . '/woocommerce/woocommerce.php';
		require $beltoft_services_licenses . '/beltoft-licenses.php';
		require dirname( __DIR__ ) . '/beltoft-services.php';
	}
);

// WooCommerce Subscriptions must load after WooCommerce has set up Action Scheduler.
tests_add_filter(
	'plugins_loaded',
	static function () use ( $beltoft_services_site ) {
		require $beltoft_services_site . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
	},
	1
);

// After Action Scheduler's data store is ready (init priority 1) and WooCommerce has
// registered its post types (init 5): installing WooCommerce sets options that WooCommerce
// Subscriptions answers by unscheduling actions and loading orders.
tests_add_filter(
	'init',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			// On a new database the install can skip its work (its lock) and the shop roles come later, so admin
			// capability checks would fail on the first run only. Make them now; it is idempotent.
			WC_Install::create_roles();
			wp_roles()->for_site();
		}

		\BeltoftLicenses\Install::create_tables();
		\BeltoftLicenses\Install::ensure_secret();
		\BeltoftServices\Proxmox\Vm\Schema::create_tables();
		\BeltoftServices\Hosting\Schema::create_tables();

		// The WordPress test installer empties the posts table on every run (so order ids start low again) but not
		// WooCommerce's order item tables. Lines that a test committed (wcs_create_renewal_order() commits) outlive
		// their order and would join a later order or subscription that gets the same id. Drop those orphans.
		global $wpdb;
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test database reset, fixed table names.
		// An item is an orphan when its order is in neither the posts table nor, where the table exists, the HPOS orders table.
		$hpos = $wpdb->prefix . 'wc_orders';
		$join = "LEFT JOIN {$wpdb->posts} p ON p.ID = i.order_id";
		$none = 'p.ID IS NULL';
		if ( $hpos === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) ) {
			$join .= " LEFT JOIN {$hpos} h ON h.id = i.order_id";
			$none .= ' AND h.id IS NULL';
		}
		$wpdb->query( "DELETE m FROM {$meta} m LEFT JOIN {$items} i ON i.order_item_id = m.order_item_id {$join} WHERE {$none}" );
		$wpdb->query( "DELETE i FROM {$items} i {$join} WHERE {$none}" );
		$wpdb->query( 'COMMIT' );
		// phpcs:enable
	},
	20
);

require $beltoft_services_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/Support/FakeDriver.php';
require __DIR__ . '/Support/DnsPackets.php';
require __DIR__ . '/Support/FakeCurrency.php';
require __DIR__ . '/TestCase.php';
