<?php
/**
 * WooCommerce → Services.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Admin;

use BeltoftLicenses\Install;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The list of live resources (activations whose kind belongs to a registered driver),
 * filtered by driver, state and failed-only. Each row links to its license screen, where the
 * service panel has the actions.
 */
final class Screen {

	public const PAGE            = 'beltoft-services-list';
	public const PER_PAGE_OPTION = 'beltoft_services_per_page';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 61 );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( __CLASS__, 'save_per_page' ), 10, 3 );
	}

	/**
	 * WooCommerce → Services.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Services', 'beltoft-services' ),
			__( 'Services', 'beltoft-services' ),
			Panel::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
		}
	}

	/**
	 * The per-page screen option.
	 */
	public static function load(): void {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => self::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Keeps the per-page screen option.
	 *
	 * @param mixed  $keep   Default.
	 * @param string $option Option.
	 * @param mixed  $value  Value.
	 * @return mixed
	 */
	public static function save_per_page( $keep, $option, $value ) {
		return self::PER_PAGE_OPTION === $option ? max( 1, min( 200, (int) $value ) ) : $keep;
	}

	/**
	 * The list filters from the request; unknown values are dropped.
	 *
	 * @return array{driver: string, state: string, failed: bool}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$driver = isset( $_GET['driver'] ) ? sanitize_key( wp_unslash( $_GET['driver'] ) ) : '';
		$state  = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$failed = ! empty( $_GET['failed'] );
		// phpcs:enable

		return array(
			'driver' => null !== Registry::get( $driver ) ? $driver : '',
			'state'  => in_array( $state, Resource::STATES, true ) ? $state : '',
			'failed' => $failed,
		);
	}

	/**
	 * The page URL with parameters; empty ones are left out.
	 *
	 * @param array<string, mixed> $args Parameters.
	 */
	public static function url( array $args = array() ): string {
		$args = array_filter(
			array_merge( array( 'page' => self::PAGE ), $args ),
			static fn( $value ) => null !== $value && '' !== $value && false !== $value
		);

		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Live resources matching the filters, newest first. A resource whose metadata is missing
	 * reads as failed.
	 *
	 * @param array<string, mixed> $filters driver, state, failed.
	 * @return Resource[]
	 */
	public static function resources( array $filters ): array {
		global $wpdb;

		$driver = (string) ( $filters['driver'] ?? '' );
		$state  = (string) ( $filters['state'] ?? '' );
		$failed = ! empty( $filters['failed'] );

		if ( '' !== $driver ) {
			$one   = Registry::get( $driver );
			$kinds = $one ? array( $one->kind() ) : array();
		} else {
			$kinds = Registry::kinds();
		}

		$resources = array();

		foreach ( $kinds as $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, admin screen, always fresh.
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE kind = %s AND deactivated_at IS NULL ORDER BY id DESC', Install::tables()['activations'], $kind ) );

			foreach ( array_map( 'intval', (array) $ids ) as $id ) {
				$r = Resource::find( $id );

				if ( ! $r ) {
					continue;
				}

				if ( '' !== $driver && $r->is_valid() && $r->get( 'driver' ) !== $driver ) {
					continue;
				}

				if ( '' !== $state && $r->get( 'state' ) !== $state ) {
					continue;
				}

				if ( $failed && 'failed' !== $r->get( 'state' ) ) {
					continue;
				}

				$resources[ $id ] = $r;
			}
		}

		krsort( $resources );

		return array_values( $resources );
	}

	/**
	 * The page.
	 */
	public static function render(): void {
		if ( ! current_user_can( Panel::CAPABILITY ) ) {
			return;
		}

		$table = new ListTable();
		$table->prepare_items();

		echo '<div class="wrap"><h1>' . esc_html__( 'Services', 'beltoft-services' ) . '</h1>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		$table->display();
		echo '</form></div>';
	}
}
