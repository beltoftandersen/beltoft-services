<?php
/**
 * The standalone licence key as a licence family of the vendor layer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Licences;

use BeltoftServices\Domains\LicenceFamily;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * What is particular to a standalone key: its meta names (lic_*) and its words. No code path bills a standalone key from
 * the usage ledger, so no cap guards it.
 */
final class KeyFamily implements LicenceFamily {

	/** Logical field => stored meta name. */
	private const META = array(
		'title' => 'lic_title',
	);

	public function meta( string $field ): string {
		return self::META[ $field ];
	}

	public function subject(): string {
		return 'licence';
	}

	public function licence_noun(): string {
		return 'licence';
	}

	public function uses_cap( Resource $r ): bool {
		unset( $r );

		return false;
	}

	public function cap_problem( Resource $r, float $cost ): ?string {
		unset( $r, $cost );

		return null;
	}
}
