<?php
/**
 * The link to a larger plan, for the usage emails. Upgrade only, as the switch check (Switches) is.
 *
 * WooCommerce Subscriptions' switch link carries a nonce of the logged-in user, so it cannot be
 * written into an email. The email links to the server page with `beltoft_upgrade=<need>`; the
 * logged-in owner who opens it is sent on to the real switch link, built for them at that moment.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Account\Servers;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Upgrades {
	public const QUERY = 'beltoft_upgrade';
	public const NEEDS = array( 'disk', 'cpu', 'memory' );

	/** Hooks. */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ) );
	}

	/**
	 * The cheapest in-stock plan of the same product and cluster that is larger on the stressed resource and
	 * not smaller on any other. A server with backups goes to the backups variation of that plan.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $need disk, cpu or memory.
	 */
	public static function target( Resource $r, string $need ): ?\WC_Product_Variation {
		if ( ! in_array( $need, self::NEEDS, true ) || 'proxmox' !== $r->get( 'driver' ) || true === $r->get( 'linked' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$license = $r->license();
		$plan    = (array) $r->get( 'plan' );
		$have    = array(
			'disk'   => max( (int) ( $plan['disk_gb'] ?? 0 ), (int) $r->get( 'disk_gb' ) ),
			'cpu'    => (int) ( $plan['cores'] ?? 0 ),
			'memory' => (int) ( $plan['memory_mb'] ?? 0 ),
		);
		$keys    = array( 'disk' => 'disk_gb', 'cpu' => 'cores', 'memory' => 'memory_mb' );
		$backups = (int) ( $plan['backup_days'] ?? 0 ) > 0;
		$best    = null;

		if ( empty( $license['product_id'] ) || $have['disk'] < 1 || $have['cpu'] < 1 || $have['memory'] < 1 ) {
			return null;
		}

		foreach ( Offers::plans() as $offer ) {
			$ok = (int) $offer['product_id'] === (int) $license['product_id'] && (string) $offer['cluster'] === (string) ( $plan['cluster'] ?? '' ) && ! empty( $offer['in_stock'] );

			foreach ( $keys as $name => $key ) {
				$ok = $ok && (int) $offer[ $key ] >= $have[ $name ] && ( $name !== $need || (int) $offer[ $key ] > $have[ $name ] );
			}

			if ( $ok && ( null === $best || (float) $offer['price'] < (float) $best['price'] ) ) {
				$best = $offer;
			}
		}

		if ( null === $best ) {
			return null;
		}

		$id = (int) $best['variation_id'];

		if ( $backups ) {
			$id = (int) ( $best['backups']['variation_id'] ?? 0 );
		}

		$variation = $id > 0 ? wc_get_product( $id ) : null;

		return $variation instanceof \WC_Product_Variation ? $variation : null;
	}

	/**
	 * The link for an email: the server page with the upgrade query. Empty when no larger plan exists or the
	 * server has no subscription to switch.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $need disk, cpu or memory.
	 */
	public static function link( Resource $r, string $need ): string {
		$license = $r->license();

		if ( ! class_exists( '\WC_Subscriptions_Switcher' ) || empty( $license['subscription_id'] ) || null === self::target( $r, $need ) ) {
			return '';
		}

		return add_query_arg( self::QUERY, $need, Servers::url( $license ) );
	}

	/**
	 * The switch link for the logged-in customer. Empty when it cannot be switched.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $need disk, cpu or memory.
	 */
	public static function url( Resource $r, string $need ): string {
		$target = self::target( $r, $need );

		return null === $target ? '' : Backups::switch_to( $r, $target );
	}

	/** Sends the owner on to the switch, or to the server page when it cannot be done. */
	public static function redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which resource to look at; the owner check below decides.
		$need = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::QUERY ] ) ) : '';

		if ( '' === $need || ! in_array( $need, self::NEEDS, true ) || ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$asked   = Servers::requested();
		$r       = $asked['id'] > 0 ? Resource::for_license( $asked['id'] ) : null;
		$license = $r ? $r->license() : null;

		if ( ! $r || ! $license || (int) ( $license['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return;
		}

		$url = self::url( $r, $need );

		wp_safe_redirect( '' !== $url ? $url : Servers::url( $license ) );
		exit;
	}
}
