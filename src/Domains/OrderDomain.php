<?php
/**
 * The domain part of an order form: tiles to register, transfer or keep a domain, with the search inside the checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the hosting address block and the Business email domain block (both ask which domain a service runs on),
 * so it lives here, where both may import it. It prints the choice as radio tiles and the panels of the two tiles that
 * put a domain in this order: Register (a search, then "Add to order") and Transfer (the domain and its transfer code).
 * The panels call the existing routes: `domains/check` for the search and `domains/cart` for the add, which end in
 * `Cart::add_line()` with all its checks. The domain line then sits beside the service line (`CartPair`). The script
 * asks the checkout to refresh (`update_checkout`) after an add or a removal. A removal is one wc-ajax call here.
 * The form posts the chosen domain as a hidden value, and the field that owns the block checks it against the cart.
 */
final class OrderDomain {

	/** Tile values that put a domain in the order. The field maps both to its own "new domain" mode. */
	public const REGISTER = 'register';
	public const TRANSFER = 'transfer';

	/** The wc-ajax action that takes a domain line out of the cart. */
	public const AJAX = 'beltoft_services_order_domain';

	/**
	 * Nonce action of that call. It starts with `woocommerce-`: only then does WooCommerce put the guest's session customer id into the
	 * nonce, so a guest's nonce does not verify in another session (a plain action is built on user 0 for every guest).
	 */
	public const NONCE = 'woocommerce-beltoft-services-order-domain';

	/** Script and style handle. */
	public const HANDLE = 'beltoft-services-order-domain';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'fragments' ) );
	}

	/**
	 * The marker that says whether the cart needs the customer's phone and which domains it holds (a domain contact goes to the registrar). The site's
	 * checkout prints its phone row when the page loads, so the script reloads the page once when a domain was added in the
	 * page and the row is not there.
	 *
	 * @param mixed $fragments Fragments of the order review refresh.
	 * @return mixed
	 */
	public static function fragments( $fragments ) {
		if ( is_array( $fragments ) ) {
			$fragments['.beltoft-services-od-phone'] = self::phone_marker();
		}

		return $fragments;
	}

	/**
	 * The marker element.
	 */
	public static function phone_marker(): string {
		$names = implode( ',', array_column( self::cart_domains(), 'domain' ) );

		return '<span class="beltoft-services-od-phone" data-od-phone="' . ( \BeltoftServices\BillingRequirements::cart_needs_phone() ? '1' : '0' ) . '" data-od-domains="' . esc_attr( $names ) . '" hidden></span>';
	}

	/**
	 * The domain lines of the cart that register or transfer a name (a DNS hosting line is not one).
	 *
	 * @return array<int, array{domain: string, mode: string}> Normalised name and register or transfer.
	 */
	public static function cart_domains(): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$out  = array();

		if ( ! $cart ) {
			return $out;
		}

		foreach ( $cart->get_cart() as $item ) {
			$line = $item['beltoft_services_domain'] ?? null;

			if ( ! is_array( $line ) || ! in_array( $line['mode'] ?? '', array( self::REGISTER, self::TRANSFER ), true ) ) {
				continue;
			}

			$name = HostName::normalise( (string) ( $line['domain'] ?? '' ) );

			if ( null !== $name ) {
				$out[ $name ] = array( 'domain' => $name, 'mode' => (string) $line['mode'] );
			}
		}

		return array_values( $out );
	}

	/**
	 * The name and the one line of a tile that is the same in every form.
	 *
	 * @param string $kind register, transfer or own.
	 * @return array{name: string, line: string}
	 */
	public static function tile( string $kind ): array {
		switch ( $kind ) {
			case self::REGISTER:
				return array(
					'name' => __( 'Register a new domain', 'beltoft-services' ),
					'line' => __( 'Find the name for your website', 'beltoft-services' ),
				);
			case self::TRANSFER:
				return array(
					'name' => __( 'Transfer a domain', 'beltoft-services' ),
					'line' => __( 'Move it from another provider', 'beltoft-services' ),
				);
			default:
				return array(
					'name' => __( 'Use my own domain', 'beltoft-services' ),
					'line' => __( 'Keep it with your current provider', 'beltoft-services' ),
				);
		}
	}

	/**
	 * The script and the style, on the checkout page.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/order-domain.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/order-domain.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/order-domain.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/order-domain.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesOrderDomain', self::config() );
	}

	/**
	 * What the script needs: the endpoints, the nonces and every text it prints (the strings are translated here, so the
	 * script has no translation files of its own).
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$root = Search::root_data();

		return array(
			'check'     => (string) ( $root['data-check'] ?? '' ),
			'cart'      => (string) ( $root['data-cart'] ?? '' ),
			'nonce'     => (string) ( $root['data-nonce'] ?? '' ),
			'restNonce' => (string) ( $root['data-rest-nonce'] ?? '' ),
			'remove'    => class_exists( '\WC_AJAX' ) ? \WC_AJAX::get_endpoint( self::AJAX ) : '',
			'removeNonce' => wp_create_nonce( self::NONCE ),
			'tax'       => \BeltoftServices\Billing\TaxNote::display( 'cart' ),
			'text'      => self::texts(),
		);
	}

	/**
	 * Every text the script prints.
	 *
	 * @return array<string, string>
	 */
	public static function texts(): array {
		return array(
			'searching'   => __( 'Searching for domains…', 'beltoft-services' ),
			'checking'    => __( 'Checking the domain…', 'beltoft-services' ),
			'adding'      => __( 'Adding to order…', 'beltoft-services' ),
			'removing'    => __( 'Removing…', 'beltoft-services' ),
			'add'         => __( 'Add to order', 'beltoft-services' ),
			'addTransfer' => __( 'Add transfer to order', 'beltoft-services' ),
			/* translators: %s: domain name */
			'addLabel'    => __( 'Add %s to this order', 'beltoft-services' ),
			'free'        => __( 'Available to register', 'beltoft-services' ),
			'taken'       => __( 'Taken', 'beltoft-services' ),
			'premium'     => __( 'Premium domain. Online ordering is unavailable.', 'beltoft-services' ),
			'notSold'     => __( 'This domain extension is not available to order here.', 'beltoft-services' ),
			'unknown'     => __( 'Availability could not be confirmed.', 'beltoft-services' ),
			'noResults'   => __( 'No results found.', 'beltoft-services' ),
			/* translators: %s: the price with its currency symbol, such as the euro symbol and 12 */
			'perYear'     => __( '%s / year', 'beltoft-services' ),
			'priceInclVat' => __( 'Prices are per year, including VAT. The renewal price is the same as the first year.', 'beltoft-services' ),
			'priceExclVat' => __( 'Prices are per year, excluding VAT. The renewal price is the same as the first year.', 'beltoft-services' ),
			'transferInstead' => __( 'Transfer it instead', 'beltoft-services' ),
			'registerInstead' => __( 'Register it instead', 'beltoft-services' ),
			'freeTransfer' => __( 'Free transfer', 'beltoft-services' ),
			'freeTransferNote' => __( 'The transfer is free. The first yearly payment is due when the domain is up for renewal.', 'beltoft-services' ),
			'paidTransferNote' => __( 'The transfer is charged as one year and adds that year to the registration.', 'beltoft-services' ),
			'notRegistered' => __( 'Not registered, so there is nothing to transfer.', 'beltoft-services' ),
			'registered'  => __( 'Registered, can be transferred', 'beltoft-services' ),
			'noTransfer'  => __( 'This domain cannot be transferred online.', 'beltoft-services' ),
			'fullDomain'  => __( 'Enter the full domain, for example example.com.', 'beltoft-services' ),
			'searchFailed' => __( 'The search could not be completed.', 'beltoft-services' ),
			'addFailed'   => __( 'Could not add this domain.', 'beltoft-services' ),
			'notInOrder'  => __( 'The domain did not stay in your order. Please try again.', 'beltoft-services' ),
			'removeFailed' => __( 'The domain could not be removed. Please reload the page and try again.', 'beltoft-services' ),
			/* translators: %s: domain name */
			'registerAdded' => __( 'Registration of %s is added to this order.', 'beltoft-services' ),
			/* translators: %s: domain name */
			'transferAdded' => __( 'Transfer of %s is added to this order.', 'beltoft-services' ),
			'remove'      => __( 'Remove from order', 'beltoft-services' ),
			/* translators: %s: domain name */
			'heldRegister' => __( 'Registration of %s stays in this order. This choice does not use it.', 'beltoft-services' ),
			/* translators: %s: domain name */
			'heldTransfer' => __( 'Transfer of %s stays in this order. This choice does not use it.', 'beltoft-services' ),
			/* translators: %s: domain name */
			'codeLabel'   => __( 'Transfer code for %s', 'beltoft-services' ),
			'codePlaceholder' => __( 'Transfer code', 'beltoft-services' ),
		);
	}

	/**
	 * One tile group: real radio inputs that post the chosen value, so a keyboard and a screen reader work as with any
	 * radio group. The look is the one of the server and app tiles.
	 *
	 * @param array{name: string, id: string, legend: string, checked: string, items: array<int, array{value: string, name: string, line: string}>} $group Group.
	 */
	public static function print_tiles( array $group ): void {
		echo '<fieldset class="beltoft-services-od-tiles" data-od-tiles id="' . esc_attr( $group['id'] ) . '_tiles">';
		echo '<legend class="beltoft-services-od-tiles__legend">' . esc_html( $group['legend'] ) . '</legend>';
		echo '<div class="beltoft-services-od-tiles__grid">';

		foreach ( $group['items'] as $item ) {
			echo '<label class="beltoft-services-od-tile">';
			echo '<input class="beltoft-services-od-tile__input" type="radio" name="' . esc_attr( $group['name'] ) . '" value="' . esc_attr( $item['value'] ) . '"' . checked( $group['checked'], $item['value'], false ) . '>';
			echo '<span class="beltoft-services-od-tile__face">';
			echo '<span class="beltoft-services-od-tile__name">' . esc_html( $item['name'] ) . '</span>';

			if ( '' !== $item['line'] ) {
				echo '<span class="beltoft-services-od-tile__line">' . esc_html( $item['line'] ) . '</span>';
			}

			echo '</span></label>';
		}

		echo '</div></fieldset>';
	}

	/**
	 * The hidden value the form posts for the chosen domain. The script changes it when a domain is added or removed.
	 *
	 * @param string $name  Field name.
	 * @param string $value Domain in the cart, or ''.
	 */
	public static function print_value( string $name, string $value ): void {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-od-value>';
		echo self::phone_marker(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built here from constants.
	}

	/**
	 * The panel of the Register or the Transfer tile: the domain this order holds for it, or the form that puts one in.
	 *
	 * @param string      $kind    register or transfer.
	 * @param string      $id      Id prefix of the line.
	 * @param bool        $open    Whether the panel shows (its tile is chosen).
	 * @param string      $chosen  The domain of this order when it is of this kind, else ''.
	 * @param string      $find_url Page of the domain search, for a visitor without script; '' for none.
	 * @param string      $note    One line under the panel, for what the service does with the domain; '' for none.
	 */
	public static function print_panel( string $kind, string $id, bool $open, string $chosen, string $find_url = '', string $note = '' ): void {
		$transfer = self::TRANSFER === $kind;
		$text     = self::texts();
		$input    = $id . '_' . $kind . '_name';

		echo '<div class="beltoft-services-od-panel" data-od-panel="' . esc_attr( $kind ) . '"' . ( $open ? '' : ' hidden' ) . '>';

		// The domain of this order.
		echo '<div class="beltoft-services-od-chosen" data-od-chosen' . ( '' === $chosen ? ' hidden' : '' ) . '>';
		echo '<p class="beltoft-services-od-chosen__text" data-od-chosen-text>';

		if ( '' !== $chosen ) {
			echo esc_html( sprintf( $transfer ? $text['transferAdded'] : $text['registerAdded'], HostName::display( $chosen ) ) );
		}

		echo '</p>';
		echo '<button type="button" class="beltoft-services-od-link" data-od-remove>' . esc_html( $text['remove'] ) . '</button>';
		echo '</div>';

		// The form that puts a domain in the order.
		echo '<div class="beltoft-services-od-form" data-od-form' . ( '' === $chosen ? '' : ' hidden' ) . '>';
		echo '<label for="' . esc_attr( $input ) . '">' . esc_html( $transfer ? __( 'Domain to transfer', 'beltoft-services' ) : __( 'Domain name', 'beltoft-services' ) ) . '</label>';
		echo '<span class="beltoft-services-od-form__row">';
		echo '<input type="text" id="' . esc_attr( $input ) . '" inputmode="url" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" placeholder="' . esc_attr( /* translators: example domain shown in an empty field; use the usual example for your language. */ __( 'example.com', 'beltoft-services' ) ) . '" data-od-input>';
		echo '<button type="button" class="beltoft-services-od-button" data-od-go>' . esc_html( $transfer ? __( 'Continue', 'beltoft-services' ) : __( 'Search', 'beltoft-services' ) ) . '</button>';
		echo '</span>';
		echo '<span class="description beltoft-services-od-help">' . esc_html( $transfer ? __( 'Unlock the domain at your current provider first. You enter its transfer code in the next step.', 'beltoft-services' ) : __( 'The domain you add is part of this order and is paid with it.', 'beltoft-services' ) ) . '</span>';
		echo '<div class="beltoft-services-od-results" data-od-results role="status" aria-live="polite"></div>';

		if ( '' !== $find_url ) {
			echo '<noscript><p class="beltoft-services-od-help">' . esc_html__( 'The search needs JavaScript.', 'beltoft-services' ) . ' <a href="' . esc_url( $find_url ) . '">' . esc_html__( 'Use the domain search page', 'beltoft-services' ) . '</a></p></noscript>';
		}

		echo '</div>';

		if ( '' !== $note ) {
			echo '<p class="beltoft-services-od-help">' . esc_html( $note ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The wc-ajax endpoint: answers with JSON.
	 */
	public static function ajax(): void {
		$answer = self::respond();

		wp_send_json( $answer[0], $answer[1] );
	}

	/**
	 * Nonce checked, then the domain line leaves the cart.
	 *
	 * @return array{0: array<string, mixed>, 1: int} Reply and HTTP status.
	 */
	public static function respond(): array {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return array( array( 'ok' => false ), 403 );
		}

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$count  = self::remove( $domain );

		return array( array( 'ok' => $count > 0, 'removed' => $count ), $count > 0 ? 200 : 409 );
	}

	/**
	 * Takes the register or transfer line of a domain out of the cart. Nothing else leaves it.
	 *
	 * @param string $domain Domain as typed.
	 * @return int Lines removed.
	 */
	public static function remove( string $domain ): int {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$name = HostName::normalise( $domain );

		if ( ! $cart || null === $name ) {
			return 0;
		}

		$count = 0;

		foreach ( $cart->get_cart() as $key => $item ) {
			$line = $item['beltoft_services_domain'] ?? null;

			if ( is_array( $line ) && in_array( $line['mode'] ?? '', array( self::REGISTER, self::TRANSFER ), true ) && HostName::normalise( (string) ( $line['domain'] ?? '' ) ) === $name && $cart->remove_cart_item( (string) $key ) ) {
				++$count;
			}
		}

		return $count;
	}
}
