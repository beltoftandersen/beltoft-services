<?php
/**
 * The "Billing" choice at checkout for a new VM purchase: Monthly or Pay as you go.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\BackupField;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\DiskField;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\SshKeyField;

defined( 'ABSPATH' ) || exit;

/**
 * Done as the backups box is (see BackupField): the pay as you go variation is the sibling of the monthly one (the
 * same plan, the same attributes but Billing; Rates::sibling()). Choosing swaps the cart's server for the sibling
 * through a wc-ajax endpoint, and the page then runs `update_checkout`. The field is shown for a cart that holds
 * one new server whose variation has the other billing model. A renewal, an early renewal or a switch never gets
 * it. The card rule is Cart's: a pay as you go server needs a card that can be charged later.
 *
 * The block checkout is not covered, as for the backups box.
 */
final class BillingField {

	public const AJAX   = 'beltoft_services_billing';
	public const NONCE  = 'beltoft-services-billing';
	public const HANDLE = 'beltoft-services-billing-field';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ), 11 );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * The one new server in the cart that the billing model can be chosen for.
	 *
	 * @return array{key: string, item: array<string, mixed>, variation: \WC_Product_Variation, usage: bool, monthly: \WC_Product_Variation, payg: \WC_Product_Variation}|null Null: no field.
	 */
	public static function cart_server(): ?array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || OsField::cart_is_existing_server() ) {
			return null;
		}

		$found = null;

		foreach ( $cart->get_cart() as $key => $item ) {
			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

			if ( ! $driver || SshKeyField::DRIVER !== $driver->id() ) {
				continue;
			}

			if ( null !== $found ) {
				return null;
			}

			$found = array( 'key' => (string) $key, 'item' => $item );
		}

		$variation = null === $found ? null : wc_get_product( (int) ( $found['item']['variation_id'] ?? 0 ) );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return null;
		}

		$usage = Rates::is_usage( $variation );
		$other = Rates::sibling( $variation, ! $usage );

		if ( null === $other || ! $other->is_purchasable() || ! $other->is_in_stock() ) {
			return null;
		}

		return array(
			'key'       => $found['key'],
			'item'      => $found['item'],
			'variation' => $variation,
			'usage'     => $usage,
			'monthly'   => $usage ? $other : $variation,
			'payg'      => $usage ? $variation : $other,
		);
	}

	/**
	 * The monthly cap of the plan, without and with daily backups ('' when that state does not exist).
	 *
	 * @param \WC_Product_Variation $monthly A monthly variation of the plan.
	 * @return array{0: string, 1: string}
	 */
	private static function caps( \WC_Product_Variation $monthly ): array {
		$has     = (int) ( Product::plan( $monthly->get_parent_id(), $monthly->get_id() )['backup_days'] ?? 0 ) > 0;
		$without = $has ? Backups::sibling( $monthly, false, true ) : $monthly;
		$with    = $has ? $monthly : Backups::sibling( $monthly, true, true );
		$cap     = static function ( ?\WC_Product_Variation $v ): string {
			$rates = $v ? Rates::for_plan( $v ) : null;

			return null === $rates ? '' : self::note( Rates::money( $rates['cap'] ) );
		};

		return array( $cap( $without ), $cap( $with ) );
	}

	/**
	 * The explanation under the choice.
	 *
	 * @param string $cap Monthly cap such as EUR 16.50.
	 */
	public static function note( string $cap ): string {
		/* translators: %s: monthly cap such as EUR 16.50 */
		return sprintf( __( 'Pay as you go: billed per hour each month, never more than %s. A card is required.', 'beltoft-services' ), $cap );
	}

	/**
	 * The radio buttons and the explanation.
	 */
	public static function render(): void {
		$server = self::cart_server();

		if ( null === $server ) {
			return;
		}

		$rates = Rates::for_plan( $server['monthly'] );

		if ( null === $rates ) {
			return;
		}

		$caps = self::caps( $server['monthly'] );
		$has  = (int) ( Product::plan( $server['monthly']->get_parent_id(), $server['variation']->get_id() )['backup_days'] ?? 0 ) > 0;

		echo '<div class="beltoft-services-billing" data-note="' . esc_attr( $caps[0] ) . '" data-note-backups="' . esc_attr( $caps[1] ) . '">';
		echo '<p class="form-row"><span class="beltoft-services-billing__legend">' . esc_html__( 'Billing', 'beltoft-services' ) . '</span></p>';

		foreach ( array(
			'monthly' => __( 'Monthly', 'beltoft-services' ),
			'usage'   => __( 'Pay as you go', 'beltoft-services' ),
		) as $value => $label ) {
			printf(
				'<p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox"><input type="radio" class="woocommerce-form__input input-radio" name="beltoft_services_billing" value="%1$s" data-nonce="%2$s" %3$s> <span>%4$s</span></label></p>',
				esc_attr( $value ),
				esc_attr( wp_create_nonce( self::NONCE ) ),
				checked( $server['usage'] === ( 'usage' === $value ), true, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description" data-billing-note>' . esc_html( $server['usage'] ? ( $has && '' !== $caps[1] ? $caps[1] : $caps[0] ) : '' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The script, on the checkout page only, and only when the field is shown.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || null === self::cart_server() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/billing-field.js', BELTOFT_SERVICES_FILE ), array( 'jquery' ), \BeltoftServices\Assets::version( 'assets/billing-field.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesBilling',
			array(
				'url'    => \WC_AJAX::get_endpoint( self::AJAX ),
				'failed' => __( 'The billing choice could not be changed. Please reload the page and try again.', 'beltoft-services' ),
			)
		);
	}

	/**
	 * The wc-ajax endpoint: answers with JSON.
	 */
	public static function ajax(): void {
		$answer = self::respond();

		wp_send_json( $answer[0], $answer[1] );
	}

	/**
	 * Nonce checked, then the swap.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The reply and its HTTP status.
	 */
	public static function respond(): array {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return array( array( 'ok' => false ), 403 );
		}

		$usage  = isset( $_POST['billing'] ) && 'usage' === sanitize_text_field( wp_unslash( $_POST['billing'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$result = self::swap( $usage );

		return array( $result, $result['ok'] ? 200 : 409 );
	}

	/**
	 * Swaps the cart's server for its sibling with the other billing model. Quantity and other item data are
	 * kept. When the add is refused the first item is put back.
	 *
	 * @param bool $usage Whether the cart should end up pay as you go.
	 * @return array{ok: bool, usage: bool, variation_id: int}
	 */
	public static function swap( bool $usage ): array {
		$server = self::cart_server();
		$cart   = function_exists( 'WC' ) ? WC()->cart : null;

		if ( null === $server || ! $cart ) {
			return array( 'ok' => false, 'usage' => $usage, 'variation_id' => 0 );
		}

		if ( $server['usage'] === $usage ) {
			return array( 'ok' => true, 'usage' => $usage, 'variation_id' => $server['variation']->get_id() );
		}

		$target = $server['usage'] ? $server['monthly'] : $server['payg'];
		$item   = $server['item'];
		$qty    = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$data   = array_diff_key( $item, array_flip( array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ) ) );

		$added = DiskField::during_server_swap(
			static function () use ( $cart, $server, $item, $qty, $target, $data ): bool {
				$cart->remove_cart_item( $server['key'] );
				$new = $cart->add_to_cart( (int) $item['product_id'], $qty, $target->get_id(), BackupField::attributes( $target ), $data );

				if ( false !== $new ) {
					BackupField::server_first( $cart, (string) $new );

					return true;
				}

				$back = $cart->add_to_cart( (int) $item['product_id'], $qty, $server['variation']->get_id(), BackupField::attributes( $server['variation'] ), $data );

				if ( false !== $back ) {
					BackupField::server_first( $cart, (string) $back );
				}

				return false;
			}
		);
		$cart->calculate_totals();

		return $added
			? array( 'ok' => true, 'usage' => $usage, 'variation_id' => $target->get_id() )
			: array( 'ok' => false, 'usage' => $server['usage'], 'variation_id' => $server['variation']->get_id() );
	}
}
