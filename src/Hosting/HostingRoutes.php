<?php
/**
 * REST routes of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Api\Routes;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Under beltoft-services/v1/resources/<id>/hosting. Every route goes through Routes::guard() (logged in with the
 * wp_rest nonce, a rate limit, the license's owner), and the work is HostingActions, which checks the plan's
 * ceilings.
 *
 * - GET    hosting                         sites, domains, ceilings
 * - POST   hosting/sites                   slug, label, php, install
 * - DELETE hosting/sites/<slug>            confirm (the site name)
 * - POST   hosting/sites/<slug>/php        php, memory_mb, upload_mb
 * - POST   hosting/sites/<slug>/workers    workers
 * - POST   hosting/sites/<slug>/cache      clears the page cache
 * - POST   hosting/sites/<slug>/login      a one-time WordPress sign-in address
 * - POST   hosting/sites/<slug>/domains    host
 * - DELETE hosting/domains/<host>
 * - POST   hosting/mail                    domain: sets up Email lite
 */
final class HostingRoutes {

	/** Changes per resource and hour, so a loop cannot fill a node. */
	public const CHANGE_LIMIT = 60;

	/** Sign-in links per resource and hour. */
	public const LOGIN_LIMIT = 20;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$id   = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );
		$site = array( 'slug' => array( 'type' => 'string', 'required' => true, 'description' => 'The site name.' ) );
		$base = '/resources/(?P<id>\d+)/hosting';
		$ns   = Routes::NAMESPACE;
		$post = \WP_REST_Server::CREATABLE;

		register_rest_route( $ns, $base, array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id ) );
		register_rest_route( $ns, $base . '/sites', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'add_site' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array(
				'slug'    => array( 'type' => 'string', 'required' => true, 'description' => 'Used in the temporary address.' ),
				'label'   => array( 'type' => 'string', 'description' => 'A title.' ),
				'php'     => array( 'type' => 'string', 'description' => 'PHP version. Default: the standard one.' ),
				'install' => array( 'type' => 'boolean', 'description' => 'Install WordPress. Default true.' ),
			),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'remove_site' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array( 'confirm' => array( 'type' => 'string', 'description' => 'The site name.' ) ),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/php', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'set_php' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array(
				'php'       => array( 'type' => 'string', 'required' => true, 'description' => 'PHP version.' ),
				'memory_mb' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'memory_limit in MB, up to the plan.' ),
				'upload_mb' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Upload size in MB, up to the plan.' ),
			),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/workers', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'set_workers' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array( 'workers' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/cache', array( 'methods' => $post, 'callback' => array( __CLASS__, 'purge_cache' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id + $site ) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/login', array( 'methods' => $post, 'callback' => array( __CLASS__, 'login_link' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id + $site ) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/domains', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'add_domain' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array( 'host' => array( 'type' => 'string', 'required' => true, 'description' => 'The domain name.' ) ),
		) );
		register_rest_route( $ns, $base . '/domains/(?P<host>[^/]+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'remove_domain' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'host' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( $ns, $base . '/mail', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'enable_mail' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'domain' => array( 'type' => 'string', 'required' => true, 'description' => 'The mail domain.' ) ),
		) );
	}

	/**
	 * The guard, and the check that the resource is a hosting account.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $ready   Whether the service must accept actions.
	 * @param int              $limit   Requests a minute.
	 * @param string           $group   Hourly change group ('' for none).
	 * @param int              $hourly  Hourly limit.
	 * @return Resource|\WP_REST_Response
	 */
	private static function guard( \WP_REST_Request $request, bool $ready, int $limit, string $group = '', int $hourly = self::CHANGE_LIMIT ) {
		$r = Routes::guard( $request, $ready, $limit );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		if ( HostingDriver::ID !== $r->get( 'driver' ) ) {
			return self::error( 'not_hosting', __( 'This is not a hosting account.', 'beltoft-services' ) );
		}

		if ( '' !== $group && RateLimit::exceeded( get_current_user_id(), 'beltoft-services:hosting-' . $group . ':' . $r->id(), $hourly, HOUR_IN_SECONDS ) ) {
			return self::error( 'rate_limited', __( 'Too many changes. Please try again later.', 'beltoft-services' ) );
		}

		return $r;
	}

	/**
	 * A reply from a result or a WP_Error of HostingActions.
	 *
	 * @param array<string, mixed>|\WP_Error $result Result.
	 * @param int                            $status Status of a success.
	 */
	private static function reply( $result, int $status = 200 ): \WP_REST_Response {
		return is_wp_error( $result ) ? self::error( $result->get_error_code(), $result->get_error_message() ) : self::ok( $result, $status );
	}

	/**
	 * @param string $code    Short code.
	 * @param string $message Words for the customer.
	 */
	private static function error( string $code, string $message ): \WP_REST_Response {
		$status = array(
			'invalid'      => 422,
			'limit'        => 409,
			'exists'       => 409,
			'busy'         => 409,
			'not_ready'    => 409,
			'not_hosting'  => 409,
			'not_found'    => 404,
			'failed'       => 502,
			'rate_limited' => 429,
		)[ $code ] ?? 400;

		$response = rest_convert_error_to_response( new \WP_Error( 'beltoft_services_hosting_' . $code, $message, array( 'status' => $status ) ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A reply.
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param int                  $status Status.
	 */
	private static function ok( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	public static function overview( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, false, Routes::STATUS_RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$actions = new HostingActions( $r );

		return self::ok(
			array(
				'sites'  => $actions->sites(),
				'limits' => $actions->limits(),
				'point_at' => SiteDns::instructions( (array) ( Nodes::get( (string) ( Records::for_resource( $r->id() )['node'] ?? '' ) ) ?? array() ) ),
			)
		);
	}

	/**
	 * Runs one change: guard, hourly limit, the action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param callable         $do      fn( HostingActions ): array|\WP_Error.
	 * @param int              $status  Status of a success.
	 */
	private static function change( \WP_REST_Request $request, callable $do, int $status = 200 ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'change' );

		return $r instanceof Resource ? self::reply( $do( new HostingActions( $r ) ), $status ) : $r;
	}

	public static function add_site( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change(
			$request,
			static fn( HostingActions $a ) => $a->add_site( (string) $request['slug'], (string) $request['label'], (string) $request['php'], null === $request['install'] || (bool) $request['install'] ),
			201
		);
	}

	public static function remove_site( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->remove_site( (string) $request['slug'], (string) $request['confirm'] ) );
	}

	public static function set_php( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change(
			$request,
			static fn( HostingActions $a ) => $a->set_php( (string) $request['slug'], (string) $request['php'], null === $request['memory_mb'] ? null : (int) $request['memory_mb'], null === $request['upload_mb'] ? null : (int) $request['upload_mb'] )
		);
	}

	public static function set_workers( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->set_workers( (string) $request['slug'], (int) $request['workers'] ) );
	}

	public static function purge_cache( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->purge_cache( (string) $request['slug'] ) );
	}

	public static function add_domain( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->add_domain( (string) $request['slug'], (string) $request['host'] ) );
	}

	public static function remove_domain( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->remove_domain( rawurldecode( (string) $request['host'] ) ) );
	}

	public static function enable_mail( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->enable_mail( (string) $request['domain'] ), 201 );
	}

	public static function login_link( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'login', self::LOGIN_LIMIT );

		return $r instanceof Resource ? self::reply( ( new HostingActions( $r ) )->login_link( (string) $request['slug'] ) ) : $r;
	}
}
