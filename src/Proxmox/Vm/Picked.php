<?php
/**
 * The choices a customer made on the server picker, carried on the cart line to checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Proxmox\Disk\DiskField;

defined( 'ABSPATH' ) || exit;

/**
 * The picker (Proxmox\Vm\ServerPicker) puts one small array of strings on the new server's cart item, and every checkout
 * refresh (update_order_review) writes the server step's choices back onto it, so a reload (a line removed from the order
 * summary, a login) prints the fields as they were chosen. Checkout uses it for two
 * things only: to start the Server step's fields from it, and, for a field the posted form does not carry at all, as
 * the value the form would have had. A value in the post always wins, and every value goes through the same
 * validators and savers as a typed one: nothing here is trusted.
 */
final class Picked {

	/**
	 * Cart item data key.
	 */
	public const KEY = 'beltoft_services_picker';

	/**
	 * Fields and the longest value of each.
	 */
	private const FIELDS = array(
		'os'        => 12,
		'app'       => 64,
		'version'   => 64,
		'tier'      => 64,
		'domain_on' => 1,
		'zone'      => 253,
		'sub'       => 120,
		'own'       => 253,
		'name'      => 120,
		'ssh_key'   => 8192,
	);

	/**
	 * The choices as strings, known fields only, each cut to its length. Anything else is dropped.
	 *
	 * @param mixed $raw Anything.
	 * @return array<string, string>
	 */
	public static function clean( $raw ): array {
		$out = array();

		foreach ( self::FIELDS as $field => $max ) {
			$value = is_array( $raw ) && isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ? trim( (string) $raw[ $field ] ) : '';

			$out[ $field ] = substr( $value, 0, $max );
		}

		return $out;
	}

	/**
	 * The choices on the cart's one new server; empty fields when there are none.
	 *
	 * @return array<string, string>
	 */
	public static function for_cart(): array {
		$server = DiskField::cart_server();

		return self::clean( null === $server ? array() : ( $server['item'][ self::KEY ] ?? array() ) );
	}

	public static function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'keep_posted' ), 5 );
	}

	/**
	 * Action woocommerce_checkout_update_order_review: the system, name and SSH key the server step posted, each only when
	 * the form carried it. The app choices are kept by Apps\AppField the same way.
	 *
	 * @param mixed $post_data The checkout form, query-string encoded (WooCommerce unslashed it).
	 */
	public static function keep_posted( $post_data ): void {
		parse_str( is_string( $post_data ) ? $post_data : '', $data );

		$values = array();

		foreach ( array( 'os' => OsField::POST_KEY, 'name' => ServerName::POST_KEY, 'ssh_key' => SshKeyField::POST_KEY ) as $field => $post_key ) {
			if ( isset( $data[ $post_key ] ) && is_scalar( $data[ $post_key ] ) ) {
				$values[ $field ] = (string) $data[ $post_key ];
			}
		}

		self::keep( $values );
	}

	/**
	 * Writes choices onto the cart's one new server; the other fields keep their value. Each value is only a starting
	 * point for the fields: checkout validates what is posted, as for the picker.
	 *
	 * @param array<string, string> $values Field => value, known fields only.
	 */
	public static function keep( array $values ): void {
		$server = array() === $values ? null : DiskField::cart_server();

		if ( null === $server ) {
			return;
		}

		$cart = WC()->cart;
		$now  = self::clean( $server['item'][ self::KEY ] ?? array() );
		$next = self::clean( array_merge( $now, array_intersect_key( $values, self::FIELDS ) ) );

		if ( $next !== $now && isset( $cart->cart_contents[ $server['key'] ] ) ) {
			$cart->cart_contents[ $server['key'] ][ self::KEY ] = $next;
			$cart->set_session();
		}
	}

	/**
	 * One carried value, '' when none.
	 *
	 * @param string $field Field name.
	 */
	public static function get( string $field ): string {
		return self::for_cart()[ $field ] ?? '';
	}
}
