<?php
/**
 * The "Your website" step at checkout for a hosting product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Checkout\LineField;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Domains\OrderDomain;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Mail\MailField;
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

	/** Name of the order link parameter that preselects what to install (wordpress, woocommerce or empty), and of its cart item data. */
	public const LINK_PARAM = 'site_install';
	public const LINE_DATA  = 'beltoft_services_install';

	public const MODE_PREVIEW = 'preview';
	public const MODE_OURS    = 'ours';
	public const MODE_OWN     = 'own';
	public const MODE_NEW     = 'new';

	/** Days the site waits for a domain registered or transferred in the same order. */
	public const WAIT_DAYS = MailField::WAIT_DAYS;

	/** Every value an account can carry (an earlier order may hold `empty`). */
	public const INSTALLS = array( 'wordpress', 'woocommerce', 'empty' );

	/** What the checkout offers and accepts for a new order: WordPress, WordPress with WooCommerce, or an empty website. */
	public const OFFERED = array( 'wordpress', 'woocommerce', 'empty' );

	public const HANDLE = 'beltoft-services-site-field';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_store_api' ), 10, 2 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'customer_made' ), 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 30, 4 );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 30, 3 );
		add_filter( 'beltoft_services_domain_service_product', static fn( $is, $id = 0 ) => true === $is || self::is_hosting_product( (int) $id ), 10, 2 );
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
		return LineField::cart_lines( array( __CLASS__, 'is_hosting_product' ) );
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
	 * Is the name held by a hosting account in a way that blocks this customer? A waiting outside name nobody proved does
	 * not, once this customer has proven the name; the same words are used for every refusal.
	 *
	 * @param string $host    Host name.
	 * @param int    $user_id Customer.
	 */
	private static function held( string $host, int $user_id ): bool {
		$row = SiteDomains::find( $host );

		if ( null === $row ) {
			return false;
		}

		return ! ( 'external' === $row['kind'] && 'waiting' === $row['state'] && \BeltoftServices\Domains\Ownership::proven( $user_id, $host ) );
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

		if ( ! in_array( $mode, array( self::MODE_PREVIEW, self::MODE_OURS, self::MODE_OWN, self::MODE_NEW ), true ) ) {
			return new \WP_Error( 'site_mode', __( 'Choose how the website is reached: a temporary address, a domain in your account, or a domain you have elsewhere.', 'beltoft-services' ) );
		}

		$domain = '';

		if ( self::MODE_NEW === $mode ) {
			// Only a domain this cart registers or transfers: the name is checked against the cart, never taken from the form alone.
			$host = HostName::normalise( (string) ( $input['host'] ?? '' ) );

			if ( null === $host || ! in_array( $host, MailField::cart_domains(), true ) ) {
				return new \WP_Error( 'site_new', __( 'Choose a domain that is part of this order.', 'beltoft-services' ) );
			}

			if ( self::held( $host, $user_id ) ) {
				return new \WP_Error( 'site_host', __( 'That domain cannot be used for hosting here. Enter another domain, or prove the domain is yours first.', 'beltoft-services' ) );
			}

			$domain = $host;
		} elseif ( self::MODE_PREVIEW !== $mode ) {
			$host = HostName::normalise( (string) ( $input['host'] ?? '' ) );

			if ( null === $host || ! SiteDomains::valid_host( $host ) ) {
				return new \WP_Error( 'site_host', __( 'Enter the domain name without http:// or a path, for example www.example.com.', 'beltoft-services' ) );
			}

			$zone = Config::preview_zone();

			if ( '' !== $zone && ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) ) {
				return new \WP_Error( 'site_host', __( 'That is a temporary address. Enter your own domain, or choose the temporary address option.', 'beltoft-services' ) );
			}

			if ( self::held( $host, $user_id ) ) {
				return new \WP_Error( 'site_host', __( 'That domain cannot be used for hosting here. Enter another domain, or prove the domain is yours first.', 'beltoft-services' ) );
			}

			if ( self::MODE_OURS === $mode && null === SiteDns::zone_for( $user_id, $host ) ) {
				return new \WP_Error( 'site_zone', __( 'Choose a name inside one of the domains in your account, or use the option for a domain you have elsewhere.', 'beltoft-services' ) );
			}

			$domain = $host;
		}

		$slug  = strtolower( trim( (string) ( $input['slug'] ?? '' ) ) );
		$owner = self::owner( $user_id );

		if ( '' === $slug && '' !== $domain ) {
			// A domain order needs no temporary name from the customer: it is made from the domain.
			$slug = self::free_slug( $domain, $owner );
		}

		if ( '' === $slug && self::MODE_PREVIEW === $mode ) {
			// The temporary address is made from the customer's details, held now as a typed name was.
			$slug = SiteNames::generate( self::name_source( $input, $user_id ), $owner, (string) ( $input['line'] ?? '' ) );

			if ( '' === $slug ) {
				return new \WP_Error( 'site_taken', __( 'That website name is taken. Choose another.', 'beltoft-services' ) );
			}
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
			return new \WP_Error( 'site_install', __( 'Choose what to install.', 'beltoft-services' ) );
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
	 * What a generated temporary name is made from: the website title, then the billing details of the order.
	 *
	 * @param array<string, string> $input   The choice (see resolve()).
	 * @param int                   $user_id Customer, 0 for a guest.
	 * @return array<string, string>
	 */
	private static function name_source( array $input, int $user_id ): array {
		$given = array(
			'company'    => (string) ( $input['company'] ?? '' ),
			'first_name' => (string) ( $input['first_name'] ?? '' ),
			'last_name'  => (string) ( $input['last_name'] ?? '' ),
			'email'      => (string) ( $input['billing_email'] ?? '' ),
		);

		// A choice made outside the checkout form carries no billing details: the customer's account says them.
		$source          = array_merge( SiteNames::source_for_user( $user_id ), array_filter( $given ) );
		$source['title'] = (string) ( $input['label'] ?? '' );

		return $source;
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
		LineField::refuse_store_api( array( __CLASS__, 'is_hosting_product' ), 'beltoft_services_site_checkout_required', __( 'Hosting is ordered at the website checkout, where you name your website. Please use the checkout page of the website.', 'beltoft-services' ) );
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

		$out         = self::read_post( $key );
		$out['line'] = $key;

		foreach ( SiteNames::source_from_post( get_current_user_id() ) as $field => $value ) {
			$out[ 'email' === $field ? 'billing_email' : $field ] = $value;
		}

		return $out;
	}

	/**
	 * The posted values of one line as plain strings.
	 *
	 * @param string $key Cart item key.
	 * @return array<string, string>
	 */
	private static function read_post( string $key ): array {
		$out = LineField::read_post( self::POST_KEY, $key, array( 'slug', 'slug_domain', 'label', 'mode', 'host', 'host_ours', 'host_new', 'zone', 'install', 'language', 'email' ) );

		// The Register and the Transfer tile both put a domain in this order: the form says which tile, the mode is one.
		$out['tile'] = $out['mode'];

		if ( in_array( $out['mode'], array( OrderDomain::REGISTER, OrderDomain::TRANSFER ), true ) ) {
			$out['mode'] = self::MODE_NEW;
		}

		// Each mode has its own inputs; only the chosen mode's inputs count. The temporary address has no name input: its name
		// is made from the customer's details, so a name posted with it (a changed request) is not taken.
		$out['slug'] = '' !== $out['mode'] && self::MODE_PREVIEW !== $out['mode'] ? $out['slug_domain'] : '';

		if ( self::MODE_NEW === $out['mode'] ) {
			$out['host'] = $out['host_new'];
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
		/**
		 * Filters whether the checkout fields print their own heading. A checkout that shows these fields in a step
		 * with a heading of its own returns false.
		 *
		 * @param bool   $show Whether to print the heading.
		 * @param string $kind Which fields: hosting, mail, ssl or licence.
		 */
		if ( apply_filters( 'beltoft_services_checkout_field_heading', true, 'hosting' ) ) {
			echo '<h3>' . esc_html__( 'Your website', 'beltoft-services' ) . '</h3>';
		}

		foreach ( $lines as $key => $item ) {
			$product = $item['data'] ?? null;
			$name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';

			self::print_line( (string) $key, count( $lines ) > 1 ? $name : '', $zones, self::default_install( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ), (array) $item ) );
		}

		echo '</div>';
	}

	/**
	 * What is preselected to install: what the order link asked for (the parameter site_install, kept on the cart line, or on the checkout
	 * address when the cart has none), else WooCommerce when the plan says so (field woocommerce_default), else WordPress. A choice the customer
	 * posts always wins over this (print_line()).
	 *
	 * @param int                  $product_id   Product.
	 * @param int                  $variation_id Variation.
	 * @param array<string, mixed> $item         The cart line, when known.
	 */
	public static function default_install( int $product_id, int $variation_id, array $item = array() ): string {
		$asked = self::asked( (string) ( $item[ self::LINE_DATA ] ?? '' ) );

		if ( '' === $asked ) {
			// A link that goes to the checkout without the cart (or a cart made before the link): the address says it.
			$asked = self::asked( isset( $_GET[ self::LINK_PARAM ] ) ? wp_unslash( $_GET[ self::LINK_PARAM ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a preselection only, checked against the list below.
		}

		if ( '' !== $asked ) {
			return $asked;
		}

		$plan = Plan::typed( Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ) );

		return ! empty( $plan['woocommerce_default'] ) ? 'woocommerce' : 'wordpress';
	}

	/**
	 * A value of the order link parameter, when it is one the checkout offers (wordpress, woocommerce, empty); '' for anything else.
	 *
	 * @param mixed $value Value.
	 */
	private static function asked( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return in_array( $value, self::OFFERED, true ) ? $value : '';
	}

	/**
	 * The order link `...add-to-cart=<id>&site_install=empty`: WooCommerce's own add-to-cart request carries the parameter, and a hosting line
	 * keeps it (a value that is not offered is ignored). The line then differs from a plain one, so the same plan with another choice is a line of its own.
	 *
	 * @param mixed $data       Cart item data so far.
	 * @param mixed $product_id Product.
	 * @param mixed $variation_id Variation.
	 * @return mixed
	 */
	public static function cart_item_data( $data, $product_id = 0, $variation_id = 0 ) {
		if ( ! is_array( $data ) || ! self::is_hosting_product( (int) ( $variation_id ?: $product_id ) ) && ! self::is_hosting_product( (int) $product_id ) ) {
			return $data;
		}

		$asked = self::asked( isset( $_REQUEST[ self::LINK_PARAM ] ) ? wp_unslash( $_REQUEST[ self::LINK_PARAM ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the add-to-cart request of WooCommerce itself; checked against the list.

		if ( '' !== $asked ) {
			$data[ self::LINE_DATA ] = $asked;
		}

		return $data;
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
		OrderDomain::enqueue();
	}

	/**
	 * A guest's account was made at the checkout: the names the guest's cart holds are the new customer's now.
	 *
	 * @param mixed $customer_id New customer id.
	 */
	public static function customer_made( $customer_id ): void {
		if ( (int) $customer_id > 0 ) {
			Sites::rekey_holds( self::owner( 0 ), self::owner( (int) $customer_id ) );
		}
	}

	/**
	 * The name a domain gives before any number is added: example.com is example-com.
	 *
	 * @param string $domain Domain.
	 */
	public static function slug_base( string $domain ): string {
		$base = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) preg_replace( '/^www\./', '', $domain ) ) ), '-' );
		$base = trim( substr( $base, 0, 28 ), '-' );

		return '' === $base ? 'site' : $base;
	}

	/**
	 * A temporary name made from a domain, free to take: example.com becomes example-com, then example-com-2 when the
	 * first is used. The name is only the temporary address, so the customer is not asked for it.	 *
	 * @param string $domain Domain.
	 * @param string $owner  Who holds the name (see owner()).
	 */
	private static function free_slug( string $domain, string $owner ): string {
		$base = self::slug_base( $domain );

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
		$have = array() !== $zones;
		$pick = in_array( $post['install'], self::OFFERED, true ) ? $post['install'] : $install;
		$lang = Languages::offered( $post['language'] ) ? $post['language'] : Languages::preselected();
		$held = OrderDomain::cart_domains();
		$have_new = array() !== $held;
		$cart = $have_new ? $held[0] : array( 'domain' => '', 'mode' => '' );
		$tile = '' === $post['tile'] ? '' : $post['tile'];

		if ( self::MODE_NEW === $tile ) {
			$tile = $have_new ? $cart['mode'] : OrderDomain::REGISTER;
		}

		if ( '' === $tile ) {
			// A domain already in the cart is the choice; otherwise the temporary address.
			$tile = $have_new ? $cart['mode'] : self::MODE_PREVIEW;
		}

		if ( ! in_array( $tile, array( self::MODE_PREVIEW, self::MODE_OURS, self::MODE_OWN, OrderDomain::REGISTER, OrderDomain::TRANSFER ), true ) || ( self::MODE_OURS === $tile && ! $have ) ) {
			$tile = self::MODE_PREVIEW;
		}

		$mode = $tile;

		// The domain this order holds, and what the form posts for it (a posted name that is not in the cart is dropped).
		$chosen = '';

		foreach ( $held as $one ) {
			if ( self::MODE_NEW === $post['mode'] && $post['host_new'] === $one['domain'] ) {
				$chosen = $one['domain'];
			}
		}

		if ( '' === $chosen && $have_new ) {
			$chosen = $cart['domain'];
		}

		echo '<div class="beltoft-services-site-field__line" data-site-line data-od-line>';

		if ( '' !== $label ) {
			echo '<p class="beltoft-services-site-field__product">' . esc_html( $label ) . '</p>';
		}

		// Where the website is reached: one tile each.
		$items = array(
			array( 'value' => OrderDomain::REGISTER ) + OrderDomain::tile( OrderDomain::REGISTER ),
			array( 'value' => OrderDomain::TRANSFER ) + OrderDomain::tile( OrderDomain::TRANSFER ),
			array( 'value' => self::MODE_OWN ) + OrderDomain::tile( self::MODE_OWN ),
		);

		if ( $have ) {
			$items[] = array(
				'value' => self::MODE_OURS,
				'name'  => __( 'A domain in my account', 'beltoft-services' ),
				'line'  => __( 'Use a domain you already have with us', 'beltoft-services' ),
			);
		}

		$items[] = array(
			'value' => self::MODE_PREVIEW,
			'name'  => __( 'Decide later', 'beltoft-services' ),
			'line'  => __( 'Start on a temporary address', 'beltoft-services' ),
		);

		OrderDomain::print_tiles( array( 'name' => $name . '[mode]', 'id' => $id, 'legend' => __( 'Website address', 'beltoft-services' ), 'checked' => $mode, 'items' => $items ) );
		OrderDomain::print_value( $name . '[host_new]', $chosen );

		$find = (string) \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Domains::class )->url_register();

		foreach ( array( OrderDomain::REGISTER, OrderDomain::TRANSFER ) as $kind ) {
			OrderDomain::print_panel( $kind, $id, $kind === $mode, $have_new && $cart['mode'] === $kind ? $chosen : '', $find, __( 'The website starts on a temporary address and moves to this domain when its registration is finished.', 'beltoft-services' ) );
		}

		echo '<div class="beltoft-services-site-field__panel" data-site-panel="' . esc_attr( self::MODE_PREVIEW ) . '"' . ( self::MODE_PREVIEW === $mode ? '' : ' hidden' ) . '>';
		echo '<p class="beltoft-services-site-field__note">' . esc_html__( 'Your website gets a temporary address made from your name. You can connect a domain later.', 'beltoft-services' ) . '</p>';
		echo '</div>';

		if ( $have ) {
			echo '<div class="beltoft-services-site-field__panel" data-site-panel="' . esc_attr( self::MODE_OURS ) . '"' . ( self::MODE_OURS === $mode ? '' : ' hidden' ) . '>';
			echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_zone">' . esc_html__( 'Domain', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[zone]" id="' . esc_attr( $id ) . '_zone">';

			foreach ( $zones as $one ) {
				echo '<option value="' . esc_attr( $one ) . '"' . selected( $post['zone'], $one, false ) . '>' . esc_html( \BeltoftServices\Domains\HostName::display( $one ) ) . '</option>';
			}

			echo '</select></span></p>';
			self::text_row( $name . '[host_ours]', $id . '_host_ours', __( 'Or a name inside it', 'beltoft-services' ), $post['host_ours'], false, __( 'For example www.mine.example. Leave empty to use the domain itself. We set up its DNS for you.', 'beltoft-services' ), 'url' );
			echo '</div>';
		}

		echo '<div class="beltoft-services-site-field__panel" data-site-panel="' . esc_attr( self::MODE_OWN ) . '"' . ( self::MODE_OWN === $mode ? '' : ' hidden' ) . '>';
		self::text_row( $name . '[host]', $id . '_host', __( 'Domain name', 'beltoft-services' ), self::MODE_OWN === $mode ? $post['host'] : '', true, __( 'For example www.example.com', 'beltoft-services' ), 'url' );
		echo '<p class="beltoft-services-site-field__note">' . esc_html__( 'After you order, your account page shows the DNS records to add where your domain is managed: an A record that points the domain to our server, and a TXT record that proves the domain is yours. Until the domain points to us, the website works on a temporary address.', 'beltoft-services' ) . '</p>';
		echo '</div>';

		// What to install and in which language.
		echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_install">' . esc_html__( 'Install', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[install]" id="' . esc_attr( $id ) . '_install" data-site-install>';

		foreach ( array( 'wordpress' => __( 'WordPress', 'beltoft-services' ), 'woocommerce' => __( 'WordPress with WooCommerce', 'beltoft-services' ), 'empty' => __( 'No WordPress, an empty website', 'beltoft-services' ) ) as $value => $text ) {
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
		self::text_row( $name . '[label]', $id . '_label', __( 'Website title', 'beltoft-services' ), $post['label'], false, __( 'Shown in the browser tab. Empty uses the domain or the website name.', 'beltoft-services' ), 'text', 100 );
		echo '<div data-site-admin-email' . ( 'empty' === $pick ? ' hidden' : '' ) . '>';
		self::text_row( $name . '[email]', $id . '_email', __( 'WordPress admin email', 'beltoft-services' ), $post['email'], false, __( 'Empty uses your billing email.', 'beltoft-services' ), 'email' );
		echo '</div>';
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

		$sibling = LineField::siblings( $license, HostingDriver::ID, static function ( Resource $r ) {
			$site = $r->get( 'site' );

			return is_array( $site ) && isset( $site['slug'] ) ? (string) $site['slug'] : null;
		} );

		if ( null === $sibling ) {
			return $meta;
		}

		$used = $sibling['used'];

		foreach ( $sibling['items'] as $item ) {
			$site = $item->get_meta( self::ITEM_META, true );

			if ( is_array( $site ) && isset( $site['slug'] ) && ! in_array( (string) $site['slug'], $used, true ) ) {
				$meta['site'] = array(
					'slug'        => (string) $site['slug'],
					'label'       => (string) ( $site['label'] ?? '' ),
					'install'     => 'empty' !== ( $site['install'] ?? 'wordpress' ),
					'install_kind' => (string) ( $site['install'] ?? 'wordpress' ),
					'language'    => (string) ( $site['language'] ?? 'en_US' ),
					'admin_email' => (string) ( $site['admin_email'] ?? '' ),
					'domain'      => self::MODE_NEW === ( $site['mode'] ?? '' ) ? '' : (string) ( $site['domain'] ?? '' ),
				);

				// A domain of the same order is attached when its registration is ready (SiteNewDomain), not at provisioning.
				if ( self::MODE_NEW === ( $site['mode'] ?? '' ) && '' !== (string) ( $site['domain'] ?? '' ) ) {
					$meta[ SiteNewDomain::META_DOMAIN ] = (string) $site['domain'];
					$meta[ SiteNewDomain::META_UNTIL ]  = time() + self::WAIT_DAYS * DAY_IN_SECONDS;
				}

				break;
			}
		}

		return $meta;
	}
}
