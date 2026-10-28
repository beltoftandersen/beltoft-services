<?php
/**
 * REST routes of the DNS ownership claims (the customer's account page calls these).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every route needs a logged-in customer (the REST nonce is checked by WordPress), loads a claim
 * only when it is the customer's own and answers a stranger's id like a missing one. Responses
 * are never cached. Everything is closed while the feature is off.
 */
final class DnsClaimRoutes {

	private const ROW_KEYS = array( 'name', 'type', 'ttl', 'value', 'priority', 'weight', 'port', 'flag', 'tag' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public static function routes(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		$one = '/domains/dns-claims/(?P<id>\d+)';

		$routes = array(
			array( '/domains/dns-claims', \WP_REST_Server::CREATABLE, 'create', array() ),
			array( '/domains/dns-claims', \WP_REST_Server::READABLE, 'index', array() ),
			array( $one, \WP_REST_Server::READABLE, 'show', $id ),
			array( $one . '/check', \WP_REST_Server::CREATABLE, 'check', $id ),
			array( $one . '/scan', \WP_REST_Server::CREATABLE, 'scan', $id ),
			array( $one . '/zone-file', \WP_REST_Server::CREATABLE, 'zone_file', $id ),
			array( $one . '/records', \WP_REST_Server::CREATABLE, 'records', $id ),
			array( $one, \WP_REST_Server::DELETABLE, 'cancel', $id ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				Routes::NAMESPACE,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => static function ( \WP_REST_Request $request ) use ( $route ) {
						$handler  = array( __CLASS__, $route[2] );
						$response = DnsClaimsFeature::enabled() ? $handler( $request ) : self::error( 'dns_claims_disabled', __( 'This is not available.', 'beltoft-services' ), 404 );
						$response->header( 'Cache-Control', 'no-store' );

						return $response;
					},
					'permission_callback' => 'is_user_logged_in',
					'args'                => $route[3],
				)
			);
		}
	}

	/**
	 * Starts a claim for a domain.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function create( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$domain  = Search::normalise( (string) $request->get_param( 'domain' ) );

		if ( is_wp_error( $domain ) || ! PublicSuffix::is_registrable_apex( $domain ) ) {
			return self::error( 'unsupported_name', __( 'Use the main domain name, for example example.com.', 'beltoft-services' ), 400 );
		}

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:dns_claim_create', 5, HOUR_IN_SECONDS ) ) {
			return self::error( 'rate_limited', __( 'Too many attempts. Try again later.', 'beltoft-services' ), 429 );
		}

		$taken = self::taken( $user_id, $domain );

		if ( $taken ) {
			return $taken;
		}

		$claim = DnsClaims::create( $user_id, $domain );

		if ( is_wp_error( $claim ) ) {
			$limit = 'claims_limit' === $claim->get_error_code();

			return self::error(
				$claim->get_error_code(),
				$limit ? __( 'You have reached the number of domains for DNS hosting. Contact support.', 'beltoft-services' ) : $claim->get_error_message(),
				$limit ? 409 : 500
			);
		}

		return new \WP_REST_Response( self::view( $claim ), 201 );
	}

	/**
	 * The customer's claims.
	 */
	private static function index(): \WP_REST_Response {
		return new \WP_REST_Response( array_map( array( __CLASS__, 'view' ), DnsClaims::for_user( get_current_user_id() ) ), 200 );
	}

	/**
	 * One claim.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function show( \WP_REST_Request $request ): \WP_REST_Response {
		$claim = self::load( $request );

		return is_array( $claim ) ? new \WP_REST_Response( self::view( $claim ), 200 ) : $claim;
	}

	/**
	 * Checks the TXT record: queued, the page asks again for the result.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function check( \WP_REST_Request $request ): \WP_REST_Response {
		$claim = self::load( $request );

		if ( ! is_array( $claim ) ) {
			return $claim;
		}

		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:dns_claim_check_' . md5( $claim['domain'] ), 5, 10 * MINUTE_IN_SECONDS ) ) {
			return self::error( 'rate_limited', __( 'Too many checks. Try again in a few minutes.', 'beltoft-services' ), 429 );
		}

		if ( 'checking' !== $claim['status'] ) {
			if ( ! in_array( $claim['status'], array( 'pending', 'verified' ), true ) ) {
				return self::wrong_state();
			}

			// A verified claim stays verified until the new result is in.
			if ( 'pending' === $claim['status'] && ! DnsClaimJobs::transition( $claim['id'], array( 'pending' ), 'checking' ) ) {
				return self::wrong_state();
			}

			DnsClaimJobs::enqueue( DnsClaimJobs::CHECK, $claim['id'] );
		}

		return new \WP_REST_Response( self::view( (array) DnsClaims::get( $claim['id'] ) ), 202 );
	}

	/**
	 * Reads the domain's current records: queued.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function scan( \WP_REST_Request $request ): \WP_REST_Response {
		$claim = self::load( $request, array( 'verified' ) );

		if ( ! is_array( $claim ) ) {
			return $claim;
		}

		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:dns_claim_scan_' . md5( $claim['domain'] ), 5, HOUR_IN_SECONDS ) ) {
			return self::error( 'rate_limited', __( 'Too many scans. Try again later.', 'beltoft-services' ), 429 );
		}

		$last = $claim['last_result'];
		unset( $last['scan'], $last['scan_reason'] );

		DnsClaimJobs::transition( $claim['id'], array( 'verified' ), 'verified', array( 'last_result' => $last ) );
		DnsClaimJobs::enqueue( DnsClaimJobs::SCAN, $claim['id'] );

		return new \WP_REST_Response( self::view( (array) DnsClaims::get( $claim['id'] ) ), 202 );
	}

	/**
	 * Takes a pasted zone file.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function zone_file( \WP_REST_Request $request ): \WP_REST_Response {
		$claim = self::load( $request, array( 'verified' ) );

		if ( ! is_array( $claim ) ) {
			return $claim;
		}

		$parsed = ZoneFile::parse( (string) $request->get_param( 'text' ), (string) $claim['domain'] );

		if ( is_wp_error( $parsed ) ) {
			return self::error( $parsed->get_error_code(), $parsed->get_error_message(), 400 );
		}

		$last              = $claim['last_result'];
		$last['zone_file'] = $parsed;

		if ( ! DnsClaimJobs::transition( $claim['id'], array( 'verified' ), 'verified', array( 'last_result' => $last ) ) ) {
			return self::wrong_state();
		}

		return new \WP_REST_Response( self::view( (array) DnsClaims::get( $claim['id'] ) ), 200 );
	}

	/**
	 * Saves the records the customer wants to bring.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function records( \WP_REST_Request $request ): \WP_REST_Response {
		$claim = self::load( $request, array( 'verified' ) );

		if ( ! is_array( $claim ) ) {
			return $claim;
		}

		$rows = $request->get_param( 'records' );

		if ( ! is_array( $rows ) ) {
			return self::error( 'bad_record', __( 'A DNS record is invalid.', 'beltoft-services' ), 400 );
		}

		$checked = Records::validate( (string) $claim['domain'], $rows );

		if ( is_wp_error( $checked ) ) {
			return self::error( $checked->get_error_code(), $checked->get_error_message(), 400 );
		}

		$draft = array();

		foreach ( array_values( $rows ) as $row ) {
			$draft[] = array_intersect_key( (array) $row, array_flip( self::ROW_KEYS ) );
		}

		if ( ! DnsClaimJobs::transition( $claim['id'], array( 'verified' ), 'verified', array( 'draft_records' => $draft ) ) ) {
			return self::wrong_state();
		}

		return new \WP_REST_Response( self::view( (array) DnsClaims::get( $claim['id'] ) ), 200 );
	}

	/**
	 * Cancels an open claim. An assignment is not touched.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function cancel( \WP_REST_Request $request ): \WP_REST_Response {
		$claim = self::load( $request, array( 'pending', 'checking', 'verified', 'support' ) );

		if ( ! is_array( $claim ) ) {
			return $claim;
		}

		if ( ! DnsClaimJobs::transition( $claim['id'], array( 'pending', 'checking', 'verified', 'support' ), 'cancelled' ) ) {
			return self::wrong_state();
		}

		return new \WP_REST_Response( self::view( (array) DnsClaims::get( $claim['id'] ) ), 200 );
	}

	/**
	 * The customer's own claim, or the refusal. A claim past its time is ended first.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string[]|null    $states  Statuses the route works on; null for any.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	private static function load( \WP_REST_Request $request, ?array $states = null ) {
		$claim = DnsClaims::get( (int) $request['id'] );

		if ( $claim && in_array( $claim['status'], array( 'pending', 'checking', 'verified' ), true ) && strtotime( (string) $claim['expires_at'] . ' UTC' ) <= time() ) {
			DnsClaims::expire_old();
			$claim = DnsClaims::get( $claim['id'] );
		}

		if ( ! $claim || (int) $claim['user_id'] !== get_current_user_id() ) {
			return self::error( 'dns_claim_not_found', __( 'This domain check was not found.', 'beltoft-services' ), 404 );
		}

		if ( null !== $states && ! in_array( $claim['status'], $states, true ) ) {
			return self::wrong_state();
		}

		return $claim;
	}

	/**
	 * A refusal when the customer has the domain already, or someone else holds it. Both the
	 * customer's and another customer's live services count, and so do assignments.
	 *
	 * @param int    $user_id Customer.
	 * @param string $domain  Domain.
	 */
	private static function taken( int $user_id, string $domain ): ?\WP_REST_Response {
		$other = self::error( 'domain_taken', __( 'This domain cannot be added here. Contact support.', 'beltoft-services' ), 409 );

		foreach ( array( 'domain', 'dns' ) as $kind ) {
			foreach ( Resource::live_ids( $kind ) as $resource_id ) {
				$resource = Resource::find( $resource_id );

				if ( ! $resource || strtolower( (string) $resource->get( 'domain' ) ) !== $domain ) {
					continue;
				}

				if ( (int) ( $resource->license()['user_id'] ?? 0 ) !== $user_id ) {
					return $other;
				}

				return self::mine( $resource_id );
			}
		}

		$assigned = DnsAssignments::get( $domain );

		if ( $assigned ) {
			return (int) $assigned['user_id'] === $user_id && ! empty( $assigned['resource_id'] ) ? self::mine( (int) $assigned['resource_id'] ) : $other;
		}

		return null;
	}

	/**
	 * The customer holds this domain already: the id lets the page link to it.
	 *
	 * @param int $resource_id Their resource.
	 */
	private static function mine( int $resource_id ): \WP_REST_Response {
		return rest_convert_error_to_response(
			new \WP_Error(
				'already_yours',
				__( 'You already have this domain.', 'beltoft-services' ),
				array(
					'status'      => 409,
					'resource_id' => $resource_id,
				)
			)
		);
	}

	/**
	 * The claim as the page sees it: no customer id, no failure counter, no raw answers.
	 *
	 * @param array<string, mixed> $claim Claim.
	 * @return array<string, mixed>
	 */
	public static function view( array $claim ): array {
		$status = (string) $claim['status'];
		$last   = (array) $claim['last_result'];
		$open   = in_array( $status, array( 'pending', 'checking', 'verified' ), true );
		$utf8   = function_exists( 'idn_to_utf8' ) ? idn_to_utf8( (string) $claim['domain'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;
		$iso    = static function ( $time ): ?string {
			$stamp = $time ? strtotime( (string) $time . ' UTC' ) : false;

			return $stamp ? gmdate( 'Y-m-d\TH:i:s\Z', $stamp ) : null;
		};

		return array(
			'id'             => (int) $claim['id'],
			'domain'         => (string) $claim['domain'],
			'domain_display' => is_string( $utf8 ) && '' !== $utf8 ? $utf8 : (string) $claim['domain'],
			'status'         => $status,
			'txt_name'       => DnsClaims::txt_name( $claim ),
			'txt_value'      => $open ? DnsClaims::txt_value( $claim ) : null,
			'expires_at'     => $iso( $claim['expires_at'] ),
			'verified_at'    => $iso( $claim['verified_at'] ?? null ),
			'checked_at'     => $iso( $claim['checked_at'] ?? null ),
			'ds_present'     => null === $claim['ds_present'] ? null : (bool) $claim['ds_present'],
			'servers'        => array_map( 'strval', array_filter( (array) ( $last['servers'] ?? array() ), 'is_scalar' ) ),
			'reason'         => (string) ( $last['reason'] ?? '' ),
			'message'        => self::message( $status, $last ),
			'scan'           => isset( $last['scan'] ) ? (array) $last['scan'] : null,
			'zone_file'      => isset( $last['zone_file'] ) ? (array) $last['zone_file'] : null,
			'draft_records'  => (array) $claim['draft_records'],
		);
	}

	/**
	 * Plain text for a status, with the last check's outcome for a claim back at pending.
	 *
	 * @param string               $status Status.
	 * @param array<string, mixed> $last   Last result.
	 */
	private static function message( string $status, array $last ): string {
		$outcome = (string) ( $last['status'] ?? '' );

		switch ( $status ) {
			case 'pending':
				if ( 'missing' === $outcome ) {
					return 'cname' === ( $last['reason'] ?? '' )
						? __( 'The TXT record must not be a CNAME.', 'beltoft-services' )
						: __( 'The record was not found on every name server yet. DNS changes can take a while; check again in a few minutes.', 'beltoft-services' );
				}

				if ( 'unknown' === $outcome ) {
					return __( 'Your name servers did not answer. Check again in a few minutes.', 'beltoft-services' );
				}

				return __( 'Add this TXT record at your current DNS provider, then check.', 'beltoft-services' );
			case 'checking':
				return __( 'Checking your name servers…', 'beltoft-services' );
			case 'verified':
				return __( 'Verified. You can bring your records and continue.', 'beltoft-services' );
			case 'support':
				return __( 'This domain needs a manual check. Contact support.', 'beltoft-services' );
			case 'expired':
				return __( 'This check has expired. Start again.', 'beltoft-services' );
			case 'cancelled':
				return __( 'Cancelled.', 'beltoft-services' );
			default:
				return '';
		}
	}

	/**
	 * The refusal for a claim that is not in the right state.
	 */
	private static function wrong_state(): \WP_REST_Response {
		return self::error( 'dns_claim_state', __( 'This is not possible in the current state of the domain check.', 'beltoft-services' ), 409 );
	}

	/**
	 * An error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, $message, array( 'status' => $status ) ) );
	}
}
