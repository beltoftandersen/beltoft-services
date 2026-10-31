<?php
/** Reinstalling an existing server to a paid app: the licence is bought first, the reinstall follows the payment. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Flow. The Reinstall form posts the app (`app_version` = app/version) and the tier (`app_tier`) of a paid app like any
 * other reinstall. Nothing is reinstalled yet: the action route asks this class (filter
 * `beltoft_services_action_redirect`, before the refusal filter) and gets a checkout URL back. The request (the posted
 * arguments, app, version, tier) is kept on the server as `applic_pending`, and the URL adds the tier's licence line to
 * the cart, linked to that server's license id with a nonce (the extra disk's pattern, AppLicenceCart). A pay-as-you-go
 * server gets the tier's usage sibling (billed by the hour, price 0 at checkout), a monthly server the monthly tier.
 *
 * When the order is paid the licence is created (AppLicence::initial_meta links it to the server) and its provision
 * step (AppLicenceDriver) calls claim(): the reinstall is queued as the customer's own `reinstall` action with the kept
 * arguments. That action checks again (Actions::check) and now finds an active licence of the app on the server;
 * without one the check refuses, so a reinstall to a paid app never runs before the licence is paid. The old app's
 * licence ends when the reinstall starts (AppLicence::reinstalled), as for any other app change.
 */
final class AppReinstallPaid {

	/** Server meta: the reinstall waiting for its licence. */
	public const PENDING = 'applic_pending';

	private const NONCE = 'beltoft-services-applic';

	/** @var bool Set while the action route only asks what checkout is needed: the licence need not exist yet. */
	private static bool $quote = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_action_redirect', array( __CLASS__, 'redirect' ), 10, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_validation' ), 10, 2 );
	}

	/**
	 * Is the check being made to find out what checkout is needed (true), or to run the reinstall (false)?
	 */
	public static function quoting(): bool {
		return self::$quote;
	}

	/**
	 * The app, version and tier a reinstall's arguments ask for, when the app is a paid one; else null.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{app: string, version: string, tier: string}|null
	 */
	public static function pick( array $args ): ?array {
		$raw = $args['app_version'] ?? '';

		if ( ! is_string( $raw ) || 1 !== preg_match( '#^([a-z0-9-]+)/([a-zA-Z0-9.-]+)$#D', $raw, $m ) || ! AppLicence::paid( $m[1] ) ) {
			return null;
		}

		$tier = $args['app_tier'] ?? '';

		return array( 'app' => $m[1], 'version' => $m[2], 'tier' => is_string( $tier ) ? sanitize_key( $tier ) : '' );
	}

	/**
	 * Does the server hold an active licence of this app (paid, not on hold, not ended)?
	 *
	 * @param Resource $server Server.
	 * @param string   $app    App id.
	 */
	public static function licensed( Resource $server, string $app ): bool {
		foreach ( AppLicence::for_server( $server ) as $licence ) {
			if ( (string) ( ( (array) $licence->get( 'plan' ) )['app'] ?? '' ) === $app && 'active' === AppLicence::state( $licence ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The reinstall that waits for its licence, or null.
	 *
	 * @param Resource $server Server.
	 * @return array{app: string, version: string, tier: string, args: array<string, mixed>, at: int}|null
	 */
	public static function pending( Resource $server ): ?array {
		$p = $server->get( self::PENDING );

		return is_array( $p ) && isset( $p['app'], $p['tier'], $p['args'] ) && is_array( $p['args'] ) ? $p : null;
	}

	/**
	 * Is this server's pending reinstall for this app and tier?
	 *
	 * @param int    $license_id Server license id.
	 * @param string $app        App id.
	 * @param string $tier       Tier key.
	 */
	public static function wants( int $license_id, string $app, string $tier ): bool {
		$server  = Resource::for_license( $license_id );
		$pending = $server ? self::pending( $server ) : null;

		return null !== $pending && $pending['app'] === $app && $pending['tier'] === $tier;
	}

	/**
	 * Is the server (by license id) billed pay as you go?
	 *
	 * @param int $license_id Server license id.
	 */
	public static function server_usage( int $license_id ): bool {
		$license = Licenses::find( $license_id );

		return is_array( $license ) && 'usage' === (string) ( $license['source'] ?? '' );
	}

	/**
	 * Does the cart hold a licence line for an existing server?
	 */
	public static function in_cart(): bool {
		foreach ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( isset( $item[ AppLicence::CART_UID ] ) && is_int( $item[ AppLicence::CART_FOR ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What a cart line says about its server: 'cart_server' (the new server of the cart), a server license id the
	 * customer owns that waits for this licence (nonce checked), or null.
	 *
	 * @param array<string, mixed> $data Cart item data.
	 * @return int|string|null
	 */
	public static function link( array $data ) {
		$asked = isset( $_REQUEST[ AppLicence::CART_FOR ] ) && is_scalar( $_REQUEST[ AppLicence::CART_FOR ] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST[ AppLicence::CART_FOR ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$link  = $data[ AppLicence::CART_FOR ] ?? $asked;

		if ( 'cart_server' === $link ) {
			return 'cart_server';
		}

		if ( ! is_scalar( $link ) || ! ctype_digit( (string) $link ) ) {
			return null;
		}

		$id    = (int) $link;
		$nonce = $data['beltoft_services_applic_nonce'] ?? ( isset( $_REQUEST['beltoft_services_applic_nonce'] ) && is_scalar( $_REQUEST['beltoft_services_applic_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['beltoft_services_applic_nonce'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next.

		if ( ! is_scalar( $nonce ) || ! wp_verify_nonce( (string) $nonce, self::NONCE . ':' . $id ) ) {
			return null;
		}

		return null !== self::own_server( $id ) ? $id : null;
	}

	/**
	 * The server of a license id when the current customer owns it and it can be reinstalled.
	 *
	 * @param int $license_id Server license id.
	 */
	private static function own_server( int $license_id ): ?Resource {
		$license = Licenses::find( $license_id );
		$server  = $license ? Resource::for_license( $license_id ) : null;

		if ( ! $server || (int) ( $license['user_id'] ?? 0 ) !== get_current_user_id() || SshKeyField::DRIVER !== $server->get( 'driver' ) || 'ready' !== $server->get( 'state' ) || $server->get( 'linked' ) ) {
			return null;
		}

		return $server;
	}

	/**
	 * The checkout URL that buys a tier for a server: the add-to-cart arguments of the tier's line variation.
	 *
	 * @param Resource              $server    Server.
	 * @param \WC_Product_Variation $variation The line variation (monthly, or the usage sibling).
	 */
	public static function url( Resource $server, \WC_Product_Variation $variation ): string {
		$id   = (int) ( $server->license()['id'] ?? 0 );
		$args = array_merge(
			array(
				'add-to-cart'                    => $variation->get_parent_id(),
				'variation_id'                   => $variation->get_id(),
				AppLicence::CART_FOR             => $id,
				'beltoft_services_applic_nonce'  => wp_create_nonce( self::NONCE . ':' . $id ),
			),
			$variation->get_variation_attributes()
		);

		return add_query_arg( $args, wc_get_checkout_url() );
	}

	/**
	 * Filter beltoft_services_action_redirect: a reinstall to a paid app without its licence goes to checkout.
	 *
	 * @param mixed $url      '' so far.
	 * @param mixed $resource Resource.
	 * @param mixed $action   Action.
	 * @param mixed $args     Cleaned arguments.
	 * @return mixed A checkout URL, a WP_Error (a refusal), or the value unchanged.
	 */
	public static function redirect( $url, $resource, $action, $args ) {
		if ( ! is_string( $url ) || '' !== $url || 'reinstall' !== $action || ! $resource instanceof Resource || SshKeyField::DRIVER !== $resource->get( 'driver' ) || ! is_array( $args ) ) {
			return $url;
		}

		$pick = self::pick( $args );

		if ( null === $pick || self::licensed( $resource, $pick['app'] ) ) {
			return $url;
		}

		self::$quote = true;

		try {
			$why = Actions::check( $resource, 'reinstall', $args );
		} finally {
			self::$quote = false;
		}

		if ( null !== $why ) {
			return new \WP_Error( 'beltoft_services_refused', $why, array( 'status' => 422 ) );
		}

		$tier      = AppLicence::tier( $pick['app'], $pick['tier'] );
		$variation = null === $tier ? null : AppLicence::line_variation( $tier, AppLicence::usage_billed( $resource ) );

		if ( null === $variation || ! function_exists( 'wc_get_checkout_url' ) ) {
			return new \WP_Error( 'beltoft_services_refused', __( 'This tier cannot be bought for this server now. Choose another tier or no app.', 'beltoft-services' ), array( 'status' => 422 ) );
		}

		$stored = $resource->set(
			array(
				self::PENDING => array(
					'app'     => $pick['app'],
					'version' => $pick['version'],
					'tier'    => $pick['tier'],
					'args'    => $args,
					'at'      => time(),
				),
			)
		);

		return $stored ? self::url( $resource, $variation ) : new \WP_Error( 'beltoft_services_refused', __( 'Your request could not be saved. Please try again.', 'beltoft-services' ), array( 'status' => 500 ) );
	}

	/**
	 * Called by the licence's provision step: the licence of an app the server does not run yet. When the server waits for
	 * it (the reinstall request is kept), the reinstall is queued now.
	 *
	 * @param Resource $server  Server.
	 * @param string   $app     App id of the licence.
	 * @param Resource $licence The licence resource.
	 * @return StepResult|null Null when there is no request for this app (the caller refuses), else the answer to give.
	 */
	public static function claim( Resource $server, string $app, Resource $licence ): ?StepResult {
		$pending = self::pending( $server );

		if ( null === $pending || $pending['app'] !== $app || (string) ( ( (array) $licence->get( 'plan' ) )['tier'] ?? '' ) !== $pending['tier'] ) {
			return null;
		}

		if ( ! $server->accepts_actions() || 'ready' !== $server->get( 'state' ) ) {
			$until = (int) $licence->get( 'reinstall_wait_until' );

			if ( $until < 1 ) {
				$until = time() + 6 * HOUR_IN_SECONDS;

				if ( ! $licence->set( array( 'reinstall_wait_until' => $until ) ) ) {
					return StepResult::retry( 60, 'The wait deadline could not be stored.' );
				}
			}

			return time() < $until ? StepResult::wait( 60, 'Waiting for the server to take the reinstall.', $until ) : StepResult::fail( 'The server could not take the reinstall of the paid app. Review the order and refund it if needed.' );
		}

		if ( ! $server->set( array( self::PENDING => null ) ) ) {
			return StepResult::retry( 60, 'The reinstall request could not be cleared.' );
		}

		Progress::mark( $server, 'reinstall', __( 'Reinstall', 'beltoft-services' ) );
		Jobs::queue_act( $server->id(), 'reinstall', $pending['args'] );

		return StepResult::done( array( 'steps' => array( 'ready' ), 'applic_tier' => $pending['tier'] ) );
	}

	/**
	 * A licence line for an existing server must still be what the server waits for, when the order is placed.
	 *
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function checkout_validation( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		foreach ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$link = $item[ AppLicence::CART_FOR ] ?? null;

			if ( ! isset( $item[ AppLicence::CART_UID ] ) || ! is_int( $link ) ) {
				continue;
			}

			$app  = AppLicence::app_of( (int) ( $item['variation_id'] ?? 0 ) );
			$plan = Product::plan( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );
			$tier = null === $app ? null : AppLicence::tier( $app, (string) ( $plan['tier'] ?? '' ) );
			$want = null === $tier ? null : AppLicence::line_variation( $tier, self::server_usage( $link ) );

			if ( null === self::own_server( $link ) || null === $app || null === $want || $want->get_id() !== (int) $item['variation_id'] || ! self::wants( $link, $app, (string) $tier['key'] ) ) {
				$errors->add( 'beltoft_services_applic', __( 'The server is not waiting for this app licence any more. Remove it from your cart and choose the app again on the server\'s Reinstall tab.', 'beltoft-services' ) );
			}
		}
	}
}
