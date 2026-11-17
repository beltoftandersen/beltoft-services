<?php
/**
 * The customer's activity log: the route behind "Show more" on the Overview.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Api;

use BeltoftServices\Account\OverviewActivity;

defined( 'ABSPATH' ) || exit;

/**
 * GET beltoft-services/v1/activity (read scope): what happened to the signed-in customer's own services, newest first, in
 * sentences (Account\ActivityMap is the only door from a history text to a sentence). The customer is the one who is signed
 * in; a service id of someone else is refused. Ended services are included. No MCP tool calls it: the tools read the
 * services themselves, and the log is for the dashboard and for a customer's own scripts.
 */
final class Activity {

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
			'/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'before'   => array(
						'description'       => 'Cursor: the next value of the last answer. Only older lines are returned.',
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'    => array(
						'description'       => 'Most lines.',
						'type'              => 'integer',
						'default'           => OverviewActivity::MORE,
						'minimum'           => 1,
						'maximum'           => OverviewActivity::MAX,
						'sanitize_callback' => 'absint',
					),
					'service'  => array(
						'description'       => 'Only this service (its license id, as listed in services). 0 for all.',
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET activity.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$limited = Routes::limited( $request, Routes::STATUS_RATE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$user_id  = get_current_user_id();
		$licenses = OverviewActivity::licenses_of( $user_id );
		$service  = (int) $request->get_param( 'service' );

		if ( $service > 0 && ! isset( $licenses[ $service ] ) ) {
			return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not yours.', 'beltoft-services' ), array( 'status' => 403 ) ) );
		}

		$page = OverviewActivity::page( $licenses, (int) $request->get_param( 'before' ), (int) $request->get_param( 'limit' ), $service );
		$days = array();

		foreach ( OverviewActivity::days( $page['lines'] ) as $day ) {
			$lines = array();

			foreach ( $day['lines'] as $line ) {
				$lines[] = array(
					'id'         => (int) $line['id'],
					'time'       => (string) $line['time'],
					'datetime'   => (string) $line['datetime'],
					'service_id' => (int) $line['license'],
					'service'    => (string) $line['name'],
					'url'        => (string) $line['url'],
					'text'       => (string) $line['sentence'],
					'count'      => (int) $line['count'],
				);
			}

			$days[] = array(
				'day'   => $day['day'],
				'label' => $day['label'],
				'lines' => $lines,
			);
		}

		$body = array(
			'days' => $days,
			'next' => $page['next'],
		);

		if ( 0 === (int) $request->get_param( 'before' ) && 0 === $service ) {
			$services = array();

			foreach ( $page['services'] as $id => $name ) {
				$services[] = array(
					'id'   => (int) $id,
					'name' => (string) $name,
				);
			}

			$body['services'] = $services;
		}

		$response = new \WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}
}
