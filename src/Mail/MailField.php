<?php
/**
 * The "Email domain" choice at checkout for a Business email product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\AppDomains;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One block per mail line in the classic checkout (one email domain per order line, so a line of quantity 2 is
 * refused). The customer picks one of the domains they hold on our name servers (only a signed-in customer who has
 * one is offered that), or types a domain hosted elsewhere. The name is checked on submit with MailDomain (usable,
 * not reserved, not already an email service) and the same name twice in one cart is refused. It is saved on the
 * order line and handed to the new resource as `mail_domain` through beltoft_services_initial_meta. Whether the
 * customer really holds the name is not decided here: the driver proves it before anything is made on the mail
 * server. A renewal or a switch pays for a service that exists and asks nothing. The block checkout is not covered:
 * a mail line bought there has no domain and the driver refuses it, so the order is reviewed by an admin.
 */
final class MailField {

	public const POST_KEY  = 'beltoft_services_mail';
	public const ITEM_META = '_beltoft_services_mail_domain';

	/** Choice values. */
	public const MODE_OURS = 'ours';
	public const MODE_OWN  = 'own';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// Under the billing details: the theme's checkout shows the order notes step only for server and database carts.
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 30, 4 );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 30, 3 );
	}

	/**
	 * Is the product (or variation) one of the mail driver?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function is_mail_product( int $product_id ): bool {
		$driver = $product_id > 0 ? Product::driver_for( $product_id ) : null;

		return $driver instanceof Driver && MailDriver::ID === $driver->id();
	}

	/**
	 * The cart lines that start a new email domain.
	 *
	 * @return array<string, array<string, mixed>> Cart item key => cart item.
	 */
	public static function cart_lines(): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$out  = array();

		if ( ! $cart ) {
			return $out;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			// A renewal or a switch pays for a service that exists.
			if ( isset( $item['subscription_renewal'] ) || isset( $item['subscription_switch'] ) ) {
				continue;
			}

			if ( self::is_mail_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				$out[ (string) $key ] = $item;
			}
		}

		return $out;
	}

	/**
	 * The domains the customer holds on our name servers.
	 *
	 * @param int $user_id Customer, 0 for a guest.
	 * @return string[]
	 */
	public static function zones( int $user_id ): array {
		return $user_id > 0 ? AppDomains::zones( $user_id ) : array();
	}

	/**
	 * The domain a choice names, or the reason it cannot be used.
	 *
	 * @param array<string, mixed> $input   mode, zone, own.
	 * @param int                  $user_id Customer, 0 for a guest.
	 * @param int                  $resource Resource asking (0 for a new one).
	 * @return string|\WP_Error The normalised domain, or an error whose message is for the customer.
	 */
	public static function resolve( array $input, int $user_id, int $resource = 0 ) {
		$mode  = (string) ( $input['mode'] ?? '' );
		$zones = self::zones( $user_id );

		if ( self::MODE_OURS === $mode ) {
			$zone = MailDomain::normalise( (string) ( $input['zone'] ?? '' ) );

			if ( null === $zone || ! in_array( $zone, $zones, true ) ) {
				return new \WP_Error( 'mail_zone', __( 'Choose one of your domains on our name servers, or enter another domain.', 'beltoft-services' ) );
			}

			$raw = $zone;
		} elseif ( self::MODE_OWN === $mode ) {
			$raw = (string) ( $input['own'] ?? '' );

			if ( '' === trim( $raw ) ) {
				return new \WP_Error( 'mail_own', __( 'Enter the domain you want email for, for example example.com.', 'beltoft-services' ) );
			}
		} else {
			return new \WP_Error( 'mail_mode', __( 'Choose the domain you want email for.', 'beltoft-services' ) );
		}

		$why = MailDomain::refusal( $raw, $resource );

		if ( null !== $why ) {
			return new \WP_Error( 'mail_refused', $why );
		}

		return (string) MailDomain::normalise( $raw );
	}

	// -- Classic checkout --------------------------------------------------------------------

	/**
	 * What the form posted for one cart line, only inside WooCommerce's own checkout run (it verified its nonce first).
	 *
	 * @param string $key Cart item key.
	 * @return array{mode: string, zone: string, own: string}
	 */
	private static function posted( string $key ): array {
		$none = array( 'mode' => '', 'zone' => '', 'own' => '' );

		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return $none;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce; every value is checked in resolve().
		$all = isset( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : array();
		// phpcs:enable

		$one = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		return array(
			'mode' => isset( $one['mode'] ) && is_string( $one['mode'] ) ? trim( $one['mode'] ) : '',
			'zone' => isset( $one['zone'] ) && is_string( $one['zone'] ) ? trim( $one['zone'] ) : '',
			'own'  => isset( $one['own'] ) && is_string( $one['own'] ) ? trim( $one['own'] ) : '',
		);
	}

	/**
	 * Prints one block per mail line.
	 */
	public static function render(): void {
		$lines = self::cart_lines();

		if ( array() === $lines ) {
			return;
		}

		$zones = self::zones( get_current_user_id() );

		echo '<div class="beltoft-services-mail-field">';
		echo '<h3>' . esc_html__( 'Email domain', 'beltoft-services' ) . '</h3>';

		foreach ( $lines as $key => $item ) {
			$product = $item['data'] ?? null;
			$name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';

			self::print_line( (string) $key, count( $lines ) > 1 ? $name : '', $zones );
		}

		echo '</div>';
	}

	/**
	 * The fields of one line.
	 *
	 * @param string   $key   Cart item key.
	 * @param string   $label Product name to show when the cart has several lines, else ''.
	 * @param string[] $zones The customer's domains on our name servers.
	 */
	private static function print_line( string $key, string $label, array $zones ): void {
		$name  = self::POST_KEY . '[' . $key . ']';
		$id    = self::POST_KEY . '_' . sanitize_key( $key );
		$have  = array() !== $zones;
		$mode  = self::MODE_OWN;
		$zone  = '';
		$own   = '';
		$post  = self::posted_for_render( $key );

		if ( $have ) {
			$mode = self::MODE_OURS;
		}

		if ( '' !== $post['mode'] && ( self::MODE_OWN === $post['mode'] || ( $have && self::MODE_OURS === $post['mode'] ) ) ) {
			$mode = $post['mode'];
		}

		$zone = in_array( $post['zone'], $zones, true ) ? $post['zone'] : '';
		$own  = $post['own'];

		echo '<fieldset class="beltoft-services-mail-field__line" style="border:0;padding:0;margin:0 0 16px">';

		if ( '' !== $label ) {
			echo '<legend>' . esc_html( $label ) . '</legend>';
		}

		if ( $have ) {
			echo '<p class="form-row form-row-wide"><label><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[mode]" value="' . esc_attr( self::MODE_OURS ) . '"' . checked( $mode, self::MODE_OURS, false ) . '> ' . esc_html__( 'A domain on our name servers', 'beltoft-services' ) . '</label>';
			echo '<span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[zone]" id="' . esc_attr( $id ) . '_zone" aria-label="' . esc_attr__( 'Domain on our name servers', 'beltoft-services' ) . '">';

			foreach ( $zones as $option ) {
				echo '<option value="' . esc_attr( $option ) . '"' . selected( $zone, $option, false ) . '>' . esc_html( $option ) . '</option>';
			}

			echo '</select></span>';
			echo '<span class="description">' . esc_html__( 'We add the email records to this domain for you. Your other records are not changed.', 'beltoft-services' ) . '</span></p>';
			echo '<p class="form-row form-row-wide"><label><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[mode]" value="' . esc_attr( self::MODE_OWN ) . '"' . checked( $mode, self::MODE_OWN, false ) . '> ' . esc_html__( 'A domain hosted elsewhere', 'beltoft-services' ) . '</label>';
		} else {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[mode]" value="' . esc_attr( self::MODE_OWN ) . '">';
			echo '<p class="form-row form-row-wide validate-required"><label for="' . esc_attr( $id ) . '_own">' . esc_html__( 'Domain you want email for', 'beltoft-services' ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr></label>';
		}

		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( $name ) . '[own]" id="' . esc_attr( $id ) . '_own" maxlength="253" autocomplete="off" spellcheck="false" placeholder="example.com" value="' . esc_attr( $own ) . '"></span>';
		echo '<span class="description">' . esc_html__( 'You add the DNS records where the domain is hosted. We show you which ones after the order, and we first ask for a TXT record that proves the domain is yours.', 'beltoft-services' ) . '</span></p>';
		echo '</fieldset>';
	}

	/**
	 * The posted values to refill the form with (the checkout page is printed again after a refresh), without the checkout-run guard.
	 *
	 * @param string $key Cart item key.
	 * @return array{mode: string, zone: string, own: string}
	 */
	private static function posted_for_render( string $key ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only refills a form; the value is printed escaped and checked again on submit.
		$all = isset( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : array();
		// phpcs:enable
		$one = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$get = static fn( string $field ): string => isset( $one[ $field ] ) && is_string( $one[ $field ] ) ? trim( $one[ $field ] ) : '';

		return array( 'mode' => $get( 'mode' ), 'zone' => $get( 'zone' ), 'own' => $get( 'own' ) );
	}

	/**
	 * Refuses a classic checkout whose mail lines have no usable domain.
	 *
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$lines = self::cart_lines();

		if ( array() === $lines || ! $errors instanceof \WP_Error ) {
			return;
		}

		$user = get_current_user_id();
		$seen = array();

		foreach ( $lines as $key => $item ) {
			$product = $item['data'] ?? null;
			$name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : __( 'Business email', 'beltoft-services' );
			$prefix  = count( $lines ) > 1 ? $name . ': ' : '';

			if ( (int) ( $item['quantity'] ?? 1 ) > 1 ) {
				$errors->add( 'beltoft_services_mail', $prefix . __( 'Each email domain is its own line. Set the quantity to 1 and add the product again for another domain.', 'beltoft-services' ) );
				continue;
			}

			$found = self::resolve( self::posted( (string) $key ), $user );

			if ( is_wp_error( $found ) ) {
				$errors->add( 'beltoft_services_mail', $prefix . $found->get_error_message() );
				continue;
			}

			if ( isset( $seen[ $found ] ) ) {
				$errors->add( 'beltoft_services_mail', $prefix . __( 'You chose the same domain for two lines. Each domain can have email once.', 'beltoft-services' ) );
				continue;
			}

			$seen[ $found ] = true;
		}
	}

	/**
	 * Saves the domain on the order line.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key.
	 * @param array<string, mixed>   $values Cart item (unused).
	 * @param \WC_Order              $order  Order (unused).
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $item instanceof \WC_Order_Item_Product || ! is_string( $key ) || ! isset( self::cart_lines()[ $key ] ) ) {
			return;
		}

		$found = self::resolve( self::posted( $key ), get_current_user_id() );

		if ( is_string( $found ) ) {
			$item->add_meta_data( self::ITEM_META, $found, true );
		}
	}

	// -- Hand-over to the resource --------------------------------------------------------------

	/**
	 * A new email domain starts with the domain its order line carries. When an order has several lines of one plan, each
	 * license takes a domain no other service of the order has taken yet.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || MailDriver::ID !== $driver->id() ) {
			return $meta;
		}

		$order_id = (int) ( $license['order_id'] ?? 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return $meta;
		}

		$product_id = (int) ( $license['product_id'] ?? 0 );
		$variation  = (int) ( $license['variation_id'] ?? 0 );
		$used       = array();

		foreach ( Licenses::where( 'order_id', $order_id ) as $other ) {
			if ( (int) $other['id'] === (int) ( $license['id'] ?? 0 ) ) {
				continue;
			}

			$resource = Resource::for_license( (int) $other['id'] );

			if ( $resource && MailDriver::ID === $resource->get( 'driver' ) ) {
				$used[] = (string) $resource->get( MailDomain::META_DOMAIN );
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id || ( 0 !== $variation && (int) $item->get_variation_id() !== $variation ) ) {
				continue;
			}

			$name = MailDomain::normalise( $item->get_meta( self::ITEM_META, true ) );

			if ( null !== $name && ! in_array( $name, $used, true ) ) {
				$meta[ MailDomain::META_DOMAIN ] = $name;
				break;
			}
		}

		return $meta;
	}
}
