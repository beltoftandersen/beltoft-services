<?php
/**
 * Wiring.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks every part of the plugin into WordPress, once its dependencies are present.
 */
final class Plugin {

	/**
	 * Whether the last boot registered the plugin's parts.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Runs on plugins_loaded, priority 20.
	 */
	public static function boot(): void {
		self::$booted = false;

		if ( ! self::requirements_met() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );
			return;
		}

		remove_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );

		Settings::register();

		Product::register();

		Jobs::register();

		Http\Connections::register();
		Http\ConnectionsScreen::register();
		Checkout::register();

		Lifecycle::register();
		Retention::register();
		Cli\Commands::register();

		// Later tasks register their parts here.
		self::$booted = true;
	}

	/**
	 * Whether the plugin's parts were registered by the last boot.
	 */
	public static function booted(): bool {
		return self::$booted;
	}

	/**
	 * Whether every dependency is present.
	 */
	public static function requirements_met(): bool {
		return array() === self::missing();
	}

	/**
	 * Human names of the missing dependencies, including a database server that is too old.
	 *
	 * @return string[]
	 */
	public static function missing(): array {
		$missing = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}

		if ( ! class_exists( 'WC_Subscriptions' ) && ! function_exists( 'wcs_get_subscription' ) ) {
			$missing[] = 'WooCommerce Subscriptions';
		}

		if ( ! class_exists( \BeltoftLicenses\Licenses::class ) || ! method_exists( \BeltoftLicenses\Licenses::class, 'add_resource' ) ) {
			$missing[] = 'Beltoft Licenses 0.7.0 or newer';
		}

		global $wpdb;

		/**
		 * Filters the database server version string the requirement check reads
		 * (wpdb::db_server_info(), e.g. "8.0.36" or "5.5.5-10.11.14-MariaDB").
		 *
		 * @param string $info Server version string.
		 */
		$database = self::unsupported_database( (string) apply_filters( 'beltoft_services_database_server_info', (string) $wpdb->db_server_info() ) );

		if ( null !== $database ) {
			$missing[] = $database;
		}

		/**
		 * Filters the list of missing dependencies.
		 *
		 * @param string[] $missing Human names of missing dependencies.
		 */
		$missing = apply_filters( 'beltoft_services_dependencies', $missing );

		return array_values( is_array( $missing ) ? $missing : array() );
	}

	/**
	 * Why the database server cannot run the plugin, or null when it can. The resource lock
	 * is taken again inside a job that holds it, which needs re-entrant GET_LOCK: MySQL 5.7.5
	 * or MariaDB 10.0.2 and newer. An unreadable version string is not refused.
	 *
	 * @param string $info Server version string (wpdb::db_server_info()).
	 */
	public static function unsupported_database( string $info ): ?string {
		$mariadb = false !== stripos( $info, 'mariadb' );

		// MariaDB may report itself behind a "5.5.5-" prefix for old MySQL clients.
		if ( $mariadb ) {
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );
		}

		if ( ! preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', $info, $match ) ) {
			return null;
		}

		$version = $match[1];
		$minimum = $mariadb ? '10.0.2' : '5.7.5';

		if ( version_compare( $version, $minimum, '>=' ) ) {
			return null;
		}

		return sprintf( 'MySQL 5.7.5 or MariaDB 10.0.2 or newer, for re-entrant named locks (this server runs %s %s)', $mariadb ? 'MariaDB' : 'MySQL', $version );
	}

	/**
	 * Tells admins what is missing.
	 */
	public static function notice_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma separated plugin names. */
					__( 'Beltoft Services is inactive until these are active: %s.', 'beltoft-services' ),
					implode( ', ', self::missing() )
				)
			)
		);
	}
}
