<?php
/**
 * REST routes of the controls of a dedicated server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;
use BeltoftServices\StepUp;

defined( 'ABSPATH' ) || exit;

/**
 * resources/<id>/dedicated, and under it reset, wol, rescue, rescue-password, rdns, firewall, images and reinstall. Owner only (Routes::guard), a
 * limit per route, no caching. The emailed code (StepUp, parameter step_code) is needed for what could cost data or access, in one place:
 *
 *  - dedicated_power: a hardware reset or the power button (a software restart needs none),
 *  - dedicated_firewall: a firewall change that leaves no incoming rule for ssh (FirewallRules::risky),
 *  - dedicated_reinstall: a reinstall, which also needs the server name typed (`confirm`),
 *  - dedicated_rescue: showing the rescue password.
 *
 * A call made by an MCP tool has the customer's confirmation already (filter beltoft_services_confirmed_by_tool): the code and the typed name are
 * then supplied by that confirmation, as for the other tools of Full access. An API token never reaches these routes (Tokens::ALLOWED).
 */
final class ControlRoutes {

	public const BASE = '/resources/(?P<id>\d+)/dedicated';

	public const P_POWER     = 'dedicated_power';
	public const P_FIREWALL  = 'dedicated_firewall';
	public const P_REINSTALL = 'dedicated_reinstall';
	public const P_RESCUE    = 'dedicated_rescue';

	/** Hooks. */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'beltoft_services_stepup_purposes', array( __CLASS__, 'purposes' ) );
		add_filter( 'beltoft_services_stepup_text', array( __CLASS__, 'text' ), 10, 2 );
		add_filter( 'beltoft_services_action_resumable', array( __CLASS__, 'resumable' ), 10, 3 );
		add_action( 'beltoft_services_action_failed', array( __CLASS__, 'failed' ), 10, 3 );
	}

	/**
	 * @param mixed $purposes Purposes so far.
	 * @return array<int, string>
	 */
	public static function purposes( $purposes ): array {
		return array_merge( (array) $purposes, array( self::P_POWER, self::P_FIREWALL, self::P_REINSTALL, self::P_RESCUE ) );
	}

	/**
	 * @param mixed $text    Text so far.
	 * @param mixed $purpose Purpose.
	 */
	public static function text( $text, $purpose ): string {
		switch ( $purpose ) {
			case self::P_POWER:
				return __( 'resetting or switching off your dedicated server', 'beltoft-services' );
			case self::P_FIREWALL:
				return __( 'changing the firewall of your dedicated server', 'beltoft-services' );
			case self::P_REINSTALL:
				return __( 'reinstalling your dedicated server, which erases its disks', 'beltoft-services' );
			case self::P_RESCUE:
				return __( 'showing the rescue password of your dedicated server', 'beltoft-services' );
		}

		return (string) $text;
	}

	/**
	 * The reinstall is replay safe: its steps are stored.
	 *
	 * @param mixed $resumable Answer so far.
	 * @param mixed $driver    Driver id.
	 * @param mixed $action    Action.
	 */
	public static function resumable( $resumable, $driver, $action ): bool {
		return ( ManualServerDriver::ID === $driver && DedicatedReinstall::ACTION === $action ) || (bool) $resumable;
	}

	/**
	 * A reinstall that ended without finishing: the memory says failed (not running) so the controls work again, and the rescue system is switched off.
	 *
	 * @param mixed $r      Resource.
	 * @param mixed $action Action.
	 */
	public static function failed( $r, $action ): void {
		if ( ! $r instanceof Resource || DedicatedReinstall::ACTION !== $action ) {
			return;
		}

		$memo = $r->get( DedicatedReinstall::META );

		if ( is_array( $memo ) && 'running' === ( $memo['state'] ?? '' ) ) {
			$r->set( array( DedicatedReinstall::META => array( 'state' => 'failed', 'image' => (string) ( $memo['image'] ?? '' ), 'at' => time() ) ) );
			$number = ServerControls::number( $r );

			if ( $number > 0 ) {
				DedicatedClients::for()->rescue_deactivate( $number );
			}
		}
	}

	/** Registers the routes. */
	public static function routes(): void {
		$id = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );
		$ns = Routes::NAMESPACE;
		$ok = 'is_user_logged_in';

		register_rest_route( $ns, self::BASE, array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $ok, 'args' => $id ) );
		register_rest_route( $ns, self::BASE . '/reset', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'reset' ), 'permission_callback' => $ok, 'args' => $id + array( 'type' => array( 'type' => 'string', 'required' => true, 'enum' => array_keys( ServerControls::RESETS ) ) ) ) );
		register_rest_route( $ns, self::BASE . '/wol', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'wol' ), 'permission_callback' => $ok, 'args' => $id ) );
		register_rest_route( $ns, self::BASE . '/rescue', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rescue_on' ), 'permission_callback' => $ok, 'args' => $id + array( 'boot' => array( 'type' => 'boolean' ) ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'rescue_off' ), 'permission_callback' => $ok, 'args' => $id ),
		) );
		register_rest_route( $ns, self::BASE . '/rescue-password', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rescue_password' ), 'permission_callback' => $ok, 'args' => $id ) );
		register_rest_route( $ns, self::BASE . '/rdns', array(
			array( 'methods' => \WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'rdns_set' ), 'permission_callback' => $ok, 'args' => $id + array( 'ip' => array( 'type' => 'string', 'required' => true ), 'ptr' => array( 'type' => 'string', 'required' => true ) ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'rdns_delete' ), 'permission_callback' => $ok, 'args' => $id + array( 'ip' => array( 'type' => 'string', 'required' => true ) ) ),
		) );
		register_rest_route( $ns, self::BASE . '/firewall', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'firewall' ), 'permission_callback' => $ok, 'args' => $id + array( 'status' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'active', 'disabled' ) ), 'input' => array( 'type' => 'array' ), 'whitelist_hos' => array( 'type' => 'boolean' ), 'filter_ipv6' => array( 'type' => 'boolean' ) ) ) );
		register_rest_route( $ns, self::BASE . '/images', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'images' ), 'permission_callback' => $ok, 'args' => $id ) );
		register_rest_route( $ns, self::BASE . '/reinstall', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'reinstall' ), 'permission_callback' => $ok, 'args' => $id + array( 'image' => array( 'type' => 'string', 'required' => true ), 'confirm' => array( 'type' => 'string' ) ) ) );
	}

	/**
	 * The checks every route makes: the limit, the owner, a dedicated server.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $limit   Requests a minute.
	 * @return Resource|\WP_REST_Response
	 */
	private static function resource( \WP_REST_Request $request, int $limit ) {
		$r = Routes::guard( $request, false, $limit );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		return ManualServerDriver::ID === $r->get( 'driver' ) ? $r : self::respond( ServerControls::refusal( 'dedicated_not', __( 'This is not a dedicated server.', 'beltoft-services' ), 404 ) );
	}

	/**
	 * Turns a result into a response, never cached.
	 *
	 * @param mixed $result Data or WP_Error.
	 * @param int   $status Status for data.
	 */
	private static function respond( $result, int $status = 200 ): \WP_REST_Response {
		$response = is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : new \WP_REST_Response( $result, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The emailed code check: a refusal response, or null when the code is right. A right code is used up here, before the work (a second
	 * request with the same code is refused). A call from an MCP tool carries the customer's confirmation instead.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $purpose Purpose.
	 */
	private static function stepped( \WP_REST_Request $request, string $purpose ): ?\WP_REST_Response {
		if ( (bool) apply_filters( 'beltoft_services_confirmed_by_tool', false, $request ) ) {
			return null;
		}

		$refused = StepUp::demand( $request, $purpose );

		if ( ! $refused && ! StepUp::consume( get_current_user_id(), $purpose ) ) {
			$refused = new \WP_Error( 'step_up', StepUp::refusal( get_current_user_id(), $purpose ), array( 'status' => 403 ) );
		}

		return $refused ? self::respond( $refused ) : null;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function overview( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 20 );

		return $r instanceof Resource ? self::respond( ServerControls::overview( $r ) ) : $r;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function reset( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 10 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$type = (string) $request['type'];
		$gate = ServerControls::guard( $r, true );

		if ( is_wp_error( $gate ) ) {
			return self::respond( $gate );
		}

		if ( in_array( $type, ServerControls::CODE_RESETS, true ) ) {
			$refused = self::stepped( $request, self::P_POWER );

			if ( $refused ) {
				return $refused;
			}
		}

		$done = ServerControls::reset( $r, $type );

		return self::respond( is_wp_error( $done ) ? $done : array( 'done' => true, 'type' => $type ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function wol( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 10 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$done = ServerControls::wol( $r );

		return self::respond( is_wp_error( $done ) ? $done : array( 'done' => true ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rescue_on( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 10 );

		return $r instanceof Resource ? self::respond( ServerControls::rescue_activate( $r, ! empty( $request['boot'] ) ) ) : $r;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rescue_off( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 10 );

		return $r instanceof Resource ? self::respond( ServerControls::rescue_deactivate( $r ) ) : $r;
	}

	/**
	 * The rescue password, once per code. Never in any other answer.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rescue_password( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 5 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$gate = ServerControls::guard( $r, false );

		if ( is_wp_error( $gate ) ) {
			return self::respond( $gate );
		}

		if ( null === ServerControls::rescue_password( $r ) ) {
			return self::respond( ServerControls::refusal( 'dedicated_no_password', __( 'There is no rescue password to show. Your key is used for the rescue system, or it is switched off.', 'beltoft-services' ), 409 ) );
		}

		$refused = self::stepped( $request, self::P_RESCUE );

		return $refused ? $refused : self::respond( array( 'password' => ServerControls::rescue_password( $r ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rdns_set( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 20 );

		return $r instanceof Resource ? self::respond( ServerControls::rdns_set( $r, (string) $request['ip'], (string) $request['ptr'] ) ) : $r;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rdns_delete( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 20 );

		return $r instanceof Resource ? self::respond( ServerControls::rdns_delete( $r, (string) $request['ip'] ) ) : $r;
	}

	/**
	 * Applies the incoming rules. A rule set that leaves ssh closed needs the code.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function firewall( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 10 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$rules = FirewallRules::clean( is_array( $request['input'] ) ? $request['input'] : array() );

		if ( is_wp_error( $rules ) ) {
			return self::respond( $rules );
		}

		$status = (string) $request['status'];
		$gate   = ServerControls::guard( $r, false );

		if ( is_wp_error( $gate ) ) {
			return self::respond( $gate );
		}

		if ( ServerControls::firewall_risky( $status, $rules ) ) {
			$refused = self::stepped( $request, self::P_FIREWALL );

			if ( $refused ) {
				return $refused;
			}
		}

		$done = ServerControls::firewall_set(
			$r,
			$status,
			$rules,
			null === $request->get_param( 'whitelist_hos' ) ? null : (bool) $request['whitelist_hos'],
			null === $request->get_param( 'filter_ipv6' ) ? null : (bool) $request['filter_ipv6']
		);

		return self::respond( $done );
	}

	/**
	 * The systems the reinstall offers, as tiles' entries (family, short text, exact file name).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function images( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 20 );

		return $r instanceof Resource ? self::respond( array( 'images' => DedicatedReinstall::images() ) ) : $r;
	}

	/**
	 * Reinstall: the typed name, the emailed code, then the job.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function reinstall( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, 5 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$image = (string) $request['image'];
		$why   = DedicatedReinstall::check( $r, $image );

		if ( $why ) {
			return self::respond( $why );
		}

		$confirmed = (bool) apply_filters( 'beltoft_services_confirmed_by_tool', false, $request );

		if ( ! $confirmed && ( '' === (string) $r->get( 'server_name' ) || (string) $request['confirm'] !== (string) $r->get( 'server_name' ) ) ) {
			return self::respond( ServerControls::refusal( 'dedicated_confirm', __( 'Type the name of the server to confirm. The disks will be erased.', 'beltoft-services' ), 400 ) );
		}

		$refused = self::stepped( $request, self::P_REINSTALL );

		if ( $refused ) {
			return $refused;
		}

		$started = DedicatedReinstall::start( $r, $image );

		if ( is_wp_error( $started ) ) {
			return Routes::not_queued( $started );
		}

		return Routes::queued( $r, DedicatedReinstall::ACTION, array(), __( 'The reinstall has started. It takes about 15 minutes.', 'beltoft-services' ) );
	}
}
