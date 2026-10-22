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
		}

		\BeltoftLicenses\Install::create_tables();
		\BeltoftLicenses\Install::ensure_secret();
		\BeltoftServices\Proxmox\Schema::create_tables();
	},
	20
);

require $beltoft_services_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/Support/FakeDriver.php';
require __DIR__ . '/TestCase.php';
