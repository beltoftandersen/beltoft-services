<?php
/**
 * Addresses and the current page of the Servers tab: a neutral helper the Account screens and the Proxmox tabs share,
 * so neither side has to import the other's internals.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint slug, server and tab addresses, the New server marker, the request's license id and tab, the renewal
 * sentence, and the server page being printed (set by Servers).
 */
final class ServerLinks {

	/**
	 * The tabs of a server page after the overview, by address part.
	 */
	public const TABS = array( 'snapshots', 'backups', 'disks', 'access', 'reinstall' );

	/**
	 * The value after the endpoint that is the New server page (the picker).
	 */
	public const NEW_VALUE = 'new';

	/**
	 * The page being printed (license row, requested tab), or null outside a server page.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $page = null;

	/**
	 * Sets the page being printed (Servers does, around the server page), or clears it.
	 *
	 * @param array<string, mixed>|null $page License, tab and resource.
	 */
	public static function set_page( ?array $page ): void {
		self::$page = $page;
	}

	/**
	 * The endpoint the tab lives on.
	 */
	public static function slug(): string {
		/**
		 * Filters the My Account endpoint of the Servers tab (the slug of the tab declared by Tabs).
		 *
		 * @param string $slug Endpoint, default vps.
		 */
		$slug = sanitize_key( (string) apply_filters( 'beltoft_services_servers_slug', 'vps' ) );

		return '' !== $slug ? $slug : 'vps';
	}

	/**
	 * The address of a server's page or of one of its tabs.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $tab     Tab, or '' for the overview.
	 */
	public static function url( array $license, string $tab = '' ): string {
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		$value = Slugs::value( $license, in_array( $tab, self::tabs(), true ) ? $tab : '' );

		return wc_get_endpoint_url( self::slug(), $value, wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * The address of the New server page (the picker).
	 *
	 * @param int $plan Variation id to start with, 0 for none.
	 */
	public static function url_new( int $plan = 0 ): string {
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		$url = wc_get_endpoint_url( self::slug(), self::NEW_VALUE, wc_get_page_permalink( 'myaccount' ) );

		return $plan > 0 ? add_query_arg( 'plan', $plan, $url ) : $url;
	}

	/**
	 * Is the address the New server page (the value after the endpoint is "new")?
	 */
	public static function is_new(): bool {
		global $wp;

		$value = is_object( $wp ) && isset( $wp->query_vars[ self::slug() ] ) ? (string) $wp->query_vars[ self::slug() ] : '';
		$parts = explode( '/', trim( sanitize_text_field( $value ), '/' ) );

		return self::NEW_VALUE === $parts[0];
	}

	/**
	 * The server page being printed, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function page(): ?array {
		return self::$page;
	}

	/**
	 * What the address after the endpoint says: the license id (from the server's slug, or the number itself) and the tab.
	 *
	 * @return array{id: int, tab: string}
	 */
	public static function requested(): array {
		$asked = Slugs::parse( self::slug(), 'servers' );

		return array(
			'id'  => $asked['id'],
			'tab' => in_array( $asked['second'], self::tabs(), true ) ? $asked['second'] : '',
		);
	}

	/**
	 * The renewal sentence of a license ("Renews 29/11/2026", "Ends 29/11/2026"), or '' when nothing is known.
	 * From the subscription's own dates; one object load.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function renewal( array $license ): string {
		$id = (int) ( $license['subscription_id'] ?? 0 );

		if ( $id <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$subscription = wcs_get_subscription( $id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		$format = get_option( 'date_format' );

		if ( $subscription->has_status( 'pending-cancel' ) ) {
			$end = (int) $subscription->get_time( 'end', 'site' );

			/* translators: %s: date the subscription ends */
			return $end > 0 ? sprintf( __( 'Ends %s', 'beltoft-services' ), date_i18n( $format, $end ) ) : '';
		}

		$next = (int) $subscription->get_time( 'next_payment', 'site' );

		/* translators: %s: date of the next payment */
		return $next > 0 && $subscription->has_status( 'active' ) ? sprintf( __( 'Renews %s', 'beltoft-services' ), date_i18n( $format, $next ) ) : '';
	}

	/**
	 * The tab addresses a server page accepts: the built-in ones, plus any an extension adds.
	 *
	 * @return array<int, string>
	 */
	public static function tabs(): array {
		/**
		 * Filters the tab addresses of a server page. An extension that adds a tab (see the filter
		 * beltoft_services_server_tabs) adds its slug here so the tab has its own address.
		 *
		 * @param array<int, string> $slugs Slugs after the overview.
		 */
		$slugs = apply_filters( 'beltoft_services_server_page_tabs', self::TABS );

		return array_values( array_filter( is_array( $slugs ) ? $slugs : self::TABS, 'is_string' ) );
	}
}
