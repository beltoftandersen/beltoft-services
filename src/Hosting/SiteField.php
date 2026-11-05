<?php
/**
 * The "Your website" step at checkout for a hosting product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 13.1, in the pattern of Mail\MailField. One block per hosting line in the classic checkout (one account per
 * line, so a quantity of 2 is refused): the site name, where the site is reached (the temporary address, a domain
 * the customer holds on our name servers, or a domain hosted elsewhere), what to install, the site language and the
 * admin email. Saved on the order line and handed to the new resource as the meta `site` through
 * `beltoft_services_initial_meta`, which Provision reads (slug, label, install, admin email, language, and the domain
 * it attaches after the preview name works). A renewal or a switch pays for an account that exists and asks nothing.
 * The block checkout has no such fields, so a cart with a hosting line is refused there, with a message that points to
 * the classic checkout.
 */
final class SiteField {

	public const POST_KEY  = 'beltoft_services_site';
	public const ITEM_META = '_beltoft_services_site';

	public const MODE_PREVIEW = 'preview';
	public const MODE_OURS    = 'ours';
	public const MODE_OWN     = 'own';

	public const INSTALLS = array( 'wordpress', 'woocommerce', 'empty' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 25 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_store_api' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 30, 4 );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 30, 3 );
	}

	/**
	 * Is the product (or variation) a hosting product?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function is_hosting_product( int $product_id ): bool {
		$driver = $product_id > 0 ? Product::driver_for( $product_id ) : null;

		return $driver instanceof Driver && HostingDriver::ID === $driver->id();
	}

	/**
	 * The cart lines that start a new hosting account.
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
			// A renewal or a switch pays for an account that exists.
			if ( isset( $item['subscription_renewal'] ) || isset( $item['subscription_switch'] ) ) {
				continue;
			}

			if ( self::is_hosting_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
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
		return $user_id > 0 ? \BeltoftServices\Domains\ZoneAccess::zones( $user_id ) : array();
	}

	/**
	 * The site a choice describes, or the reason it cannot be used.
	 *
	 * @param array<string, string> $input   slug, label, mode, host, install, language, email.
	 * @param int                   $user_id Customer, 0 for a guest.
	 * @return array<string, mixed>|\WP_Error The site (slug, label, mode, domain, install, language, admin_email) or an error whose message is for the customer.
	 */
	public static function resolve( array $input, int $user_id ) {
		$slug = strtolower( trim( (string) ( $input['slug'] ?? '' ) ) );

		if ( ! Sites::valid_slug( $slug ) ) {
			return new \WP_Error( 'site_slug', __( 'Give the website a short name with lowercase letters, numbers and dashes. It is used in its temporary address.', 'beltoft-services' ) );
		}

		if ( Sites::taken( $slug ) ) {
			return new \WP_Error( 'site_taken', __( 'That website name is taken. Choose another.', 'beltoft-services' ) );
		}

		$mode   = '' === (string) ( $input['mode'] ?? '' ) ? self::MODE_PREVIEW : (string) $input['mode'];
		$domain = '';

		if ( self::MODE_OURS === $mode || self::MODE_OWN === $mode ) {
			$host = HostName::normalise( (string) ( $input['host'] ?? '' ) );

			if ( null === $host || ! SiteDomains::valid_host( $host ) ) {
				return new \WP_Error( 'site_host', __( 'Enter the domain name for the website, for example www.example.com.', 'beltoft-services' ) );
			}

			$zone = Config::preview_zone();

			if ( '' !== $zone && ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) ) {
				return new \WP_Error( 'site_host', __( 'Choose your own domain, or use the temporary address.', 'beltoft-services' ) );
			}

			if ( null !== SiteDomains::find( $host ) ) {
				return new \WP_Error( 'site_host', __( 'That domain is already used by a hosting account.', 'beltoft-services' ) );
			}

			if ( self::MODE_OURS === $mode && null === SiteDns::zone_for( $user_id, $host ) ) {
				return new \WP_Error( 'site_zone', __( 'Choose a name inside one of your domains on our name servers, or enter a domain hosted elsewhere.', 'beltoft-services' ) );
			}

			$domain = $host;
		} elseif ( self::MODE_PREVIEW !== $mode ) {
			return new \WP_Error( 'site_mode', __( 'Choose how the website is reached.', 'beltoft-services' ) );
		}

		$install = '' === (string) ( $input['install'] ?? '' ) ? 'wordpress' : (string) $input['install'];

		if ( ! in_array( $install, self::INSTALLS, true ) ) {
			return new \WP_Error( 'site_install', __( 'Choose what to install.', 'beltoft-services' ) );
		}

		$language = (string) ( $input['language'] ?? '' );

		if ( '' !== $language && 1 !== preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/D', $language ) ) {
			return new \WP_Error( 'site_language', __( 'Choose a site language from the list.', 'beltoft-services' ) );
		}

		$email = trim( (string) ( $input['email'] ?? '' ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error( 'site_email', __( 'Enter a valid admin email address, or leave it empty to use your billing email.', 'beltoft-services' ) );
		}

		return array(
			'slug'        => $slug,
			'label'       => sanitize_text_field( (string) ( $input['label'] ?? '' ) ),
			'mode'        => $mode,
			'domain'      => $domain,
			'install'     => $install,
			'language'    => '' === $language ? 'en_US' : $language,
			'admin_email' => '' === $email ? '' : sanitize_email( $email ),
		);
	}

	/**
	 * The block checkout cannot ask for the site, so a cart with a new hosting line is refused there.
	 *
	 * @param \WC_Order         $order   Order being made.
	 * @param \WP_REST_Request $request Store API request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the cart has a hosting line.
	 */
	public static function block_store_api( \WC_Order $order, \WP_REST_Request $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( array() !== self::cart_lines() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'beltoft_services_site_checkout_required', esc_html__( 'Hosting is ordered at the website checkout, where you name your website. Please use the checkout page of the website.', 'beltoft-services' ), 400 );
		}
	}

	/**
	 * What the form posted for one cart line, only inside WooCommerce's own checkout run (it verified its nonce first).
	 *
	 * @param string $key Cart item key.
	 * @return array<string, string>
	 */
	private static function posted( string $key ): array {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return array();
		}

		return self::read_post( $key );
	}

	/**
	 * The posted values of one line as plain strings.
	 *
	 * @param string $key Cart item key.
	 * @return array<string, string>
	 */
	private static function read_post( string $key ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce (or the form is only refilled); every value is checked in resolve().
		$all = isset( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : array();
		// phpcs:enable
		$one = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$out = array();

		foreach ( array( 'slug', 'label', 'mode', 'host', 'zone', 'install', 'language', 'email' ) as $field ) {
			$out[ $field ] = isset( $one[ $field ] ) && is_string( $one[ $field ] ) ? trim( $one[ $field ] ) : '';
		}

		// The select of our zones and the text field share one value: the one that matches the chosen mode.
		if ( self::MODE_OURS === $out['mode'] && '' !== $out['zone'] && '' === $out['host'] ) {
			$out['host'] = $out['zone'];
		}

		return $out;
	}

	/**
	 * Prints one block per hosting line.
	 */
	public static function render(): void {
		$lines = self::cart_lines();

		if ( array() === $lines ) {
			return;
		}

		$zones = self::zones( get_current_user_id() );

		echo '<div class="beltoft-services-site-field" style="margin:40px 0 0">';
		echo '<h3 style="margin:0 0 16px">' . esc_html__( 'Your website', 'beltoft-services' ) . '</h3>';

		foreach ( $lines as $key => $item ) {
			$product = $item['data'] ?? null;
			$name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';

			self::print_line( (string) $key, count( $lines ) > 1 ? $name : '', $zones, self::default_install( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ) );
		}

		echo '</div>';
	}

	/**
	 * What is preselected to install: WooCommerce on a plan with 5 websites or more (the Shop plans), WordPress otherwise.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation.
	 */
	public static function default_install( int $product_id, int $variation_id ): string {
		$plan = Plan::typed( Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ) );

		return (int) $plan['sites'] >= 5 ? 'woocommerce' : 'wordpress';
	}

	/**
	 * The fields of one line.
	 *
	 * @param string   $key     Cart item key.
	 * @param string   $label   Product name to show when the cart has several lines, else ''.
	 * @param string[] $zones   The customer's domains on our name servers.
	 * @param string   $install Preselected install.
	 */
	private static function print_line( string $key, string $label, array $zones, string $install ): void {
		$name = self::POST_KEY . '[' . $key . ']';
		$id   = self::POST_KEY . '_' . sanitize_key( $key );
		$post = self::read_post( $key );
		$mode = '' === $post['mode'] ? self::MODE_PREVIEW : $post['mode'];
		$have = array() !== $zones;
		$pick = '' === $post['install'] ? $install : $post['install'];

		echo '<fieldset class="beltoft-services-site-field__line" style="border:0;padding:0;margin:0 0 16px">';

		if ( '' !== $label ) {
			echo '<legend>' . esc_html( $label ) . '</legend>';
		}

		self::text_row( $name . '[slug]', $id . '_slug', __( 'Website name', 'beltoft-services' ), $post['slug'], true, __( 'Lowercase letters, numbers and dashes. It is used in the temporary address.', 'beltoft-services' ) );
		self::text_row( $name . '[label]', $id . '_label', __( 'Website title', 'beltoft-services' ), $post['label'], false, '' );

		echo '<p class="form-row form-row-wide"><label><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[mode]" value="' . esc_attr( self::MODE_PREVIEW ) . '"' . checked( $mode, self::MODE_PREVIEW, false ) . '> ' . esc_html__( 'Temporary address', 'beltoft-services' ) . '</label>';
		echo '<span class="description">' . esc_html__( 'Start on a temporary address and connect your domain later.', 'beltoft-services' ) . '</span></p>';

		if ( $have ) {
			echo '<p class="form-row form-row-wide"><label><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[mode]" value="' . esc_attr( self::MODE_OURS ) . '"' . checked( $mode, self::MODE_OURS, false ) . '> ' . esc_html__( 'A domain I have with you', 'beltoft-services' ) . '</label>';
			echo '<span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[zone]" aria-label="' . esc_attr__( 'Domain on our name servers', 'beltoft-services' ) . '">';

			foreach ( $zones as $zone ) {
				echo '<option value="' . esc_attr( $zone ) . '"' . selected( $post['zone'], $zone, false ) . '>' . esc_html( $zone ) . '</option>';
			}

			echo '</select></span></p>';
		}

		echo '<p class="form-row form-row-wide"><label><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[mode]" value="' . esc_attr( self::MODE_OWN ) . '"' . checked( $mode, self::MODE_OWN, false ) . '> ' . esc_html__( 'A domain I have elsewhere', 'beltoft-services' ) . '</label></p>';
		self::text_row( $name . '[host]', $id . '_host', __( 'Domain name', 'beltoft-services' ), $post['host'], false, __( 'You add one DNS record where the domain is hosted. We show you which after the order.', 'beltoft-services' ) );

		echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_install">' . esc_html__( 'Install', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[install]" id="' . esc_attr( $id ) . '_install">';

		foreach ( array( 'wordpress' => __( 'WordPress', 'beltoft-services' ), 'woocommerce' => __( 'WordPress with WooCommerce', 'beltoft-services' ), 'empty' => __( 'Empty site', 'beltoft-services' ) ) as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $pick, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '</select></span></p>';

		self::text_row( $name . '[language]', $id . '_language', __( 'Site language', 'beltoft-services' ), '' === $post['language'] ? 'en_US' : $post['language'], false, __( 'A WordPress locale such as en_US or da_DK.', 'beltoft-services' ) );
		self::text_row( $name . '[email]', $id . '_email', __( 'Admin email', 'beltoft-services' ), $post['email'], false, __( 'Empty uses your billing email.', 'beltoft-services' ) );
		echo '</fieldset>';
	}

	/**
	 * One text input row.
	 *
	 * @param string $name     Field name.
	 * @param string $id       Field id.
	 * @param string $label    Label.
	 * @param string $value    Value.
	 * @param bool   $required Whether it is required.
	 * @param string $help     Help text.
	 */
	private static function text_row( string $name, string $id, string $label, string $value, bool $required, string $help ): void {
		echo '<p class="form-row form-row-wide' . ( $required ? ' validate-required' : '' ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr>' : '' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" maxlength="253" autocomplete="off" spellcheck="false" value="' . esc_attr( $value ) . '"></span>';
		echo '' === $help ? '' : '<span class="description">' . esc_html( $help ) . '</span>';
		echo '</p>';
	}

	/**
	 * Refuses a classic checkout whose hosting lines have no usable site.
	 *
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$lines = self::cart_lines();

		if ( array() === $lines || ! $errors instanceof \WP_Error ) {
			return;
		}

		$user  = get_current_user_id();
		$slugs = array();
		$hosts = array();

		foreach ( $lines as $key => $item ) {
			$product = $item['data'] ?? null;
			$name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : __( 'Hosting', 'beltoft-services' );
			$prefix  = count( $lines ) > 1 ? $name . ': ' : '';

			if ( (int) ( $item['quantity'] ?? 1 ) > 1 ) {
				$errors->add( 'beltoft_services_site', $prefix . __( 'Each hosting account is its own line. Set the quantity to 1 and add the product again for another account.', 'beltoft-services' ) );
				continue;
			}

			$found = self::resolve( self::posted( (string) $key ), $user );

			if ( is_wp_error( $found ) ) {
				$errors->add( 'beltoft_services_site', $prefix . $found->get_error_message() );
				continue;
			}

			if ( isset( $slugs[ $found['slug'] ] ) || ( '' !== $found['domain'] && isset( $hosts[ $found['domain'] ] ) ) ) {
				$errors->add( 'beltoft_services_site', $prefix . __( 'You used the same website name or domain for two lines. Each one can be used once.', 'beltoft-services' ) );
				continue;
			}

			$slugs[ $found['slug'] ] = true;

			if ( '' !== $found['domain'] ) {
				$hosts[ $found['domain'] ] = true;
			}
		}
	}

	/**
	 * Saves the site on the order line.
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

		if ( is_array( $found ) ) {
			$item->add_meta_data( self::ITEM_META, $found, true );
		}
	}

	/**
	 * A new account starts with the site its order line carries. When an order has several lines of one plan, each
	 * license takes a site no other hosting account of the order has taken yet.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || HostingDriver::ID !== $driver->id() ) {
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
			$site     = $resource && HostingDriver::ID === $resource->get( 'driver' ) ? $resource->get( 'site' ) : null;

			if ( is_array( $site ) && isset( $site['slug'] ) ) {
				$used[] = (string) $site['slug'];
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id || ( 0 !== $variation && (int) $item->get_variation_id() !== $variation ) ) {
				continue;
			}

			$site = $item->get_meta( self::ITEM_META, true );

			if ( is_array( $site ) && isset( $site['slug'] ) && ! in_array( (string) $site['slug'], $used, true ) ) {
				$meta['site'] = array(
					'slug'        => (string) $site['slug'],
					'label'       => (string) ( $site['label'] ?? '' ),
					'install'     => 'empty' !== ( $site['install'] ?? 'wordpress' ),
					'install_kind' => (string) ( $site['install'] ?? 'wordpress' ),
					'language'    => (string) ( $site['language'] ?? 'en_US' ),
					'admin_email' => (string) ( $site['admin_email'] ?? '' ),
					'domain'      => (string) ( $site['domain'] ?? '' ),
				);
				break;
			}
		}

		return $meta;
	}
}
