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
		'DAN' => 'æø',
		'GER' => 'üß',
		'SWE' => 'åäö',
		'ICE' => 'ðþ',
		'SPA' => 'ñ',
		'POR' => 'ãõ',
		'FRE' => 'éèêëàâçùûîïôœ',
	);

	/** Site language (first part of the locale) to tag, used when the letters themselves do not say. */
	private const LOCALE = array(
		'da' => 'DAN',
		'sv' => 'SWE',
		'de' => 'GER',
		'is' => 'ICE',
		'es' => 'SPA',
		'pt' => 'POR',
		'fr' => 'FRE',
		'en' => 'ENG',
		'fi' => 'FIN',
		'nl' => 'DUT',
		'it' => 'ITA',
		'nb' => 'NOR',
		'nn' => 'NOR',
	);

	/**
	 * The tag for a stored (punycode) name; an empty string for a plain ASCII name, for a name in a non-Latin script
	 * (the registry answers for those) and when neither the letters nor the site language point to a tag.
	 *
	 * @param string $name Stored name, for example xn--blegrd-ux1109-2fb9y.com.
	 */
	public static function for_name( string $name ): string {
		if ( false === stripos( $name, 'xn--' ) ) {
			return '';
		}
		$unicode = mb_strtolower( HostName::display( $name ) );
		$tag     = '';
		if ( 1 === preg_match( '/[^\p{Latin}\p{N}\p{P}\p{M}]/u', $unicode ) ) {
			$tag = '';
		} else {
			foreach ( self::HINTS as $code => $letters ) {
				if ( 1 === preg_match( '/[' . $letters . ']/u', $unicode ) ) {
					$tag = $code;
					break;
				}
			}
			if ( '' === $tag ) {
				$tag = self::LOCALE[ strtolower( substr( (string) determine_locale(), 0, 2 ) ) ] ?? '';
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
