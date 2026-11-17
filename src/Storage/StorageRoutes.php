<?php
/**
 * REST routes of a storage box: its facts, the password behind the emailed code, a new password and a rollback.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;
use BeltoftServices\StepUp;

defined( 'ABSPATH' ) || exit;

/**
 * Under beltoft-services/v1/resources/<id>/ . Every route goes through Routes::guard() (login, a rate limit, the owner).
 * They are dashboard only: the token gate lists no address of this feature, so an API token or a connected app reaches none.
 *
 * - GET  storage                  the connection facts, the usage and the snapshots. Never the password.
 * - POST storage-password         the password, only with the code emailed to the account.
 * - POST storage-password-reset   a new password (the code is needed); the job makes it, and it is shown through the route above.
 * - POST storage-rollback         puts the box back to a snapshot (the code is needed): newer snapshots and later changes are lost.
 */
final class StorageRoutes {

	/** Requests a minute for the routes that change or show a secret. */
	public const SECRET_LIMIT = 5;

	public const PURPOSE_PASSWORD = 'storage_password';
	public const PURPOSE_RESTORE  = 'storage_restore';

	/** Hooks. */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'beltoft_services_stepup_purposes', array( __CLASS__, 'stepup_purposes' ) );
		add_filter( 'beltoft_services_stepup_text', array( __CLASS__, 'stepup_text' ), 10, 2 );
		add_filter( 'beltoft_services_action_refusal', array( StorageActions::class, 'refusal' ), 10, 4 );
	}

	/**
	 * @param mixed $purposes Purposes so far.
	 * @return array<int, string>
	 */
	public static function stepup_purposes( $purposes ): array {
		return array_merge( (array) $purposes, array( self::PURPOSE_PASSWORD, self::PURPOSE_RESTORE ) );
	}

	/**
	 * @param mixed $text    Text so far.
	 * @param mixed $purpose Purpose.
	 */
	public static function stepup_text( $text, $purpose ): string {
		if ( self::PURPOSE_PASSWORD === $purpose ) {
			return __( 'showing or changing the password of your backup storage', 'beltoft-services' );
		}

		return self::PURPOSE_RESTORE === $purpose ? __( 'restoring your backup storage to a snapshot', 'beltoft-services' ) : (string) $text;
	}

	/** Registers the routes. */
	public static function routes(): void {
		$id = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );

		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/storage', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'facts' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id,
		) );
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/storage-password', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'password' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id,
		) );
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/storage-password-reset', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reset' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id,
		) );
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/storage-rollback', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rollback' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'snapshot' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[0-9]{1,20}$', 'description' => 'Id of the snapshot to go back to.' ) ),
		) );
	}

	/**
	 * Guard and driver check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $ready   Whether the service must accept actions.
	 * @param int              $limit   Requests a minute.
	 * @return Resource|\WP_REST_Response
	 */
	private static function guard( \WP_REST_Request $request, bool $ready, int $limit ) {
		$resource = Routes::guard( $request, $ready, $limit );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( StorageDriver::ID !== $resource->get( 'driver' ) ) {
			return self::error( 'not_storage', __( 'This is not a backup storage.', 'beltoft-services' ), 404 );
		}

		return $resource;
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
	 * The emailed code check, with the code used up: a refusal response, or null when it was right.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $purpose Purpose.
	 */
	private static function stepped( \WP_REST_Request $request, string $purpose ): ?\WP_REST_Response {
		$refused = StepUp::demand( $request, $purpose );

		if ( ! $refused && ! StepUp::consume( get_current_user_id(), $purpose ) ) {
			$refused = new \WP_Error( 'step_up', StepUp::refusal( get_current_user_id(), $purpose ), array( 'status' => 403 ) );
		}

		if ( ! $refused ) {
			return null;
		}

		$response = rest_convert_error_to_response( $refused );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The facts of the box.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function facts( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, false, 20 );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		Boxes::refresh( $resource );
		$resource  = Resource::find( $resource->id() ) ?? $resource;
		$snapshots = '' !== Boxes::id( $resource ) ? StorageClients::for()->snapshots( Boxes::id( $resource ) ) : array();
		$facts     = Boxes::connection( $resource );
		unset( $facts['tls_host'] );

		$response = new \WP_REST_Response(
			array(
				'state'      => (string) $resource->get( 'state' ),
				'connection' => $facts,
				'snapshots'  => is_array( $snapshots ) ? array_map( static fn( $s ) => array( 'id' => $s['id'], 'name' => $s['name'], 'created' => $s['created'], 'automatic' => $s['automatic'] ), $snapshots ) : array(),
			),
			200
		);
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * The password, once the code is right.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function password( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, false, self::SECRET_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( null === Boxes::password( $resource ) ) {
			return self::error( 'storage_no_password', __( 'There is no password yet.', 'beltoft-services' ), 409 );
		}

		$stop = self::stepped( $request, self::PURPOSE_PASSWORD );

		if ( $stop ) {
			return $stop;
		}

		$response = new \WP_REST_Response( array( 'password' => Boxes::password( $resource ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A new password.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function reset( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, true, self::SECRET_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( 'ready' !== (string) $resource->get( 'state' ) || '' === Boxes::id( $resource ) ) {
			return self::error( 'storage_not_ready', __( 'Your storage is not ready.', 'beltoft-services' ), 409 );
		}

		$stop = self::stepped( $request, self::PURPOSE_PASSWORD );

		if ( $stop ) {
			return $stop;
		}

		$started = \BeltoftServices\Jobs::start_action( $resource, StorageActions::PASSWORD_RESET, __( 'Change the password', 'beltoft-services' ) );

		return is_wp_error( $started ) ? Routes::not_queued( $started ) : Routes::queued( $resource, StorageActions::PASSWORD_RESET );
	}

	/**
	 * Back to a snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rollback( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, true, self::SECRET_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$snapshot = (string) $request['snapshot'];

		if ( 'ready' !== (string) $resource->get( 'state' ) || '' === Boxes::id( $resource ) || 1 !== preg_match( '/^\d{1,20}$/D', $snapshot ) ) {
			return self::error( 'storage_not_ready', __( 'Your storage is not ready.', 'beltoft-services' ), 409 );
		}

		$stop = self::stepped( $request, self::PURPOSE_RESTORE );

		if ( $stop ) {
			return $stop;
		}

		$started = \BeltoftServices\Jobs::start_action( $resource, StorageActions::ROLLBACK, __( 'Restore a snapshot', 'beltoft-services' ), array( 'snapshot' => $snapshot ) );

		return is_wp_error( $started ) ? Routes::not_queued( $started ) : Routes::queued( $resource, StorageActions::ROLLBACK );
	}
}
