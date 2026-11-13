<?php
/**
 * The top-up limits and line, as the site's currency bridge and theme ask for them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\GiftCredit;

use Bgca\Settings as AddonSettings;
use Bgca\TopUp as AddonTopUp;

defined( 'ABSPATH' ) || exit;

/**
 * A thin read-only face over the add-on's top-up (Bgca\TopUp). The old name BeltoftServices\Credit\Topup is an alias of this class
 * (beltoft-services.php), because the site's currency bridge calls Topup::CART_KEY, is_product(), available(), min() and max() in whole
 * amounts of a currency. Nothing inside this plugin uses it.
 */
final class Topup {

	/** The cart item key that carries the top-up amount in cents. */
	public const CART_KEY = AddonTopUp::CART_KEY;

	/**
	 * Is this product the top-up product?
	 *
	 * @param int $product_id Product id.
	 */
	public static function is_product( int $product_id ): bool {
		return AddonTopUp::is_product( $product_id );
	}

	/**
	 * Are top-ups sold in a currency: gift credit is active, top-up is on, and the add-on has limits for it.
	 *
	 * @param string $currency Currency code, '' for the shop's.
	 */
	public static function available( string $currency = '' ): bool {
		return GiftCredit::active() && (bool) AddonSettings::get( 'topup_enabled' ) && null !== AddonTopUp::limits( $currency );
	}

	/**
	 * The smallest top-up in a currency, a whole amount. 0 when top-ups are not sold in it.
	 *
	 * @param string $currency Currency code, '' for the shop's.
	 */
	public static function min( string $currency = '' ): int {
		$limits = self::available( $currency ) ? AddonTopUp::limits( $currency ) : null;

		return null === $limits ? 0 : $limits['min'];
	}

	/**
	 * The largest top-up in a currency, a whole amount. 0 when top-ups are not sold in it.
	 *
	 * @param string $currency Currency code, '' for the shop's.
	 */
	public static function max( string $currency = '' ): int {
		$limits = self::available( $currency ) ? AddonTopUp::limits( $currency ) : null;

		return null === $limits ? 0 : $limits['max'];
	}
}
