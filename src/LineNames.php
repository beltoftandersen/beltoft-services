<?php
/**
 * One readable name for this plugin's products in emails, cart, checkout and My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce names a variation line "VPS hosting - Starter, Daily" (or just "VPS hosting", with the attributes as
 * meta lines). Here every line of a service product reads the same way: "VPS hosting Starter, daily backups",
 * "VPS hosting Starter, pay as you go", "Extra disk 50 GB for web1", "OdooWoo Pro, billed monthly". Only the display
 * changes (the filters run when a page or email is rendered): stored order data is untouched, and the attribute
 * lines that the name already says are left out of the meta list.
 */
final class LineNames {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'order_item_name' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_item_name' ), 10, 2 );
		add_filter( 'woocommerce_display_item_meta', array( __CLASS__, 'display_item_meta' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 30, 2 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'usage_meta' ), 20, 2 );
	}

	/**
	 * The attribute rows of a pay-as-you-go line that say nothing: "Billing" (the name says pay as you go) and the
	 * add-ons that were not chosen ("Backups: None", "Public IP: No", "Extra storage: None"). The thank-you page
	 * (the Bricks order details element), the order emails and My Account all read the formatted meta, so this one
	 * filter covers them. Real choices (plan, OS, app, server name, an add-on that was chosen) stay, and so does
	 * every row of a monthly line.
	 *
	 * @param mixed $meta Formatted meta rows (stdClass keyed by meta id).
	 * @param mixed $item Order item.
	 * @return mixed
	 */
	public static function usage_meta( $meta, $item = null ) {
		if ( ! is_array( $meta ) || ! $item instanceof \WC_Order_Item_Product || ! self::is_usage( $item->get_product() ) ) {
			return $meta;
		}

		foreach ( $meta as $id => $row ) {
			$slug  = sanitize_title( (string) preg_replace( '/^(attribute_)?(pa_)?/', '', (string) ( $row->key ?? '' ) ) );
			// The stored value, not the shown one: a translated "None" (Danish "Ingen") is still none.
			$value = strtolower( trim( wp_strip_all_tags( (string) ( $row->value ?? $row->display_value ?? '' ) ) ) );

			if ( 'billing' === $slug || ( in_array( $slug, array( 'backups', 'public-ip', 'extra-storage' ), true ) && in_array( $value, array( 'none', 'no', '' ), true ) ) ) {
				unset( $meta[ $id ] );
			}
		}

		return $meta;
	}

	/**
	 * A billing period or a backup frequency, as the attribute value of a product names it ("monthly", "daily"), in the language of the
	 * page: the plugin's own strings for the usual ones, the value itself for any other. The value is lower case.
	 *
	 * @param string $low Value in lower case.
	 */
	public static function period_word( string $low ): string {
		switch ( $low ) {
			case 'monthly':
				return __( 'monthly', 'beltoft-services' );
			case 'yearly':
				return __( 'yearly', 'beltoft-services' );
			case 'annually':
				return __( 'annually', 'beltoft-services' );
			case 'quarterly':
				return __( 'quarterly', 'beltoft-services' );
			case 'weekly':
				return __( 'weekly', 'beltoft-services' );
			case 'daily':
				return __( 'daily', 'beltoft-services' );
			case 'hourly':
				return __( 'hourly', 'beltoft-services' );
			default:
				return $low;
		}
	}

	/**
	 * The note under a pay-as-you-go line.
	 *
	 * @param string $context 'order' or 'cart'.
	 */
	public static function usage_note( string $context = 'order' ): string {
		/**
		 * The note under a pay-as-you-go line, for the feature that bills it.
		 *
		 * @param string $note    Empty until the feature says it.
		 * @param string $context 'order' or 'cart': both follow the cart's tax display, like the figures next to the note.
		 */
		return (string) apply_filters( 'beltoft_services_line_usage_note', '', $context );
	}

	/**
	 * Is this line billed by use (pay as you go)? The feature that bills it says so.
	 *
	 * @param mixed $product Product or variation of the line, or its id.
	 */
	private static function is_usage( $product ): bool {
		/**
		 * Is a product billed by use?
		 *
		 * @param bool  $usage   False until the feature says so.
		 * @param mixed $product Product, variation or id.
		 */
		return (bool) apply_filters( 'beltoft_services_line_is_usage', false, $product );
	}

	/**
	 * Is this the hidden pay-as-you-go account product?
	 *
	 * @param int $product_id Product id.
	 */
	private static function is_account_product( int $product_id ): bool {
		/**
		 * The id of the hidden pay-as-you-go account product.
		 *
		 * @param int $id 0 until the feature says it.
		 */
		$id = (int) apply_filters( 'beltoft_services_line_account_product_id', 0 );

		return $id > 0 && $id === $product_id;
	}

	/**
	 * The name of a line.
	 *
	 * @param mixed $product        Product (variation) of the line.
	 * @param mixed $disk_server_id License id of the server an extra disk is for, when known.
	 * @return string '' when the line keeps WooCommerce's name.
	 */
	public static function label( $product, $disk_server_id = 0 ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		if ( self::is_account_product( $product->get_id() ) ) {
			return __( 'Pay as you go account', 'beltoft-services' );
		}

		if ( ! $product instanceof \WC_Product_Variation || ! Product::is_service( $product->get_id() ) ) {
			return '';
		}

		$driver = Product::driver_for( $product->get_parent_id() );

		if ( ! $driver || in_array( $driver->id(), array( 'domain', 'dns' ), true ) ) {
			return '';
		}

		$parent = wc_get_product( $product->get_parent_id() );

		if ( ! $parent ) {
			return '';
		}

		$name   = $parent->get_name();
		$plain  = array();
		$extras = array();

		foreach ( $product->get_attributes() as $key => $value ) {
			$value = self::value( $product, $parent, (string) $key, (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$slug = preg_replace( '/^(attribute_)?(pa_)?/', '', sanitize_title( (string) $key ) );
			$low  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

			if ( 'backups' === $slug ) {
				if ( in_array( $low, array( 'none', 'no' ), true ) ) {
					continue;
				}
				/* translators: %s: backup frequency, such as "daily". */
				$extras[] = sprintf( __( '%s backups', 'beltoft-services' ), self::period_word( $low ) );
			} elseif ( 'billing' === $slug ) {
				$extras[] = self::is_usage( $product ) ? __( 'pay as you go', 'beltoft-services' ) : sprintf( /* translators: %s: billing period, such as "monthly". */ __( 'billed %s', 'beltoft-services' ), self::period_word( $low ) );
			} elseif ( 'extra-storage' === $slug ) {
				if ( in_array( $low, array( 'none', 'no' ), true ) ) {
					continue;
				}
				/* translators: %s: size, such as "100 GB". */
				$extras[] = sprintf( __( '%s extra storage', 'beltoft-services' ), $value );
			} elseif ( 'public-ip' === $slug ) {
				if ( in_array( $low, array( 'none', 'no' ), true ) ) {
					continue;
				}
				$extras[] = __( 'public IPv4', 'beltoft-services' );
			} else {
				$plain[] = $value;
			}
		}

		// "Notifier - Pro" with the plan "Pro": the plan already says it.
		$dash = strrpos( $name, ' - ' );

		if ( array() !== $plain && false !== $dash && 0 === stripos( $plain[0], substr( $name, $dash + 3 ) ) ) {
			$name = substr( $name, 0, $dash );
		}

		$label = trim( $name . ' ' . implode( ' ', $plain ) );

		/**
		 * The name of a line before its extras: a feature adds what belongs to its own product (the server an extra
		 * disk is for).
		 *
		 * @param string $label          Name and plain attribute values.
		 * @param string $driver_id      The driver of the product.
		 * @param mixed  $disk_server_id The licence id of the server the line is for, when known.
		 */
		$label = (string) apply_filters( 'beltoft_services_line_label', $label, (string) $driver->id(), $disk_server_id );

		return array() === $extras ? $label : $label . ', ' . implode( ', ', $extras );
	}

	/**
	 * The value of a variation attribute as the customer sees it.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param \WC_Product           $parent    Parent.
	 * @param string                $key       Attribute name.
	 * @param string                $value     Stored value.
	 */
	public static function value( \WC_Product_Variation $variation, \WC_Product $parent, string $key, string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$name = preg_replace( '/^attribute_/', '', $key );

		if ( taxonomy_exists( $name ) ) {
			$term = get_term_by( 'slug', $value, $name );

			return $term && ! is_wp_error( $term ) ? (string) $term->name : $value;
		}

		foreach ( $parent->get_attributes() as $attribute ) {
			if ( sanitize_title( $attribute->get_name() ) !== sanitize_title( $name ) ) {
				continue;
			}

			foreach ( $attribute->get_options() as $option ) {
				if ( is_scalar( $option ) && ( (string) $option === $value || sanitize_title( (string) $option ) === sanitize_title( $value ) ) ) {
					return (string) $option;
				}
			}
		}

		return $value;
	}

	/**
	 * Puts a new text in a name that may be a link.
	 *
	 * @param string $html  Name as WooCommerce built it.
	 * @param string $label New text.
	 */
	public static function swap( string $html, string $label ): string {
		if ( false !== strpos( $html, '<a ' ) ) {
			return (string) preg_replace( '~(<a\b[^>]*>).*?(</a>)~s', '${1}' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), esc_html( $label ) ) . '${2}', $html, 1 );
		}

		return esc_html( $label );
	}

	/**
	 * Order and subscription lines.
	 *
	 * @param mixed $name Name html.
	 * @param mixed $item Order item.
	 * @return mixed
	 */
	public static function order_item_name( $name, $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $name;
		}

		$label = self::label( $item->get_product(), $item->get_meta( '_beltoft_services_disk_server' ) );

		return '' === $label ? $name : self::swap( (string) $name, $label );
	}

	/**
	 * Cart and checkout lines.
	 *
	 * @param mixed $name      Name html.
	 * @param mixed $cart_item Cart item.
	 * @return mixed
	 */
	public static function cart_item_name( $name, $cart_item ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item['data'] ) ) {
			return $name;
		}

		$label = self::label( $cart_item['data'], $cart_item['beltoft_services_disk_for'] ?? 0 );

		return '' === $label ? $name : self::swap( (string) $name, $label );
	}

	/**
	 * The attribute names of a variation as the meta lines show them (label), plus the plain slugs.
	 *
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	private static function attribute_labels( \WC_Product $product ): array {
		$labels = array();

		foreach ( array_keys( $product->get_attributes() ) as $key ) {
			$name     = preg_replace( '/^attribute_/', '', (string) $key );
			$labels[] = wc_attribute_label( $name, $product );
			$labels[] = (string) $name;
		}

		return $labels;
	}

	/**
	 * Meta lines of an order line in a customer view: the attribute lines the name says are left out, and a server
	 * name and the pay-as-you-go note are added.
	 *
	 * @param mixed $html Html built by WooCommerce.
	 * @param mixed $item Order item.
	 * @param mixed $args Arguments of wc_display_item_meta().
	 * @return mixed
	 */
	public static function display_item_meta( $html, $item, $args = array() ) {
		if ( ! $item instanceof \WC_Order_Item_Product || ! is_array( $args ) ) {
			return $html;
		}

		$product = $item->get_product();

		if ( ! $product instanceof \WC_Product || '' === self::label( $product, $item->get_meta( '_beltoft_services_disk_server' ) ) ) {
			return $html;
		}

		$skip = self::is_account_product( $product->get_id() ) ? array() : self::attribute_labels( $product );
		$rows = array();

		foreach ( $item->get_formatted_meta_data() as $meta ) {
			if ( in_array( (string) $meta->display_key, $skip, true ) || in_array( (string) $meta->key, $skip, true ) ) {
				continue;
			}

			$rows[ wp_strip_all_tags( (string) $meta->display_key ) ] = (string) $meta->display_value;
		}

		/**
		 * The meta rows of an order line, before the billing note: a feature adds its own (the server name).
		 *
		 * @param array<string, string> $rows Label => html.
		 * @param \WC_Order_Item_Product $item The line.
		 */
		$filtered = apply_filters( 'beltoft_services_line_rows', $rows, $item );
		$rows     = is_array( $filtered ) ? $filtered : $rows;

		if ( self::is_account_product( $product->get_id() ) || self::is_usage( $product ) ) {
			$rows[ __( 'Billing', 'beltoft-services' ) ] = esc_html( self::usage_note() );
		}

		$strings = array();

		foreach ( $rows as $label => $value ) {
			$value     = ! empty( $args['autop'] ) ? wp_kses_post( $value ) : wp_kses_post( make_clickable( trim( $value ) ) );
			$strings[] = ( $args['label_before'] ?? '' ) . wp_kses_post( (string) $label ) . ( $args['label_after'] ?? ': ' ) . $value;
		}

		if ( array() === $strings ) {
			return '';
		}

		return ( $args['before'] ?? '' ) . implode( (string) ( $args['separator'] ?? ', ' ), $strings ) . ( $args['after'] ?? '' );
	}

	/**
	 * Is the checkout (also while WooCommerce refreshes it) showing the order summary? The note under the items says it there.
	 */
	private static function at_checkout(): bool {
		return defined( 'WOOCOMMERCE_CHECKOUT' ) || ( function_exists( 'is_checkout' ) && is_checkout() );
	}

	/**
	 * Cart lines: the attribute rows the name says are left out, and the pay-as-you-go note is added.
	 *
	 * @param mixed $data      Rows.
	 * @param mixed $cart_item Cart item.
	 * @return mixed
	 */
	public static function cart_item_data( $data, $cart_item ) {
		if ( ! is_array( $data ) || ! is_array( $cart_item ) || empty( $cart_item['data'] ) || '' === self::label( $cart_item['data'], $cart_item['beltoft_services_disk_for'] ?? 0 ) ) {
			return $data;
		}

		$product = $cart_item['data'];

		if ( ! self::is_account_product( $product->get_id() ) ) {
			$skip = self::attribute_labels( $product );
			$data = array_values(
				array_filter(
					$data,
					static fn( $row ) => ! is_array( $row ) || ! in_array( (string) ( $row['key'] ?? '' ), $skip, true )
				)
			);
		}

		// At checkout the note is said once under the items (Usage\Summary).
		if ( ( self::is_account_product( $product->get_id() ) || self::is_usage( $product ) ) && ! self::at_checkout() ) {
			$data[] = array(
				'key'   => __( 'Billing', 'beltoft-services' ),
				'value' => self::usage_note( 'cart' ),
			);
		}

		return $data;
	}
}
