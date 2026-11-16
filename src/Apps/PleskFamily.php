<?php
/**
 * The Plesk app licence as a licence family of the vendor layer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Apps;

use BeltoftServices\Currency;
use BeltoftServices\Domains\LicenceFamily;
use BeltoftServices\Domains\LicenceVendor;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * What is particular to the licence behind a server's Plesk tier: its meta names (plesk_*), its words, and the pay as
 * you go cap that guards a licence billed by the hour.
 */
final class PleskFamily implements LicenceFamily {

	private const APP = PleskLicence::APP;

	/** Logical field => stored meta name. */
	private const META = array(
		'title'     => 'plesk_title',
		'key_id'    => 'plesk_key_id',
		'item'      => 'plesk_item',
		'key'       => 'plesk_key',
		'key_tries' => 'plesk_key_tries',
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

	public function key_hook(): string {
		return PleskLicence::KEY_HOOK;
	}

	public function owns( Resource $r ): bool {
		return $r->is_live() && self::APP === (string) ( ( (array) $r->get( 'plan' ) )['app'] ?? '' );
	}

	public function ended( Resource $r ): bool {
		return 'ended' === AppLicence::state( $r );
	}

	public function detailed_key(): bool {
		return false;
	}

	public function key_meta( Resource $r, string $key ): array {
		unset( $r, $key );

		return array( 'plesk_key_pushes' => 0 );
	}

	public function on_key_stored( Resource $r, string $key ): void {
		// A key the server has not confirmed yet is sent once the app is there.
		if ( hash( 'sha256', $key ) !== (string) $r->get( 'plesk_key_job' ) ) {
			Jobs::schedule_once( time(), PleskLicence::KEY_HOOK, array( $r->id() ) );
		}
	}

	public function ask_meta( int $tries ): array {
		return array( 'plesk_key_tries' => $tries );
	}

	public function ask_alert( Resource $r ): string {
		unset( $r );

		return 'The Plesk key could not be fetched from Openprovider after ' . LicenceVendor::TRIES . ' tries.';
	}

	public function changed_meta(): array {
		return array( 'plesk_sync_tries' => null, 'plesk_key' => null, 'plesk_key_tries' => null );
	}
}
