<?php
/**
 * One place that applies the customer's choice for the checkout add-ons of a new server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Backup;

use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Disk\DiskField;

defined( 'ABSPATH' ) || exit;

/**
 * The "Daily backups" box and the extra disk selector each change the cart through their own wc-ajax call. Two
 * calls at once on one WooCommerce session each load the cart at the start and save it at shutdown, so the later
 * save used to win and drop the other change. Here every call carries the full current choice (backups on or off,
 * disk variation) and applies both, under a per-session lock, on the cart as the session last saved it, and saves
 * before it lets go. Whatever the order, the last call leaves the cart with both choices.
 */
final class CartSwap {

	/**
	 * Seconds a swap waits for the swap of the same session before it gives up.
	 */
	private const WAIT = 10;

	/**
	 * Applies the choice under the session lock.
	 *
	 * @param bool|null $with Backups on or off; null leaves them as they are.
	 * @param int|null  $disk Extra disk variation id, 0 for none; null leaves the disk as it is.
	 * @return array{ok: bool, with: bool|null, disk: int|null, variation_id: int} Ok, and the cart's state after.
	 */
	public static function apply( ?bool $with, ?int $disk ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return self::state( false );
		}

		$run = self::locked( static fn(): array => self::apply_now( $with, $disk ) );

		return $run ?? self::state( false );
	}

	/**
	 * Runs a callback on the session's latest cart, alone, and saves the session before the lock is released.
	 *
	 * @param callable $callback Work to do.
	 * @return mixed|null The callback's result; null when the lock could not be taken.
	 */
	public static function locked( callable $callback ) {
		$session = WC()->session;
		$id      = $session instanceof \WC_Session_Handler && $session->has_session() ? (string) $session->get_customer_id() : '';

		if ( '' === $id ) {
			return $callback(); // No saved session (nothing to race with).
		}

		$run = Locks::with(
			Locks::name( 'cart', abs( crc32( $id ) ) ),
			self::WAIT,
			static function () use ( $session, $id, $callback ) {
				self::reload( $session, $id );
				$result = $callback();
				WC()->cart->set_session();
				$session->save_data();

				return $result;
			}
		);

		return $run[0] ? $run[1] : null;
	}

	/**
	 * Reads the session again, so a swap that saved while this request was starting is not lost.
	 *
	 * @param \WC_Session_Handler $session Session.
	 * @param string              $id      Customer id.
	 */
	private static function reload( \WC_Session_Handler $session, string $id ): void {
		wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $id, WC_SESSION_CACHE_GROUP );
		$fresh = $session->get_session( $id, array() );

		if ( ! is_array( $fresh ) ) {
			return;
		}

		foreach ( $fresh as $key => $value ) {
			$session->set( (string) $key, maybe_unserialize( $value ) );
		}

		WC()->cart->get_cart_from_session();
	}

	/**
	 * Checks the whole choice first, then applies it, so a refused part changes nothing.
	 *
	 * @param bool|null $with Backups.
	 * @param int|null  $disk Disk variation id.
	 * @return array{ok: bool, with: bool|null, disk: int|null, variation_id: int}
	 */
	private static function apply_now( ?bool $with, ?int $disk ): array {
		if ( null !== $with && null === BackupField::cart_server() ) {
			return self::state( false );
		}

		if ( null !== $disk && ( null === DiskField::cart_server() || ( $disk > 0 && ! isset( DiskField::sizes_for( DiskField::cart_server_usage() )[ $disk ] ) ) ) ) {
			return self::state( false );
		}

		$ok = true;

		if ( null !== $with ) {
			$ok = BackupField::swap( $with )['ok'];
		}

		if ( $ok && null !== $disk ) {
			$ok = DiskField::choose( $disk );
		}

		return self::state( $ok );
	}

	/**
	 * The cart's current choice.
	 *
	 * @param bool $ok Whether the call succeeded.
	 * @return array{ok: bool, with: bool|null, disk: int|null, variation_id: int}
	 */
	private static function state( bool $ok ): array {
		$backups = function_exists( 'WC' ) && WC()->cart ? BackupField::cart_server() : null;
		$disk    = function_exists( 'WC' ) && WC()->cart ? DiskField::selected_variation() : 0;

		return array(
			'ok'           => $ok,
			'with'         => $backups ? (bool) $backups['has'] : null,
			'disk'         => $disk,
			'variation_id' => $backups ? $backups['variation']->get_id() : 0,
		);
	}

	/**
	 * Loads the shared script both fields queue their changes through.
	 */
	public static function enqueue(): void {
		wp_enqueue_script( 'beltoft-services-cart-swap', plugins_url( 'assets/cart-swap.js', BELTOFT_SERVICES_FILE ), array( 'jquery', 'beltoft-services-status' ), \BeltoftServices\Assets::version( 'assets/cart-swap.js' ), true );
	}
}
