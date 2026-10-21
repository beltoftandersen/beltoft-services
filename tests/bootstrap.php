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

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $beltoft_services_site, $beltoft_services_licenses ) {
		if ( $beltoft_services_site && file_exists( $beltoft_services_site . '/woocommerce/woocommerce.php' ) ) {
			require $beltoft_services_site . '/woocommerce/woocommerce.php';
		}

		if ( $beltoft_services_site && file_exists( $beltoft_services_site . '/woocommerce-subscriptions/woocommerce-subscriptions.php' ) ) {
			require $beltoft_services_site . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
		}

		require $beltoft_services_licenses . '/beltoft-licenses.php';
		require dirname( __DIR__ ) . '/beltoft-services.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}

		\BeltoftLicenses\Install::create_tables();
		\BeltoftLicenses\Install::ensure_secret();
	}
);

require $beltoft_services_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
