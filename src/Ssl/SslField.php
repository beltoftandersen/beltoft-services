<?php
/**
 * The certificate choices at checkout: the name, how it is validated and where the key comes from.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

use BeltoftLicenses\Licenses;
use BeltoftServices\Checkout\LineField;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One block per certificate line in the classic checkout (a line of quantity 2 is refused: each certificate is its own
 * order). The choices are checked on submit, saved on the order line and handed to the new resource as meta through
 * beltoft_services_initial_meta. The block checkout (Store API) has no such fields, so it is refused for a cart with a
 * certificate, with a message that points to the classic checkout. Nothing here places an order at the registrar.
 */
final class SslField {

	public const POST_KEY  = 'beltoft_services_ssl';
	public const ITEM_META = '_beltoft_services_ssl';

	/** Hooks. */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_store_api' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 30, 4 );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 30, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Checkout with a certificate line: the script that shows only the inputs of the chosen options.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || array() === self::cart_lines() ) {
			return;
		}

		wp_enqueue_script( 'beltoft-services-ssl-field', plugins_url( 'assets/ssl-field.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/ssl-field.js' ), true );
	}

	/**
	 * Is the product (or variation) one of the ssl driver?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function is_ssl_product( int $product_id ): bool {
		$driver = $product_id > 0 ? Product::driver_for( $product_id ) : null;

		return $driver instanceof Driver && SslDriver::ID === $driver->id();
	}

	/**
	 * The cart lines that start a new certificate.
	 *
	 * @return array<string, array<string, mixed>> Cart item key => cart item.
	 */
	public static function cart_lines(): array {
		return LineField::cart_lines( array( __CLASS__, 'is_ssl_product' ) );
	}

	/**
	 * The plan of a cart line: registrar product, level and wildcard.
	 *
	 * @param array<string, mixed> $item Cart item.
	 * @return array{level: string, wildcard: bool, domains: int, smime: bool}
	 */
	private static function plan_of( array $item ): array {
		$plan = Product::plan( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ?: null );

		return array(
			'smime'    => SslCatalog::is_smime( (int) ( $plan['ssl_product'] ?? 0 ) ),
			'level'    => in_array( (string) ( $plan['ssl_level'] ?? '' ), array( 'dv', 'ov', 'ev' ), true ) ? (string) $plan['ssl_level'] : 'dv',
			'wildcard' => ! empty( $plan['ssl_wildcard'] ),
			'domains'  => max( 1, (int) ( $plan['ssl_domains'] ?? 1 ) ),
		);
	}

	/**
	 * The choices of one line, or why they cannot be used.
	 *
	 * @param array<string, mixed> $input    domain, validation, approver, key_mode, csr; for a multi domain certificate names (a list of domain and validation).
	 * @param int                  $user_id  Customer, 0 for a guest.
	 * @param string               $level    dv, ov or ev.
	 * @param bool                 $wildcard Wildcard product.
	 * @param string               $company  The billing company, for OV and EV.
	 * @param int                  $domains  How many names the product includes (more than one makes it a multi domain certificate).
	 * @return array<string, mixed>|\WP_Error What is saved on the order line; the error's message is for the customer.
	 */
	public static function resolve( array $input, int $user_id, string $level, bool $wildcard, string $company, int $domains = 1 ) {
		if ( ! SslSettings::enabled() ) {
			return new \WP_Error( 'ssl_off', __( 'SSL certificates cannot be ordered right now. Please try again later.', 'beltoft-services' ) );
		}

		$entries  = array();
		$approver = '';

		if ( $domains > 1 ) {
			$entries = self::multi_entries( $input, $user_id, $domains );

			if ( is_wp_error( $entries ) ) {
				return $entries;
			}

			$base   = $entries[0]['base'];
			$name   = $entries[0]['name'];
			$method = $entries[0]['validation'];
		} else {
			$base = SslNames::base( $input['domain'] ?? '' );

			if ( null === $base ) {
				return new \WP_Error( 'ssl_domain', __( 'Enter the domain name for the certificate, for example example.com.', 'beltoft-services' ) );
			}

			if ( ! $wildcard && SslNames::typed_wildcard( $input['domain'] ?? '' ) ) {
				return new \WP_Error( 'ssl_wildcard', __( 'This certificate covers one name. Choose a wildcard certificate for *.example.com.', 'beltoft-services' ) );
			}

			$name   = SslNames::common_name( $base, $wildcard );
			$method = (string) ( $input['validation'] ?? '' );

			if ( ! in_array( $method, Validation::METHODS, true ) || ( $wildcard && 'file' === $method ) ) {
				return new \WP_Error( 'ssl_validation', $wildcard ? __( 'A wildcard certificate is validated with a DNS record or an email.', 'beltoft-services' ) : __( 'Choose how the domain is validated.', 'beltoft-services' ) );
			}

			if ( 'auto' === $method && null === SslNames::zone_for( $base, $user_id ) ) {
				return new \WP_Error( 'ssl_auto', __( 'Automatic validation needs a domain on our name servers. Choose another method.', 'beltoft-services' ) );
			}

			if ( 'email' === $method ) {
				$approver = (string) SslNames::approver( (string) ( $input['approver'] ?? '' ), $base );

				if ( '' === $approver ) {
					return new \WP_Error( 'ssl_approver', __( 'Choose the address the approval email is sent to.', 'beltoft-services' ) );
				}
			}
		}

		$key_mode = 'own' === ( $input['key_mode'] ?? '' ) ? 'own' : 'generate';
		$csr      = '';

		if ( 'own' === $key_mode ) {
			$read = Csr::check( (string) ( $input['csr'] ?? '' ), $name, $level, array_column( $entries, 'name' ) );

			if ( is_wp_error( $read ) ) {
				return $read;
			}

			$csr = trim( (string) $input['csr'] );
		} elseif ( ! Crypto::available() ) {
			return new \WP_Error( 'ssl_key', __( 'We cannot make a key for you right now. Paste your own certificate request instead.', 'beltoft-services' ) );
		}

		if ( in_array( $level, array( 'ov', 'ev' ), true ) && '' === trim( $company ) ) {
			return new \WP_Error( 'ssl_company', __( 'This certificate type is issued to a company. Enter the company name in the billing details.', 'beltoft-services' ) );
		}

		$refused = SslNames::refusal( $user_id, $name );

		if ( null !== $refused ) {
			return new \WP_Error( 'ssl_refused', $refused );
		}

		$saved = array( 'domain' => $base, 'name' => $name, 'validation' => $method, 'approver' => $approver, 'key_mode' => $key_mode, 'csr' => $csr );

		return array() === $entries ? $saved : $saved + array( 'names' => $entries );
	}

	/**
	 * The choices of an S/MIME line: the mailbox the certificate is for, the name on it, and where the key comes from. The mailbox is
	 * the address of the account or the billing email of this order: the authority mails its confirmation link there, and the
	 * checkout does not let an order be placed for any address (the filter beltoft_services_smime_mailbox_allowed can).
	 *
	 * @param array<string, mixed> $input         mailbox, person, key_mode, csr.
	 * @param int                  $user_id       Customer, 0 for a guest.
	 * @param string               $billing_email The billing email of the order.
	 * @param string               $account_email The email of the logged in account, or ''.
	 * @return array<string, mixed>|\WP_Error What is saved on the order line; the error's message is for the customer.
	 */
	public static function resolve_smime( array $input, int $user_id, string $billing_email, string $account_email ) {
		if ( ! SslSettings::enabled() ) {
			return new \WP_Error( 'ssl_off', __( 'SSL certificates cannot be ordered right now. Please try again later.', 'beltoft-services' ) );
		}

		$mailbox = strtolower( trim( (string) ( $input['mailbox'] ?? '' ) ) );

		if ( '' === $mailbox || ! is_email( $mailbox ) || 1 !== preg_match( '/^[^\s,;:]+$/D', $mailbox ) || strlen( $mailbox ) > 120 ) {
			return new \WP_Error( 'ssl_mailbox', __( 'Enter the email address the certificate is for.', 'beltoft-services' ) );
		}

		$allowed = in_array( $mailbox, array_filter( array( strtolower( trim( $billing_email ) ), strtolower( trim( $account_email ) ) ) ), true );

		/**
		 * May an S/MIME certificate be ordered for this mailbox? By default only the account email and the billing email of the order.
		 *
		 * @param bool   $allowed Whether it is allowed.
		 * @param string $mailbox The address.
		 * @param int    $user_id Customer.
		 */
		if ( ! apply_filters( 'beltoft_services_smime_mailbox_allowed', $allowed, $mailbox, $user_id ) ) {
			return new \WP_Error( 'ssl_mailbox_bound', __( 'The certificate can be ordered for your account email or the billing email of this order. Change the billing email, or use that address.', 'beltoft-services' ) );
		}

		$key_mode = 'own' === ( $input['key_mode'] ?? '' ) ? 'own' : 'generate';
		$csr      = '';

		if ( 'own' === $key_mode ) {
			$read = Csr::check_email( (string) ( $input['csr'] ?? '' ), $mailbox );

			if ( is_wp_error( $read ) ) {
				return $read;
			}

			$csr = trim( (string) $input['csr'] );
		} elseif ( ! Crypto::available() ) {
			return new \WP_Error( 'ssl_key', __( 'We cannot make a key for you right now. Paste your own certificate request instead.', 'beltoft-services' ) );
		}

		$refused = SslNames::refusal( $user_id, $mailbox );

		if ( null !== $refused ) {
			return new \WP_Error( 'ssl_refused', $refused );
		}

		$person = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', wp_strip_all_tags( (string) ( $input['person'] ?? '' ) ) ) );

		return array(
			'profile'    => 'smime',
			'domain'     => (string) substr( strrchr( $mailbox, '@' ), 1 ),
			'name'       => $mailbox,
			'person'     => mb_substr( $person, 0, 64 ),
			'validation' => 'email',
			'approver'   => $mailbox,
			'key_mode'   => $key_mode,
			'csr'        => $csr,
		);
	}

	/**
	 * The names of a multi domain certificate, each with its validation method. Blank rows are skipped; the first filled row is
	 * the common name. Email validation is not offered (the registrar's sandbox does not take it per name), nor are wildcard names.
	 *
	 * @param array<string, mixed> $input   The posted choices.
	 * @param int                  $user_id Customer.
	 * @param int                  $max     Names the product includes.
	 * @return array<int, array{name: string, base: string, validation: string}>|\WP_Error
	 */
	private static function multi_entries( array $input, int $user_id, int $max ) {
		$entries = array();
		$seen    = array();

		foreach ( (array) ( $input['names'] ?? array() ) as $row ) {
			$typed = is_array( $row ) ? trim( (string) ( $row['domain'] ?? '' ) ) : '';

			if ( '' === $typed ) {
				continue;
			}

			$one = SslNames::list_of( $typed );

			if ( null === $one || 1 !== count( $one ) ) {
				/* translators: %s: what was typed. */
				return new \WP_Error( 'ssl_domain', sprintf( __( '%s is not a domain name. Enter one name per row, for example example.com or www.example.com. A wildcard name needs a wildcard certificate.', 'beltoft-services' ), wp_strip_all_tags( $typed ) ) );
			}

			$method = (string) ( $row['validation'] ?? '' );

			if ( ! in_array( $method, array( 'auto', 'dns', 'file' ), true ) ) {
				/* translators: %s: domain name. */
				return new \WP_Error( 'ssl_validation', sprintf( __( 'Choose how %s is validated: automatically, with a DNS record or with a file.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $one[0] ) ) );
			}

			if ( 'auto' === $method && null === SslNames::zone_for( $one[0], $user_id ) ) {
				/* translators: %s: domain name. */
				return new \WP_Error( 'ssl_auto', sprintf( __( 'Automatic validation needs %s on our name servers. Choose another method for it.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $one[0] ) ) );
			}

			if ( isset( $seen[ $one[0] ] ) ) {
				/* translators: %s: domain name. */
				return new \WP_Error( 'ssl_duplicate', sprintf( __( '%s is entered twice.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $one[0] ) ) );
			}

			$seen[ $one[0] ] = true;
			$entries[]       = array( 'name' => $one[0], 'base' => $one[0], 'validation' => $method );
		}

		if ( array() === $entries ) {
			return new \WP_Error( 'ssl_domain', __( 'Enter the domain names for the certificate, for example example.com.', 'beltoft-services' ) );
		}

		// The registrar refuses a multi domain order with a single name, so one name is a job for a single name certificate.
		if ( count( $entries ) < 2 ) {
			return new \WP_Error( 'ssl_few', __( 'This certificate is for several names. Enter at least two, or choose a certificate for one name.', 'beltoft-services' ) );
		}

		if ( count( $entries ) > $max ) {
			/* translators: %d: number of names. */
			return new \WP_Error( 'ssl_too_many', sprintf( _n( 'This certificate covers up to %d name.', 'This certificate covers up to %d names.', $max, 'beltoft-services' ), $max ) );
		}

		return $entries;
	}

	/**
	 * The block checkout cannot ask for the choices, so a cart with a new certificate line is refused there.
	 *
	 * @param \WC_Order         $order   Order being made.
	 * @param \WP_REST_Request $request Store API request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the cart has a certificate line.
	 */
	public static function block_store_api( \WC_Order $order, \WP_REST_Request $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		LineField::refuse_store_api( array( __CLASS__, 'is_ssl_product' ), 'beltoft_services_ssl_checkout_required', __( 'SSL certificates are ordered at the website checkout, where you enter the domain. Please use the checkout page of the website.', 'beltoft-services' ) );
	}

	/**
	 * What the form posted for one cart line, only inside WooCommerce's own checkout run (it verified its nonce first).
	 *
	 * @param string $key Cart item key.
	 * @return array<string, mixed>
	 */
	private static function posted( string $key ): array {
		return did_action( 'woocommerce_checkout_process' ) ? self::read_post( $key ) : array();
	}

	/**
	 * The posted values of one line as plain strings (the CSR keeps its line breaks).
	 *
	 * @param string $key Cart item key.
	 * @return array<string, mixed>
	 */
	private static function read_post( string $key ): array {
		$out = LineField::read_post( self::POST_KEY, $key, array( 'domain', 'validation', 'approver', 'key_mode', 'csr', 'mailbox', 'person' ) );
		$one = LineField::posted_line( self::POST_KEY, $key );

		$out['names'] = array();

		foreach ( isset( $one['names'] ) && is_array( $one['names'] ) ? array_slice( $one['names'], 0, 20 ) : array() as $row ) {
			$out['names'][] = array(
				'domain'     => is_array( $row ) && isset( $row['domain'] ) && is_string( $row['domain'] ) ? trim( $row['domain'] ) : '',
				'validation' => is_array( $row ) && isset( $row['validation'] ) && is_string( $row['validation'] ) ? trim( $row['validation'] ) : '',
			);
		}

		return $out;
	}

	/**
	 * Prints one block per certificate line.
	 */
	public static function render(): void {
		$lines = self::cart_lines();

		if ( array() === $lines ) {
			return;
		}

		$user  = get_current_user_id();
		$zones = $user > 0 ? ZoneAccess::zones( $user ) : array();

		$smime = true;

		foreach ( $lines as $item ) {
			$smime = $smime && self::plan_of( $item )['smime'];
		}

		echo '<div class="beltoft-services-ssl-field" style="margin:40px 0 0">';
		/**
		 * Filters whether the checkout fields print their own heading. A checkout that shows these fields in a step
		 * with a heading of its own returns false.
		 *
		 * @param bool   $show Whether to print the heading.
		 * @param string $kind Which fields: hosting, mail, ssl or licence.
		 */
		if ( apply_filters( 'beltoft_services_checkout_field_heading', true, 'ssl' ) ) {
			echo '<h3 style="margin:0 0 16px">' . esc_html( $smime ? __( 'Email certificate (S/MIME)', 'beltoft-services' ) : __( 'SSL certificate', 'beltoft-services' ) ) . '</h3>';
		}

		foreach ( $lines as $key => $item ) {
			self::print_line( (string) $key, self::plan_of( $item ), $zones );
		}

		echo '</div>';
	}

	/**
	 * The fields of one line.
	 *
	 * @param string                               $key   Cart item key.
	 * @param array{level: string, wildcard: bool, domains: int, smime: bool} $plan  Plan.
	 * @param string[]                                           $zones The customer's domains on our name servers.
	 */
	private static function print_line( string $key, array $plan, array $zones ): void {
		$name = self::POST_KEY . '[' . $key . ']';
		$id   = self::POST_KEY . '_' . sanitize_key( $key );
		$post = self::read_post( $key );

		echo '<fieldset class="beltoft-services-ssl-field__line" style="border:0;padding:0;margin:0 0 16px">';

		if ( $plan['smime'] ) {
			self::print_smime( $name, $id, $post );
		} elseif ( $plan['domains'] > 1 ) {
			self::print_names( $name, $id, $plan['domains'], $post, $zones );
		} else {
			self::print_name( $name, $id, $plan, $post, $zones );
		}

		self::print_key( $name, $plan, $post );
		echo '</fieldset>';
	}

	/**
	 * The name and validation fields of a single name or wildcard certificate.
	 *
	 * @param string                $name  Field name prefix.
	 * @param string                $id    Field id prefix.
	 * @param array<string, mixed>  $plan  Plan.
	 * @param array<string, mixed>  $post  Posted values.
	 * @param string[]              $zones The customer's domains on our name servers.
	 */
	private static function print_name( string $name, string $id, array $plan, array $post, array $zones ): void {
		$have = array() !== $zones;
		$meth = '' !== $post['validation'] ? $post['validation'] : ( $have ? 'auto' : 'dns' );

		echo '<p class="form-row form-row-wide validate-required"><label for="' . esc_attr( $id ) . '_domain">' . esc_html( $plan['wildcard'] ? __( 'Domain for the wildcard certificate', 'beltoft-services' ) : __( 'Domain name', 'beltoft-services' ) ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr></label>';
		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( $name ) . '[domain]" id="' . esc_attr( $id ) . '_domain" maxlength="253" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( /* translators: example domain shown in an empty field; use the usual example for your language. */ __( 'example.com', 'beltoft-services' ) ) . '" list="' . esc_attr( $id ) . '_zones" value="' . esc_attr( $post['domain'] ) . '"></span>';

		if ( $have ) {
			echo '<datalist id="' . esc_attr( $id ) . '_zones">';

			foreach ( $zones as $zone ) {
				echo '<option value="' . esc_attr( $zone ) . '"></option>';
			}

			echo '</datalist>';
		}

		echo '<span class="description">' . esc_html( $plan['wildcard'] ? __( 'The certificate covers every name under this domain, such as *.example.com.', 'beltoft-services' ) : __( 'The one name the certificate is for, for example example.com or www.example.com.', 'beltoft-services' ) ) . '</span></p>';

		$methods = array(
			'auto'  => array( __( 'Add the validation record to my DNS for me (domains on our name servers)', 'beltoft-services' ), $have ),
			'dns'   => array( __( 'I add a DNS record myself', 'beltoft-services' ), true ),
			'file'  => array( __( 'I put a file on my website', 'beltoft-services' ), ! $plan['wildcard'] ),
			'email' => array( __( 'Send an approval email to an address at the domain', 'beltoft-services' ), true ),
		);

		echo '<p class="form-row form-row-wide"><span>' . esc_html__( 'How the domain is validated', 'beltoft-services' ) . '</span>';

		foreach ( $methods as $value => $row ) {
			if ( $row[1] ) {
				echo '<label style="display:block"><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[validation]" value="' . esc_attr( $value ) . '"' . checked( $meth, $value, false ) . '> ' . esc_html( $row[0] ) . '</label>';
			}
		}

		echo '</p><p class="form-row form-row-wide" data-ssl-show="validation:email"' . ( 'email' === $meth ? '' : ' hidden' ) . '><label for="' . esc_attr( $id ) . '_approver">' . esc_html__( 'Approval address', 'beltoft-services' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[approver]" id="' . esc_attr( $id ) . '_approver">';

		foreach ( SslNames::APPROVERS as $prefix ) {
			echo '<option value="' . esc_attr( $prefix ) . '"' . selected( $post['approver'], $prefix, false ) . '>' . esc_html( $prefix . '@' ) . '</option>';
		}

		echo '</select></span><span class="description">' . esc_html__( 'The approval email goes to this address at your domain.', 'beltoft-services' ) . '</span></p>';
	}

	/**
	 * The mailbox and the name of an S/MIME certificate.
	 *
	 * @param string               $name Field name prefix.
	 * @param string               $id   Field id prefix.
	 * @param array<string, mixed> $post Posted values.
	 */
	private static function print_smime( string $name, string $id, array $post ): void {
		$user    = wp_get_current_user();
		$mailbox = '' !== $post['mailbox'] ? $post['mailbox'] : ( $user->exists() ? (string) $user->user_email : '' );

		echo '<p class="form-row form-row-wide validate-required"><label for="' . esc_attr( $id ) . '_mailbox">' . esc_html__( 'Email address for the certificate', 'beltoft-services' ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr></label>';
		echo '<span class="woocommerce-input-wrapper"><input type="email" class="input-text" name="' . esc_attr( $name ) . '[mailbox]" id="' . esc_attr( $id ) . '_mailbox" maxlength="120" autocomplete="email" spellcheck="false" value="' . esc_attr( $mailbox ) . '"></span>';
		echo '<span class="description">' . esc_html__( 'The certificate is for this one address, your account email or the billing email of this order. We send you a link to confirm that the mailbox is yours, and you click it.', 'beltoft-services' ) . '</span></p>';
		echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_person">' . esc_html__( 'Name on the certificate (optional)', 'beltoft-services' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( $name ) . '[person]" id="' . esc_attr( $id ) . '_person" maxlength="64" autocomplete="name" value="' . esc_attr( $post['person'] ) . '"></span>';
		echo '<span class="description">' . esc_html__( 'Left empty, the name in your billing details is used.', 'beltoft-services' ) . '</span></p>';
	}

	/**
	 * One row per name of a multi domain certificate: the name and how it is validated.
	 *
	 * @param string               $name    Field name prefix.
	 * @param string               $id      Field id prefix.
	 * @param int                  $domains Names the certificate includes.
	 * @param array<string, mixed> $post    Posted values.
	 * @param string[]             $zones   The customer's domains on our name servers.
	 */
	private static function print_names( string $name, string $id, int $domains, array $post, array $zones ): void {
		$have = array() !== $zones;

		echo '<p class="form-row form-row-wide"><span>' . esc_html( sprintf( /* translators: %d: number of names. */ _n( 'One certificate for up to %d name. The first name is the main name.', 'One certificate for up to %d names. The first name is the main name.', $domains, 'beltoft-services' ), $domains ) ) . '</span></p>';

		if ( $have ) {
			echo '<datalist id="' . esc_attr( $id ) . '_zones">';

			foreach ( $zones as $zone ) {
				echo '<option value="' . esc_attr( $zone ) . '"></option>';
			}

			echo '</datalist>';
		}

		$methods = array(
			'auto' => array( __( 'We add the DNS record', 'beltoft-services' ), $have ),
			'dns'  => array( __( 'I add a DNS record', 'beltoft-services' ), true ),
			'file' => array( __( 'I put a file on my website', 'beltoft-services' ), true ),
		);

		for ( $i = 0; $i < $domains; $i++ ) {
			$row  = $post['names'][ $i ] ?? array( 'domain' => '', 'validation' => '' );
			$meth = '' !== (string) $row['validation'] ? (string) $row['validation'] : ( $have ? 'auto' : 'dns' );
			/* translators: %d: row number. */
			$label = sprintf( __( 'Name %d', 'beltoft-services' ), $i + 1 );

			// The method list sits in its own row: in the name's row its value would make an empty required name pass the step check.
			echo '<p class="form-row form-row-wide' . ( 0 === $i ? ' validate-required' : '' ) . '"><label for="' . esc_attr( $id ) . '_name_' . (int) $i . '">' . esc_html( $label ) . ( 0 === $i ? '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr>' : '' ) . '</label>';
			echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( $name ) . '[names][' . (int) $i . '][domain]" id="' . esc_attr( $id ) . '_name_' . (int) $i . '" maxlength="253" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( /* translators: example domain shown in an empty field; use the usual example for your language. */ __( 'example.com', 'beltoft-services' ) ) . '" list="' . esc_attr( $id ) . '_zones" value="' . esc_attr( (string) $row['domain'] ) . '"></span></p>';
			echo '<p class="form-row form-row-wide"><span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[names][' . (int) $i . '][validation]" aria-label="' . esc_attr( sprintf( /* translators: %d: row number. */ __( 'How name %d is validated', 'beltoft-services' ), $i + 1 ) ) . '">';

			foreach ( $methods as $value => $m ) {
				if ( $m[1] ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $meth, $value, false ) . '>' . esc_html( $m[0] ) . '</option>';
				}
			}

			echo '</select></span></p>';
		}

		echo '<p class="description">' . esc_html__( 'Leave a row empty to use fewer names. Every name is validated on its own.', 'beltoft-services' ) . '</p>';
	}

	/**
	 * The key choice and the note for company certificates.
	 *
	 * @param string               $name Field name prefix.
	 * @param array<string, mixed> $plan Plan.
	 * @param array<string, mixed> $post Posted values.
	 */
	private static function print_key( string $name, array $plan, array $post ): void {
		$mode = 'own' === $post['key_mode'] ? 'own' : 'generate';

		echo '<p class="form-row form-row-wide"><span>' . esc_html__( 'Private key', 'beltoft-services' ) . '</span>';
		echo '<label style="display:block"><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[key_mode]" value="generate"' . checked( $mode, 'generate', false ) . '> ' . esc_html__( 'Make the key for me. I download it once from my account.', 'beltoft-services' ) . '</label>';
		echo '<label style="display:block"><input type="radio" class="input-radio" name="' . esc_attr( $name ) . '[key_mode]" value="own"' . checked( $mode, 'own', false ) . '> ' . esc_html__( 'I have a certificate request (CSR). We never see my key.', 'beltoft-services' ) . '</label>';
		echo '</p><p class="form-row form-row-wide validate-required" data-ssl-show="key_mode:own"' . ( 'own' === $mode ? '' : ' hidden' ) . '><label for="' . esc_attr( sanitize_key( $name ) ) . '_csr">' . esc_html__( 'Certificate request (CSR)', 'beltoft-services' ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr></label>';
		echo '<span class="woocommerce-input-wrapper"><textarea class="input-text" name="' . esc_attr( $name ) . '[csr]" id="' . esc_attr( sanitize_key( $name ) ) . '_csr" rows="5" spellcheck="false" placeholder="-----BEGIN CERTIFICATE REQUEST-----">' . esc_textarea( $post['csr'] ) . '</textarea></span></p>';

		if ( 'dv' !== $plan['level'] ) {
			echo '<p class="description">' . esc_html__( 'This certificate is issued to the company in your billing details. The certificate authority checks the company and may phone or ask for documents, which can take a few days.', 'beltoft-services' ) . '</p>';
		}
	}

	/**
	 * Refuses a classic checkout whose certificate lines have unusable choices.
	 *
	 * @param mixed     $data   Posted checkout data.
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void {
		$lines = self::cart_lines();

		if ( array() === $lines || ! $errors instanceof \WP_Error ) {
			return;
		}

		$user    = get_current_user_id();
		$company = is_array( $data ) ? (string) ( $data['billing_company'] ?? '' ) : '';

		foreach ( $lines as $key => $item ) {
			if ( (int) ( $item['quantity'] ?? 1 ) > 1 ) {
				$errors->add( 'beltoft_services_ssl', __( 'Each certificate is its own order. Set the quantity to 1 and order again for another certificate.', 'beltoft-services' ) );
				continue;
			}

			$plan  = self::plan_of( $item );
			$found = $plan['smime']
				? self::resolve_smime( self::posted( (string) $key ), $user, is_array( $data ) ? (string) ( $data['billing_email'] ?? '' ) : '', self::account_email( $user ) )
				: self::resolve( self::posted( (string) $key ), $user, $plan['level'], $plan['wildcard'], $company, $plan['domains'] );

			if ( is_wp_error( $found ) ) {
				$errors->add( 'beltoft_services_ssl', $found->get_error_message() );
			}
		}
	}

	/**
	 * The email of a logged in account, '' for a guest.
	 *
	 * @param int $user_id User.
	 */
	private static function account_email( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user ? (string) $user->user_email : '';
	}

	/**
	 * Saves the choices on the order line.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key.
	 * @param array<string, mixed>   $values Cart item.
	 * @param \WC_Order              $order  Order.
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! is_string( $key ) || ! isset( self::cart_lines()[ $key ] ) ) {
			return;
		}

		$plan    = self::plan_of( is_array( $values ) ? $values : array() );
		$company = $order instanceof \WC_Order ? (string) $order->get_billing_company() : '';
		$found   = $plan['smime']
			? self::resolve_smime( self::posted( $key ), get_current_user_id(), $order instanceof \WC_Order ? (string) $order->get_billing_email() : '', self::account_email( get_current_user_id() ) )
			: self::resolve( self::posted( $key ), get_current_user_id(), $plan['level'], $plan['wildcard'], $company, $plan['domains'] );

		if ( is_array( $found ) ) {
			$item->add_meta_data( self::ITEM_META, $found, true );
		}
	}

	/**
	 * A new certificate starts with the choices its order line carries. When an order has several lines of one product, each
	 * license takes a line no other service of the order has taken.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || SslDriver::ID !== $driver->id() ) {
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

			if ( $resource && SslDriver::ID === $resource->get( 'driver' ) ) {
				$used[] = (int) $resource->get( 'order_item_id' );
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id || ( 0 !== $variation && (int) $item->get_variation_id() !== $variation ) || in_array( (int) $item->get_id(), $used, true ) ) {
				continue;
			}

			$data = $item->get_meta( self::ITEM_META, true );

			if ( ! is_array( $data ) || empty( $data['name'] ) ) {
				continue;
			}

			$meta['ssl_domain']     = (string) $data['domain'];
			$meta['ssl_name']       = (string) $data['name'];
			$meta['ssl_validation'] = (string) $data['validation'];
			$meta['ssl_approver']   = (string) $data['approver'];
			$meta['ssl_key_mode']   = (string) $data['key_mode'];
			$meta['order_item_id']  = (int) $item->get_id();

			if ( 'own' === $data['key_mode'] ) {
				$meta['ssl_csr'] = (string) $data['csr'];
			}

			if ( ! empty( $data['person'] ) ) {
				$meta['ssl_person'] = (string) $data['person'];
			}

			if ( ! empty( $data['names'] ) && is_array( $data['names'] ) ) {
				$meta['ssl_names'] = array_values( array_map( static fn( $e ) => array( 'name' => (string) $e['name'], 'validation' => (string) $e['validation'] ), array_filter( $data['names'], 'is_array' ) ) );
			}

			break;
		}

		return $meta;
	}
}
