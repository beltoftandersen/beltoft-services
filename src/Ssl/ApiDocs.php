<?php
/**
 * The API documents of the SSL certificate route.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

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
		add_filter( 'beltoft_services_api_reference_tags', array( __CLASS__, 'tag_ssl' ), 50 );
	}

	/**
	 * Filter beltoft_services_openapi_operations: "METHOD /path" => operation, as Api\OpenApi::operations() describes them.
	 *
	 * @param mixed $operations Operations so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function operations( $operations ): array {
		$text = array( 'type' => 'string' );

		return (array) $operations + array(
			'GET /resources/{id}/ssl-certificate'        => array(
				'summary' => 'The current certificate of an SSL certificate service and its chain, as PEM text, for a script that installs it. Only public parts: the private key is never in a reply.',
				'scope'   => 'read',
				'query'   => array( 'part' => array( 'type' => 'string', 'enum' => array( 'fullchain', 'cert', 'chain' ), 'description' => 'Which part to return. Default fullchain.' ) ),
				'reply'   => array(
					'name'       => $text,
					'serial'     => $text,
					'valid_from' => $text,
					'valid_to'   => $text,
					'part'       => $text,
					'pem'        => $text,
				),
				'errors'  => array( 401, 403, 404, 429 ),
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
		$fragments['resource'] = array_merge( (array) ( $fragments['resource'] ?? array() ), array( 'ssl-certificate' ) );
		$fragments['root']     = array_merge( (array) ( $fragments['root'] ?? array() ), array(  ) );

		return $fragments;
	}

	/**
	 * Filter beltoft_services_api_reference_tags: the SSL certificates group.
	 *
	 * @param mixed $tags Tags so far.
	 * @return array<string, mixed>
	 */
	public static function tag_ssl( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		$tags['ssl'] = array(
			0       => __( 'SSL certificates', 'beltoft-services' ),
			1       => __( 'Fetch the current certificate and its chain, for a script that installs each new certificate. The private key is only available in the dashboard, once.', 'beltoft-services' ),
			'paths' => array( 'ssl-certificate' ),
		);

		return $tags;
	}
}
