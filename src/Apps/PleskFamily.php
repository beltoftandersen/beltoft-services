<?php
/**
 * The Plesk app licence as a licence family of the vendor layer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Apps;

use BeltoftServices\Currency;
use BeltoftServices\Domains\LicenceFamily;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * What is particular to the licence behind a server's Plesk tier: its meta names (plesk_*), its words, and the pay as
 * you go cap that guards a licence billed by the hour.
 */
final class PleskFamily implements LicenceFamily {

	/** Logical field => stored meta name. */
	private const META = array(
		'title'  => 'plesk_title',
		'key_id' => 'plesk_key_id',
		'item'   => 'plesk_item',
	);

	public function meta( string $field ): string {
		return self::META[ $field ];
	}

	public function subject(): string {
		return 'Plesk tier';
	}

	public function licence_noun(): string {
		return 'Plesk licence';
	}

	public function uses_cap( Resource $r ): bool {
		return AppLicence::usage_billed( $r );
	}

	public function cap_problem( Resource $r, float $cost ): ?string {
		// Billed by the hour with the server: the most a month can bring in is the cap, read in the currency of the usage account
		// and compared at the guard rate when that is not the cost currency.
		$code = $this->account_currency( $r );
		list( $ok, $items ) = Currency::with( $code, static fn() => Rates::items( $r ) );
		$cap  = $ok && $items ? Currency::price_to_cost( (float) $items[0]['rate'] * Rates::HOURS, $code ) : null;

		return null !== $cap && $cap + 0.01 >= $cost ? null : 'The pay as you go cap of this Plesk tier is below the Openprovider cost.';
	}

	/**
	 * The currency a pay as you go licence is billed in: that of the usage account its licence is on, else the shop's.
	 *
	 * @param Resource $licence Licence resource.
	 */
	private function account_currency( Resource $licence ): string {
		$id   = (int) ( $licence->license()['subscription_id'] ?? 0 );
		$sub  = $id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : false;
		$code = $sub instanceof \WC_Subscription ? Currency::clean( $sub->get_currency() ) : '';

		return '' !== $code ? $code : Currency::base();
	}
}
