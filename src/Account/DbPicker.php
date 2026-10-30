<?php
/**
 * My Account → Databases → New database: choose a plan and go on to checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DbEngine;
use BeltoftServices\Proxmox\PostgresEngine;
use BeltoftServices\Proxmox\DbNeedsServer;
use BeltoftServices\Proxmox\DiskCart;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\BackupField;
use BeltoftServices\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * The page is the address /my-account/databases/new/ (the Databases endpoint with the value "new"), laid out like the
 * New server page (ServerPicker, same markup and style): the plans of the published managed database products, found
 * by driver. A database has no operating system, app, name or key to choose, so a plan is all there is. A plain form
 * posted to the same address puts the plan in the cart and goes to checkout. A customer without a server with us gets
 * the notice of DbNeedsServer above the plans (it never refuses). Nothing here calls Proxmox: stock is the cached capacity.
 */
final class DbPicker {

	public const NONCE = 'beltoft-services-db-picker';
	public const FIELD = 'beltoft_services_db_picker_nonce';

	/**
	 * What the page prints after a refused post.
	 *
	 * @var array<string, string>
	 */
	private static $errors = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'submit' ), 5 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 16, 2 );
	}

	/**
	 * The address of the page.
	 */
	public static function url(): string {
		return ServiceArea::get( Databases::class )->url_new();
	}

	/**
	 * Is the request for the New database page?
	 */
	private static function on_page(): bool {
		$area = ServiceArea::get( Databases::class );

		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( $area->slug() ) && $area->is_new();
	}

	// -- Plans -------------------------------------------------------------------------------------

	/**
	 * The plans on offer by variation id: the published variations of the published variable products of the
	 * managed database driver.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function plans(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 30,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => Product::META_DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the catalogue of databases is small and bounded.
				'meta_value'     => DbDriver::ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		/**
		 * The products the New database page offers (ids). A site can narrow or reorder them.
		 *
		 * @param int[] $ids Product ids.
		 */
		$filtered = apply_filters( 'beltoft_services_db_picker_products', array_map( 'intval', $ids ) );
		$plans    = array();

		foreach ( is_array( $filtered ) ? $filtered : $ids as $id ) {
			$product = wc_get_product( (int) $id );

			if ( ! $product instanceof \WC_Product_Variable || 'publish' !== $product->get_status() ) {
				continue;
			}

			foreach ( $product->get_children() as $child ) {
				$variation = wc_get_product( (int) $child );

				if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() || '' === (string) $variation->get_price() || \BeltoftServices\Usage\Rates::is_usage( $variation ) ) {
					continue;
				}

				$numbers = Product::plan( $product->get_id(), $variation->get_id() );
				$days    = max( 0, (int) ( $numbers['backup_days'] ?? 0 ) );

				$plans[ $variation->get_id() ] = array(
					'product_id'   => $product->get_id(),
					'variation_id' => $variation->get_id(),
					'name'         => Offers::label( $product, $variation ),
					'price'        => (float) wc_get_price_excluding_tax( $variation ),
					'period'       => Offers::period( $variation ),
					'cores'        => (int) ( $numbers['cores'] ?? 0 ),
					'memory_mb'    => (int) ( $numbers['memory_mb'] ?? 0 ),
					'disk_gb'      => (int) ( $numbers['disk_gb'] ?? 0 ),
					'major'        => (int) ( $numbers['major'] ?? 0 ),
					'engine'       => (string) ( $numbers['engine'] ?? 'postgres' ),
					'cluster'      => (string) ( $numbers['cluster'] ?? '' ),
					'backup_days'  => 0 === $days ? Backups::DB_DEFAULT_DAYS : $days,
					'in_stock'     => Offers::in_stock( $variation ),
				);
			}
		}

		return $plans;
	}

	// -- The post ----------------------------------------------------------------------------------

	/**
	 * Early on the page: a visitor who is not logged in goes to the login and comes back here; a post is checked and,
	 * when it holds, the cart is filled and the customer goes to checkout. A refused post prints the page again.
	 */
	public static function submit(): void {
		if ( ! self::on_page() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( self::url() ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in process().
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in process() and the plan is checked against what is offered.
		$result = self::process( wp_unslash( $_POST ), get_current_user_id() );

		if ( '' !== $result['redirect'] ) {
			wp_safe_redirect( $result['redirect'] );
			exit;
		}
	}

	/**
	 * Checks a post and, when it holds, puts the database in the cart.
	 *
	 * @param array<string, mixed> $post Raw post (unslashed): the nonce and the plan (a variation id).
	 * @param int                  $user Customer.
	 * @return array{redirect: string, errors: array<string, string>}
	 */
	public static function process( array $post, int $user ): array {
		self::$errors = array();

		$fail = static function ( string $field, string $message ): array {
			self::$errors[ $field ] = $message;

			return array(
				'redirect' => '',
				'errors'   => self::$errors,
			);
		};

		if ( $user <= 0 || false === wp_verify_nonce( isset( $post[ self::FIELD ] ) && is_string( $post[ self::FIELD ] ) ? $post[ self::FIELD ] : '', self::NONCE ) ) {
			return $fail( 'form', __( 'Your session has expired. Reload the page and try again.', 'beltoft-services' ) );
		}

		// The limit of the server picker, with its filter (see ServerPicker::process()).
		if ( RateLimit::exceeded( $user, 'beltoft-services:picker', (int) apply_filters( 'beltoft_services_picker_rate_limit', 20 ), HOUR_IN_SECONDS ) ) {
			return $fail( 'form', __( 'Too many tries. Please wait a while and try again.', 'beltoft-services' ) );
		}

		$id   = isset( $post[ ServerPicker::PLAN ] ) && is_scalar( $post[ ServerPicker::PLAN ] ) ? absint( (string) $post[ ServerPicker::PLAN ] ) : 0;
		$plan = self::plans()[ $id ] ?? null;

		if ( null === $plan ) {
			return $fail( 'plan', __( 'Choose a plan.', 'beltoft-services' ) );
		}

		if ( ! $plan['in_stock'] ) {
			return $fail( 'plan', __( 'That plan is out of stock. Choose another plan.', 'beltoft-services' ) );
		}

		/**
		 * Whether this customer can order this database plan now (a site rule).
		 *
		 * @param bool                 $allowed Default true.
		 * @param array<string, mixed> $plan    The plan.
		 * @param int                  $user    Customer.
		 */
		if ( ! apply_filters( 'beltoft_services_db_picker_can_buy', true, $plan, $user ) ) {
			return $fail( 'plan', __( 'This plan cannot be ordered right now.', 'beltoft-services' ) );
		}

		$added = self::add_to_cart( $plan );

		if ( null !== $added ) {
			return $fail( 'form', $added );
		}

		return array(
			'redirect' => wc_get_checkout_url(),
			'errors'   => array(),
		);
	}

	/**
	 * Replaces an earlier try's database in the cart with this one, through WooCommerce's add-to-cart so the shop's rules apply.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @return string|null The refusal text, or null when the cart holds the database.
	 */
	private static function add_to_cart( array $plan ): ?string {
		$cart      = function_exists( 'WC' ) ? WC()->cart : null;
		$variation = wc_get_product( (int) $plan['variation_id'] );

		if ( ! $cart || ! $variation instanceof \WC_Product_Variation ) {
			return __( 'The database could not be added. Please try again.', 'beltoft-services' );
		}

		$old = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			// A payment for a subscription that exists is never touched or mixed with a new database.
			if ( DiskCart::flow( (array) $item ) ) {
				return __( 'Finish or remove the subscription payment in your cart first.', 'beltoft-services' );
			}

			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

			if ( $driver && DbDriver::ID === $driver->id() ) {
				$old[] = (string) $key;
			}
		}

		wc_clear_notices();

		$key = $cart->add_to_cart( (int) $plan['product_id'], 1, $variation->get_id(), BackupField::attributes( $variation ) );

		if ( false !== $key ) {
			// The same plan is "already in the cart" and returns its line: that one stays.
			foreach ( $old as $old_key ) {
				if ( (string) $key !== $old_key ) {
					$cart->remove_cart_item( $old_key );
				}
			}

			$cart->calculate_totals();

			return null;
		}

		$notes = array();

		foreach ( (array) wc_get_notices( 'error' ) as $notice ) {
			$text = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;

			if ( '' !== $text ) {
				$notes[] = wp_strip_all_tags( $text );
			}
		}

		wc_clear_notices();

		return array() !== $notes ? implode( ' ', $notes ) : __( 'The database could not be added. Please try again.', 'beltoft-services' );
	}

	// -- Assets ------------------------------------------------------------------------------------

	/**
	 * The page's style and script (those of the New server page, which this page's markup shares). Nothing loads elsewhere.
	 *
	 * @param mixed $parent   Handle of the generic account script.
	 * @param mixed $endpoint The endpoint being shown.
	 */
	public static function assets( $parent = null, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && ServiceArea::get( Databases::class )->slug() !== $endpoint ) || ! self::on_page() || ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_style( ServerPicker::HANDLE, plugins_url( 'assets/picker.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/picker.css' ) );
		wp_enqueue_script( ServerPicker::HANDLE, plugins_url( 'assets/picker.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/picker.js' ), true );
	}

	// -- The page ----------------------------------------------------------------------------------

	/**
	 * Does the customer lack a server with us on a cluster the plans are on (so the notice is shown)?
	 *
	 * @param array<int, array<string, mixed>> $plans Plans.
	 * @param int                              $user  Customer (0 when logged out).
	 */
	public static function needs_notice( array $plans, int $user ): bool {
		$clusters = array_values( array_unique( array_map( static fn( $plan ): string => (string) $plan['cluster'], $plans ) ) );

		return DbNeedsServer::refused( $user, $clusters );
	}

	/**
	 * Prints the page.
	 */
	public static function render(): void {
		$plans    = self::plans();
		$selected = self::selected( $plans );

		echo '<div class="beltoft-services-picker">';
		echo '<p class="beltoft-services-server__back"><a href="' . esc_url( ServiceArea::get( Databases::class )->url_list() ) . '">&lsaquo; ' . esc_html( ServiceArea::get( Databases::class )->all_label() ) . '</a></p>';
		echo '<h2 class="beltoft-services-picker__title">' . esc_html__( 'New database', 'beltoft-services' ) . '</h2>';

		if ( array() === $plans ) {
			echo '<p class="beltoft-services-notice" role="status">' . esc_html__( 'No database can be ordered right now.', 'beltoft-services' ) . '</p></div>';

			return;
		}

		echo '<p class="beltoft-services-picker__intro">' . esc_html__( 'Choose a plan. You pay on the next page.', 'beltoft-services' ) . '</p>';

		if ( self::needs_notice( $plans, get_current_user_id() ) ) {
			echo '<p class="beltoft-services-notice" role="status">' . wp_kses( DbNeedsServer::message( true ), array( 'a' => array( 'href' => array() ) ) ) . '</p>';
		}

		self::error( 'form' );

		echo '<form class="beltoft-services-picker__form" method="post" action="' . esc_url( self::url() ) . '">';
		wp_nonce_field( self::NONCE, self::FIELD );

		$available = array_filter( $plans, static fn( $plan ) => ! empty( $plan['in_stock'] ) );

		echo '<fieldset class="beltoft-services-picker__plans"><legend class="beltoft-services-picker__legend">' . esc_html__( 'Plan', 'beltoft-services' ) . '</legend>';

		if ( array() === $available ) {
			echo '<p class="beltoft-services-picker__soldout">' . esc_html__( 'Every plan is out of stock right now. Please check back soon.', 'beltoft-services' ) . '</p>';
		}

		echo '<div class="beltoft-services-picker__grid">';

		foreach ( $plans as $plan ) {
			self::card( $plan, (int) $plan['variation_id'] === $selected );
		}

		echo '</div>';
		self::error( 'plan' );
		echo '</fieldset>';

		echo '<div class="beltoft-services-picker__go">';
		echo '<p class="beltoft-services-picker__summary" data-picker-summary aria-live="polite">' . esc_html( isset( $plans[ $selected ] ) ? self::summary( $plans[ $selected ] ) : '' ) . '</p>';
		echo '<button type="submit" class="beltoft-services-picker__button" data-picker-submit' . ( isset( $plans[ $selected ] ) ? '' : ' disabled' ) . '>' . esc_html__( 'Continue to checkout', 'beltoft-services' ) . '</button>';
		echo '</div></form></div>';
	}

	/**
	 * The plan that starts selected: ?plan= when it can be bought, else the first that can be; 0 when none can.
	 *
	 * @param array<int, array<string, mixed>> $plans Plans.
	 */
	private static function selected( array $plans ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a starting choice that only selects among the offered plans.
		$asked = isset( $_GET['plan'] ) ? absint( wp_unslash( $_GET['plan'] ) ) : 0;

		if ( $asked > 0 && ! empty( $plans[ $asked ]['in_stock'] ) ) {
			return $asked;
		}

		foreach ( $plans as $id => $plan ) {
			if ( ! empty( $plan['in_stock'] ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * "PostgreSQL 18", "Valkey": the engine and, when the plan names one, its major version.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	private static function engine( array $plan ): string {
		return ( DbEngine::for_plan( $plan ) ?? new PostgresEngine() )->label( $plan );
	}

	/**
	 * One line for the chosen plan: "Starter: €25.00 per month excluding VAT".
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	private static function summary( array $plan ): string {
		$money = html_entity_decode( wp_strip_all_tags( wc_price( (float) $plan['price'] ) ), ENT_QUOTES, 'UTF-8' );

		if ( '' === (string) $plan['period'] ) {
			/* translators: 1: plan name, 2: price such as €25.00 */
			return sprintf( __( '%1$s: %2$s excluding VAT', 'beltoft-services' ), (string) $plan['name'], $money );
		}

		/* translators: 1: plan name, 2: price such as €25.00, 3: period such as month */
		return sprintf( __( '%1$s: %2$s per %3$s excluding VAT', 'beltoft-services' ), (string) $plan['name'], $money, (string) $plan['period'] );
	}

	/**
	 * One plan card, the markup of the server plan cards: a radio, the name, the price and the numbers.
	 *
	 * @param array<string, mixed> $plan     Plan.
	 * @param bool                 $selected Checked.
	 */
	private static function card( array $plan, bool $selected ): void {
		$stock = ! empty( $plan['in_stock'] );
		$attrs = array(
			'data-cores'       => (string) $plan['cores'],
			'data-memory'      => (string) $plan['memory_mb'],
			'data-disk'        => (string) $plan['disk_gb'],
			'data-summary'     => self::summary( $plan ),
			'data-has-backups' => '0',
		);

		echo '<label class="beltoft-services-plan' . ( $stock ? '' : ' is-disabled' ) . '">';
		echo '<input class="beltoft-services-plan__input" type="radio" name="' . esc_attr( ServerPicker::PLAN ) . '" value="' . esc_attr( (string) $plan['variation_id'] ) . '"';
		checked( $selected, true );
		disabled( ! $stock, true );

		foreach ( $attrs as $name => $value ) {
			echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		echo '>';
		echo '<span class="beltoft-services-plan__face">';
		echo '<span class="beltoft-services-plan__name">' . esc_html( (string) $plan['name'] ) . '</span>';
		echo '<span class="beltoft-services-plan__price"><b>' . wp_kses_post( wc_price( (float) $plan['price'] ) ) . '</b> <span>' . esc_html( ServerPicker::per( (string) $plan['period'] ) ) . '</span></span>';
		echo '<span class="beltoft-services-plan__specs">';
		echo '<span>' . esc_html( self::engine( $plan ) ) . '</span>';
		/* translators: %d: number of virtual CPU cores */
		echo '<span>' . esc_html( sprintf( _n( '%d vCPU', '%d vCPU', (int) $plan['cores'], 'beltoft-services' ), (int) $plan['cores'] ) ) . '</span>';
		echo '<span>' . esc_html( ServerPicker::memory( (int) $plan['memory_mb'] ) ) . '</span>';
		/* translators: %d: data disk size in gigabytes */
		echo '<span>' . esc_html( sprintf( __( '%d GB data disk', 'beltoft-services' ), (int) $plan['disk_gb'] ) ) . '</span>';
		/* translators: %d: number of days backups are kept */
		echo '<span>' . esc_html( sprintf( _n( 'Daily backups, kept %d day', 'Daily backups, kept %d days', (int) $plan['backup_days'], 'beltoft-services' ), (int) $plan['backup_days'] ) ) . '</span>';
		echo '</span>';

		if ( ! $stock ) {
			echo '<span class="beltoft-services-plan__stock">' . esc_html__( 'Out of stock', 'beltoft-services' ) . '</span>';
		}

		echo '</span></label>';
	}

	/**
	 * Prints a message at a field.
	 *
	 * @param string $field Field.
	 */
	private static function error( string $field ): void {
		if ( isset( self::$errors[ $field ] ) ) {
			echo '<p class="beltoft-services-picker__error" role="alert">' . esc_html( self::$errors[ $field ] ) . '</p>';
		}
	}
}
