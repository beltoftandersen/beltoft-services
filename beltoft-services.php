<?php
/**
 * Plugin Name:          Beltoft Services for WooCommerce
 * Plugin URI:           https://beltoft.net/
 * Description:          Sell services with WooCommerce Subscriptions: a license is the entitlement, and a driver provisions, pauses, resumes and removes the service, driven by Beltoft Licenses.
 * Version:              0.6.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce, woocommerce-subscriptions, beltoft-licenses
 * Author:               beltoft.net
 * Author URI:           https://beltoft.net/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          beltoft-services
 * Domain Path:          /languages
 * WC requires at least: 9.0
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

define( 'BELTOFT_SERVICES_FILE', __FILE__ );
define( 'BELTOFT_SERVICES_PATH', plugin_dir_path( __FILE__ ) );
define( 'BELTOFT_SERVICES_VERSION', '0.6.0' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BeltoftServices\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = BELTOFT_SERVICES_PATH . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BELTOFT_SERVICES_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BELTOFT_SERVICES_FILE, true );
		}
	}
);

// Creates the IP pool and VM tables; an update runs Schema::maybe_upgrade() from Plugin::boot().
register_activation_hook( __FILE__, array( \BeltoftServices\Proxmox\Schema::class, 'activate' ) );
// Clusters that are saved already get their capacity cached at once, so the stock is not closed for an hour.
register_activation_hook( __FILE__, array( \BeltoftServices\Proxmox\ProxmoxJobs::class, 'activate' ) );

// The sweep, the retention run and the usage check stop with the plugin; pending reconcile jobs keep their place.
register_deactivation_hook( __FILE__, array( \BeltoftServices\Jobs::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Retention::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Proxmox\ProxmoxJobs::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Emails\Emails::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Domains\DnsClaimJobs::class, 'unschedule' ) );

// After beltoft-licenses (priority 10), so its classes and hooks exist.
add_action( 'plugins_loaded', array( \BeltoftServices\Plugin::class, 'boot' ), 20 );
