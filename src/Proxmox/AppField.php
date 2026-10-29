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
 * An optional "App" select with a "Version" select and, for apps that serve a website, a domain
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
	public const POST_DOMAIN  = 'beltoft_services_app_domain_on';
	public const POST_ZONE    = 'beltoft_services_app_zone';
	public const POST_SUB     = 'beltoft_services_app_sub';

	public const ITEM_APP     = '_beltoft_services_app';
	public const ITEM_VERSION = '_beltoft_services_app_version';
	public const ITEM_ZONE    = '_beltoft_services_app_zone';
	public const ITEM_DOMAIN  = '_beltoft_services_app_domain';

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

		$plan    = Product::plan( (int) ( $server['item']['product_id'] ?? 0 ), (int) ( $server['item']['variation_id'] ?? 0 ) ?: null );
		$cluster = Clusters::get( (string) ( $plan['cluster'] ?? '' ) );

		if ( ! $cluster ) {
			return null;
		}

		$apps = Apps::offered( $cluster );
		$os   = Apps::os_template( $cluster );
		$choice = OsField::cart_choice();

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
	 * @param array<string, mixed> $input  app, version, domain_on, zone, sub.
	 * @param int                  $user   The customer.
	 * @param bool                 $check_dns Whether to read the zone for a free name.
	 * @return array{app: string, version: string, zone: string, domain: string}|string An empty app when none is wanted; else the refusal text.
	 */
	public static function resolve( array $offer, array $input, int $user, bool $check_dns = true ) {
		$none = array( 'app' => '', 'version' => '', 'zone' => '', 'domain' => '' );
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

		$out = array( 'app' => $id, 'version' => $version, 'zone' => '', 'domain' => '' );

		if ( empty( $input['domain_on'] ) ) {
			return $out;
		}

		if ( empty( $app['domain'] ) ) {
			return __( 'This app does not use a domain at install. You can add one later.', 'beltoft-services' );
		}

		$zone = strtolower( trim( (string) ( $input['zone'] ?? '' ) ) );
		$sub  = AppDomains::sub( $input['sub'] ?? '' );

		if ( '' === $zone || ! in_array( $zone, AppDomains::zones( $user ), true ) ) {
			return __( 'Choose one of your domains that uses our name servers.', 'beltoft-services' );
		}

		if ( null === $sub ) {
			return __( 'The subdomain can have letters (a to z), digits and hyphens, up to three parts separated by dots.', 'beltoft-services' );
		}

		$fqdn = AppDomains::fqdn( $zone, $sub );
		$bad  = AppDomains::dry_run( $zone, $fqdn );

		if ( null !== $bad ) {
			return str_contains( $bad, 'long' ) ? __( 'That domain name is too long. A name can have up to 253 characters and each part up to 63. Choose a shorter subdomain.', 'beltoft-services' ) : __( 'That domain name cannot be used for a DNS record. Choose another subdomain.', 'beltoft-services' );
		}

		if ( $check_dns ) {
			$free = AppDomains::is_free( $zone, $fqdn );

			if ( null === $free ) {
				return __( 'Your DNS records could not be checked right now. Try again, or leave the domain out and add it later.', 'beltoft-services' );
			}

			if ( ! $free ) {
				/* translators: %s: a domain name such as shop.example.com */
				return sprintf( __( '%s already has a DNS record. Choose another subdomain.', 'beltoft-services' ), $fqdn );
			}
		}

		$out['zone']   = $zone;
		$out['domain'] = $fqdn;

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

		return array(
			'app'       => $get( self::POST_APP ),
			'version'   => $get( self::POST_VERSION ),
			'domain_on' => '' !== $get( self::POST_DOMAIN ),
			'zone'      => $get( self::POST_ZONE ),
			'sub'       => $get( self::POST_SUB ),
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

		$zones = AppDomains::zones( get_current_user_id() );
		$data  = array();

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

		echo '<p class="form-row form-row-wide"><label for="' . esc_attr( self::POST_APP ) . '">' . esc_html__( 'App (optional)', 'beltoft-services' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><select name="' . esc_attr( self::POST_APP ) . '" id="' . esc_attr( self::POST_APP ) . '" data-app-select>';
		echo '<option value="">' . esc_html__( 'None, just the operating system', 'beltoft-services' ) . '</option>';

		foreach ( $offer['apps'] as $id => $app ) {
			$current = reset( $app['choices'] );
			$why     = Apps::plan_refusal( $current, (array) $offer['plan'] );
			$label   = (string) $app['name'];

			if ( null !== $why ) {
				$name = Apps::plan_name( $current );
				/* translators: 1: app name, 2: plan name such as Performance */
				$label = '' !== $name ? sprintf( __( '%1$s (needs the %2$s plan)', 'beltoft-services' ), $label, $name ) : sprintf( __( '%s (needs a larger plan)', 'beltoft-services' ), $label );
			}

			echo '<option value="' . esc_attr( (string) $id ) . '"' . disabled( null !== $why, true, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></span>';
		echo '<span class="beltoft-services-app__note description" data-app-note>' . esc_html__( 'A ready-made app is installed on Ubuntu 24.04 after the server starts. Without an app, you choose the operating system above.', 'beltoft-services' ) . '</span></p>';

		echo '<p class="form-row form-row-wide" data-app-version-row hidden><label for="' . esc_attr( self::POST_VERSION ) . '">' . esc_html__( 'Version', 'beltoft-services' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><select name="' . esc_attr( self::POST_VERSION ) . '" id="' . esc_attr( self::POST_VERSION ) . '" data-app-version></select></span></p>';

		if ( array() !== $zones ) {
			echo '<div class="beltoft-services-app__domain" data-app-domain hidden>';
			echo '<p class="form-row form-row-wide"><label class="checkbox"><input type="checkbox" class="input-checkbox" name="' . esc_attr( self::POST_DOMAIN ) . '" value="1" data-app-domain-on> <span>' . esc_html__( 'Point a domain to this server', 'beltoft-services' ) . '</span></label>';
			echo '<span class="description">' . esc_html__( 'Adds an A record to one of your domains on our name servers, and the site is served with HTTPS. Your other records are not changed.', 'beltoft-services' ) . '</span></p>';
			echo '<div class="beltoft-services-app__domain-fields" data-app-domain-fields hidden>';
			echo '<p class="form-row form-row-wide"><label for="' . esc_attr( self::POST_ZONE ) . '">' . esc_html__( 'Domain', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><select name="' . esc_attr( self::POST_ZONE ) . '" id="' . esc_attr( self::POST_ZONE ) . '">';

			foreach ( $zones as $zone ) {
				echo '<option value="' . esc_attr( $zone ) . '">' . esc_html( $zone ) . '</option>';
			}

			echo '</select></span></p>';
			echo '<p class="form-row form-row-wide"><label for="' . esc_attr( self::POST_SUB ) . '">' . esc_html__( 'Subdomain (optional)', 'beltoft-services' ) . '</label><span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( self::POST_SUB ) . '" id="' . esc_attr( self::POST_SUB ) . '" maxlength="120" autocomplete="off" spellcheck="false" placeholder="www"></span>';
			echo '<span class="description">' . esc_html__( 'Leave it empty to use the domain itself. The name must not have an address record yet.', 'beltoft-services' ) . '</span></p>';
			echo '</div></div>';
		}

		echo '</div>';
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

		if ( ! $errors instanceof \WP_Error || array() === $posted || '' === $posted['app'] ) {
			return;
		}

		// An app the cluster does not offer (any more) must not pass silently: the server would be built without it.
		$why = null === $offer ? __( 'Apps are not available for this server. Choose none.', 'beltoft-services' ) : self::resolve( $offer, $posted, get_current_user_id() );

		if ( is_string( $why ) ) {
			$errors->add( 'beltoft_services_app', $why );
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
		$item->update_meta_data( OsField::ITEM_META, (string) $offer['os'] );

		if ( '' !== $choice['domain'] ) {
			$item->add_meta_data( self::ITEM_ZONE, $choice['zone'], true );
			$item->add_meta_data( self::ITEM_DOMAIN, $choice['domain'], true );
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

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/app-field.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/app-field.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/app-field.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/app-field.js' ), true );
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
					'domain_on' => '' !== (string) $item->get_meta( self::ITEM_DOMAIN, true ),
					'zone'      => (string) $item->get_meta( self::ITEM_ZONE, true ),
					'sub'       => self::sub_of( (string) $item->get_meta( self::ITEM_DOMAIN, true ), (string) $item->get_meta( self::ITEM_ZONE, true ) ),
				),
				(int) ( $license['user_id'] ?? 0 ),
				false
			);

			if ( ! is_array( $choice ) || '' === $choice['app'] ) {
				return $meta;
			}

			$meta['app_id']          = $choice['app'];
			$meta['app_version']     = $choice['version'];
			$meta[ OsField::META ]   = $offer['os'];

			if ( '' !== $choice['domain'] ) {
				$meta['app_zone']        = $choice['zone'];
				$meta['app_domain_want'] = $choice['domain'];
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
