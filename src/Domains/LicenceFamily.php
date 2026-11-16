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
	 * The stored meta name of a logical field. Fields: title.
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
}
