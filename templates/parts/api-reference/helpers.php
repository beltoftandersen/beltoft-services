<?php
/**
 * Shared helpers of the two API reference layouts: the access level label, a path with its {parameters} wrapped so that
 * they can be coloured, and the method => class suffix map. Included into the layout's scope.
 *
 * Expects $scopes (level => label) and $beltoft_services_cls (the layout's class prefix, such as bsdocs__).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_level = static function ( string $level ) use ( $scopes ): string {
	return 'none' === $level ? __( 'None, public', 'beltoft-services' ) : (string) ( $scopes[ $level ] ?? $level );
};

// A path with its {parameters} wrapped, so that they can be coloured.
$beltoft_services_path = static function ( string $path ) use ( $beltoft_services_cls ): string {
	$parts = preg_split( '/(\{\w+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE );
	$out   = '';

	foreach ( (array) $parts as $part ) {
		$out .= 1 === preg_match( '/^\{\w+\}$/', (string) $part ) ? '<span class="' . $beltoft_services_cls . 'param">' . esc_html( (string) $part ) . '</span>' : esc_html( (string) $part );
	}

	return $out;
};

$beltoft_services_methods = array(
	'GET'    => 'get',
	'POST'   => 'post',
	'PUT'    => 'put',
	'DELETE' => 'delete',
);
