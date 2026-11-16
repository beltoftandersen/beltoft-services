<?php
/**
 * The static names of the Domains tab of My Account, kept while their callers move to the area (Account\DomainArea).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A thin facade: every method is one line to the area. It goes when the last caller has moved.
 */
final class Domains {

	/**
	 * The tabs of a domain page after the overview, by address part.
	 */
	public const TABS = DomainArea::TABS;

	/**
	 * The value after the endpoint that is the add page.
	 */
	public const ADD_VALUE = DomainArea::ADD_VALUE;

	/**
	 * The area.
	 */
	private static function area(): DomainArea {
		$area = ServiceArea::get( DomainArea::class );

		return $area instanceof DomainArea ? $area : new DomainArea();
	}

	/**
	 * The tab addresses a domain page accepts.
	 *
	 * @return array<int, string>
	 */
	public static function tabs(): array {
		return self::area()->tabs();
	}

	/**
	 * The endpoint the tab lives on.
	 */
	public static function slug(): string {
		return self::area()->slug();
	}

	/**
	 * The address of a domain's page or of one of its tabs.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $tab     Tab, or '' for the overview.
	 */
	public static function url( array $license, string $tab = '' ): string {
		return self::area()->url( $license, $tab );
	}

	/**
	 * The page of the domain an order is for, else the list.
	 *
	 * @param int      $order_id Order id.
	 * @param string[] $names    Domain names on the order.
	 */
	public static function url_for_order( int $order_id, array $names ): string {
		return self::area()->url_for_order( $order_id, $names );
	}

	/**
	 * The address of the add page.
	 */
	public static function url_add(): string {
		return self::area()->url_add();
	}

	/**
	 * The address of the list.
	 */
	public static function url_list(): string {
		return self::area()->url_list();
	}

	/**
	 * The site's page for registering a new domain, or ''.
	 */
	public static function url_register(): string {
		return self::area()->url_register();
	}

	/**
	 * The domain page being printed, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function page(): ?array {
		$page = ServiceArea::page();

		return null !== $page && ( $page['area'] ?? null ) instanceof DomainArea ? $page : null;
	}

	/**
	 * What the address after the endpoint says.
	 *
	 * @return array{id: int, tab: string, add: bool, path: array<int, string>}
	 */
	public static function requested(): array {
		return self::area()->requested();
	}

	/**
	 * Is the license a domain or DNS hosting service?
	 *
	 * @param mixed $license License row.
	 */
	public static function is_domain( $license ): bool {
		return self::area()->is_member( $license );
	}

	/**
	 * Prints the Domains tab for the licenses the customer has on it.
	 *
	 * @param array<int, array<string, mixed>> $views License views.
	 * @param string                           $empty Text for an empty tab.
	 */
	public static function render( array $views, string $empty = '' ): void {
		self::area()->render( $views, $empty );
	}

	/**
	 * The rows of the list.
	 *
	 * @param array<int, array<string, mixed>> $views     Domain views by license id.
	 * @param array<int, Resource>             $resources Their resources by license id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( array $views, array $resources ): array {
		return self::area()->rows( $views, $resources );
	}

	/**
	 * Rows for the customer's open DNS hosting claims.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array<string, string>>
	 */
	public static function claim_rows( int $user_id ): array {
		return self::area()->claim_rows( $user_id );
	}

	/**
	 * The header buttons.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	public static function buttons(): array {
		return self::area()->buttons();
	}

	/**
	 * A template of this plugin, or the theme's copy of it.
	 *
	 * @param string $name File name in templates/account.
	 */
	public static function file( string $name ): string {
		return ServiceArea::file( $name );
	}
}
