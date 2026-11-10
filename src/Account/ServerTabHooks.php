<?php
/**
 * The hooks that every tab of the server page shares.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * For a tab class with a SLUG constant and tabs(), pane() and assets() methods: puts its address part, tab, pane and
 * assets on the server page.
 */
trait ServerTabHooks {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_server_page_tabs', array( __CLASS__, 'slugs' ) );
		add_filter( 'beltoft_services_server_tabs', array( __CLASS__, 'tabs' ), 10, 2 );
		add_action( 'beltoft_services_server_tab_pane', array( __CLASS__, 'pane' ), 10, 2 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 20, 2 );
	}

	/**
	 * The address part of the tab.
	 *
	 * @param mixed $slugs Slugs.
	 * @return mixed
	 */
	public static function slugs( $slugs ) {
		return is_array( $slugs ) ? array_merge( $slugs, array( self::SLUG ) ) : $slugs;
	}
}
