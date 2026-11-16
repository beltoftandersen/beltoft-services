<?php
/**
 * What differs between the licence families that share LicenceVendor.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The Plesk app licence (Apps\PleskFamily) and the standalone key (Licences\KeyFamily) order the same kind of licence at
 * the same registrar. They differ in the names of the resource meta they keep, in a few words of text and in whether a
 * pay as you go cap guards the spend. LicenceVendor holds the vendor code once and asks the family for these.
 */
interface LicenceFamily {

	/**
	 * The stored meta name of a logical field. Fields: title, key_id, item, key, key_tries, shape.
	 *
	 * @param string $field Logical field.
	 */
	public function meta( string $field ): string;

	/**
	 * What the cost belongs to, in the admin texts: "Plesk tier" or "licence".
	 */
	public function subject(): string;

	/**
	 * What the paid order line is for, in the admin texts: "Plesk licence" or "licence".
	 */
	public function licence_noun(): string;

	/**
	 * Is this licence billed by usage, so that a monthly cap and not an order line pays for it?
	 *
	 * @param Resource $r Licence resource.
	 */
	public function uses_cap( Resource $r ): bool;

	/**
	 * Does the cap of a usage billed licence cover the cost at the vendor? Null when covered, else why not. Only asked
	 * when uses_cap() is true.
	 *
	 * @param Resource $r    Licence resource.
	 * @param float    $cost Cost per month at the vendor, EUR excluding VAT.
	 */
	public function cap_problem( Resource $r, float $cost ): ?string;

	/**
	 * The Action Scheduler hook of the job that asks for the key again.
	 */
	public function key_hook(): string;

	/**
	 * Is this resource a licence of the family (and live, where the family says so)? Used by the watchdog lists.
	 *
	 * @param Resource $r Resource.
	 */
	public function owns( Resource $r ): bool;

	/**
	 * Has the licence ended, so that no key is fetched for it any more?
	 *
	 * @param Resource $r Licence resource.
	 */
	public function ended( Resource $r ): bool;

	/**
	 * Does a key fetch read the licence record too (a second request), and record the names of the fields the vendor
	 * answered with (and an activation code as a fallback key)?
	 */
	public function detailed_key(): bool;

	/**
	 * More meta to store with a fetched key (kind and state, or the push counter).
	 *
	 * @param Resource $r   Licence resource.
	 * @param string   $key The key (never to be stored in clear).
	 * @return array<string, mixed>
	 */
	public function key_meta( Resource $r, string $key ): array;

	/**
	 * Called after a key was stored. The Plesk app licence hands a changed key to the server here.
	 *
	 * @param Resource $r   Licence resource.
	 * @param string   $key The key.
	 */
	public function on_key_stored( Resource $r, string $key ): void;

	/**
	 * Meta to store with the count of key asks that found no key.
	 *
	 * @param int $tries Asks so far, this one included.
	 * @return array<string, mixed>
	 */
	public function ask_meta( int $tries ): array;

	/**
	 * The text of the admin alert when the asks reach the limit. Never carries a key.
	 *
	 * @param Resource $r Licence resource.
	 */
	public function ask_alert( Resource $r ): string;
}
