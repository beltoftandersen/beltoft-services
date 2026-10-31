<?php
/**
 * One readable name for this plugin's products in emails, cart, checkout and My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftServices\Proxmox\DiskDriver;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Rates;

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
	}

	/**
	 * The note under a pay-as-you-go line.
	 */
	public static function usage_note(): string {
		return __( 'Billed monthly for the hours used, excl. VAT', 'beltoft-services' );
	}

	/**
	 * Is this the hidden pay-as-you-go account product?
	 *
	 * @param int $product_id Product id.
	 */
	private static function is_account_product( int $product_id ): bool {
		$id = (int) get_option( Account::OPTION_PRODUCT );

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
				$extras[] = sprintf( __( '%s backups', 'beltoft-services' ), $low );
			} elseif ( 'billing' === $slug ) {
				$extras[] = Rates::is_usage( $product ) ? __( 'pay as you go', 'beltoft-services' ) : sprintf( /* translators: %s: billing period, such as "monthly". */ __( 'billed %s', 'beltoft-services' ), $low );
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

		if ( DiskDriver::ID === $driver->id() && $disk_server_id ) {
			$server = is_numeric( $disk_server_id ) ? Resource::for_license( (int) $disk_server_id ) : null;
			$on     = $server ? ServerName::of( $server ) : '';

			if ( '' !== $on ) {
				/* translators: 1: "Extra disk 50 GB", 2: server name. */
				$label = sprintf( __( '%1$s for %2$s', 'beltoft-services' ), $label, $on );
			}
		}

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
	private static function value( \WC_Product_Variation $variation, \WC_Product $parent, string $key, string $value ): string {
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
	private static function swap( string $html, string $label ): string {
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

		$server = trim( (string) $item->get_meta( ServerName::ITEM_META ) );

		if ( '' !== $server ) {
			$rows[ __( 'Server name', 'beltoft-services' ) ] = esc_html( $server );
		}

		if ( self::is_account_product( $product->get_id() ) || Rates::is_usage( $product ) ) {
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

		if ( self::is_account_product( $product->get_id() ) || Rates::is_usage( $product ) ) {
			$data[] = array(
				'key'   => __( 'Billing', 'beltoft-services' ),
				'value' => self::usage_note(),
			);
		}

		return $data;
	}
}
