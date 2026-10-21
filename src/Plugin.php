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
	 * Human names of the missing dependencies.
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

		/**
		 * Filters the list of missing dependencies.
		 *
		 * @param string[] $missing Human names of missing dependencies.
		 */
		$missing = apply_filters( 'beltoft_services_dependencies', $missing );

		return array_values( is_array( $missing ) ? $missing : array() );
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
