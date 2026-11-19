<?php
/**
 * WooCommerce → Services.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Admin;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Network\IpWho;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The list of live resources (activations whose kind belongs to a registered driver),
 * filtered by driver, state and failed-only, and searched (see search_ids()). Each row links to its license screen, where the
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
	 * @return array{driver: string, state: string, failed: bool, s: string, deleted: bool}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$driver  = isset( $_GET['driver'] ) ? sanitize_key( wp_unslash( $_GET['driver'] ) ) : '';
		$state   = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$failed  = ! empty( $_GET['failed'] );
		$search  = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) ), 0, 100 ) : '';
		$deleted = ! empty( $_GET['deleted'] );
		// phpcs:enable

		return array(
			'driver'  => null !== Registry::get( $driver ) ? $driver : '',
			'state'   => in_array( $state, Resource::STATES, true ) ? $state : '',
			'failed'  => $failed,
			's'       => $search,
			'deleted' => $deleted,
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
	 * The kinds a driver filter names (every registered kind without one).
	 *
	 * @param string $driver Driver id or empty.
	 * @return string[]
	 */
	private static function kinds( string $driver ): array {
		if ( '' === $driver ) {
			return Registry::kinds();
		}

		$one = Registry::get( $driver );

		return $one ? array( $one->kind() ) : array();
	}

	/**
	 * Does a loaded resource pass the driver, state and failed-only filters?
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $filters Filters.
	 */
	private static function passes( Resource $r, array $filters ): bool {
		$driver = (string) ( $filters['driver'] ?? '' );
		$state  = (string) ( $filters['state'] ?? '' );

		if ( '' !== $driver && $r->is_valid() && $r->get( 'driver' ) !== $driver ) {
			return false;
		}

		if ( '' !== $state && $r->get( 'state' ) !== $state ) {
			return false;
		}

		return empty( $filters['failed'] ) || 'failed' === $r->get( 'state' );
	}

	/**
	 * Ids of the services matching the search text, newest first, closed ones only with "include deleted".
	 *
	 * @param array<string, mixed> $filters driver, s, deleted.
	 * @return int[]
	 */
	public static function search_ids( array $filters ): array {
		$term = trim( (string) ( $filters['s'] ?? '' ) );

		return Resource::search_ids( $term, self::kinds( (string) ( $filters['driver'] ?? '' ) ), ! empty( $filters['deleted'] ), self::matching_users( $term ), self::address_holders( $term ) );
	}

	/**
	 * Resources that hold or held an address according to the plugin's own address tables (pool, holding log, a server's
	 * reserved IPv6), through the abuse lookup that owns them. Empty for a term that is not an address.
	 *
	 * @param string $term Search text.
	 * @return int[]
	 */
	private static function address_holders( string $term ): array {
		if ( false === filter_var( $term, FILTER_VALIDATE_IP ) ) {
			return array();
		}

		return array_map( static fn( array $h ) => (int) $h['resource_id'], IpWho::lookup( $term ) );
	}

	/**
	 * Ids of the users a term names: email (partial), login, display name, billing first name, last name and company, or
	 * "first last". A number or an IP address names none here (a number is a user id and is matched on the license).
	 *
	 * @param string $term Search text.
	 * @return int[]
	 */
	private static function matching_users( string $term ): array {
		if ( '' === $term || ctype_digit( $term ) || false !== filter_var( $term, FILTER_VALIDATE_IP ) ) {
			return array();
		}

		$ids = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'fields'         => 'ID',
				'number'         => 200,
			)
		);

		$billing = array( 'relation' => 'OR' );

		foreach ( array( 'billing_first_name', 'billing_last_name', 'billing_company' ) as $key ) {
			$billing[] = array(
				'key'     => $key,
				'value'   => $term,
				'compare' => 'LIKE',
			);
		}

		if ( preg_match( '/^(\S+)\s+(.+)$/u', $term, $m ) ) {
			$billing[] = array(
				'relation' => 'AND',
				array(
					'key'     => 'billing_first_name',
					'value'   => $m[1],
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'billing_last_name',
					'value'   => $m[2],
					'compare' => 'LIKE',
				),
			);
		}

		$more = get_users(
			array(
				'meta_query' => $billing, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin search, one query.
				'fields'     => 'ID',
				'number'     => 200,
			)
		);

		return array_values( array_unique( array_map( 'intval', array_merge( $ids, $more ) ) ) );
	}

	/**
	 * Resources matching the filters, newest first. A resource whose metadata is missing reads as failed. Without a search
	 * only live ones; a search finds closed ones too when the filters ask for them.
	 *
	 * @param array<string, mixed> $filters driver, state, failed, s, deleted.
	 * @return Resource[]
	 */
	public static function resources( array $filters ): array {
		$driver    = (string) ( $filters['driver'] ?? '' );
		$resources = array();

		if ( '' !== trim( (string) ( $filters['s'] ?? '' ) ) ) {
			foreach ( Resource::find_many( self::search_ids( $filters ) ) as $id => $r ) {
				if ( self::passes( $r, $filters ) ) {
					$resources[ $id ] = $r;
				}
			}

			return array_values( $resources );
		}

		foreach ( self::kinds( $driver ) as $kind ) {
			// One read per 500 ids (Resource::find_many), not one per resource.
			foreach ( Resource::find_many( Resource::live_ids( $kind, 0, 0, 'DESC' ) ) as $id => $r ) {
				if ( self::passes( $r, $filters ) ) {
					$resources[ $id ] = $r;
				}
			}
		}

		krsort( $resources );

		return array_values( $resources );
	}

	/**
	 * One page of the list and the total. With no driver, state or failed filter only the ids are read for the count and the page, and only
	 * the resources of the page are loaded; a filter reads them all (resources()).
	 *
	 * @param array<string, mixed> $filters  driver, state, failed, s, deleted.
	 * @param int                  $offset   First row.
	 * @param int                  $per_page Rows.
	 * @return array{items: Resource[], total: int}
	 */
	public static function page( array $filters, int $offset, int $per_page ): array {
		if ( '' !== (string) ( $filters['driver'] ?? '' ) || '' !== (string) ( $filters['state'] ?? '' ) || ! empty( $filters['failed'] ) ) {
			$all = self::resources( $filters );

			return array(
				'items' => array_slice( $all, $offset, $per_page ),
				'total' => count( $all ),
			);
		}

		if ( '' !== trim( (string) ( $filters['s'] ?? '' ) ) ) {
			$ids = self::search_ids( $filters );
		} else {
			$ids = array();

			foreach ( self::kinds( (string) ( $filters['driver'] ?? '' ) ) as $kind ) {
				foreach ( Resource::live_ids( $kind, 0, 0, 'DESC' ) as $id ) {
					$ids[ (int) $id ] = (int) $id;
				}
			}

			krsort( $ids );
			$ids = array_values( $ids );
		}

		return array(
			'items' => array_values( Resource::find_many( array_slice( $ids, $offset, $per_page ) ) ),
			'total' => count( $ids ),
		);
	}

	/**
	 * The search box, always shown (WP_List_Table::search_box() hides itself while the list is empty, which is when a search
	 * most needs to stay).
	 */
	private static function search_box(): void {
		$term = self::filters()['s'];

		echo '<p class="search-box"><label class="screen-reader-text" for="bs-search">' . esc_html__( 'Search services', 'beltoft-services' ) . '</label>';
		echo '<input type="search" id="bs-search" name="s" value="' . esc_attr( $term ) . '" size="34" placeholder="' . esc_attr__( 'IP, name, domain, customer, number', 'beltoft-services' ) . '">';
		submit_button( __( 'Search services', 'beltoft-services' ), '', '', false, array( 'id' => 'bs-search-submit' ) );
		echo '</p>';
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
		self::search_box();
		$table->display();
		echo '</form></div>';
	}
}
