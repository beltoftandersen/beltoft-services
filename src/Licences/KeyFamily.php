<?php
/**
 * The standalone licence key as a licence family of the vendor layer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Licences;

use BeltoftServices\Domains\LicenceFamily;
use BeltoftServices\Domains\LicenceVendor;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * What is particular to a standalone key: its meta names (lic_*) and its words. No code path bills a standalone key from
 * the usage ledger, so no cap guards it.
 */
final class KeyFamily implements LicenceFamily {

	/** Logical field => stored meta name. */
	private const META = array(
		'title'     => 'lic_title',
		'key_id'    => 'lic_key_id',
		'item'      => 'lic_item',
		'key'       => 'lic_key',
		'key_tries' => 'lic_tries',
		'shape'     => 'lic_shape',
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

	public function key_hook(): string {
		return KeyOrder::KEY_HOOK;
	}

	public function owns( Resource $r ): bool {
		return KeyDriver::ID === $r->get( 'driver' );
	}

	public function ended( Resource $r ): bool {
		return KeyOrder::ended( $r );
	}

	public function detailed_key(): bool {
		return true;
	}

	public function key_meta( Resource $r, string $key ): array {
		unset( $r );

		return array(
			'lic_key_kind' => '<' === $key[0] ? 'file' : 'code',
			'lic_state'    => 'ready',
		);
	}

	public function on_key_stored( Resource $r, string $key ): void {
		unset( $r, $key );
	}

	public function ask_meta( int $tries ): array {
		return array( 'lic_tries' => $tries, 'lic_state' => 'waiting' );
	}

	public function ask_alert( Resource $r ): string {
		return 'Openprovider has not handed over a key for this licence after ' . LicenceVendor::TRIES . ' tries. Open the licence at Openprovider and copy the key to the customer. Fields answered: ' . (string) $r->get( 'lic_shape' ) . '.';
	}
}
