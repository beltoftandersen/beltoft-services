<?php
/**
 * The options of an order line in the words the customer saw.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * When an order line is made at checkout, the chosen options of the product (its variation attributes, "Billing: Pay as you go")
 * are kept on the line as the customer saw them, in the language of the checkout. An accounting system can print them on the invoice
 * in that language; the shop's own attribute keys and values stay as they are. Renewals copy the line, and so the text.
 */
final class LineOptions {

	/** Order item meta: a JSON list of array( label, value, key ) (key: the attribute, "plan", "pa_size"). Hidden from the order screens. */
	public const META = '_beltoft_services_options';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'keep' ), 20, 1 );
	}

	/**
	 * Keeps the options of a new order line.
	 *
	 * @param mixed $item Order line.
	 */
	public static function keep( $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$options = self::of( $item );

		if ( array() !== $options ) {
			$item->update_meta_data( self::META, wp_json_encode( $options ) );
		}
	}

	/**
	 * The options of a line as the customer sees them now: array( label, value, key ) for each visible attribute meta of the line.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function of( \WC_Order_Item_Product $item ): array {
		$product = $item->get_product();
		$names   = $product instanceof \WC_Product_Variation ? array_keys( $product->get_variation_attributes( false ) ) : array();
		$rows    = array();

		// Built here rather than with get_formatted_meta_data(), which skips meta that is not saved yet (a line at checkout).
		foreach ( $item->get_meta_data() as $index => $meta ) {
			$key = (string) $meta->key;

			// Only the product's own attributes: other plugins' visible meta (a gift note, a domain name) is not an option.
			if ( ! is_scalar( $meta->value ) || '' === (string) $meta->value || ( ! in_array( $key, $names, true ) && 0 !== strpos( $key, 'pa_' ) ) ) {
				continue;
			}

			$value = (string) $meta->value;
			$shown = $value;

			if ( taxonomy_exists( $key ) ) {
				$term  = get_term_by( 'slug', $value, $key );
				$shown = $term instanceof \WP_Term ? $term->name : $value;
			}

			$rows[ 'o' . $index ] = (object) array(
				'key'           => $key,
				'value'         => $value,
				// The display filters of the order screens: a language plugin translates labels and values there.
				'display_key'   => apply_filters( 'woocommerce_order_item_display_meta_key', wc_attribute_label( $key, $product ), $meta, $item ),
				'display_value' => apply_filters( 'woocommerce_order_item_display_meta_value', $shown, $meta, $item ),
			);
		}

		// The same filter as the order screens, so what they hide (a billing choice, "no backups") is not kept either.
		$rows = (array) apply_filters( 'woocommerce_order_item_get_formatted_meta_data', $rows, $item );
		$out  = array();

		foreach ( $rows as $row ) {
			$label = trim( wp_strip_all_tags( (string) ( $row->display_key ?? '' ) ) );
			$value = trim( html_entity_decode( wp_strip_all_tags( (string) ( $row->display_value ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );

			if ( '' !== $label && '' !== $value ) {
				$out[] = array( $label, $value, (string) ( $row->key ?? '' ) );
			}
		}

		return $out;
	}
}
