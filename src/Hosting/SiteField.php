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

	/** Every value an account can carry (an earlier order may hold `empty`). */
	public const INSTALLS = array( 'wordpress', 'woocommerce', 'empty' );

	/** What the checkout offers and accepts for a new order. */
	public const OFFERED = array( 'wordpress', 'woocommerce' );

	public const HANDLE = 'beltoft-services-site-field';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
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
		$mode = '' === (string) ( $input['mode'] ?? '' ) ? self::MODE_PREVIEW : (string) $input['mode'];

		if ( ! in_array( $mode, array( self::MODE_PREVIEW, self::MODE_OURS, self::MODE_OWN ), true ) ) {
			return new \WP_Error( 'site_mode', __( 'Choose how the website is reached: a temporary address, a domain you have with us, or a domain you have elsewhere.', 'beltoft-services' ) );
		}

		$domain = '';

		if ( self::MODE_PREVIEW !== $mode ) {
			$host = HostName::normalise( (string) ( $input['host'] ?? '' ) );

			if ( null === $host || ! SiteDomains::valid_host( $host ) ) {
				return new \WP_Error( 'site_host', __( 'Enter the domain name without http:// or a path, for example www.example.com.', 'beltoft-services' ) );
			}

			$zone = Config::preview_zone();

			if ( '' !== $zone && ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) ) {
				return new \WP_Error( 'site_host', __( 'That is a temporary address. Enter your own domain, or choose the temporary address option.', 'beltoft-services' ) );
			}

			if ( null !== SiteDomains::find( $host ) ) {
				return new \WP_Error( 'site_host', __( 'That domain is already used by a hosting account. Enter another domain.', 'beltoft-services' ) );
			}

			if ( self::MODE_OURS === $mode && null === SiteDns::zone_for( $user_id, $host ) ) {
				return new \WP_Error( 'site_zone', __( 'Choose a name inside one of your domains with us, or use the option for a domain you have elsewhere.', 'beltoft-services' ) );
			}

			$domain = $host;
		}

		$slug  = strtolower( trim( (string) ( $input['slug'] ?? '' ) ) );
		$owner = self::owner( $user_id );

		if ( '' === $slug && '' !== $domain ) {
			// A domain order needs no temporary name from the customer: it is made from the domain.
			$slug = self::free_slug( $domain, $owner );
		}

		if ( Sites::reserved( $slug ) ) {
			return new \WP_Error( 'site_slug', __( 'That website name is reserved. Choose another.', 'beltoft-services' ) );
		}

		if ( ! Sites::valid_slug( $slug ) ) {
			return new \WP_Error( 'site_slug', __( 'Enter a website name of 1 to 32 characters: lowercase letters, numbers and dashes, not starting or ending with a dash. For example myshop.', 'beltoft-services' ) );
		}

		if ( Sites::taken( $slug ) || ! Sites::reserve( $slug, $owner ) ) {
			return new \WP_Error( 'site_taken', __( 'That website name is taken. Choose another.', 'beltoft-services' ) );
		}

		$install = '' === (string) ( $input['install'] ?? '' ) ? 'wordpress' : (string) $input['install'];

		if ( ! in_array( $install, self::OFFERED, true ) ) {
			return new \WP_Error( 'site_install', __( 'Choose WordPress or WordPress with WooCommerce.', 'beltoft-services' ) );
		}

		$language = (string) ( $input['language'] ?? '' );

		if ( '' !== $language && ! Languages::offered( $language ) ) {
			return new \WP_Error( 'site_language', __( 'Choose the site language from the list.', 'beltoft-services' ) );
		}

		$email = trim( (string) ( $input['email'] ?? '' ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error( 'site_email', __( 'Enter a valid admin email address, for example name@example.com, or leave it empty to use your billing email.', 'beltoft-services' ) );
		}

		$label = sanitize_text_field( (string) ( $input['label'] ?? '' ) );

		return array(
			'slug'        => $slug,
			'label'       => '' === $label ? $domain : $label,
			'mode'        => $mode,
			'domain'      => $domain,
			'install'     => $install,
			'language'    => '' === $language ? 'en_US' : $language,
			'admin_email' => '' === $email ? '' : sanitize_email( $email ),
		);
	}

	/**
	 * Who a held name belongs to: the customer, or the cart's session for a guest.
	 *
	 * @param int $user_id Customer id, 0 for a guest.
	 */
	private static function owner( int $user_id ): string {
		if ( $user_id > 0 ) {
			return 'u' . $user_id;
		}

		$session = function_exists( 'WC' ) && WC() && isset( WC()->session ) && WC()->session ? (string) WC()->session->get_customer_id() : '';

		return 'g' . ( '' !== $session ? $session : 'none' );
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

		foreach ( array( 'slug', 'slug_domain', 'label', 'mode', 'host', 'host_ours', 'zone', 'install', 'language', 'email' ) as $field ) {
			$out[ $field ] = isset( $one[ $field ] ) && is_string( $one[ $field ] ) ? trim( $one[ $field ] ) : '';
		}

		// Each mode has its own inputs; only the chosen mode's inputs count.
		if ( '' !== $out['mode'] && self::MODE_PREVIEW !== $out['mode'] ) {
			$out['slug'] = $out['slug_domain'];
		}

		if ( self::MODE_OURS === $out['mode'] ) {
			$out['host'] = '' !== $out['host_ours'] ? $out['host_ours'] : ( '' !== $out['host'] ? $out['host'] : $out['zone'] );
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

		echo '<div class="beltoft-services-site-field">';
		echo '<h3>' . esc_html__( 'Your website', 'beltoft-services' ) . '</h3>';

		foreach ( $lines as $key => $item ) {
			$product = $item['data'] ?? null;
			$name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';

			self::print_line( (string) $key, count( $lines ) > 1 ? $name : '', $zones, self::default_install( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ) );
		}

		echo '</div>';
	}

	/**
	 * What is preselected to install: WooCommerce when the plan says so (field woocommerce_default), WordPress otherwise.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation.
	 */
	public static function default_install( int $product_id, int $variation_id ): string {
		$plan = Plan::typed( Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ) );

		return ! empty( $plan['woocommerce_default'] ) ? 'woocommerce' : 'wordpress';
	}
	/**
	 * The scripts and styles, on the checkout page only, and only when the cart has a new hosting line.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || array() === self::cart_lines() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/site-field.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/site-field.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/site-field.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/site-field.js' ), true );
	}

	/**
	 * A temporary name made from a domain, free to take: example.com becomes example-com, then example-com-2 when the
	 * first is used. The name is only the temporary address, so the customer is not asked for it.
	 *
	 * @param string $domain Domain.
	 * @param string $owner  Who holds the name (see owner()).
	 */
	private static function free_slug( string $domain, string $owner ): string {
		$base = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) preg_replace( '/^www\./', '', $domain ) ) ), '-' );
		$base = trim( substr( $base, 0, 28 ), '-' );

		if ( '' === $base ) {
			$base = 'site';
		}

		for ( $n = 1; $n <= 50; $n++ ) {
			$slug = 1 === $n ? $base : $base . '-' . $n;

			if ( Sites::valid_slug( $slug ) && ! Sites::taken( $slug ) && Sites::reserve( $slug, $owner ) ) {
				return $slug;
			}
		}

		return $base;
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
		$pick = in_array( $post['install'], self::OFFERED, true ) ? $post['install'] : $install;
		$lang = Languages::offered( $post['language'] ) ? $post['language'] : Languages::DEFAULT;
		$zone = Config::preview_zone();

		if ( ! in_array( $mode, array( self::MODE_PREVIEW, self::MODE_OURS, self::MODE_OWN ), true ) || ( self::MODE_OURS === $mode && ! $have ) ) {
			$mode = self::MODE_PREVIEW;
		}

		echo '<div class="beltoft-services-site-field__line" data-site-line>';

		if ( '' !== $label ) {
			echo '<p class="beltoft-services-site-field__product">' . esc_html( $label ) . '</p>';
		}

		// Where the website is reached.
		echo '<fieldset class="beltoft-services-site-field__group"><legend>' . esc_html__( 'Website address', 'beltoft-services' ) . '</legend>';
		self::radio( $name, $id, self::MODE_PREVIEW, $mode, __( 'Temporary address, connect a domain later', 'beltoft-services' ) );
		echo '<div class="beltoft-services-site-field__panel" data-site-panel="' . esc_attr( self::MODE_PREVIEW ) . '"' . ( self::MODE_PREVIEW === $mode ? '' : ' hidden' ) . '>';
		self::text_row( $name . '[slug]', $id . '_slug', __( 'Website name', 'beltoft-services' ), $post['slug'], true, '' !== $zone ? __( 'Lowercase letters, numbers and dashes. You connect your own domain later.', 'beltoft-services' ) : __( 'Lowercase letters, numbers and dashes.', 'beltoft-services' ), 'text', 32 );

		if ( '' !== $zone ) {
			/* translators: %s: address such as myshop.example.net, with the name part marked. */
			echo '<p class="beltoft-services-site-field__note" data-site-address data-zone="' . esc_attr( $zone ) . '">' . wp_kses( sprintf( __( 'Your website opens at %s', 'beltoft-services' ), '<strong><span data-site-address-name>' . esc_html( '' !== $post['slug'] ? $post['slug'] : 'myshop' ) . '</span>.' . esc_html( $zone ) . '</strong>' ), array( 'strong' => array(), 'span' => array( 'data-site-address-name' => true ) ) ) . '</p>';
		}

		echo '</div>';
		if ( $have ) {
			self::radio( $name, $id, self::MODE_OURS, $mode, __( 'A domain I have with you', 'beltoft-services' ) );
			echo '<div class="beltoft-services-site-field__panel" data-site-panel="' . esc_attr( self::MODE_OURS ) . '"' . ( self::MODE_OURS === $mode ? '' : ' hidden' ) . '>';
			echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_zone">' . esc_html__( 'Domain', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[zone]" id="' . esc_attr( $id ) . '_zone">';

			foreach ( $zones as $one ) {
				echo '<option value="' . esc_attr( $one ) . '"' . selected( $post['zone'], $one, false ) . '>' . esc_html( $one ) . '</option>';
			}

			echo '</select></span></p>';
			self::text_row( $name . '[host_ours]', $id . '_host_ours', __( 'Or a name inside it', 'beltoft-services' ), $post['host_ours'], false, __( 'For example www.mine.example. Leave empty to use the domain itself. We set up its DNS for you.', 'beltoft-services' ), 'url' );
			echo '</div>';
		}

		self::radio( $name, $id, self::MODE_OWN, $mode, __( 'A domain I have elsewhere', 'beltoft-services' ) );
		echo '<div class="beltoft-services-site-field__panel" data-site-panel="' . esc_attr( self::MODE_OWN ) . '"' . ( self::MODE_OWN === $mode ? '' : ' hidden' ) . '>';
		self::text_row( $name . '[host]', $id . '_host', __( 'Domain name', 'beltoft-services' ), self::MODE_OWN === $mode ? $post['host'] : '', false, __( 'For example www.example.com', 'beltoft-services' ), 'url' );
		echo '<p class="beltoft-services-site-field__note">' . esc_html__( 'After you order, your account page shows the DNS records to add where your domain is managed: an A record that points the domain to our server, and a TXT record that proves the domain is yours. Until the domain points to us, the website works on a temporary address.', 'beltoft-services' ) . '</p>';
		echo '</div>';

		echo '</fieldset>';

		// What to install and in which language.
		echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_install">' . esc_html__( 'Install', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[install]" id="' . esc_attr( $id ) . '_install">';

		foreach ( array( 'wordpress' => __( 'WordPress', 'beltoft-services' ), 'woocommerce' => __( 'WordPress with WooCommerce', 'beltoft-services' ) ) as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $pick, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '</select></span></p>';

		echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_language">' . esc_html__( 'Site language', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[language]" id="' . esc_attr( $id ) . '_language">';
		$groups = Languages::groups();
		echo '<optgroup label="' . esc_attr__( 'Common languages', 'beltoft-services' ) . '">';

		foreach ( $groups['common'] as $locale => $text ) {
			echo '<option value="' . esc_attr( $locale ) . '"' . selected( $lang, $locale, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '</optgroup><optgroup label="' . esc_attr__( 'All languages', 'beltoft-services' ) . '">';

		foreach ( $groups['all'] as $locale => $text ) {
			echo '<option value="' . esc_attr( $locale ) . '"' . selected( $lang, $locale, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '</optgroup></select></span></p>';

		// Everything that has a good default.
		$open = '' !== $post['email'] || '' !== $post['label'] || '' !== $post['slug_domain'];

		echo '<details class="beltoft-services-site-field__more"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'More options', 'beltoft-services' ) . '</summary>';
		self::text_row( $name . '[label]', $id . '_label', __( 'Website title', 'beltoft-services' ), $post['label'], false, __( 'Shown in the browser tab. Empty uses the domain or the website name. You can change it in WordPress.', 'beltoft-services' ), 'text', 100 );
		self::text_row( $name . '[email]', $id . '_email', __( 'WordPress admin email', 'beltoft-services' ), $post['email'], false, __( 'Empty uses your billing email.', 'beltoft-services' ), 'email' );
		echo '<div data-site-more-slug' . ( self::MODE_PREVIEW === $mode ? ' hidden' : '' ) . '>';
		self::text_row( $name . '[slug_domain]', $id . '_slug_domain', __( 'Temporary address name', 'beltoft-services' ), $post['slug_domain'], false, __( 'Made from your domain, for example example-com. Your website is reachable at this address until your domain points to us.', 'beltoft-services' ), 'text', 32 );
		echo '</div>';
		echo '</details>';
		echo '</div>';
	}

	/**
	 * One radio of the address choice.
	 *
	 * @param string $name  Field name prefix of the line.
	 * @param string $id    Id prefix of the line.
	 * @param string $value Mode.
	 * @param string $mode  Chosen mode.
	 * @param string $text  Label.
	 */
	private static function radio( string $name, string $id, string $value, string $mode, string $text ): void {
		echo '<p class="form-row form-row-wide beltoft-services-site-field__choice"><label for="' . esc_attr( $id . '_mode_' . $value ) . '"><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[mode]" id="' . esc_attr( $id . '_mode_' . $value ) . '" value="' . esc_attr( $value ) . '"' . checked( $mode, $value, false ) . '> ' . esc_html( $text ) . '</label></p>';
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
	 * @param string $type     Input kind: text, url or email (sets the mobile keyboard).
	 * @param int    $max      Longest value.
	 */
	private static function text_row( string $name, string $id, string $label, string $value, bool $required, string $help, string $type = 'text', int $max = 253 ): void {
		$mode = 'email' === $type ? 'email' : ( 'url' === $type ? 'url' : 'text' );
		$auto = 'email' === $type ? 'email' : 'off';

		echo '<p class="form-row form-row-wide' . ( $required ? ' validate-required' : '' ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr>' : '' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" maxlength="' . (int) $max . '" inputmode="' . esc_attr( $mode ) . '" autocomplete="' . esc_attr( $auto ) . '" autocapitalize="none" spellcheck="false" value="' . esc_attr( $value ) . '"' . ( '' === $help ? '' : ' aria-describedby="' . esc_attr( $id ) . '_help"' ) . '></span>';
		echo '' === $help ? '' : '<span class="description" id="' . esc_attr( $id ) . '_help">' . esc_html( $help ) . '</span>';
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
