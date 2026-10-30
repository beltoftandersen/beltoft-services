<?php
/**
 * The customer's route to the allow-list of a managed database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Jobs;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * GET and POST beltoft-services/v1/resources/<id>/db-access. The checks are Routes::guard() (10 requests a minute, login,
 * the license is the customer's, the resource accepts actions), the same answer for another customer's database, a
 * missing id and a paused one. POST validates one change (DbAccess::change), stores it with a new revision under the
 * resource lock and queues one internal job, which brings the firewall and the guest to the stored list. The reply
 * carries no secret and is never cached.
 */
final class DbAccessRoute {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function routes(): void {
		$args = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );

		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/db-access',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => $args,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * An error reply.
	 *
	 * @param string $code    Code.
	 * @param string $message Message for the customer.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, $message, array( 'status' => $status ) ) );
	}

	/**
	 * A reply nobody caches.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private static function quiet( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * GET: the state of the list, for the page that waits for a change.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, false, DbAccess::RATE_LIMIT * 6 );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( ! DbAccess::is_db( $resource ) ) {
			return self::error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), 403 );
		}

		$working = Jobs::acting( $resource->id() );

		// A customer waiting for the change should not wait for the next cron tick.
		if ( $working ) {
			Jobs::run_waiting( $resource->id() );
			$resource = Resource::find( $resource->id() ) ?? $resource;
			$working  = Jobs::acting( $resource->id() );
		}

		$state = (string) $resource->get( DbAccess::STATE );

		// A change that no job is working on any more and that was not confirmed did not finish.
		if ( 'applying' === $state && ! $working ) {
			$state = 'failed';
		}

		return self::quiet( new \WP_REST_Response( array( 'state' => $state, 'working' => $working ), 200 ) );
	}

	/**
	 * POST: one change. Body {op: add|remove|world_on|world_off, cidr, label, confirm}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function post( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, true, DbAccess::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( ! DbAccess::editable( $resource ) ) {
			return self::error( 'beltoft_services_forbidden', __( 'The access list can be changed when the database is ready and has a public IP address.', 'beltoft-services' ), 403 );
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$meta = null;
		$why  = null;
		$op   = (string) ( $body['op'] ?? '' );

		$stored = $resource->locked(
			static function () use ( $resource, $body, &$meta, &$why ): bool {
				// Read again under the lock: a change that was stored a moment ago counts.
				$fresh = Resource::find( $resource->id() ) ?? $resource;
				$meta  = DbAccess::change( $fresh, $body );

				if ( $meta instanceof \WP_Error ) {
					$why = $meta;

					return false;
				}

				return $resource->set( $meta );
			}
		);

		if ( null === $stored ) {
			return self::error( 'beltoft_services_busy', __( 'Your database is busy with another change. Please try again in a minute.', 'beltoft-services' ), 409 );
		}

		if ( $why instanceof \WP_Error ) {
			$data   = $why->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 422;

			return self::error( $why->get_error_code(), $why->get_error_message(), $status );
		}

		if ( ! $stored ) {
			return self::error( 'beltoft_services_failed', __( 'This was not done. Your database was not changed.', 'beltoft-services' ), 500 );
		}

		Progress::mark( $resource, DbAccess::APPLY, __( 'Apply access changes', 'beltoft-services' ) );
		Jobs::queue_act( $resource->id(), DbAccess::APPLY, array( 'rev' => (string) ( is_array( $meta ) ? $meta[ DbAccess::REV ] : 0 ) ) );

		if ( in_array( $op, array( 'world_on', 'add', 'remove' ), true ) ) {
			DbAccess::notify( $resource, $op );
		}

		return self::quiet( new \WP_REST_Response( array( 'queued' => true ), 202 ) );
	}
}
