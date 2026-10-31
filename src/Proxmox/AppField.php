<?php
/**
 * The ready-made app choice at checkout, for a new VPS.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * An optional "App" choice (square radio tiles with logos, see Tiles) with a "Version" select for an app with more than one version and, for apps that serve a website, a domain
 * picker. Built the way OsField is (classic checkout field, saved on the order line item, handed to
 * the resource through beltoft_services_initial_meta), with no edit to OsField.
 *
 * It is offered only for a new server whose cluster has apps_ready, the app in apps_enabled, the
 * v2 vendor snippet and a template of the family ubuntu-24.04; otherwise nothing is printed. An
 * app sets the operating system: the line item and the resource get that template whatever the
 * operating system select said. The plan minimum is checked before payment. The domain picker is
 * opt-in; the A record is made later, when the app is installed (AppInstall), after the zone's
 * ownership is checked again.
 *
 * The block checkout is not covered (its fields cannot depend on each other); without this field
 * a server is built without an app, which is always safe.
 */
final class AppField {

	public const POST_APP     = 'beltoft_services_app';
	public const POST_VERSION = 'beltoft_services_app_version';
	public const POST_TIER    = 'beltoft_services_app_tier';
	public const POST_DOMAIN  = 'beltoft_services_app_domain_on';
	public const POST_ZONE    = 'beltoft_services_app_zone';
	public const POST_SUB     = 'beltoft_services_app_sub';
	public const POST_OWN     = 'beltoft_services_app_own';
	/** The value of the domain choice (POST_DOMAIN) that means "my own domain, registered elsewhere". */
	public const MODE_OWN     = 'own';

	public const ITEM_APP     = '_beltoft_services_app';
	public const ITEM_VERSION = '_beltoft_services_app_version';
	public const ITEM_TIER    = '_beltoft_services_app_tier';
	public const ITEM_ZONE    = '_beltoft_services_app_zone';
	public const ITEM_DOMAIN  = '_beltoft_services_app_domain';
	public const ITEM_OWN     = '_beltoft_services_app_own';

	public const HANDLE = 'beltoft-services-app-field';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_classic' ), 15 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		// After OsField (10): the app has the last word on the operating system.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 20, 4 );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	// -- What the cart offers ------------------------------------------------------------------

	/**
	 * What checkout offers for the cart's one new server, or null (no app is shown).
	 *
	 * @return array{cluster: string, plan: array<string, mixed>, apps: array<string, array<string, mixed>>, os: int}|null
	 */
	public static function offer(): ?array {
		$server = DiskField::cart_server();

		if ( null === $server ) {
			return null;
		}

		return self::offer_for( (int) ( $server['item']['product_id'] ?? 0 ), (int) ( $server['item']['variation_id'] ?? 0 ) );
	}

	/**
	 * What is offered for a product and variation, with no cart (the picker and checkout ask the same question).
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id, 0 for none.
	 * @return array{cluster: string, plan: array<string, mixed>, apps: array<string, array<string, mixed>>, os: int}|null
	 */
	public static function offer_for( int $product_id, int $variation_id = 0 ): ?array {
		$plan    = Product::plan( $product_id, $variation_id ?: null );
		$cluster = Clusters::get( (string) ( $plan['cluster'] ?? '' ) );

		if ( ! $cluster ) {
			return null;
		}

		$apps   = Apps::offered( $cluster );
		$os     = Apps::os_template( $cluster );
		$choice = OsField::choice_for( $product_id, $variation_id );

		// A paid app nobody can buy a tier of is not offered.
		foreach ( array_keys( $apps ) as $id ) {
			if ( AppLicence::paid( (string) $id ) && array() === AppLicence::tiers( (string) $id ) ) {
				unset( $apps[ $id ] );
			}
		}

		if ( array() === $apps || $os <= 0 || null === $choice || ! isset( $choice['options'][ $os ] ) ) {
			return null;
		}

		return array(
			'cluster' => (string) $plan['cluster'],
			'plan'    => $plan,
			'apps'    => $apps,
			'os'      => $os,
		);
	}

	/**
	 * The choice a customer made, checked against what is offered.
	 *
	 * @param array<string, mixed> $offer  The offer.
	 * @param array<string, mixed> $input  app, version, tier (a paid app), domain_on, zone, sub, own_on, own (a domain registered elsewhere).
	 * @param int                  $user   The customer.
	 * @param bool                 $check_dns Whether to read the zone for a free name, and whether a paid app's tier must be on sale now (an order already paid is built even if the tier is out of stock).
	 * @return array{app: string, version: string, tier: string, zone: string, domain: string, own: string}|string An empty app when none is wanted; else the refusal text.
	 */
	public static function resolve( array $offer, array $input, int $user, bool $check_dns = true ) {
		$none = array( 'app' => '', 'version' => '', 'tier' => '', 'zone' => '', 'domain' => '', 'own' => '' );
		$id   = (string) ( $input['app'] ?? '' );

		if ( '' === $id ) {
			return $none;
		}

		if ( ! isset( $offer['apps'][ $id ] ) ) {
			return __( 'Choose one of the listed apps, or none.', 'beltoft-services' );
		}

		$app     = $offer['apps'][ $id ];
		$version = (string) ( $input['version'] ?? '' );
		$version = '' === $version ? (string) array_key_first( $app['choices'] ) : $version;

		if ( ! isset( $app['choices'][ $version ] ) ) {
			return __( 'Choose one of the listed versions.', 'beltoft-services' );
		}

		$why = Apps::plan_refusal( $app['choices'][ $version ], (array) $offer['plan'] );

		if ( null !== $why ) {
			return $why;
		}

		$out = array( 'app' => $id, 'version' => $version, 'tier' => '', 'zone' => '', 'domain' => '', 'own' => '' );

		if ( AppLicence::paid( $id ) ) {
			$tier = (string) ( $input['tier'] ?? '' );

			if ( '' === $tier ) {
				/* translators: %s: app name */
				return sprintf( __( '%s has a monthly licence. Choose a tier, or no app.', 'beltoft-services' ), (string) $app['name'] );
			}

			if ( null === AppLicence::tier( $id, $tier, false ) ) {
				return __( 'Choose one of the listed tiers.', 'beltoft-services' );
			}

			if ( $check_dns && null === AppLicence::tier( $id, $tier ) ) {
				return __( 'This tier cannot be bought now. Choose another tier or no app.', 'beltoft-services' );
			}

			$out['tier'] = $tier;
		}

		if ( ! empty( $input['own_on'] ) ) {
			if ( ! empty( $input['domain_on'] ) ) {
				return __( 'Choose one domain option.', 'beltoft-services' );
			}

			$own = AppOwnDomain::choose( $app, $input['own'] ?? '' );

			if ( is_string( $own ) ) {
				return $own;
			}

			$out['own'] = $own['fqdn'];

			return $out;
		}

		if ( empty( $input['domain_on'] ) ) {
			return $out;
		}

		$choice = AppDomains::choose( $app, (string) ( $input['zone'] ?? '' ), $input['sub'] ?? '', $user, $check_dns );

		if ( is_string( $choice ) ) {
			return $choice;
		}

		$out['zone']   = $choice['zone'];
		$out['domain'] = $choice['fqdn'];

		return $out;
	}

	// -- Classic checkout ---------------------------------------------------------------------

	/**
	 * What the form posted, only inside WooCommerce's own checkout run (it verified its nonce first).
	 *
	 * @return array<string, mixed>
	 */
	private static function posted(): array {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return array();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce; every value is checked against the offer in resolve().
		$get = static function ( string $key ): string {
			$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

			return is_string( $value ) ? trim( $value ) : '';
		};
		// phpcs:enable

		// A form that does not carry the field at all (the step was not shown) uses what the picker carried. A posted value, even an empty one, wins.
		if ( ! isset( $_POST[ self::POST_APP ] ) ) {
			$picked = Picked::for_cart();

			return array(
				'app'       => $picked['app'],
				'version'   => $picked['version'],
				'tier'      => $picked['tier'],
				'domain_on' => '' !== $picked['domain_on'],
				'zone'      => $picked['zone'],
				'sub'       => $picked['sub'],
				'own_on'    => '' !== $picked['own'],
				'own'       => $picked['own'],
			);
		}

		$mode = $get( self::POST_DOMAIN );

		return array(
			'app'       => $get( self::POST_APP ),
			'version'   => $get( self::POST_VERSION ),
			'tier'      => $get( self::POST_TIER ),
			'domain_on' => '' !== $mode && self::MODE_OWN !== $mode,
			'zone'      => $get( self::POST_ZONE ),
			'sub'       => $get( self::POST_SUB ),
			'own_on'    => self::MODE_OWN === $mode,
			'own'       => $get( self::POST_OWN ),
		);
	}

	/**
	 * Prints the fields for a new server whose cluster offers apps.
	 */
	public static function render_classic(): void {
		$offer = self::offer();

		if ( null === $offer ) {
			return;
		}

		self::print_fields( $offer, Picked::for_cart() );
	}

	/**
	 * The fields for an offer, starting from the given choices (checkout and the picker print the same markup).
	 *
	 * @param array<string, mixed>  $offer  From offer() or offer_for().
	 * @param array<string, string> $picked Choices to start from (Picked::clean() shape), or none.
	 */
	public static function print_fields( array $offer, array $picked = array() ): void {
		$picked    = Picked::clean( $picked );
		$start     = isset( $offer['apps'][ $picked['app'] ] ) ? $picked['app'] : '';
		$zones     = AppDomains::zones( get_current_user_id() );
		$supports  = '' !== $start && ! empty( $offer['apps'][ $start ]['domain'] );
		$domain_on = '' !== $picked['domain_on'] && $supports && in_array( $picked['zone'], $zones, true );
		$own_on    = '' !== $picked['own'] && $supports;
		$mode      = $own_on ? self::MODE_OWN : ( $domain_on ? '1' : '' );
		$data      = array();

		foreach ( $offer['apps'] as $id => $app ) {
			$versions = array();

			foreach ( $app['choices'] as $vid => $version ) {
				$versions[] = array( 'id' => (string) $vid, 'label' => Apps::version_label( $version ) );
			}

			$data[ $id ] = array(
				'summary'  => (string) ( $app['summary'] ?? '' ),
				/* translators: %d: minutes */
				'minutes'  => sprintf( _n( 'Installs in about %d minute after the server starts.', 'Installs in about %d minutes after the server starts.', (int) ( $app['minutes'] ?? 5 ), 'beltoft-services' ), (int) ( $app['minutes'] ?? 5 ) ),
				'domain'   => ! empty( $app['domain'] ),
				'versions' => $versions,
			);
		}

		echo '<div class="beltoft-services-app" data-app-field data-os="' . esc_attr( (string) $offer['os'] ) . '" data-apps="' . esc_attr( (string) wp_json_encode( $data ) ) . '">';

		$items = array(
			array(
				'value'   => '',
				'name'    => __( 'No app', 'beltoft-services' ),
				'line'    => __( 'Just the operating system', 'beltoft-services' ),
				'logo'    => Tiles::app_logo( array() ),
				'checked' => '' === $start,
				'attrs'   => array( 'data-none' => '1' ),
			),
		);

		$cluster = (array) Clusters::get( (string) ( $offer['cluster'] ?? '' ) );

		foreach ( $offer['apps'] as $id => $app ) {
			$families = Apps::families( (array) $app['choices'] );
			$current  = reset( $app['choices'] );
			$why      = Apps::plan_refusal( $current, (array) $offer['plan'] );
			$versions = array();

			foreach ( $app['choices'] as $vid => $version ) {
				$versions[] = array( 'value' => (string) $vid, 'label' => Apps::version_label( $version ) );
			}

			$items[] = array(
				'value'    => (string) $id,
				'name'     => (string) $app['name'],
				'line'     => (string) ( $app['summary'] ?? '' ),
				'runs'     => Apps::runs_on( $cluster, $families ),
				'logo'     => Tiles::app_logo( $app ),
				'checked'  => (string) $id === $start && null === $why,
				'disabled' => null !== $why,
				'why'      => null === $why ? '' : self::needs( $current ),
				'attrs'    => array(
					'data-versions' => (string) wp_json_encode( $versions ),
					'data-min'      => (string) wp_json_encode( self::minimum( $current ) ),
					'data-why'      => self::needs( $current ),
					'data-families' => implode( ',', $families ),
					'data-dropped'  => Apps::dropped_text( $cluster, $families, (string) $app['name'] ),
				),
			);
		}

		Tiles::print_group(
			array(
				'kind'   => 'app',
				'id'     => self::POST_APP,
				'name'   => self::POST_APP,
				'legend' => __( 'App (optional)', 'beltoft-services' ),
				'items'  => $items,
				'attrs'  => array(
					'data-os' => (string) $offer['os'],
				),
			)
		);

		echo '<p class="beltoft-services-app__note description" data-app-note>' . esc_html__( 'A ready-made app is installed after the server starts, on a system its tile names.', 'beltoft-services' ) . '</p>';

		echo '<p class="form-row form-row-wide" data-tiles-version-row hidden><label for="' . esc_attr( self::POST_VERSION ) . '">' . esc_html__( 'Version', 'beltoft-services' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><select name="' . esc_attr( self::POST_VERSION ) . '" id="' . esc_attr( self::POST_VERSION ) . '" data-tiles-version data-selected="' . esc_attr( $picked['version'] ) . '"></select></span></p>';

		// A paid app's tiers, shown when that app is chosen. The tier line joins the cart when one is picked (checkout) or when the server is added (picker).
		foreach ( $offer['apps'] as $id => $app ) {
			if ( AppLicence::paid( (string) $id ) ) {
				self::print_tiers( (string) $id, (string) $app['name'], (string) $id === $start, $picked['tier'] );
			}
		}

		// The domain choice: none, a domain on our name servers (only when the customer has one), or a domain registered elsewhere.
		echo '<fieldset class="beltoft-services-app__domain" data-app-domain hidden><legend>' . esc_html__( 'Domain (optional)', 'beltoft-services' ) . '</legend>';
		$choices = array(
			array( '', __( 'No domain', 'beltoft-services' ), __( 'The app uses the server address, without HTTPS.', 'beltoft-services' ) ),
		);

		if ( array() !== $zones ) {
			$choices[] = array( '1', __( 'A domain on our name servers', 'beltoft-services' ), __( 'Adds an A record to one of your domains on our name servers, and the site is served with HTTPS. Your other records are not changed. On the domain itself, www is added too when it has no record yet, and redirects to the domain.', 'beltoft-services' ) );
		}

		$choices[] = array( self::MODE_OWN, __( 'Use my own domain', 'beltoft-services' ), __( 'For a domain registered or hosted elsewhere. After the server starts you point an A record at its address, and the app is installed with HTTPS when the name resolves to the server. For a bare domain such as example.com, point A records for both example.com and www.example.com at the address; www redirects to example.com.', 'beltoft-services' ) );

		foreach ( $choices as $choice ) {
			echo '<label class="beltoft-services-app__domain-choice"><input type="radio" class="input-radio" name="' . esc_attr( self::POST_DOMAIN ) . '" value="' . esc_attr( $choice[0] ) . '" data-app-domain-mode' . checked( $mode, $choice[0], false ) . '> <span>' . esc_html( $choice[1] ) . '</span><span class="description">' . esc_html( $choice[2] ) . '</span></label>';
		}

		if ( array() !== $zones ) {
			echo '<div class="beltoft-services-app__domain-fields" data-app-domain-fields="1"' . ( $domain_on ? '' : ' hidden' ) . '>';
			echo '<p class="form-row form-row-wide"><label for="' . esc_attr( self::POST_ZONE ) . '">' . esc_html__( 'Domain', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( self::POST_ZONE ) . '" id="' . esc_attr( self::POST_ZONE ) . '">';

			foreach ( $zones as $zone ) {
				echo '<option value="' . esc_attr( $zone ) . '"' . selected( $picked['zone'], $zone, false ) . '>' . esc_html( $zone ) . '</option>';
			}

			echo '</select></span></p>';
			echo '<p class="form-row form-row-wide"><label for="' . esc_attr( self::POST_SUB ) . '">' . esc_html__( 'Subdomain (optional)', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( self::POST_SUB ) . '" id="' . esc_attr( self::POST_SUB ) . '" maxlength="120" autocomplete="off" spellcheck="false" placeholder="www" value="' . esc_attr( $picked['sub'] ) . '"></span>';
			echo '<span class="description">' . esc_html__( 'Leave it empty to use the domain itself; www is then added too when it has no record yet. The name must not have an address record yet.', 'beltoft-services' ) . '</span></p>';
			echo '</div>';
		}

		echo '<div class="beltoft-services-app__domain-fields" data-app-domain-own-fields="1"' . ( $own_on ? '' : ' hidden' ) . '>';
		echo '<p class="form-row form-row-wide"><label for="' . esc_attr( self::POST_OWN ) . '">' . esc_html__( 'Your domain name', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( self::POST_OWN ) . '" id="' . esc_attr( self::POST_OWN ) . '" maxlength="253" autocomplete="off" spellcheck="false" placeholder="shop.example.com" value="' . esc_attr( $picked['own'] ) . '"></span>';
		echo '<span class="description">' . esc_html__( 'We do not change any DNS records for it. You add the A record where the domain is hosted. For a bare domain such as example.com, add one for www.example.com too.', 'beltoft-services' ) . '</span></p>';
		echo '</div></fieldset>';

		echo '</div>';
	}

	/**
	 * The tiers of a paid app as radio choices with their price (hidden unless the app is the chosen one; the script shows and hides them).
	 *
	 * @param string $id       App id.
	 * @param string $name     App name.
	 * @param bool   $shown    Whether the app is the chosen one.
	 * @param string $selected The tier to start from, or ''.
	 */
	private static function print_tiers( string $id, string $name, bool $shown, string $selected ): void {
		echo '<fieldset class="beltoft-services-app__domain beltoft-services-app__tiers" data-app-tiers="' . esc_attr( $id ) . '"' . ( $shown ? '' : ' hidden' ) . '>';
		/* translators: %s: app name */
		echo '<legend>' . esc_html( sprintf( __( '%s tier', 'beltoft-services' ), $name ) ) . '</legend>';

		$usage = AppLicenceCart::server_usage();

		foreach ( AppLicence::tiers( $id ) as $key => $tier ) {
			// Both prices are printed: the script shows the one that fits the billing choice (a pay-as-you-go server is billed by the hour, and only a tier with an hourly price can be bought there).
			$hourly = AppLicence::usage_price( $tier );

			echo '<label class="beltoft-services-app__domain-choice"><input type="radio" class="input-radio" name="' . esc_attr( self::POST_TIER ) . '" value="' . esc_attr( (string) $key ) . '" data-app-tier' . ( '' === $hourly ? ' data-no-usage' : '' ) . checked( $shown && $selected === (string) $key, true, false ) . ( $shown ? '' : ' disabled' ) . '> <span>' . esc_html( $tier['label'] ) . '</span>';
			echo '<span class="description" data-price-monthly' . ( $usage ? ' hidden' : '' ) . '>' . esc_html( $tier['price'] ) . '</span>';
			echo '<span class="description" data-price-usage' . ( $usage ? '' : ' hidden' ) . '>' . esc_html( '' === $hourly ? __( 'Not available with pay as you go', 'beltoft-services' ) : $hourly ) . '</span></label>';
		}

		echo '</fieldset>';
	}

	/**
	 * The smallest plan an app version runs on: what the picker's script compares a plan card to.
	 *
	 * @param array<string, mixed> $version Version.
	 * @return array{cores: int, memory_mb: int, disk_gb: int}
	 */
	public static function minimum( array $version ): array {
		return array(
			'cores'     => (int) ( $version['min']['cores'] ?? 0 ),
			'memory_mb' => (int) ( $version['min']['memory_mb'] ?? 0 ),
			'disk_gb'   => (int) ( $version['min']['disk_gb'] ?? 0 ),
		);
	}

	/**
	 * The short line on a tile that the plan is too small for: "Needs Performance".
	 *
	 * @param array<string, mixed> $version Version.
	 */
	public static function needs( array $version ): string {
		$name = Apps::plan_name( $version );

		/* translators: %s: plan name such as Performance */
		return '' !== $name ? sprintf( __( 'Needs %s', 'beltoft-services' ), $name ) : __( 'Needs a larger plan', 'beltoft-services' );
	}

	/**
	 * Refuses a classic checkout whose app choice cannot be built.
	 *
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate_classic( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$offer  = self::offer();
		$posted = self::posted();

		if ( ! $errors instanceof \WP_Error || array() === $posted ) {
			return;
		}

		if ( '' === $posted['app'] ) {
			// A tier line without an app (the app was dropped after the tier was chosen) is not paid for by mistake.
			if ( AppLicenceCart::lines() > 0 ) {
				$errors->add( 'beltoft_services_app', __( 'An app licence is in your cart but no app is chosen. Remove the licence or choose the app.', 'beltoft-services' ) );
			}

			return;
		}

		// An app the cluster does not offer (any more) must not pass silently: the server would be built without it.
		$why = null === $offer ? __( 'Apps are not available for this server. Choose none.', 'beltoft-services' ) : self::resolve( $offer, $posted, get_current_user_id() );

		if ( is_string( $why ) ) {
			$errors->add( 'beltoft_services_app', $why );

			return;
		}

		$line = AppLicenceCart::line();

		if ( AppLicence::paid( $why['app'] ) ) {
			$tier = AppLicence::tier( $why['app'], $why['tier'] );

			// The tier is its own cart line: it must be there once, and be the tier chosen (for a pay-as-you-go server its usage sibling).
			$want = null === $tier ? null : AppLicence::line_variation( $tier, AppLicenceCart::server_usage() );

			if ( null === $want || 1 !== AppLicenceCart::lines() || (int) ( $line['item']['variation_id'] ?? 0 ) !== $want->get_id() ) {
				$errors->add( 'beltoft_services_app', __( 'The tier is not in your cart. Reload the page and choose the tier again.', 'beltoft-services' ) );
			}
		} elseif ( AppLicenceCart::lines() > 0 ) {
			$errors->add( 'beltoft_services_app', __( 'An app licence is in your cart for another app. Remove it or choose that app.', 'beltoft-services' ) );
		}
	}

	/**
	 * Saves the choice on the line item of the new server, and the operating system the app needs.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key (unused).
	 * @param array<string, mixed>   $values Cart item (unused).
	 * @param \WC_Order              $order  Order (unused).
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$offer  = $item instanceof \WC_Order_Item_Product ? self::offer() : null;
		$server = null === $offer ? null : DiskField::cart_server();

		if ( null === $offer || null === $server || (int) ( $server['item']['product_id'] ?? 0 ) !== (int) $item->get_product_id() ) {
			return;
		}

		$choice = self::resolve( $offer, self::posted(), get_current_user_id() );

		if ( ! is_array( $choice ) || '' === $choice['app'] ) {
			return;
		}

		$item->add_meta_data( self::ITEM_APP, $choice['app'], true );
		$item->add_meta_data( self::ITEM_VERSION, $choice['version'], true );

		if ( '' !== $choice['tier'] ) {
			$item->add_meta_data( self::ITEM_TIER, $choice['tier'], true );
		}

		$item->update_meta_data( OsField::ITEM_META, (string) $offer['os'] );

		if ( '' !== $choice['domain'] ) {
			$item->add_meta_data( self::ITEM_ZONE, $choice['zone'], true );
			$item->add_meta_data( self::ITEM_DOMAIN, $choice['domain'], true );
		}

		if ( '' !== $choice['own'] ) {
			$item->add_meta_data( self::ITEM_OWN, $choice['own'], true );
		}
	}

	// -- Assets -------------------------------------------------------------------------------

	/**
	 * The script and style, on the checkout page only, and only when the field is printed.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || null === self::offer() ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * The script and style of the fields (checkout, and the picker, which prints the same fields).
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/app-field.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/app-field.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/app-field.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/app-field.js' ), true );
		wp_enqueue_script( 'beltoft-services-app-tier', plugins_url( 'assets/app-tier.js', BELTOFT_SERVICES_FILE ), array( 'jquery' ), \BeltoftServices\Assets::version( 'assets/app-tier.js' ), true );

		// Only checkout keeps a cart line in step; the picker has no cart line to change before the server is added.
		if ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'wp_create_nonce' ) ) {
			wp_localize_script(
				'beltoft-services-app-tier',
				'beltoftServicesAppTier',
				array(
					'url'    => \WC_AJAX::get_endpoint( AppLicenceCart::AJAX ),
					'nonce'  => wp_create_nonce( AppLicenceCart::NONCE ),
					'failed' => __( 'The tier could not be changed. Reload checkout and try again.', 'beltoft-services' ),
				)
			);
		}
	}

	// -- Hand-over to the resource ---------------------------------------------------------------

	/**
	 * Adds the app to the initial meta of a VM resource, from the license's order line. The choice is
	 * checked again against the catalogue of this moment: if it no longer holds, the server is built
	 * without the app. The operating system is the app's, whatever else was chosen.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || SshKeyField::DRIVER !== $driver->id() ) {
			return $meta;
		}

		$order = ! empty( $license['order_id'] ) ? wc_get_order( (int) $license['order_id'] ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return $meta;
		}

		$product_id = (int) ( $license['product_id'] ?? 0 );
		$variation  = (int) ( $license['variation_id'] ?? 0 );

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id || ( 0 !== $variation && (int) $item->get_variation_id() !== $variation ) ) {
				continue;
			}

			$id = (string) $item->get_meta( self::ITEM_APP, true );

			if ( '' === $id ) {
				continue;
			}

			$plan    = Product::plan( $product_id, $variation ?: null );
			$cluster = Clusters::get( (string) ( $plan['cluster'] ?? '' ) );
			$offer   = $cluster ? array( 'apps' => Apps::offered( $cluster ), 'plan' => $plan, 'os' => Apps::os_template( $cluster ) ) : null;

			if ( null === $offer || $offer['os'] <= 0 ) {
				return $meta;
			}

			$choice = self::resolve(
				$offer,
				array(
					'app'       => $id,
					'version'   => (string) $item->get_meta( self::ITEM_VERSION, true ),
					'tier'      => (string) $item->get_meta( self::ITEM_TIER, true ),
					'domain_on' => '' !== (string) $item->get_meta( self::ITEM_DOMAIN, true ),
					'zone'      => (string) $item->get_meta( self::ITEM_ZONE, true ),
					'sub'       => self::sub_of( (string) $item->get_meta( self::ITEM_DOMAIN, true ), (string) $item->get_meta( self::ITEM_ZONE, true ) ),
					'own_on'    => '' !== (string) $item->get_meta( self::ITEM_OWN, true ),
					'own'       => (string) $item->get_meta( self::ITEM_OWN, true ),
				),
				(int) ( $license['user_id'] ?? 0 ),
				false
			);

			if ( ! is_array( $choice ) || '' === $choice['app'] ) {
				return $meta;
			}

			$meta['app_id']          = $choice['app'];
			$meta['app_version']     = $choice['version'];

			if ( '' !== $choice['tier'] ) {
				$meta['app_tier'] = $choice['tier'];
			}

			$meta[ OsField::META ]   = $offer['os'];

			if ( '' !== $choice['domain'] ) {
				$meta['app_zone']        = $choice['zone'];
				$meta['app_domain_want'] = $choice['domain'];
			}

			if ( '' !== $choice['own'] ) {
				// Nothing is written to any DNS: the install waits until the name points at the server (AppOwnDomain).
				$meta = array_merge( $meta, array_filter( AppOwnDomain::fresh_wait( $choice['own'] ), static fn( $value ) => null !== $value ) );
			}

			break;
		}

		return $meta;
	}

	/**
	 * The subdomain part of a stored full name.
	 *
	 * @param string $fqdn Full name.
	 * @param string $zone Zone.
	 */
	private static function sub_of( string $fqdn, string $zone ): string {
		return '' !== $zone && $fqdn !== $zone && str_ends_with( $fqdn, '.' . $zone ) ? substr( $fqdn, 0, -( strlen( $zone ) + 1 ) ) : '';
	}
}
