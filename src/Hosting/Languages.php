<?php
/**
 * The languages a new WordPress site can be installed in.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Locale => native name. The list is the WordPress translations API data, kept in an option that a weekly job
 * refreshes (wp_get_available_translations(), run in the background, never at checkout). Until the first refresh the
 * bundled list in languages-data.php is used, so the checkout never waits for the network.
 */
final class Languages {

	public const OPTION  = 'beltoft_services_wp_languages';
	public const HOOK    = 'beltoft_services_hosting_languages';
	public const CRON    = '15 4 * * 0';
	public const DEFAULT = 'en_US';

	/** Locale shape: en_US, da_DK, ast, de_DE_formal, ca_valencia. */
	public const PATTERN = '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/D';

	/** The languages most orders use, in the order shown first. */
	private const COMMON = array( 'en_US', 'en_GB', 'da_DK', 'de_DE', 'sv_SE', 'nb_NO', 'nl_NL', 'fr_FR', 'es_ES', 'it_IT', 'pt_PT', 'pl_PL', 'fi' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the weekly refresh, once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON, array(), 600 );
	}

	/**
	 * Is this a well formed locale?
	 *
	 * @param string $locale Locale.
	 */
	public static function valid( string $locale ): bool {
		return 1 === preg_match( self::PATTERN, $locale );
	}

	/**
	 * All languages, locale => native name.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION );
		$list   = is_array( $stored ) && count( $stored ) >= 20 ? $stored : (array) require __DIR__ . '/languages-data.php';
		$clean  = array();

		foreach ( $list as $locale => $name ) {
			if ( is_string( $locale ) && is_string( $name ) && '' !== $name && self::valid( $locale ) ) {
				$clean[ $locale ] = $name;
			}
		}

		if ( ! isset( $clean[ self::DEFAULT ] ) ) {
			$clean[ self::DEFAULT ] = 'English (United States)';
		}

		return $clean;
	}

	/**
	 * Is this locale one the list offers?
	 *
	 * @param string $locale Locale.
	 */
	public static function offered( string $locale ): bool {
		return isset( self::all()[ $locale ] );
	}

	/**
	 * The language a new website is offered first: the language of the page the customer is ordering on when the list
	 * has it (a Danish checkout offers Dansk), else the default.
	 */
	public static function preselected(): string {
		$locale = (string) determine_locale();

		/**
		 * The website language preselected in the order form.
		 *
		 * @param string $locale The page's locale when it is on the list, else the default.
		 */
		$locale = (string) apply_filters( 'beltoft_services_hosting_default_language', self::offered( $locale ) ? $locale : self::DEFAULT );

		return self::offered( $locale ) ? $locale : self::DEFAULT;
	}

	/**
	 * The native name of a locale, or the locale itself when it is not on the list.
	 *
	 * @param string $locale Locale.
	 */
	public static function name( string $locale ): string {
		return self::all()[ $locale ] ?? $locale;
	}

	/**
	 * The list as two groups for a select: the common languages in a fixed order, then all of them by name.
	 *
	 * @return array{common: array<string, string>, all: array<string, string>}
	 */
	public static function groups(): array {
		$all    = self::all();
		$common = array();

		foreach ( self::COMMON as $locale ) {
			if ( isset( $all[ $locale ] ) ) {
				$common[ $locale ] = $all[ $locale ];
			}
		}

		asort( $all, SORT_NATURAL | SORT_FLAG_CASE );

		return array(
			'common' => $common,
			'all'    => $all,
		);
	}

	/**
	 * Reads the translations API (wp_get_available_translations, which keeps its own 3 day cache) and stores the
	 * names. A failed or short answer keeps what is stored. Never called at checkout.
	 *
	 * @return bool True when the list was replaced.
	 */
	public static function refresh(): bool {
		if ( ! function_exists( 'wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$found = wp_get_available_translations();
		$names = array();

		foreach ( is_array( $found ) ? $found : array() as $locale => $row ) {
			if ( is_string( $locale ) && is_array( $row ) && isset( $row['native_name'] ) && is_string( $row['native_name'] ) && self::valid( $locale ) ) {
				$names[ $locale ] = $row['native_name'];
			}
		}

		if ( count( $names ) < 20 ) {
			return false;
		}

		$names[ self::DEFAULT ] = 'English (United States)';
		update_option( self::OPTION, $names, false );

		return true;
	}
}
