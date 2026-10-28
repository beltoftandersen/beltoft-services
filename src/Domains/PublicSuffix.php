<?php
/** The public suffix list: which names can be registered. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class PublicSuffix {
	/** @var array{rules: array<string, true>, wild: array<string, true>, except: array<string, true>}|null */
	private static ?array $list = null;

	/** The registrable domain (suffix plus one label), or null for a suffix itself or an unknown ending. */
	public static function registrable( string $domain ): ?string {
		$domain = strtolower( rtrim( trim( $domain ), '.' ) );
		if ( '' === $domain ) { return null; }
		$list = self::load();
		if ( ! $list['rules'] && ! $list['wild'] && ! $list['except'] ) { return null; }
		$labels = explode( '.', $domain );
		$count  = count( $labels );
		$suffix = null;
		// An exception rule wins over every other rule: the suffix is the rule minus its first label.
		for ( $k = $count; $k >= 1; --$k ) {
			if ( isset( $list['except'][ implode( '.', array_slice( $labels, $count - $k ) ) ] ) ) { $suffix = $k - 1; break; }
		}
		// Otherwise the longest matching rule; there is no implicit "*" rule, so an unknown ending matches nothing.
		for ( $k = $count; null === $suffix && $k >= 1; --$k ) {
			$tail = array_slice( $labels, $count - $k );
			if ( isset( $list['rules'][ implode( '.', $tail ) ] ) || ( $k >= 2 && isset( $list['wild'][ implode( '.', array_slice( $tail, 1 ) ) ] ) ) ) { $suffix = $k; }
		}
		if ( null === $suffix || $suffix >= $count ) { return null; }
		return implode( '.', array_slice( $labels, $count - $suffix - 1 ) );
	}

	/** Forget the parsed list (tests). */
	public static function flush(): void { self::$list = null; }

	public static function is_registrable_apex( string $domain ): bool {
		$domain = strtolower( rtrim( trim( $domain ), '.' ) );
		return '' !== $domain && self::registrable( $domain ) === $domain;
	}

	/** @return array{rules: array<string, true>, wild: array<string, true>, except: array<string, true>} */
	private static function load(): array {
		if ( null !== self::$list ) { return self::$list; }
		$list = array( 'rules' => array(), 'wild' => array(), 'except' => array() );
		/** Path of the bundled public suffix file; tests point it elsewhere. */
		$path = (string) apply_filters( 'beltoft_services_public_suffix_file', BELTOFT_SERVICES_PATH . 'data/public-suffix.dat' );
		$text = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled data file, not a remote request.
		if ( ! is_string( $text ) ) { return self::$list = $list; }
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '//' ) ) { continue; }
			$rule = strtolower( (string) strtok( $line, " \t" ) );
			$kind = 'rules';
			if ( str_starts_with( $rule, '!' ) ) { $kind = 'except'; $rule = substr( $rule, 1 ); }
			elseif ( str_starts_with( $rule, '*.' ) ) { $kind = 'wild'; $rule = substr( $rule, 2 ); }
			if ( preg_match( '/[^\x00-\x7F]/', $rule ) ) {
				$ascii = function_exists( 'idn_to_ascii' ) ? idn_to_ascii( $rule, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;
				if ( ! is_string( $ascii ) ) { continue; }
				$rule = strtolower( $ascii );
			}
			if ( '' !== $rule ) { $list[ $kind ][ $rule ] = true; }
		}
		return self::$list = $list;
	}
}
