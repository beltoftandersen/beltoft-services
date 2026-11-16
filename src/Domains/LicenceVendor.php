<?php
/**
 * The vendor licence at the registrar (Openprovider Plesk licence API) for the Plesk app licence and the standalone keys.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

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
}
