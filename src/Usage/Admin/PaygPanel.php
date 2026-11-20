<?php
/**
 * The Billing block of the Service panel in the product editor.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Admin;

use BeltoftServices\Currency;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * One select (Monthly subscription, Pay as you go by the hour, Pay per unit) offering only what the driver of the product can be
 * billed by, the premium for an hourly product, a preview of the rates and the checks. The kind is stored on the product and written
 * only when it changed.
 */
final class PaygPanel {

	public const NONCE  = 'beltoft_services_payg';
	public const AJAX   = 'beltoft_services_payg_checks';
	public const HANDLE = 'beltoft-services-payg-admin';

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		// The premium field of Usage\CoreHooks is replaced by this block's own.
		remove_action( 'beltoft_services_product_panel_after_reminder', array( \BeltoftServices\Usage\CoreHooks::class, 'premium_field' ), 10 );
		remove_action( 'beltoft_services_product_save', array( \BeltoftServices\Usage\CoreHooks::class, 'save_premium' ), 30 );

		add_action( 'beltoft_services_product_panel_after_reminder', array( __CLASS__, 'block' ), 10, 2 );
		add_action( 'beltoft_services_product_save', array( __CLASS__, 'save' ), 30, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax_checks' ) );
		PaygVariation::register();
	}

	/**
	 * The script and style of the block, on the product screen only.
	 */
	public static function assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/payg-admin.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/payg-admin.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/payg-admin.js', BELTOFT_SERVICES_FILE ), array( 'jquery' ), \BeltoftServices\Assets::version( 'assets/payg-admin.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftPayg',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'steps'   => PaygVariation::steps_message( '%1$s', '%2$s' ),
				'live'    => PaygVariation::live_text( '%1$s', '%2$s' ),
				'hours'   => Rates::HOURS,
				'default' => Rates::DEFAULT_PREMIUM,
				'premium' => array(
					'bad'  => __( 'The premium must be a number of 0 or more.', 'beltoft-services' ),
					'high' => __( 'The premium is above 100 percent: the monthly cap is more than double the monthly price.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * The choices of the select for a driver (and the kind already stored, so it is never dropped silently).
	 *
	 * @param string $driver_id Driver id.
	 * @param string $current   Stored kind.
	 * @return array<string, string>
	 */
	public static function options( string $driver_id, string $current = '' ): array {
		$support = PaygProduct::support( $driver_id );
		$options = array( '' => __( 'Monthly subscription', 'beltoft-services' ) );

		if ( $support['hourly'] || Rates::KIND_HOURLY === $current ) {
			$options[ Rates::KIND_HOURLY ] = __( 'Pay as you go by the hour', 'beltoft-services' );
		}

		if ( $support['counted'] || Rates::KIND_COUNTED === $current ) {
			$options[ Rates::KIND_COUNTED ] = __( 'Pay per unit', 'beltoft-services' );
		}

		return $options;
	}

	/**
	 * Prints the block.
	 *
	 * @param mixed $product   The product being edited, null for a new one.
	 * @param mixed $driver_id The saved driver id.
	 */
	public static function block( $product = null, $driver_id = '' ): void {
		$product = $product instanceof \WC_Product ? $product : null;

		printf(
			'<div class="bs-payg" data-bs-product="%1$d" data-bs-driver="%2$s"><h3 class="bs-payg-heading">%3$s</h3>',
			$product ? (int) $product->get_id() : 0,
			esc_attr( (string) $driver_id ),
			esc_html__( 'Billing', 'beltoft-services' )
		);
		echo '<p class="description bs-payg-driver-note" style="display:none">' . esc_html__( 'Save to see the billing options of this service.', 'beltoft-services' ) . '</p>';

		if ( ! $product || '' === (string) $driver_id ) {
			echo '<p class="description bs-payg-none">' . esc_html__( 'Choose a service driver and save to see the billing options.', 'beltoft-services' ) . '</p></div>';

			return;
		}

		if ( ! PaygProduct::offers( $product ) ) {
			echo '<p class="description bs-payg-none">' . esc_html__( 'This service cannot be billed by use. It is sold as a monthly subscription.', 'beltoft-services' ) . '</p></div>';

			return;
		}

		$kind = PaygProduct::kind( $product );

		echo '<div class="bs-payg-body">';

		woocommerce_wp_select(
			array(
				'id'          => Rates::META_BILLING,
				'label'       => __( 'Billing', 'beltoft-services' ),
				'description' => __( 'Monthly subscription is paid in advance. Pay as you go is billed after use and needs a Variable product. Changing it is refused while a service runs on this product.', 'beltoft-services' ),
				'desc_tip'    => false,
				'options'     => self::options( (string) $driver_id, $kind ),
				'value'       => $kind,
			)
		);

		printf( '<div class="bs-payg-premium"%s>', Rates::KIND_HOURLY === $kind ? '' : ' style="display:none"' );
		woocommerce_wp_text_input(
			array(
				'id'                => Rates::META_PREMIUM,
				'label'             => __( 'Premium (%)', 'beltoft-services' ),
				'description'       => __( 'Added to the monthly price for the hourly rate and the monthly cap. Empty means 10.', 'beltoft-services' ),
				'desc_tip'          => false,
				'type'              => 'number',
				'placeholder'       => (string) (int) Rates::DEFAULT_PREMIUM,
				'custom_attributes' => array( 'min' => '0', 'step' => '0.1' ),
				'value'             => $product->get_meta( Rates::META_PREMIUM ),
			)
		);
		echo '<p class="form-field bs-payg-premium-msg" role="alert" style="display:none"><label></label><span></span></p></div>';

		if ( '' !== $kind ) {
			self::preview( $product, $kind );
			self::running_note( $product );
		}

		echo '<div class="bs-payg-problems">' . self::problems_html( $product ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div></div>';
	}

	/**
	 * The rates per plan and currency. The browser works the hourly figures out again while the premium is typed; the saved ones are the server's.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $kind    Kind.
	 */
	private static function preview( \WC_Product $product, string $kind ): void {
		$rows    = PaygPreview::rows( $product );
		$codes   = Currency::known_codes();
		$hourly  = Rates::KIND_HOURLY === $kind;
		$columns = $hourly ? 3 : 1;

		if ( ! $rows ) {
			return;
		}

		echo '<div class="bs-payg-wrap"><div class="bs-payg-scroll"><table class="widefat bs-payg-preview"><thead><tr><th rowspan="2">' . esc_html__( 'Plan', 'beltoft-services' ) . '</th>';

		foreach ( $codes as $code ) {
			echo '<th colspan="' . (int) $columns . '" class="bs-payg-code">' . esc_html( $code ) . '</th>';
		}

		echo '</tr><tr>';

		foreach ( $codes as $unused ) {
			echo $hourly
				? '<th>' . esc_html__( 'Monthly', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Per hour', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Max per month', 'beltoft-services' ) . '</th>'
				: '<th>' . esc_html( sprintf( /* translators: %s: number of units */ __( '%s units', 'beltoft-services' ), number_format_i18n( PaygPreview::EXAMPLE_UNITS ) ) ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td>';

			foreach ( $codes as $code ) {
				$p = $row['prices'][ $code ];

				if ( $hourly && null !== $p['monthly'] ) {
					printf(
						'<td>%1$s</td><td class="bs-payg-hourly" data-monthly="%4$s">%2$s</td><td class="bs-payg-cap" data-monthly="%4$s">%3$s</td>',
						esc_html( number_format( $p['monthly'], 2, '.', '' ) ),
						esc_html( number_format( (float) $p['hourly'], 4, '.', '' ) ),
						esc_html( number_format( (float) $p['cap'], 2, '.', '' ) ),
						esc_attr( (string) $p['monthly'] )
					);
				} elseif ( ! $hourly && null !== $p['example'] ) {
					echo '<td>' . esc_html( number_format( $p['example'], 2, '.', '' ) ) . '</td>';
				} else {
					echo str_repeat( '<td>-</td>', (int) $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				}
			}

			echo '</tr>';
		}

		echo '</tbody></table></div><p class="description">' . esc_html__( 'Prices excl. VAT. The meter reads these figures every hour.', 'beltoft-services' ) . '</p></div>';
	}

	/**
	 * How many services are billed from these prices, stressed when there are some.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function running_note( \WC_Product $product ): void {
		$ids = array_map( static fn( $v ) => $v->get_id(), PaygProduct::variations( $product ) );

		if ( ! $ids ) {
			return;
		}

		$count = PaygProduct::running( $ids );
		$text  = sprintf(
			/* translators: %d: number of services */
			__( 'Services billed from these prices: %d. A change applies from the next hour.', 'beltoft-services' ),
			$count
		);

		echo $count > 0
			? '<p class="bs-payg-running"><strong>' . esc_html( $text ) . '</strong></p>'
			: '<p class="description">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The check list: the problems and notes (prefixed, not only coloured), or one line when all pass. Empty for a monthly product.
	 *
	 * @param \WC_Product $product Product.
	 * @return string HTML.
	 */
	public static function problems_html( \WC_Product $product ): string {
		$checks = PaygChecks::run( $product );
		$colour = array(
			PaygChecks::WARN  => '#dba617',
			PaygChecks::ERROR => '#d63638',
		);
		$items  = '';

		if ( ! $checks ) {
			return '';
		}

		foreach ( $checks as $check ) {
			if ( PaygChecks::OK === $check['level'] ) {
				continue;
			}

			$plans = array();

			foreach ( $check['plans'] as $id => $label ) {
				$plans[] = '<a href="#" class="bs-payg-goto" data-bs-variation="' . (int) $id . '">' . esc_html( $label ) . '</a>';
			}

			$items .= sprintf(
				'<li class="bs-payg-problem-%1$s" style="border-left:4px solid %2$s"><strong>%3$s</strong> %4$s %5$s</li>',
				esc_attr( $check['level'] ),
				esc_attr( $colour[ $check['level'] ] ),
				esc_html( PaygChecks::ERROR === $check['level'] ? __( 'Problem:', 'beltoft-services' ) : __( 'Note:', 'beltoft-services' ) ),
				esc_html( $check['text'] ),
				implode( ', ', $plans )
			);
		}

		return '' === $items
			? '<p class="bs-payg-allpass"><span style="color:#00a32a">&#10003;</span> ' . esc_html__( 'All checks pass', 'beltoft-services' ) . '</p>'
			: '<ul class="bs-payg-problem-list">' . $items . '</ul>';
	}

	/**
	 * Answers the screen script after variations were saved: the check list again.
	 */
	public static function ajax_checks(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;

		if ( ! $id || ! current_user_can( 'edit_product', $id ) ) {
			wp_send_json_error( null, 403 );
		}

		$product = wc_get_product( $id );

		if ( ! $product instanceof \WC_Product ) {
			wp_send_json_error( null, 404 );
		}

		wp_send_json_success( array( 'html' => self::problems_html( $product ) ) );
	}

	/**
	 * Saves the billing choice and the premium. Each is written only when it changed; a refused one leaves the saved value and says
	 * why. WooCommerce has verified the form's nonce and the user's capability.
	 *
	 * @param mixed $product   The product.
	 * @param mixed $driver_id The saved driver id.
	 */
	public static function save( $product, $driver_id = '' ): void {
		if ( ! $product instanceof \WC_Product || '' === $driver_id ) {
			return;
		}

		self::save_kind( $product, (string) $driver_id );
		self::save_premium( $product );
	}

	/**
	 * The billing choice.
	 *
	 * @param \WC_Product $product   Product.
	 * @param string      $driver_id Driver id.
	 */
	private static function save_kind( \WC_Product $product, string $driver_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		if ( ! isset( $_POST[ Rates::META_BILLING ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted = sanitize_key( wp_unslash( $_POST[ Rates::META_BILLING ] ) );
		$had    = PaygProduct::kind( $product );

		if ( $posted === $had ) {
			return;
		}

		if ( ! isset( self::options( $driver_id, $had )[ $posted ] ) ) {
			return;
		}

		if ( '' !== $posted && 'variable' !== $product->get_type() ) {
			PaygVariation::refuse( __( 'Pay as you go needs a Variable product, not a subscription product. Create it as a Variable product. The billing was not changed.', 'beltoft-services' ) );

			return;
		}

		$ids = array_map( static fn( $v ) => $v->get_id(), PaygProduct::variations( $product ) );

		if ( $product->get_id() > 0 && $ids && PaygProduct::running( $ids ) > 0 ) {
			PaygVariation::refuse( __( 'A service runs on this product, so its billing cannot be changed. It was not changed.', 'beltoft-services' ) );

			return;
		}

		if ( '' === $posted ) {
			$product->delete_meta_data( Rates::META_BILLING );
		} else {
			$product->update_meta_data( Rates::META_BILLING, $posted );
		}
	}

	/**
	 * The premium. A value that is not a number of 0 or more is refused and the saved one stays.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function save_premium( \WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		if ( ! isset( $_POST[ Rates::META_PREMIUM ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted = trim( sanitize_text_field( wp_unslash( $_POST[ Rates::META_PREMIUM ] ) ) );
		$had    = trim( (string) $product->get_meta( Rates::META_PREMIUM ) );

		if ( '' !== $posted && ( ! is_numeric( $posted ) || (float) $posted < 0 ) ) {
			PaygVariation::refuse( __( 'The premium must be a number of 0 or more. It was not changed.', 'beltoft-services' ) );

			return;
		}

		if ( $posted === $had || ( '' !== $posted && '' !== $had && is_numeric( $had ) && (float) $had === (float) $posted ) ) {
			return;
		}

		$product->update_meta_data( Rates::META_PREMIUM, '' === $posted ? '' : (string) (float) $posted );
	}
}
