<?php
/**
 * Plugin Name:          Beltoft Services for WooCommerce
 * Plugin URI:           https://beltoft.net/
 * Description:          Sell services with WooCommerce Subscriptions: a license is the entitlement, and a driver provisions, pauses, resumes and removes the service, driven by Beltoft Licenses.
 * Version:              0.23.8
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
define( 'BELTOFT_SERVICES_VERSION', '0.23.8' );

/*
 * Old class names that outside code still calls (the site theme, the OdooWoo portal and system scripts; see tests/fixtures/external-api.php)
 * and that moved to a new namespace: old name => new name. The autoloader loads the new class and aliases the old name to it.
 * Only classes listed in tests/fixtures/external-api.php are here (ExternalApiTest reads that list through these aliases).
 */
define(
	'BELTOFT_SERVICES_CLASS_ALIASES',
	array(
		'BeltoftServices\\Proxmox\\AppField'    => 'BeltoftServices\\Apps\\AppField',
		'BeltoftServices\\Proxmox\\AppLicence'  => 'BeltoftServices\\Apps\\AppLicence',
		'BeltoftServices\\Proxmox\\Backups'      => 'BeltoftServices\\Proxmox\\Backup\\Backups',
		'BeltoftServices\\Proxmox\\Clients'      => 'BeltoftServices\\Proxmox\\Pve\\Clients',
		'BeltoftServices\\Proxmox\\Clusters'     => 'BeltoftServices\\Proxmox\\Pve\\Clusters',
		'BeltoftServices\\Proxmox\\DbIpField'     => 'BeltoftServices\\Proxmox\\Db\\DbIpField',
		'BeltoftServices\\Proxmox\\DiskField'    => 'BeltoftServices\\Proxmox\\Disk\\DiskField',
		'BeltoftServices\\Proxmox\\OsField'      => 'BeltoftServices\\Proxmox\\Vm\\OsField',
		'BeltoftServices\\Proxmox\\Picked'       => 'BeltoftServices\\Proxmox\\Vm\\Picked',
		'BeltoftServices\\Proxmox\\ServerName'   => 'BeltoftServices\\Proxmox\\Vm\\ServerName',
		'BeltoftServices\\Proxmox\\SshKeyField'  => 'BeltoftServices\\Proxmox\\Vm\\SshKeyField',
	)
);

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BeltoftServices\\';

		if ( isset( BELTOFT_SERVICES_CLASS_ALIASES[ $class_name ] ) ) {
			if ( class_exists( BELTOFT_SERVICES_CLASS_ALIASES[ $class_name ] ) ) {
				class_alias( BELTOFT_SERVICES_CLASS_ALIASES[ $class_name ], $class_name );
			}
			return;
		}

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
register_activation_hook( __FILE__, array( \BeltoftServices\Proxmox\Vm\Schema::class, 'activate' ) );
// Clusters that are saved already get their capacity cached at once, so the stock is not closed for an hour.
register_activation_hook( __FILE__, array( \BeltoftServices\Proxmox\Pve\CapacityJobs::class, 'activate' ) );

register_activation_hook( __FILE__, array( \BeltoftServices\Jobs::class, 'activate' ) );

// The sweep, the retention run and the usage check stop with the plugin; pending reconcile jobs keep their place.
register_deactivation_hook( __FILE__, array( \BeltoftServices\Jobs::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Retention::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Hosting\Addons::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Domains\Renewals::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Proxmox\Vm\ProxmoxJobs::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Ssl\SslJobs::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Ssl\SslRenewals::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Emails\Emails::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Usage\Meter::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Domains\DnsClaimJobs::class, 'unschedule' ) );
register_deactivation_hook( __FILE__, array( \BeltoftServices\Domains\DnsReleaseJob::class, 'unschedule' ) );

// Translations: the plugin's own .mo files in /languages (the site's language packs in wp-content/languages load first).
add_action( 'init', array( \BeltoftServices\Plugin::class, 'load_textdomain' ), 1 );

// After beltoft-licenses (priority 10), so its classes and hooks exist.
add_action( 'plugins_loaded', array( \BeltoftServices\Plugin::class, 'boot' ), 20 );
