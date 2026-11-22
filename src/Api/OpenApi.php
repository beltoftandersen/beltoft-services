<?php
/**
 * The OpenAPI 3.1 description of the customer API.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Api;

use BeltoftServices\Oauth\Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET beltoft-services/v1/openapi.json, public. It is built from the REST routes that are registered right now: a
 * route the API documents is read from the server's route table (its path and its argument definitions), and the
 * summary, reply fields, scope and error codes come from the table below. A route in the table that is not registered
 * is left out, and a route a token can reach (Tokens::reachable) that is missing from the table fails the tests, so
 * the document cannot drift from the code.
 *
 * Left out on purpose: every route that reveals or opens something (password reveals, console, transfer codes, the signed
 * Open link) and the dashboard's own helpers. Tokens cannot reach them either. The one exception is the CA download
 * (db-ca): a public certificate that a read token may fetch, left out of the document only to keep it short; the MCP tool
 * asks for more (Full access and a confirmation), so the two surfaces differ on purpose until the owner decides.
 */
final class OpenApi {

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
			'/openapi.json',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get' ),
				// Public: it describes the API and holds no customer data and no secret.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET openapi.json.
	 */
	public static function get(): \WP_REST_Response {
		$response = new \WP_REST_Response( self::document(), 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * What the API documents: "METHOD /path" => summary, scope (read, actions or none), reply fields, query and body
	 * fields the route's own arguments do not describe, and the error statuses it can answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function operations(): array {
		// The platform's literal table plus what the features add: it depends on nothing that changes, so one build per request cannot go stale.
		static $table = null;

		if ( null !== $table ) {
			return $table;
		}

		$text = array( 'type' => 'string' );
		$int  = array( 'type' => 'integer' );
		$bool = array( 'type' => 'boolean' );
		$table = array(
			'GET /resources'                             => array(
				'summary' => 'List your services: servers, databases, domains and apps.',
				'scope'   => 'read',
				'reply'   => array(
					'resources' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => $int,
								'type'   => $text,
								'name'   => $text,
								'state'  => $text,
								'status' => $text,
								'renews' => $text,
								'ip'     => $text,
							),
						),
					),
				),
				'errors'  => array( 401, 429 ),
			),
			'GET /resources/{id}'                        => array(
				'summary' => 'Status of one service.',
				'scope'   => 'read',
				'reply'   => array(
					'state'       => $text,
					'desired'     => $text,
					'working'     => $bool,
					'app_state'   => $text,
					'label'       => $text,
					'message'     => $text,
					'progress'    => array( 'type' => 'object' ),
					'last_result' => array( 'type' => array( 'object', 'null' ) ),
					'now'         => $int,
				),
				'errors'  => array( 401, 403, 429 ),
			),
			'POST /resources/{id}/actions/{action}'      => array(
				'summary' => 'Ask for an action on a service, for example start, shutdown or reboot. The work runs in the background.',
				'scope'   => 'actions',
				'body'    => array( 'args' => array( 'type' => 'object', 'description' => 'Arguments of the action, as text values.' ) ),
				'reply'   => array(
					'queued'  => $bool,
					'at'      => $int,
					'message' => $text,
				),
				'status'  => 202,
				'errors'  => array( 401, 403, 409, 422, 429 ),
			),
			'GET /resources/{id}/metrics'                => array(
				'summary' => 'Usage figures of a server or a hosting account over an hour, a day or a month.',
				'scope'   => 'read',
				'reply'   => array(
					'range'     => $text,
					'step'      => $int,
					'points'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'disk'      => array( 'type' => array( 'object', 'null' ), 'description' => 'Used and total bytes: the disk of a server, or the files and databases of a hosting account against the storage of its plan.' ),
					'memory'    => array( 'type' => 'object', 'description' => 'Memory in use without the file cache, from inside the guest (the guest agent of a server, the node agent of a hosting account), never the hypervisor figure: state, step and points with t, used_mb, total_mb, percent and peak_percent (null where there was no reading).' ),
					'available' => $bool,
				),
				'errors'  => array( 401, 403, 429 ),
			),
			'GET /usage'                                 => array(
				'summary' => 'Current CPU, memory (in use without the file cache) and disk or storage use of your running servers and hosting accounts. Optional ids limit it to some services.',
				'scope'   => 'read',
				'reply'   => array( 'resources' => array( 'type' => 'object', 'description' => 'Readings by service id.' ) ),
				'errors'  => array( 401, 429 ),
			),
			'GET /activity'                              => array(
				'summary' => 'What happened to your services, newest first, in sentences: what you did and what you are told about. Ended services are included. Lines are grouped by day; a sentence that repeats on one day for one service is one line with a count. Pass the `next` of an answer as `before` for the following page.',
				'scope'   => 'read',
				'reply'   => array(
					'days'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'day'   => array( 'type' => 'string', 'description' => 'The day, Y-m-d, in the site time zone.' ),
								'label' => $text,
								'lines' => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'id'         => array( 'type' => 'integer', 'description' => 'Id of the newest event of the line.' ),
											'time'       => $text,
											'datetime'   => array( 'type' => 'string', 'description' => 'ISO 8601, UTC.' ),
											'service_id' => $int,
											'service'    => array( 'type' => 'string', 'description' => 'The name of the service as the lists show it. An ended service keeps its last name.' ),
											'url'        => array( 'type' => 'string', 'description' => 'The service page, empty when the service has ended.' ),
											'text'       => $text,
											'count'      => $int,
										),
									),
								),
							),
						),
					),
					'next'     => array( 'type' => 'integer', 'description' => 'Cursor for the following page, 0 when there are no older lines.' ),
					'services' => array(
						'type'        => 'array',
						'description' => 'First page without a service filter only: the services that have history.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => $int,
								'name' => $text,
							),
						),
					),
				),
				'errors'  => array( 401, 403, 429 ),
			),
			'GET /openapi.json'                          => array(
				'summary' => 'This document.',
				'scope'   => 'none',
				'reply'   => array( 'openapi' => $text, 'info' => array( 'type' => 'object' ), 'paths' => array( 'type' => 'object' ) ),
				'errors'  => array(),
			),
		);

		/**
		 * The documented operations of the features: "METHOD /path" => operation (the shape of the entries above). The
		 * platform lists only its own routes here; each feature adds its own (Domains\ApiDocs, Mail\ApiDocs, Ssl\ApiDocs).
		 *
		 * @param array<string, array<string, mixed>> $table Operations so far.
		 */
		$table = (array) apply_filters( 'beltoft_services_openapi_operations', $table );

		return $table;
	}

	/**
	 * The document.
	 *
	 * @return array<string, mixed>
	 */
	public static function document(): array {
		$paths  = array();
		$errors = array(
			400 => 'The request is not valid.',
			401 => 'Not signed in, or the token is not valid.',
			403 => 'The service is not yours or not available, or the token may not do this.',
			404 => 'Not found.',
			409 => 'The change clashes with the current state. Read and try again.',
			422 => 'A value is not valid.',
			429 => 'Too many requests. Wait a minute.',
			503 => 'Not available right now.',
		);

		$ops = self::operations();

		foreach ( self::registered() as $path => $methods ) {
			foreach ( $methods as $method => $args ) {
				$op = $ops[ $method . ' ' . $path ] ?? null;

				if ( null === $op ) {
					continue;
				}

				$paths[ $path ][ strtolower( $method ) ] = self::operation( $method, $path, $op, $args, $errors );
			}
		}

		return array(
			'openapi'    => '3.1.0',
			'info'       => array(
				'title'       => get_bloginfo( 'name' ) . ' customer API',
				'version'     => defined( 'BELTOFT_SERVICES_VERSION' ) ? (string) BELTOFT_SERVICES_VERSION : '1',
				'description' => 'Your own services. Sign in with a personal token (HTTP Basic: your username and the token) or with OAuth. A token with the read scope reads; the actions scope also changes things. Secrets, and actions that destroy something, are only available in the dashboard. A Full access token (confirmed when it is made) also opens the Full tools of the MCP endpoint, each after a confirmation; on these routes it works as actions.',
			),
			'servers'    => array( array( 'url' => untrailingslashit( rest_url( Routes::NAMESPACE ) ) ) ),
			'paths'      => (object) $paths,
			'components' => array(
				'schemas'         => array(
					'Error' => array(
						'type'       => 'object',
						'properties' => array(
							'code'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
				),
				'securitySchemes' => array(
					'basicAuth' => array(
						'type'        => 'http',
						'scheme'      => 'basic',
						'description' => 'Your username and a personal token from Account details, Security.',
					),
					'oauth2'    => array(
						'type'        => 'oauth2',
						'description' => 'Authorization code flow with PKCE (S256). Apps register themselves (RFC 7591).',
						'flows'       => array(
							'authorizationCode' => array(
								'authorizationUrl' => Server::authorize_url(),
								'tokenUrl'         => rest_url( Server::NAMESPACE . '/token' ),
								'refreshUrl'       => rest_url( Server::NAMESPACE . '/token' ),
								'scopes'           => array(
									Tokens::READ    => 'Read your services.',
									Tokens::ACTIONS => 'Read, and run actions and DNS changes.',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The documented routes that are registered, as path => method => the route's argument definitions.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function registered(): array {
		$out = array();

		foreach ( rest_get_server()->get_routes( Routes::NAMESPACE ) as $pattern => $handlers ) {
			$path = (string) preg_replace( '#\(\?P<(\w+)>[^)]*\)#', '{$1}', substr( (string) $pattern, strlen( '/' . Routes::NAMESPACE ) ) );

			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $method ) {
					$out[ $path ][ $method ] = (array) ( $handler['args'] ?? array() );
				}
			}
		}

		return $out;
	}

	/**
	 * One operation.
	 *
	 * @param string                $method Method.
	 * @param string                $path   Path with {name} parts.
	 * @param array<string, mixed>  $op     Table entry.
	 * @param array<string, mixed>  $args   The route's argument definitions.
	 * @param array<int, string>    $errors Error texts by status.
	 * @return array<string, mixed>
	 */
	private static function operation( string $method, string $path, array $op, array $args, array $errors ): array {
		$params = array();
		$names  = array();

		preg_match_all( '#\{(\w+)\}#', $path, $m );

		foreach ( $m[1] as $name ) {
			$names[]  = $name;
			$params[] = array(
				'name'     => $name,
				'in'       => 'path',
				'required' => true,
				'schema'   => self::schema( (array) ( $args[ $name ] ?? array() ), 'id' === $name ? 'integer' : 'string' ),
			);
		}

		foreach ( $args as $name => $def ) {
			if ( in_array( $name, $names, true ) || ! in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
				continue;
			}

			$params[] = array(
				'name'     => (string) $name,
				'in'       => 'query',
				'required' => ! empty( $def['required'] ),
				'schema'   => self::schema( (array) $def, 'string' ),
			);
		}

		foreach ( (array) ( $op['query'] ?? array() ) as $name => $schema ) {
			// The table's description of a query field replaces the one made from the route's own argument: a name is listed once.
			$params = array_values( array_filter( $params, static fn( $p ) => ! ( 'query' === $p['in'] && $p['name'] === (string) $name ) ) );
			$params[] = array( 'name' => $name, 'in' => 'query', 'required' => false, 'schema' => $schema );
		}

		$status = (int) ( $op['status'] ?? 200 );
		$out    = array(
			'summary'      => $op['summary'],
			'operationId'  => strtolower( $method ) . '_' . trim( (string) preg_replace( '/[^a-z0-9]+/i', '_', $path ), '_' ),
			'security'     => 'none' === $op['scope'] ? array() : array( array( 'basicAuth' => array() ), array( 'oauth2' => array( $op['scope'] ) ) ),
			'x-required-scope' => $op['scope'],
			'parameters'   => $params,
			'responses'    => array(
				(string) $status => array(
					'description' => 'OK',
					'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object', 'properties' => $op['reply'] ) ) ),
				),
			),
		);

		if ( ! empty( $op['body'] ) ) {
			$out['requestBody'] = array(
				'required' => ! empty( $op['required'] ),
				'content'  => array( 'application/json' => array( 'schema' => array( 'type' => 'object', 'properties' => $op['body'], 'required' => $op['required'] ?? array() ) ) ),
			);
		}

		foreach ( (array) $op['errors'] as $code ) {
			$out['responses'][ (string) $code ] = array(
				'description' => $errors[ $code ] ?? 'Error.',
				'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Error' ) ) ),
			);
		}

		return $out;
	}

	/**
	 * A JSON schema from a route argument.
	 *
	 * @param array<string, mixed> $def     Argument definition.
	 * @param string               $default Type when the argument has none.
	 * @return array<string, mixed>
	 */
	private static function schema( array $def, string $default ): array {
		$schema = array( 'type' => is_string( $def['type'] ?? null ) ? $def['type'] : $default );

		foreach ( array( 'minimum', 'enum', 'default', 'description' ) as $key ) {
			if ( isset( $def[ $key ] ) ) {
				$schema[ $key ] = $def[ $key ];
			}
		}

		return $schema;
	}
}
