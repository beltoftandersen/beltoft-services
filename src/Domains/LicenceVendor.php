<?php
/**
 * The vendor licence at the registrar (Openprovider Plesk licence API) for the Plesk app licence and the standalone keys.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One owner of the calls to the registrar's licence API that both licence families make: Apps\PleskLicence (the licence
 * behind a server's Plesk tier) and Licences\KeyOrder (a standalone licence key). It names no feature: what differs
 * between the families (meta names, release rule, texts) arrives as a LicenceFamily. Every call goes through
 * Clients::registrar(): the fake in tests and on staging, the live client only behind LiveGate.
 */
final class LicenceVendor {

	/**
	 * The registrar client (the fake, the sandbox or the live one, as Clients decides).
	 */
	public static function client(): Client {
		return Clients::registrar();
	}

	/**
	 * Is an error one that a later try may cure (a transport problem, a rate limit, a server error, a missing login)?
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function transient( \WP_Error $error ): bool {
		return LiveClient::is_transient( $error ) || 'credentials_missing' === $error->get_error_code();
	}

	/**
	 * Does an error say the licence does not exist? Openprovider answers HTTP 404 with its own code op_3108 ("License not
	 * found"), which the client reports as op_3108, never as http_404.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function gone( \WP_Error $error ): bool {
		$data = $error->get_error_data();

		return 'op_3108' === $error->get_error_code() || 'http_404' === $error->get_error_code() || ( is_array( $data ) && 404 === (int) ( $data['status'] ?? 0 ) );
	}

	/**
	 * The key as the guest can install it. Openprovider answers with the Plesk key FILE base64 encoded (one long line of
	 * about 17 000 characters, no markup), which `plesk bin license --install` cannot read and the guest script refused
	 * (it takes the XML itself, or an activation code of up to 64 characters): a Plesk that stayed on its trial although
	 * the licence was paid. The XML is what is stored and sent; an activation code is left alone.
	 *
	 * @param string $key What Openprovider sent, or what was stored earlier.
	 */
	public static function normalise_key( string $key ): string {
		$key = trim( $key );

		if ( '' === $key || '<' === $key[0] || strlen( $key ) <= 64 || 1 !== preg_match( '/^[A-Za-z0-9+\/=\r\n]+$/D', $key ) ) {
			return $key;
		}

		$decoded = base64_decode( (string) preg_replace( '/\s+/', '', $key ), true );

		return false !== $decoded && 1 === preg_match( '/^\s*</', $decoded ) ? trim( $decoded ) : $key;
	}

	/**
	 * The title of the vendor licence: fixed by the licence id, so a crash can find it again. Once made it is stored in
	 * the family's title meta by the caller and read back from there.
	 *
	 * @param Resource      $r      Licence resource.
	 * @param LicenceFamily $family Family.
	 * @param string        $seed   What follows the licence id (the server's name, or the bound address).
	 */
	public static function title( Resource $r, LicenceFamily $family, string $seed ): string {
		$stored = (string) $r->get( $family->meta( 'title' ) );

		if ( '' !== $stored ) {
			return $stored;
		}

		$title = trim( sprintf( 'licence %d %s', (int) ( $r->license()['id'] ?? 0 ), $seed ) );

		return substr( preg_replace( '/[^A-Za-z0-9 ._-]/', '', $title ) ?? $title, 0, 100 );
	}

	/**
	 * Is the paid line of this licence at least the cost at the vendor? Null when the spend is covered, else why not. An
	 * unknown cost is refused, except on the fake client. A usage billed licence is checked against its cap by the family.
	 *
	 * @param Resource             $r      Licence resource.
	 * @param LicenceFamily        $family Family.
	 * @param string               $key    Tier or catalogue key.
	 * @param array<string, float> $costs  Key => cost.
	 */
	public static function spend_problem( Resource $r, LicenceFamily $family, string $key, array $costs ): ?string {
		$cost = $costs[ $key ] ?? null;

		if ( null === $cost ) {
			return 'fake' === Clients::mode() ? null : 'The Openprovider cost of this ' . $family->subject() . ' is not set.';
		}

		if ( $family->uses_cap( $r ) ) {
			return $family->cap_problem( $r, $cost );
		}

		$item = SpendGuard::item_of_resource( $r );

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return 'The paid order line for this ' . $family->licence_noun() . ' is unavailable.';
		}

		$order = $item->get_order();

		$paid = $order ? Currency::to_cost( SpendGuard::paid( $item ), (string) $order->get_currency() ) : null;

		if ( null === $paid ) {
			return 'The order is not in EUR; the Openprovider cost cannot be compared.';
		}

		return $paid + 0.01 < $cost ? 'The order was paid below the Openprovider cost.' : null;
	}

	/**
	 * The same check for a switch to another key or tier: the price of the new variation excluding VAT must cover its
	 * cost. A usage billed licence is checked like a new order (its cap).
	 *
	 * @param LicenceFamily           $family    Family.
	 * @param string                  $key       New tier or catalogue key.
	 * @param array<string, float>    $costs     Key => cost.
	 * @param \WC_Product|false|null  $variation The new variation, when known.
	 * @param Resource|null           $r         Licence resource (only needed to tell a usage billed licence).
	 */
	public static function switch_problem( LicenceFamily $family, string $key, array $costs, $variation, ?Resource $r = null ): ?string {
		$cost = $costs[ $key ] ?? null;

		if ( null === $cost ) {
			return 'fake' === Clients::mode() ? null : 'The Openprovider cost of this ' . $family->subject() . ' is not set.';
		}

		if ( null !== $r && $family->uses_cap( $r ) ) {
			return self::spend_problem( $r, $family, $key, $costs );
		}

		// The variation's price is read in the request's currency; in another one it is compared at the guard rate, and refused without one.
		$price = $variation instanceof \WC_Product ? Currency::price_to_cost( (float) wc_get_price_excluding_tax( $variation ), Currency::current() ) : null;

		return null !== $price && $price + 0.01 >= $cost ? null : 'The price of this ' . $family->subject() . ' is below the Openprovider cost.';
	}

	/**
	 * What a failed vendor call means for a step: a temporary one is tried again soon, any other slowly and an admin is told.
	 *
	 * @param Resource  $r     Licence resource.
	 * @param \WP_Error $error Error.
	 * @param string    $text  What was tried.
	 */
	public static function failure( Resource $r, \WP_Error $error, string $text ): StepResult {
		if ( self::transient( $error ) ) {
			return StepResult::retry( 300, $text );
		}

		$data = $error->get_error_data();
		Alerts::admin( $r, $text . ' (' . $error->get_error_code() . ( is_array( $data ) && ! empty( $data['desc'] ) ? ': ' . $data['desc'] : '' ) . ')' );

		return StepResult::retry( 3600, $text );
	}
}
