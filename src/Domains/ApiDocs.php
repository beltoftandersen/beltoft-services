<?php
/**
 * The API documents of the domains and DNS routes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * What this feature gives the platform's API documents: its operations for the OpenAPI document, the route parts a token may
 * reach, and its groups in the API reference. The platform (Api\OpenApi, Api\Tokens, Api\Reference) never names them.
 */
final class ApiDocs {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_openapi_operations', array( __CLASS__, 'operations' ) );
		add_filter( 'beltoft_services_token_path_fragments', array( __CLASS__, 'token_fragments' ) );
		add_filter( 'beltoft_services_api_reference_tags', array( __CLASS__, 'tag_dns' ), 30 );
		add_filter( 'beltoft_services_api_reference_tags', array( __CLASS__, 'tag_domains' ), 70 );
	}

	/**
	 * Filter beltoft_services_openapi_operations: "METHOD /path" => operation, as Api\OpenApi::operations() describes them.
	 *
	 * @param mixed $operations Operations so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function operations( $operations ): array {
		$text = array( 'type' => 'string' );
		$int  = array( 'type' => 'integer' );
		$bool = array( 'type' => 'boolean' );
		$rows = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'name'  => $text,
					'type'  => $text,
					'value' => $text,
					'ttl'   => $int,
				),
			),
		);

		return (array) $operations + array(
			'GET /resources/{id}/dns-records'            => array(
				'summary' => 'The DNS records of a domain or DNS service that we host.',
				'scope'   => 'read',
				'reply'   => array(
					'rows'        => $rows,
					'ttl_default' => $int,
					'paused'      => $bool,
					'serial'      => $int,
				),
				'errors'  => array( 401, 403, 404, 409, 429 ),
			),
			'PUT /resources/{id}/dns-records/{type}/{name}' => array(
				'summary' => 'Create or replace the records of one name and type. Other records stay as they are.',
				'scope'   => 'actions',
				'body'    => array(
					'records'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'The records after the change; each has a value.' ),
					'ttl'      => $int,
					'expect'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'The records you saw before; the change is refused if they changed.' ),
					'new_name' => $text,
					'new_type' => $text,
				),
				'required' => array( 'records' ),
				'reply'   => array( 'rows' => $rows ),
				'errors'  => array( 401, 403, 404, 409, 422, 429 ),
			),
			'DELETE /resources/{id}/dns-records/{type}/{name}' => array(
				'summary' => 'Remove the records of one name and type, or one record when value is given.',
				'scope'   => 'actions',
				'query'   => array( 'value' => array( 'type' => 'string', 'description' => 'Remove only the record with this value.' ) ),
				'reply'   => array( 'rows' => $rows ),
				'errors'  => array( 401, 403, 404, 409, 422, 429 ),
			),
			'GET /domains/check'                         => array(
				'summary' => 'Whether a domain name is available, and its price per year excluding tax, in the currency named in the answer.',
				'scope'   => 'read',
				'reply'   => array( 'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'currency' => $text ),
				'errors'  => array( 400, 429, 503 ),
			),
		);
	}

	/**
	 * Filter beltoft_services_token_path_fragments: regular expression parts of the routes a token may reach, below
	 * resources/<id>/ ('resource') and at the top of the namespace ('root'). See Api\Tokens.
	 *
	 * @param mixed $fragments Fragments so far.
	 * @return array{resource: string[], root: string[]}
	 */
	public static function token_fragments( $fragments ): array {
		$fragments             = is_array( $fragments ) ? $fragments : array();
		$fragments['resource'] = array_merge( (array) ( $fragments['resource'] ?? array() ), array( 'dns-records(/[A-Za-z]+/[^/]+)?' ) );
		$fragments['root']     = array_merge( (array) ( $fragments['root'] ?? array() ), array( 'domains/check' ) );

		return $fragments;
	}

	/**
	 * Filter beltoft_services_api_reference_tags: the DNS group.
	 *
	 * @param mixed $tags Tags so far.
	 * @return array<string, mixed>
	 */
	public static function tag_dns( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		$tags['dns'] = array(
			0       => __( 'DNS', 'beltoft-services' ),
			1       => __( 'Read and change the records of the domains and DNS services we host.', 'beltoft-services' ),
			'paths' => array( 'dns-records' ),
		);

		return $tags;
	}

	/**
	 * Filter beltoft_services_api_reference_tags: the domains group (the first path segment is its key).
	 *
	 * @param mixed $tags Tags so far.
	 * @return array<string, mixed>
	 */
	public static function tag_domains( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		$tags['domains'] = array(
			0       => __( 'Domains', 'beltoft-services' ),
			1       => __( 'Check whether a domain name is available.', 'beltoft-services' ),
			'paths' => array(),
		);

		return $tags;
	}
}
