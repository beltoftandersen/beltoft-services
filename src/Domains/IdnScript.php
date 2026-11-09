<?php
/**
 * The language tag the registrar asks for with an internationalised name.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Openprovider refuses an IDN registration without additional_data.idn_script, a three letter ISO 639-2/B code
 * (DAN, SWE, GER, FRE ...). Checked against its sandbox 2026-11-09: DAN, SWE and ENG are accepted, the ISO 639-2/T form DEU is not.
 */
final class IdnScript {

	/** Letters that point to one language, most telling first. */
	private const HINTS = array(
		'DAN' => 'æøå',
		'ICE' => 'ðþ',
		'GER' => 'üößä',
		'SPA' => 'ñ¿¡',
		'POR' => 'ãõ',
		'FRE' => 'éèêëàâçùûîïôœ',
		'SWE' => '',
	);

	/**
	 * The tag for a stored (punycode) name; an empty string for a plain ASCII name.
	 *
	 * @param string $name Stored name, for example xn--blegrd-ux1109-2fb9y.com.
	 */
	public static function for_name( string $name ): string {
		if ( false === stripos( $name, 'xn--' ) ) {
			return '';
		}
		$unicode = mb_strtolower( HostName::display( $name ) );
		$tag     = 'ENG';
		foreach ( self::HINTS as $code => $letters ) {
			if ( '' !== $letters && 1 === preg_match( '/[' . $letters . ']/u', $unicode ) ) {
				$tag = $code;
				break;
			}
		}
		/**
		 * Filters the registrar language tag of an internationalised name.
		 *
		 * @param string $tag  Three letter ISO 639-2/B code.
		 * @param string $name Stored name (punycode).
		 */
		$filtered = apply_filters( 'beltoft_services_domain_idn_script', $tag, $name );
		return is_string( $filtered ) ? $filtered : $tag;
	}
}
