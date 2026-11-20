<?php
/**
 * The cart's one new database, and the name a theme asks it by.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Vm\OsField;

defined( 'ABSPATH' ) || exit;

/**
 * What is left of the old public IP checkbox. The public address of a database is now the IPv4 add-on, chosen with the
 * same checkout line as for a server (Ipv4\Ipv4Field), so the plan variation pair, the checkbox, its swap and its script are
 * gone. This class stays for two reasons: the theme asks `Proxmox\DbIpField::cart_has_new_database()` (pinned in
 * tests/fixtures/external-api.php), and DbDiskField and the IPv4 add-on use new_database().
 */
final class DbIpField {

	/**
	 * The variation attribute the old pair used (name "Public IP"). A variation that still has it is shown without it.
	 */
	public const ATTRIBUTE = 'public-ip';

	/**
	 * The one new database in the cart: not a renewal, a switch or a resubscribe. Null when the cart holds none or
	 * more than one.
	 *
	 * @return array{key: string, item: array<string, mixed>}|null
	 */
	public static function new_database(): ?array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || OsField::cart_is_existing_server() ) {
			return null;
		}

		$found = null;

		foreach ( $cart->get_cart() as $key => $item ) {
			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

			if ( ! $driver || DbDriver::ID !== $driver->id() ) {
				continue;
			}

			// Two databases on one order: which one would a choice mean?
			if ( null !== $found || CartFlow::has_value( $item, CartFlow::BOUGHT_BEFORE ) ) {
				return null;
			}

			$found = array(
				'key'  => (string) $key,
				'item' => $item,
			);
		}

		return $found;
	}

	/**
	 * Does the cart hold exactly one new database? For a theme's or the checkout builder's condition on the "Your database" step.
	 */
	public static function cart_has_new_database(): bool {
		return null !== self::new_database();
	}
}
