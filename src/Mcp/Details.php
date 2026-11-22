<?php
/**
 * What the read tools say about one service, built from the classes the account pages use.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Account\Panel;
use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every value here is picked by name from what those classes return: their views also carry links with nonces,
 * the customer's own address and button state, which must never reach a result. What belongs to a kind of service (a
 * server, a database, a domain) is added by the feature that owns it, through the filter beltoft_services_mcp_service_details,
 * and the address of its page through beltoft_services_mcp_service_page.
 */
final class Details {

	/**
	 * Everything service_details says about one service.
	 *
	 * @param Resource $r Service, already checked to be the customer's.
	 * @return array<string, mixed>
	 */
	public static function of( Resource $r ): array {
		$license = $r->license();
		$driver  = Registry::get( (string) $r->get( 'driver' ) );
		$kind    = $driver ? $driver->kind() : '';
		$state   = (string) $r->get( 'state' );
		$offered = $driver ? array_values( array_map( 'strval', $driver->customer_actions( $r ) ) ) : array();

		$out = array(
			'id'                  => $r->id(),
			'license_id'          => (int) ( $license['id'] ?? 0 ),
			'type'                => $kind,
			'name'                => (string) apply_filters( 'beltoft_licenses_account_title', \BeltoftLicenses\Account\View::product_name( $license ), $license ),
			'state'               => $state,
			'status'              => Panel::label( $state, $r ),
			'renews'              => ServerLinks::renewal( $license ),
			'subscription'        => Reads::subscription_summary( (int) ( $license['subscription_id'] ?? 0 ) ),
			'dashboard_offers'    => $offered,
			'dashboard_page'      => self::page( $r, $kind, $license ),
		);

		// What belongs to the kind of service: the feature that owns the kind answers (a server, a database, a domain).
		$extra = apply_filters( 'beltoft_services_mcp_service_details', array(), $r, $kind );

		return is_array( $extra ) ? $out + $extra : $out;
	}

	/**
	 * The plain address of the service's page in the dashboard (no login, no nonce), or ''.
	 *
	 * @param Resource             $r       Service.
	 * @param string               $kind    Driver kind.
	 * @param array<string, mixed> $license License.
	 * @param string               $tab     A tab of the page, or ''.
	 */
	public static function page( Resource $r, string $kind, array $license, string $tab = '' ): string {
		return (string) apply_filters( 'beltoft_services_mcp_service_page', '', $r, $kind, $license, $tab );
	}
}
