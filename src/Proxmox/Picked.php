<?php
/**
 * The choices a customer made on the server picker, carried on the cart line to checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * The picker (Account\ServerPicker) puts one small array of strings on the new server's cart item. Checkout uses it for two
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

	/**
	 * One carried value, '' when none.
	 *
	 * @param string $field Field name.
	 */
	public static function get( string $field ): string {
		return self::for_cart()[ $field ] ?? '';
	}
}
