<?php
/**
 * The order summary line of a pay-as-you-go server or database: the plan and every add-on as a monthly maximum.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\LineNames;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Backup\BackupField;
use BeltoftServices\Proxmox\Db\DbDisk;
use BeltoftServices\Proxmox\Db\DbDiskField;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Db\DbIp;
use BeltoftServices\Proxmox\Db\DbIpField;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;

defined( 'ABSPATH' ) || exit;

/**
 * Display only: real prices and totals are never touched. Checkout, cart and mini cart show the line as a stacked
 * block (name, plan and specs, then the monthly amounts and the maximum, then the hourly price once). The amounts
 * are the monthly prices of the monthly sibling variations: the plan is the variation without add-ons, an add-on
 * is the difference between two variations, and the maximum is what the hourly rate is capped at.
 */
final class Breakdown {

	public const CLASS_NAME = 'beltoft-services-payg-line';
	public const HANDLE     = 'beltoft-services-payg-breakdown';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'name' ), 50, 3 );
		add_filter( 'woocommerce_checkout_cart_item_quantity', array( __CLASS__, 'quantity' ), 20, 3 );
		add_filter( 'woocommerce_widget_cart_item_quantity', array( __CLASS__, 'widget_quantity' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'empty_price' ), 110, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'empty_price' ), 110, 2 );
		add_filter( 'woocommerce_cart_item_class', array( __CLASS__, 'row_class' ), 20, 2 );
		add_filter( 'woocommerce_mini_cart_item_class', array( __CLASS__, 'row_class' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 40, 2 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'checkout_rows' ), 31, 1 );
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'addon_note' ), 12 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * The stylesheet, wherever a cart is shown and the cart holds such a line.
	 */
	public static function assets(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || array() === array_filter( $cart->get_cart(), static fn( $item ) => null !== self::of_item( $item ) ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/payg-breakdown.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/payg-breakdown.css' ) );
	}

	/**
	 * The driver id of a pay-as-you-go server or database variation, or ''.
	 *
	 * @param mixed $product Product of a line.
	 */
	private static function kind( $product ): string {
		if ( ! $product instanceof \WC_Product_Variation || ! Rates::is_usage( $product ) ) {
			return '';
		}

		$driver = Product::driver_for( $product->get_parent_id() );
		$id     = $driver ? $driver->id() : '';

		return in_array( $id, array( DbDriver::ID, ProxmoxDriver::ID ), true ) ? $id : '';
	}

	/**
	 * The breakdown of a cart item, or null when the line is not a pay-as-you-go server or database (a renewal
	 * keeps its look).
	 *
	 * @param mixed $item Cart item.
	 * @return array<string, mixed>|null
	 */
	private static function of_item( $item ): ?array {
		if ( ! is_array( $item ) || CartFlow::is_renewal( $item ) || '' === self::kind( $item['data'] ?? null ) ) {
			return null;
		}

		return self::parts( $item['data'] );
	}

	/**
	 * The amounts of a pay-as-you-go variation, per month and without tax: the plan, the public IPv4 address, the
	 * extra storage and the daily backups (0 when not chosen), the maximum and the hourly price.
	 *
	 * @param \WC_Product_Variation $usage Pay-as-you-go variation.
	 * @return array{plan: float, ip: float, storage: float, backups: float, storage_gb: int, max: float, hourly: float, driver: string}|null
	 */
	public static function parts( \WC_Product_Variation $usage ): ?array {
		$kind  = self::kind( $usage );
		$rates = Rates::for_usage( $usage );
		$full  = Rates::sibling( $usage, false );

		if ( '' === $kind || null === $rates || ! $full ) {
			return null;
		}

		$premium = Rates::premium( $usage->get_parent_id() );
		$price   = static fn( \WC_Product_Variation $v ): float => (float) wc_get_price_excluding_tax( $v );
		$ip      = 0.0;
		$storage = 0.0;
		$backups = 0.0;
		$gb      = 0;
		$base    = $price( $full );

		if ( DbDriver::ID === $kind ) {
			$bare    = DbDisk::ladder( $full )[0] ?? $full;
			$storage = $price( $full ) - $price( $bare );
			$gb      = DbDisk::extra( Product::plan( $usage->get_parent_id(), $full->get_id() ) );
			$no_ip   = DbIp::plan_wants( Product::plan( $usage->get_parent_id(), $bare->get_id() ) ) ? Backups::sibling_by( $bare, 'public_ip', false ) : null;
			$ip      = $no_ip ? $price( $bare ) - $price( $no_ip ) : 0.0;
			$base    = $no_ip ? $price( $no_ip ) : $price( $bare );
		} elseif ( (int) ( Product::plan( $usage->get_parent_id(), $full->get_id() )['backup_days'] ?? 0 ) > 0 ) {
			$without = Backups::sibling( $full, false );
			$backups = $without ? $price( $full ) - $price( $without ) : 0.0;
			$base    = $without ? $price( $without ) : $price( $full );
		}

		return array(
			'plan'       => Rates::cap( $base, $premium ),
			'ip'         => max( 0.0, Rates::cap( $ip, $premium ) ),
			'storage'    => max( 0.0, Rates::cap( $storage, $premium ) ),
			'backups'    => max( 0.0, Rates::cap( $backups, $premium ) ),
			'storage_gb' => $storage > 0 ? $gb : 0,
			'max'        => $rates['cap'],
			'hourly'     => $rates['hourly'],
			'driver'     => $kind,
		);
	}

	/**
	 * The plan's own name ("Standard"): the attribute values that are not billing or an add-on.
	 *
	 * @param \WC_Product_Variation $usage Variation.
	 */
	private static function plan_name( \WC_Product_Variation $usage ): string {
		$parent = wc_get_product( $usage->get_parent_id() );
		$out    = array();

		foreach ( $parent ? $usage->get_attributes() : array() as $key => $value ) {
			$slug = (string) preg_replace( '/^(attribute_)?(pa_)?/', '', sanitize_title( (string) $key ) );

			if ( in_array( $slug, array( 'billing', 'backups', 'public-ip', 'extra-storage', 'os', 'operating-system' ), true ) ) {
				continue;
			}

			$text = LineNames::value( $usage, $parent, (string) $key, (string) $value );

			if ( '' !== $text ) {
				$out[] = $text;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * "Standard, 2 vCPU, 4 GB memory, 40 GB data disk".
	 *
	 * @param \WC_Product_Variation $usage Variation.
	 * @param string                $kind  Driver id.
	 */
	public static function specs( \WC_Product_Variation $usage, string $kind ): string {
		$plan   = Product::plan( $usage->get_parent_id(), $usage->get_id() );
		$memory = (int) ( $plan['memory_mb'] ?? 0 );
		$parts  = array();
		$name   = self::plan_name( $usage );

		if ( '' !== $name ) {
			$parts[] = $name;
		}

		if ( (int) ( $plan['cores'] ?? 0 ) > 0 ) {
			/* translators: %d: number of virtual CPU cores */
			$parts[] = sprintf( _n( '%d vCPU', '%d vCPU', (int) $plan['cores'], 'beltoft-services' ), (int) $plan['cores'] );
		}

		if ( $memory >= 1024 ) {
			/* translators: %s: memory in gigabytes, such as 4 or 1.5 */
			$parts[] = sprintf( __( '%s GB memory', 'beltoft-services' ), (string) ( round( $memory / 1024, 1 ) + 0 ) );
		} elseif ( $memory > 0 ) {
			/* translators: %d: memory in megabytes */
			$parts[] = sprintf( __( '%d MB memory', 'beltoft-services' ), $memory );
		}

		if ( (int) ( $plan['disk_gb'] ?? 0 ) > 0 ) {
			$parts[] = DbDriver::ID === $kind
				/* translators: %d: data disk size in gigabytes */
				? sprintf( __( '%d GB data disk', 'beltoft-services' ), (int) $plan['disk_gb'] )
				/* translators: %d: disk size in gigabytes */
				: sprintf( __( '%d GB disk', 'beltoft-services' ), (int) $plan['disk_gb'] );
		}

		return implode( ', ', $parts );
	}

	/**
	 * The rows of the amounts: label and text.
	 *
	 * @param array<string, mixed> $parts parts(), with an optional disk and disk_gb (the extra disk of a new server).
	 * @param \WC_Product|null     $tax   Product for the tax display, null for the amounts as given (API answers).
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function rows( array $parts, string $plan_name = '', ?\WC_Product $tax = null ): array {
		/* translators: %s: plan name such as Standard */
		$rows = array( array( '' !== $plan_name ? sprintf( __( '%s plan', 'beltoft-services' ), $plan_name ) : __( 'Plan', 'beltoft-services' ), Rates::per_month_line( (float) $parts['plan'], $tax ) ) );

		if ( $parts['ip'] > 0 ) {
			$rows[] = array( __( 'Public IPv4 address', 'beltoft-services' ), Rates::plus_per_month_line( (float) $parts['ip'], $tax ) );
		}

		if ( $parts['storage'] > 0 ) {
			/* translators: %d: extra storage in GB */
			$rows[] = array( $parts['storage_gb'] > 0 ? sprintf( __( 'Extra storage, %d GB', 'beltoft-services' ), (int) $parts['storage_gb'] ) : __( 'Extra storage', 'beltoft-services' ), Rates::plus_per_month_line( (float) $parts['storage'], $tax ) );
		}

		if ( $parts['backups'] > 0 ) {
			$rows[] = array( __( 'Daily backups', 'beltoft-services' ), Rates::plus_per_month_line( (float) $parts['backups'], $tax ) );
		}

		// The extra disk of a new server is a cart line of its own: its row is added by the caller that quotes it (parts['disk']).
		if ( ( $parts['disk'] ?? 0 ) > 0 ) {
			/* translators: %d: extra disk in GB */
			$rows[] = array( ( $parts['disk_gb'] ?? 0 ) > 0 ? sprintf( __( 'Extra disk, %d GB', 'beltoft-services' ), (int) $parts['disk_gb'] ) : __( 'Extra disk', 'beltoft-services' ), Rates::plus_per_month_line( (float) $parts['disk'], $tax ) );
		}

		return $rows;
	}

	/**
	 * The maximum per month to show. In the including display each row is rounded to cents on its own and the maximum is the sum of the
	 * rows shown, so the rows add up to it (the usage invoice has a line per item and taxes each line); otherwise the maximum as it is.
	 * The figure given back is excluding tax: cap_money() turns it into the shown one, so the sum is divided back by the same factor.
	 *
	 * @param array<string, mixed> $parts  parts().
	 * @param \WC_Product|null     $usage  Product for the tax display.
	 */
	private static function shown_max( array $parts, ?\WC_Product $usage ): float {
		if ( null === $usage || 'incl' !== TaxNote::display() ) {
			return (float) $parts['max'];
		}

		$sum = 0.0;

		foreach ( array( 'plan', 'ip', 'storage', 'backups', 'disk' ) as $key ) {
			$sum += round( TaxNote::usage_figure( (float) ( $parts[ $key ] ?? 0 ), $usage ), 2 );
		}

		// The sum of the shown rows is already including tax: Rates::cap_money() would add it again, so the max is passed through the
		// same function with the tax taken off by the factor of the standard class.
		$factor = TaxNote::usage_figure( 1.0, $usage );

		return $factor > 0 ? $sum / $factor : (float) $parts['max'];
	}

	/**
	 * The two blocks of the line: name and plan, then the amounts. Siblings, so a theme can place the cart's
	 * other meta lines (the operating system) between them.
	 *
	 * @param \WC_Product_Variation $usage Variation.
	 * @param array<string, mixed>  $parts parts().
	 * @param string                $name  Product name.
	 * @return array{0: string, 1: string} Head and amounts, html.
	 */
	public static function blocks( \WC_Product_Variation $usage, array $parts, string $name ): array {
		$plan = self::plan_name( $usage );
		$head = '<span class="beltoft-services-payg__head"><span class="beltoft-services-payg__name">' . esc_html( $name ) . '</span><span class="beltoft-services-payg__plan">' . esc_html( self::specs( $usage, (string) $parts['driver'] ) ) . '</span></span>';
		$out  = '<span class="beltoft-services-payg__sum">';

		// The order summary is in the cart: its figures follow the cart's tax display.
		$out .= TaxNote::in_cart(
			static function () use ( $parts, $plan, $usage ): string {
				$rows = '';

				foreach ( self::rows( $parts, $plan, $usage ) as $row ) {
					$rows .= '<span class="beltoft-services-payg__row"><span>' . esc_html( $row[0] ) . '</span><span>' . esc_html( $row[1] ) . '</span></span>';
				}

				$rows .= '<span class="beltoft-services-payg__row beltoft-services-payg__row--max"><span>' . esc_html__( 'Max per month', 'beltoft-services' ) . '</span><span>' . esc_html( Rates::cap_money( self::shown_max( $parts, $usage ), $usage ) ) . '</span></span>';
				/* translators: %s: price per hour such as €0.0740 */
				$rows .= '<span class="beltoft-services-payg__hourly">' . esc_html( sprintf( __( 'That is %s per hour, billed for the hours used.', 'beltoft-services' ), Rates::money( (float) $parts['hourly'], 4, '', $usage ) ) ) . '</span></span>';

				return $rows;
			}
		);

		return array( $head, $out );
	}

	/**
	 * The Bricks order summary: each pay-as-you-go server or database row (title, hourly price, specs, operating
	 * system, amount in a two-column flex row) becomes the stacked block, full width. Rows are matched to the
	 * cart's visible items in order, and only when the title names the product; anything else is left alone.
	 *
	 * @param mixed $content Rendered html.
	 */
	public static function checkout_rows( $content ) {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! is_string( $content ) || ! $cart || false === strpos( $content, 'bricks-woo-checkout-order-summary' ) ) {
			return $content;
		}

		$items = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( apply_filters( 'woocommerce_checkout_cart_item_visible', true, $item, $key ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
				$items[] = $item;
			}
		}

		$from = (int) strpos( $content, 'bricks-woo-checkout-order-summary' );
		$i    = 0;
		$row  = '~<div class="brxe-[a-z0-9]+ brxe-div"><div class="brxe-[a-z0-9]+ brxe-div"><div class="brxe-[a-z0-9]+ brxe-div"><div class="brxe-[a-z0-9]+ brxe-text-basic">(.*?)</div><div class="brxe-[a-z0-9]+ brxe-text-basic">(.*?)</div><small[^>]*>(.*?)</small><div class="brxe-[a-z0-9]+ brxe-text-basic checkout-summary-os">(.*?)</div></div><div class="brxe-[a-z0-9]+ brxe-div"><div class="brxe-[a-z0-9]+ brxe-text-basic"[^>]*>(.*?)</div></div></div></div>~s';

		$tail = (string) preg_replace_callback(
			$row,
			static function ( array $m ) use ( &$i, $items ): string {
				$item  = $items[ $i++ ] ?? null;
				$parts = self::of_item( $item );

				if ( null === $parts ) {
					return $m[0];
				}

				$parent = wc_get_product( $item['data']->get_parent_id() );
				$label  = $parent ? $parent->get_name() : '';

				if ( '' === $label || false === strpos( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ), html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ) ) {
					return $m[0];
				}

				$blocks = self::blocks( $item['data'], $parts, $label );
				$os     = '' === trim( wp_strip_all_tags( $m[4] ) ) ? '' : '<span class="beltoft-services-payg__os">' . wp_kses_post( $m[4] ) . '</span>';

				return '<div class="beltoft-services-payg-row">' . $blocks[0] . $os . $blocks[1] . '</div>';
			},
			substr( $content, $from )
		);

		return substr( $content, 0, $from ) . $tail;
	}

	/**
	 * The name cell of the line.
	 *
	 * @param mixed $name Name html.
	 * @param mixed $item Cart item.
	 */
	public static function name( $name, $item = array() ) {
		$parts = self::of_item( $item );

		// Bricks' own title tag strips the tags of this filter's result: its rows are made in checkout_rows() instead.
		if ( null === $parts || doing_filter( 'bricks/frontend/render_data' ) || doing_filter( 'bricks/dynamic_data/render_content' ) ) {
			return $name;
		}

		$parent = wc_get_product( $item['data']->get_parent_id() );
		$label  = $parent ? $parent->get_name() : $item['data']->get_name();
		$blocks = self::blocks( $item['data'], $parts, $label );
		$head   = LineNames::swap( (string) $name, $label );

		// A linked name keeps its link: the head's own name span is replaced by the (possibly linked) name.
		if ( false !== strpos( $head, '<a ' ) ) {
			return $head . preg_replace( '~<span class="beltoft-services-payg__name">.*?</span>~s', '', $blocks[0], 1 ) . $blocks[1];
		}

		return $blocks[0] . $blocks[1];
	}

	/**
	 * No "x 1" next to the name.
	 *
	 * @param mixed $quantity Quantity html.
	 * @param mixed $item     Cart item.
	 */
	public static function quantity( $quantity, $item = array() ) {
		return null === self::of_item( $item ) ? $quantity : '';
	}

	/**
	 * The mini cart's "1 x price" is left out: the block carries the price.
	 *
	 * @param mixed $html Quantity html.
	 * @param mixed $item Cart item.
	 */
	public static function widget_quantity( $html, $item = array() ) {
		return null === self::of_item( $item ) ? $html : '';
	}

	/**
	 * The right-hand cell stays empty.
	 *
	 * @param mixed $html Price html.
	 * @param mixed $item Cart item.
	 */
	public static function empty_price( $html, $item = array() ) {
		return null === self::of_item( $item ) ? $html : '';
	}

	/**
	 * The row's class, for the stylesheet.
	 *
	 * @param mixed $class Class.
	 * @param mixed $item  Cart item.
	 */
	public static function row_class( $class, $item = array() ) {
		return null === self::of_item( $item ) ? $class : trim( (string) $class . ' ' . self::CLASS_NAME );
	}

	/**
	 * The add-on rows ("Public IPv4 address: +€0.0068 / hour" and the like) are in the block instead.
	 *
	 * @param mixed $data Rows.
	 * @param mixed $item Cart item.
	 */
	public static function item_data( $data, $item = array() ) {
		if ( ! is_array( $data ) || null === self::of_item( $item ) ) {
			return $data;
		}

		$skip = array( __( 'Public IPv4 address', 'beltoft-services' ), __( 'Extra storage', 'beltoft-services' ), __( 'Daily backups', 'beltoft-services' ), 'Public IP', 'Backups' );

		return array_values( array_filter( $data, static fn( $row ) => ! is_array( $row ) || ! in_array( (string) ( $row['key'] ?? '' ), $skip, true ) ) );
	}

	/**
	 * "Billed by the hour, like the database." under the add-on fields of a pay-as-you-go line at checkout.
	 */
	public static function addon_note(): void {
		$database = DbIpField::cart_database() ?? DbDiskField::cart_database();
		$server   = null === $database ? BackupField::cart_server() : null;
		$usage    = $database ? $database['variation'] : ( $server ? $server['variation'] : null );

		if ( ! $usage instanceof \WC_Product_Variation || ! Rates::is_usage( $usage ) ) {
			return;
		}

		echo '<p class="beltoft-services-payg-addon-note">' . esc_html( self::addon_note_text( null !== $database ) ) . '</p>';
	}

	/**
	 * The words of that line.
	 *
	 * @param bool $database A database (else a server).
	 */
	public static function addon_note_text( bool $database ): string {
		return $database ? __( 'Billed by the hour, like the database.', 'beltoft-services' ) : __( 'Billed by the hour, like the server.', 'beltoft-services' );
	}
}
