<?php
/** Owner-only VPS usage route. @package BeltoftServices */
namespace BeltoftServices\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class MetricsRoute {
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/metrics', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'get' ),
			'permission_callback' => 'is_user_logged_in',
			'args' => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
				'range' => array( 'type' => 'string', 'default' => 'hour', 'enum' => array_keys( Metrics::RANGES ) ),
			),
		) );
	}

	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, false, Routes::STATUS_RATE_LIMIT );
		if ( ! $resource instanceof Resource ) {
			return $resource;
		}
		if ( 'proxmox' !== $resource->get( 'driver' ) || 'ready' !== $resource->get( 'state' ) || null === VmRecords::for_resource( $resource->id() ) ) {
			return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), array( 'status' => 403 ) ) );
		}
		$range = (string) $request['range'];
		if ( ! isset( Metrics::RANGES[ $range ] ) ) {
			return rest_convert_error_to_response( new \WP_Error( 'bad_range', __( 'Choose a valid time range.', 'beltoft-services' ), array( 'status' => 400 ) ) );
		}
		$key = 'beltoft_services_proxmox_metrics_' . $resource->id() . '_' . $range;
		$series = get_transient( $key );
		if ( ! is_array( $series ) ) {
			$rows = ( new Operations( $resource ) )->series( $range );
			$series = null === $rows ? array( 'range' => $range, 'step' => 0, 'points' => array() ) : Metrics::shape( $rows, $range );
			if ( null !== $rows ) {
				set_transient( $key, $series, MINUTE_IN_SECONDS );
			}
		}
		$series['disk'] = GuestDisk::cached( $resource );
		$series['available'] = ! empty( $series['points'] );
		$response = new \WP_REST_Response( $series, 200 );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
