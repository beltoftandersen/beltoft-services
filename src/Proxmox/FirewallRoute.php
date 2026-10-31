<?php
/**
 * The customer's firewall route: read the state, send the wanted state.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Jobs;
use BeltoftServices\Mcp\Endpoint;
use BeltoftServices\Progress;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * GET and PUT beltoft-services/v1/resources/<id>/firewall. The checks are Routes::guard() (rate limit, login, the
 * license is the customer's, the server shows its status or accepts actions), the same answer for another
 * customer's server, a missing id and a paused one. The rule list does not fit the scalar arguments of the generic
 * action route, so PUT has its own: it validates the whole wanted state, stores it with a new revision and queues
 * one fw_apply job, which reconciles Proxmox to it. The reply never carries fw_error.
 */
final class FirewallRoute {

	/**
	 * Seconds a live read of an unmanaged server is kept; a failed read is kept shorter.
	 */
	public const LIVE_TTL = MINUTE_IN_SECONDS;

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
		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/firewall',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'put' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
				),
			)
		);
	}

	/**
	 * The same refusal for everything that is not the customer's to change.
	 */
	private static function forbidden(): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), array( 'status' => 403 ) ) );
	}

	/**
	 * The address the customer is using (IPv4 only), or ''. In a call from a connected app the visitor is the app's
	 * server, not the customer, so only an address the call names (body my_ip) counts there.
	 *
	 * @param array<string, mixed> $body Request body.
	 */
	private static function my_ip( array $body = array() ): string {
		$ip = Endpoint::inside() ? ( is_string( $body['my_ip'] ?? null ) ? trim( $body['my_ip'] ) : '' ) : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '' );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '';
	}

	/**
	 * GET: what the tab shows. A server the dashboard does not manage yet also says what Proxmox shows now (kept a
	 * minute, a failed read 20 seconds, so polling does not reach the cluster on every request).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, false, Routes::STATUS_RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( ! Firewall::editable( $resource ) ) {
			return self::forbidden();
		}

		$view            = Firewall::view( $resource, self::my_ip() );
		$view['working'] = Jobs::acting( $resource->id() );

		// A customer waiting for the change should not wait for the next cron tick.
		if ( $view['working'] ) {
			Jobs::run_waiting( $resource->id() );
			$view['working'] = Jobs::acting( $resource->id() );
			$resource        = Resource::find( $resource->id() ) ?? $resource;
			$view            = array_merge( Firewall::view( $resource, self::my_ip() ), array( 'working' => $view['working'] ) );
		}

		// A change that no job is working on any more and that was not confirmed did not finish.
		if ( 'applying' === $view['state'] && ! $view['working'] ) {
			$view['state'] = 'failed';
		}

		$view['live'] = null;

		if ( ! $view['managed'] ) {
			$key  = 'beltoft_services_fw_live_' . $resource->id();
			$live = get_transient( $key );

			if ( ! is_string( $live ) ) {
				$read = ( new Firewall( $resource ) )->live();
				$live = null === $read ? 'error' : $read;
				set_transient( $key, $live, 'error' === $live ? 20 : self::LIVE_TTL );
			}

			$view['live'] = 'error' === $live ? null : $live;
		}

		$view['last_result'] = is_array( $resource->get( Jobs::RESULT ) ) ? $resource->get( Jobs::RESULT ) : null;
		$view['now']         = time();

		$response = new \WP_REST_Response( $view, 200 );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * An error reply.
	 *
	 * @param string               $code    Code.
	 * @param string               $message Message for the customer.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    More data (the position of a refused rule).
	 */
	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) ) );
	}

	/**
	 * PUT: the wanted state. Body {on, rules[], ack_no_ssh, confirm_off}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function put( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, true, Routes::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( ! Firewall::editable( $resource ) ) {
			return self::forbidden();
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$on   = ! empty( $body['on'] );
		$ack  = ! empty( $body['ack_no_ssh'] );

		// Turning a firewall that is on off lets every port be reached: say so and ask once.
		if ( ! $on && Firewall::managed( $resource ) && true === $resource->get( Firewall::ON ) && empty( $body['confirm_off'] ) ) {
			return self::error( 'beltoft_services_confirm', __( 'Please confirm that you want to turn the firewall off.', 'beltoft-services' ), 422 );
		}

		$rules = Firewall::normalise( $body['rules'] ?? null, $resource );

		if ( is_wp_error( $rules ) ) {
			$data = $rules->get_error_data();

			return self::error( $rules->get_error_code(), $rules->get_error_message(), 422, is_array( $data ) && isset( $data['rule'] ) ? array( 'rule' => (int) $data['rule'] ) : array() );
		}

		if ( $on && ! $ack && Firewall::blocks_ssh( $rules, self::my_ip( $body ) ) ) {
			return self::error( 'beltoft_services_ssh_closed', __( 'No rule lets you in over SSH from where you are now. Add an SSH rule, or tick the box to confirm that you do not need SSH.', 'beltoft-services' ), 409 );
		}

		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:vm-action:' . $resource->id() . ':fw_apply', 20, HOUR_IN_SECONDS ) ) {
			return self::error( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a while and try again.', 'beltoft-services' ), 429 );
		}

		$rev   = 0;
		$stored = $resource->locked(
			static function () use ( $resource, $on, $rules, $ack, &$rev ): bool {
				$rev = Firewall::store( $resource, $on, $rules, $ack, get_current_user_id() );

				return $rev > 0;
			}
		);

		if ( null === $stored ) {
			return self::error( 'beltoft_services_busy', __( 'Your server is busy with another change. Please try again in a minute.', 'beltoft-services' ), 409 );
		}

		if ( ! $stored ) {
			return self::error( 'beltoft_services_failed', __( 'This was not done. Your server was not changed.', 'beltoft-services' ), 500 );
		}

		delete_transient( 'beltoft_services_fw_live_' . $resource->id() );
		Progress::mark( $resource, 'fw_apply', __( 'Apply firewall changes', 'beltoft-services' ) );
		Jobs::queue_act( $resource->id(), 'fw_apply', array( 'rev' => $rev ) );

		$response = new \WP_REST_Response( array( 'queued' => true, 'rev' => $rev ), 202 );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}
}
