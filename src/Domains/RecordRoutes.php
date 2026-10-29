<?php
/**
 * REST routes of the DNS records editor: one RRset (a name and a type) per request.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\Proxmox\AppDomains;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the customer records of a zone and writes one name and type at a time through Records::change(),
 * which holds the zone lock, keeps every other record as it is and never calls anything outside the DNS
 * client. The zone is always the resource's own domain, never a value from the request.
 */
final class RecordRoutes {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The routes.
	 */
	public static function routes(): void {
		$id   = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
		$path = '/resources/(?P<id>\d+)/dns-records';

		register_rest_route(
			Routes::NAMESPACE,
			$path,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'id' => $id ),
			)
		);

		$set = array(
			'id'   => $id,
			'type' => array(
				'type'     => 'string',
				'required' => true,
			),
			'name' => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		register_rest_route(
			Routes::NAMESPACE,
			$path . '/(?P<type>[A-Za-z]+)/(?P<name>[^/]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'put' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => $set,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => $set,
				),
			)
		);
	}

	/**
	 * GET: the customer records, for a refresh of the table.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, false );

		if ( $r instanceof \WP_REST_Response ) {
			return $r;
		}

		$zone = (string) $r->get( 'domain' );
		$data = self::zone( $zone );

		if ( $data instanceof \WP_REST_Response ) {
			return $data;
		}

		return self::reply(
			new \WP_REST_Response(
				array(
					'rows'        => Records::to_rows( $zone, (array) ( $data['rrsets'] ?? array() ) ),
					'ttl_default' => 3600,
					'paused'      => Records::paused( $zone, $data ),
					'serial'      => (int) ( $data['serial'] ?? 0 ),
				),
				200
			)
		);
	}

	/**
	 * PUT: creates or replaces the RRset at one name and type. Optional new_name and new_type move it.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function put( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, true );

		if ( $r instanceof \WP_REST_Response ) {
			return $r;
		}

		$zone    = (string) $r->get( 'domain' );
		$params  = (array) $request->get_json_params();
		$rows    = self::rows( $params['records'] ?? array(), $params['ttl'] ?? null );
		$expect  = isset( $params['expect'] ) && is_array( $params['expect'] ) ? self::rows( $params['expect'], null ) : null;
		$to      = null;
		$name    = rawurldecode( (string) $request['name'] );
		$type    = strtoupper( (string) $request['type'] );
		$new_to  = array();

		if ( isset( $params['new_name'] ) && is_string( $params['new_name'] ) ) {
			$new_to['name'] = $params['new_name'];
		}

		if ( isset( $params['new_type'] ) && is_string( $params['new_type'] ) ) {
			$new_to['type'] = $params['new_type'];
		}

		if ( $new_to ) {
			$to = $new_to;
		}

		if ( ! $rows ) {
			return self::failure( new \WP_Error( 'bad_record', 'A DNS record is invalid.' ) );
		}

		$done = Records::change( $zone, $name, $type, $rows, $expect, $to );

		if ( is_wp_error( $done ) ) {
			return self::failure( $done );
		}

		return self::after( $zone, (string) ( $to['name'] ?? $name ), strtoupper( (string) ( $to['type'] ?? $type ) ) );
	}

	/**
	 * DELETE: the RRset at one name and type, or with ?value= one record of it.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function delete( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::resource( $request, true );

		if ( $r instanceof \WP_REST_Response ) {
			return $r;
		}

		$zone  = (string) $r->get( 'domain' );
		$name  = rawurldecode( (string) $request['name'] );
		$type  = strtoupper( (string) $request['type'] );
		$value = $request->get_param( 'value' );

		if ( is_string( $value ) && '' !== $value ) {
			$done = self::delete_one( $zone, $name, $type, $value );
		} else {
			$done = Records::delete_set( $zone, $name, $type );
		}

		if ( is_wp_error( $done ) ) {
			return self::failure( $done );
		}

		return self::after( $zone, $name, $type );
	}

	/**
	 * Removes one record of a set that has several: a change of the set to the rest.
	 *
	 * @param string $zone  Zone.
	 * @param string $name  Name.
	 * @param string $type  Type.
	 * @param string $value Value of the record to remove.
	 */
	private static function delete_one( string $zone, string $name, string $type, string $value ): true|\WP_Error {
		$fqdn = Records::fqdn( $zone, $name, $type );

		if ( is_wp_error( $fqdn ) ) {
			return $fqdn;
		}

		$data = self::read( $zone );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$keep  = array();
		$found = false;

		foreach ( Records::to_rows( $zone, (array) ( $data['rrsets'] ?? array() ) ) as $row ) {
			$same = Records::fqdn( $zone, (string) $row['name'], (string) $row['type'] ) === $fqdn && $row['type'] === $type;

			if ( $same && ! $found && strtolower( (string) $row['value'] ) === strtolower( trim( $value ) ) ) {
				$found = true;

				continue;
			}

			if ( $same ) {
				$keep[] = $row;
			}
		}

		if ( ! $found ) {
			return new \WP_Error( 'not_found', 'That record was not found.' );
		}

		return Records::change( $zone, $name, $type, $keep );
	}

	/**
	 * The rows of one name and type after a write, as the table shows them.
	 *
	 * @param string $zone Zone.
	 * @param string $name Record name.
	 * @param string $type Record type.
	 */
	private static function after( string $zone, string $name, string $type ): \WP_REST_Response {
		$data = self::read( $zone );
		$rows = array();

		if ( is_array( $data ) ) {
			$fqdn = Records::fqdn( $zone, $name, $type );

			foreach ( Records::to_rows( $zone, (array) ( $data['rrsets'] ?? array() ) ) as $row ) {
				if ( $row['type'] === $type && Records::fqdn( $zone, (string) $row['name'], $type ) === $fqdn ) {
					$rows[] = $row;
				}
			}
		}

		return self::reply( new \WP_REST_Response( array( 'rows' => $rows ), 200 ) );
	}

	/**
	 * The record rows of a request body: known keys only, scalar values, the TTL applied to each.
	 *
	 * @param mixed $list Raw list.
	 * @param mixed $ttl  TTL of the set, or null.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( $list, $ttl ): array {
		$rows = array();

		foreach ( is_array( $list ) ? array_slice( $list, 0, 201 ) : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$row = array();

			foreach ( array( 'value', 'priority', 'weight', 'port', 'flag', 'tag', 'ttl' ) as $key ) {
				if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ) {
					$row[ $key ] = is_bool( $entry[ $key ] ) ? (int) $entry[ $key ] : $entry[ $key ];
				}
			}

			if ( is_scalar( $ttl ) && ctype_digit( (string) $ttl ) ) {
				$row['ttl'] = (int) $ttl;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The checks every route makes: rate limit, owner, a domain or DNS service, a zone that is ours to edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $write   Whether this route changes records.
	 * @return Resource|\WP_REST_Response
	 */
	private static function resource( \WP_REST_Request $request, bool $write ) {
		$r = Routes::guard( $request, $write, $write ? 60 : 30 );

		if ( ! $r instanceof Resource ) {
			return self::reply( $r );
		}

		if ( ! in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) || $r->folded_into() > 0 ) {
			return self::failure( new \WP_Error( 'forbidden', 'This service is not available.' ), 403 );
		}

		if ( ! AppDomains::editable( $r, get_current_user_id() ) ) {
			return self::failure( new \WP_Error( 'not_ours', 'The DNS of this domain is not hosted here, so its records cannot be changed.' ), 409 );
		}

		if ( $write ) {
			/**
			 * Record writes a customer may make per minute in one zone.
			 *
			 * @param int    $max  Writes, default 20; 0 turns the limit off.
			 * @param string $zone The zone.
			 */
			$max = (int) apply_filters( 'beltoft_services_dns_write_limit', 20, (string) $r->get( 'domain' ) );

			if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:dns-write:' . strtolower( (string) $r->get( 'domain' ) ), $max, MINUTE_IN_SECONDS ) ) {
				return self::failure( new \WP_Error( 'rate_limited', 'Too many changes. Try again in a minute.' ), 429 );
			}
		}

		return $r;
	}

	/**
	 * The zone as the DNS server has it, read with the short page timeout.
	 *
	 * @param string $zone Zone.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	private static function zone( string $zone ) {
		$data = self::read( $zone );

		return is_wp_error( $data ) ? self::failure( $data ) : $data;
	}

	/**
	 * The zone, or an error.
	 *
	 * @param string $zone Zone.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function read( string $zone ) {
		$client = Clients::dns();

		if ( $client instanceof LiveDns ) {
			$client = $client->with_timeout( Panels::PAGE_TIMEOUT );
		}

		$data = $client->zone( $zone );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return null === $data ? new \WP_Error( 'zone_missing', 'The DNS zone does not exist.' ) : $data;
	}

	/**
	 * A plain error response. Status by code; the DNS server's own words are never passed on.
	 *
	 * @param \WP_Error $error  Error.
	 * @param int       $status Status, or 0 to take it from the code.
	 */
	private static function failure( \WP_Error $error, int $status = 0 ): \WP_REST_Response {
		$code     = $error->get_error_code();
		$statuses = array(
			'zone_busy'       => 409,
			'stale'           => 409,
			'exists'          => 409,
			'records_off'     => 403,
			'zone_missing'    => 404,
			'not_found'       => 404,
			'bad_record'      => 422,
			'bad_name'        => 422,
			'outside_zone'    => 422,
			'bad_ip'          => 422,
			'bad_txt'         => 422,
			'bad_target'      => 422,
			'bad_number'      => 422,
			'bad_caa'         => 422,
			'cname_apex'      => 422,
			'cname_conflict'  => 422,
			'mixed_ttl'       => 422,
			'too_many_records' => 422,
		);

		$status  = $status > 0 ? $status : ( $statuses[ $code ] ?? 502 );
		$message = isset( $statuses[ $code ] ) || $status < 500 ? $error->get_error_message() : __( 'The DNS change could not be made. Try again in a moment.', 'beltoft-services' );

		// The customer-facing code of a paused service.
		if ( 'records_off' === $code ) {
			$code = 'paused';
		}

		return self::reply( rest_convert_error_to_response( new \WP_Error( (string) $code, $message, array( 'status' => $status ) ) ) );
	}

	/**
	 * Marks a response as not cacheable.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private static function reply( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
