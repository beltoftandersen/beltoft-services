<?php
/**
 * The customer's own services: one list for the REST route and the MCP tool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Api;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\Servers;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * GET beltoft-services/v1/resources (read scope). The ownership rule is the one of Routes::guard(): the license's
 * user_id is the customer (an email match on a guest license is not enough). Nothing secret is in the list.
 */
final class Services {

	/**
	 * Most services listed.
	 */
	public const MAX = 100;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The route.
	 */
	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/resources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * GET resources.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$limited = Routes::limited( $request, Routes::STATUS_RATE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$response = new \WP_REST_Response( array( 'resources' => self::for_user( get_current_user_id() ) ), 200 );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * A customer's services.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array{id: int, type: string, name: string, state: string, status: string, renews: string, ip: string}>
	 */
	public static function for_user( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( Endpoint::class ) || Settings::get( 'pause_all' ) ) {
			return array();
		}

		$out = array();

		foreach ( array_slice( Endpoint::licenses_for_user( $user_id ), 0, self::MAX ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || 'deleted' === $resource->get( 'state' ) || $resource->folded_into() > 0 ) {
				continue;
			}

			$driver = Registry::get( (string) $resource->get( 'driver' ) );
			$state  = (string) $resource->get( 'state' );
			$name   = (string) apply_filters( 'beltoft_licenses_account_title', View::product_name( $license ), $license );

			$out[] = array(
				'id'     => $resource->id(),
				'type'   => $driver ? $driver->kind() : '',
				'name'   => $name,
				'state'  => $state,
				'status' => Panel::label( $state, $resource ),
				'renews' => Servers::renewal( $license ),
				'ip'     => 'vm' === ( $driver ? $driver->kind() : '' ) && $resource->shows_status() ? (string) $resource->get( 'ip' ) : '',
			);
		}

		return $out;
	}
}
