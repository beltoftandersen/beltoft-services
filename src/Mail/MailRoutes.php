<?php
/**
 * REST routes of an email domain: mailboxes, aliases, the DNS check and the one-time password reveal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Api\Routes;
use BeltoftServices\Proxmox\RevealRoute;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Under beltoft-services/v1/resources/<id>/ . Every route goes through Routes::guard() like the other customer routes
 * (logged in with the wp_rest nonce, a rate limit, the license's owner), and the work is done by Mailboxes, Aliases and
 * MailDns, which check that the domain is ready and keep every name inside it.
 *
 * - GET    mail                              the whole picture (MailView)
 * - POST   mail/mailboxes                    local, name
 * - DELETE mail/mailboxes/<email>            confirm (the word DELETE)
 * - POST   mail/mailboxes/<email>/password   a new password, kept for one reveal
 * - POST   mail/aliases                      address (the part before the @), goto
 * - DELETE mail/aliases/<address>
 * - POST   mail/dns/check                    reads public DNS now
 * - POST   mail-reveal                       email: the password, once (never reachable with an API token)
 *
 * A password is in no reply except the one of mail-reveal, which is never cached.
 */
final class MailRoutes {

	/** The word typed to delete a mailbox. */
	public const CONFIRM_DELETE = 'DELETE';

	/** Requests per user and window for the reveal. */
	public const REVEAL_LIMIT = 5;

	/** Mailbox and alias changes per resource and hour, so a loop cannot fill a mail server. */
	public const CHANGE_LIMIT = 60;

	/** DNS checks per resource and hour. */
	public const CHECK_LIMIT = 20;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$id    = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );
		$base  = '/resources/(?P<id>\d+)/mail';
		$email = array( 'email' => array( 'type' => 'string', 'required' => true, 'description' => 'The mailbox address.' ) );
		$ns    = Routes::NAMESPACE;

		register_rest_route( $ns, $base, array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'overview' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id,
		) );
		register_rest_route( $ns, $base . '/mailboxes', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_mailbox' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array(
				'local' => array( 'type' => 'string', 'required' => true, 'description' => 'The part before the @.' ),
				'name'  => array( 'type' => 'string', 'description' => 'The name shown for the mailbox.' ),
				'quota_mb' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The size in MB, up to the size of the plan. Default: the size of the plan.' ),
			),
		) );
		register_rest_route( $ns, $base . '/mailboxes/(?P<email>[^/]+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_mailbox' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $email + array( 'confirm' => array( 'type' => 'string', 'description' => 'The word DELETE.' ) ),
		) );
		register_rest_route( $ns, $base . '/mailboxes/(?P<email>[^/]+)/password', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reset_password' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $email,
		) );
		register_rest_route( $ns, $base . '/aliases', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_alias' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array(
				'address' => array( 'type' => 'string', 'required' => true, 'description' => 'The part before the @.' ),
				'goto'    => array( 'description' => 'Where the mail goes: a list of addresses, or addresses separated by commas or lines.' ),
			),
		) );
		register_rest_route( $ns, $base . '/aliases/(?P<address>[^/]+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_alias' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'address' => array( 'type' => 'string', 'required' => true, 'description' => 'The alias address.' ) ),
		) );
		register_rest_route( $ns, $base . '/dns/check', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'check_dns' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id,
		) );
		register_rest_route( $ns, '/resources/(?P<id>\d+)/mail-reveal', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reveal' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $email,
		) );
	}

	/**
	 * The guard of a route, and the check that the resource is an email domain. Mail routes that change something need
	 * a ready service; the overview only a visible one.
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

		if ( MailDriver::ID !== $resource->get( 'driver' ) ) {
			return self::error( 'not_mail', __( 'This is not an email service.', 'beltoft-services' ) );
		}

		return $resource;
	}

	/**
	 * An error reply from a WP_Error of Mailboxes, Aliases or MailGuard.
	 *
	 * @param \WP_Error $e Error.
	 */
	private static function failure( \WP_Error $e ): \WP_REST_Response {
		return self::error( $e->get_error_code(), $e->get_error_message() );
	}

	/**
	 * @param string $code    Short code of an error of this feature.
	 * @param string $message Words for the customer.
	 */
	private static function error( string $code, string $message ): \WP_REST_Response {
		$status = array(
			'invalid'     => 422,
			'external'    => 422,
			'confirm'     => 422,
			'limit'       => 409,
			'exists'      => 409,
			'busy'        => 409,
			'not_ready'   => 409,
			'not_mail'    => 409,
			'not_found'   => 404,
			'no_password' => 409,
			'unavailable' => 503,
			'failed'      => 502,
			'rate_limited' => 429,
		)[ $code ] ?? 400;

		$response = rest_convert_error_to_response( new \WP_Error( 'beltoft_services_mail_' . $code, $message, array( 'status' => $status ) ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Counts the changes of one email domain (apart from the per-route limits). A filter cannot turn it off.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $what  Group of changes.
	 * @param int      $limit Per hour.
	 */
	private static function too_many( Resource $r, string $what, int $limit ): ?\WP_REST_Response {
		return RateLimit::exceeded( get_current_user_id(), 'beltoft-services:mail-' . $what . ':' . $r->id(), $limit, HOUR_IN_SECONDS )
			? self::error( 'rate_limited', __( 'Too many changes. Please try again later.', 'beltoft-services' ) )
			: null;
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

		return $r instanceof Resource ? self::ok( MailView::data( $r ) ) : $r;
	}

	public static function create_mailbox( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$limited = self::too_many( $r, 'change', self::CHANGE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$made = Mailboxes::create( $r, $request->get_param( 'local' ), is_string( $request->get_param( 'name' ) ) ? $request->get_param( 'name' ) : '', (int) $request->get_param( 'quota_mb' ) );

		if ( is_wp_error( $made ) ) {
			return self::failure( $made );
		}

		return self::ok(
			array(
				'email'    => $made['email'],
				'quota_mb' => $made['quota_mb'],
				'reveal'   => true,
				'message'  => __( 'The mailbox is ready. Show the password once to set up your mail program.', 'beltoft-services' ),
			),
			201
		);
	}

	public static function delete_mailbox( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		if ( self::CONFIRM_DELETE !== (string) $request->get_param( 'confirm' ) ) {
			/* translators: %s: the word to type, DELETE. */
			return self::error( 'confirm', sprintf( __( 'Type %s to confirm. Every message in the mailbox is deleted for good.', 'beltoft-services' ), self::CONFIRM_DELETE ) );
		}

		$limited = self::too_many( $r, 'change', self::CHANGE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$email = rawurldecode( (string) $request['email'] );
		$gone  = Mailboxes::delete( $r, $email );

		return is_wp_error( $gone ) ? self::failure( $gone ) : self::ok( array( 'deleted' => strtolower( $email ) ) );
	}

	public static function reset_password( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$limited = self::too_many( $r, 'password', self::CHANGE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$email = rawurldecode( (string) $request['email'] );
		$done  = Mailboxes::reset_password( $r, $email );

		return is_wp_error( $done ) ? self::failure( $done ) : self::ok(
			array(
				'email'    => strtolower( $email ),
				'reveal'   => true,
				'message'  => __( 'The old password no longer works. Show the new password once.', 'beltoft-services' ),
			)
		);
	}

	public static function create_alias( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$limited = self::too_many( $r, 'change', self::CHANGE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$goto = $request->get_param( 'goto' );
		$made = Aliases::create( $r, $request->get_param( 'address' ), is_array( $goto ) || is_string( $goto ) ? $goto : '' );

		return is_wp_error( $made ) ? self::failure( $made ) : self::ok( $made + array( 'message' => __( 'The alias is ready.', 'beltoft-services' ) ), 201 );
	}

	public static function delete_alias( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$limited = self::too_many( $r, 'change', self::CHANGE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$address = rawurldecode( (string) $request['address'] );
		$gone    = Aliases::delete( $r, $address );

		return is_wp_error( $gone ) ? self::failure( $gone ) : self::ok( array( 'deleted' => strtolower( $address ) ) );
	}

	/**
	 * Reads public DNS now for the records that are not in place yet.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function check_dns( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, 3 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$limited = self::too_many( $r, 'dns', self::CHECK_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		MailDns::restart( $r );
		MailDns::run( $r->id() );

		$fresh = Resource::find( $r->id() ) ?? $r;
		$view  = MailView::data( $fresh, array() );

		return self::ok( array( 'dns' => $view['dns'] ) );
	}

	/**
	 * The password of a mailbox, once. The same checks as the server password reveal, and a floor of requests that no
	 * filter lifts.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function reveal( \WP_REST_Request $request ): \WP_REST_Response {
		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:mail-reveal-floor', RevealRoute::FLOOR_LIMIT, MINUTE_IN_SECONDS ) ) {
			return self::error( 'rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ) );
		}

		$r = self::guard( $request, true, self::REVEAL_LIMIT );

		if ( ! $r instanceof Resource ) {
			$r->header( 'Cache-Control', 'no-store' );

			return $r;
		}

		$email    = MailGuard::inside( $r, rawurldecode( (string) $request->get_param( 'email' ) ) );
		$password = null === $email ? null : MailAccess::take( $r, $email, get_current_user_id() );

		if ( null === $password ) {
			return self::error( 'no_password', __( 'The password has already been shown or is no longer available.', 'beltoft-services' ) );
		}

		$response = new \WP_REST_Response( array( 'password' => $password, 'email' => $email ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
