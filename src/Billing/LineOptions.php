<?php
/**
 * The options of an order line in the words the customer saw.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * When an order line is made at checkout, it keeps one line of text for the invoice, in the language of the checkout: the product's
 * name with its main choice, "Ekstra disk - 100 GB", "WordPress webhotel - Pro", "Domænenavn - eksempel.dk". An accounting system
 * prints it as the line. Renewals copy the line, and so the text.
 */
final class LineOptions {

	/** Order item meta: the invoice text of the line. Hidden from the order screens. */
	public const META = '_beltoft_services_invoice_text';

	/** The option keys whose value names the line, in this order (the plan, else the size, and so on). */
	private const MAIN_KEYS = array( 'plan', 'size', 'pack', 'tier', 'type', 'licence', 'domain' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'keep' ), 20, 1 );
	}

	/**
	 * Keeps the invoice text of a new order line.
	 *
	 * @param mixed $item Order line.
	 */
	public static function keep( $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$text = self::text( $item );

		if ( '' !== $text ) {
			$item->update_meta_data( self::META, $text );
		}
	}

	/**
	 * The invoice text of a line: the product's name, then " - " and the value of its first main option.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 */
	public static function text( \WC_Order_Item_Product $item ): string {
		$title = self::title( $item );

		if ( '' === $title ) {
			return '';
		}

		$values = array();

		foreach ( self::of( $item ) as $pair ) {
			$key = strtolower( preg_replace( '/^pa_/', '', (string) ( $pair[2] ?? '' ) ) );

			if ( in_array( $key, self::MAIN_KEYS, true ) && ! isset( $values[ $key ] ) ) {
				$values[ $key ] = (string) $pair[1];
			}
		}

		foreach ( self::MAIN_KEYS as $key ) {
			if ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				return $title . ' - ' . $values[ $key ];
			}
		}

		return $title;
	}

	/**
	 * The product's name for the invoice line, in the order's language: the parent product's name of a variation (the options are
	 * kept apart), through the filter beltoft_services_line_title, where the site gives its own name in that language.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 */
	public static function title( \WC_Order_Item_Product $item ): string {
		$product = $item->get_product();

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$name   = $parent instanceof \WC_Product ? $parent->get_name() : $product->get_name();
		$order  = $item->get_order();
		$title  = $name;
		$write  = static function () use ( &$title, $name, $item, $parent ): void {
			/**
			 * The name of a product on an invoice line, in the language in force (the order's).
			 *
			 * @param string                     $name    The product's name in the shop.
			 * @param \WC_Order_Item_Product|null $item    The line, or null for a usage line.
			 * @param \WC_Product|null            $product The product (the parent of a variation).
			 */
			$title = (string) apply_filters( 'beltoft_services_line_title', $name, $item, $parent instanceof \WC_Product ? $parent : null );
		};

		if ( $order instanceof \WC_Order ) {
			\BeltoftServices\OrderLanguage::run( $order, $write );
		} else {
			$write();
		}

		return trim( $title );
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

		/**
		 * The options of an order line in the customer's words, for a feature to add what is not a product attribute (a domain).
		 *
		 * @param array<int, array{0: string, 1: string, 2: string}> $out  array( label, value, key ) pairs.
		 * @param \WC_Order_Item_Product                             $item The line.
		 */
		return (array) apply_filters( 'beltoft_services_line_options', $out, $item );
	}
}
