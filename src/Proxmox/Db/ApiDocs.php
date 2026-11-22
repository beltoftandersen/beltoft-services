<?php
/**
 * The API documents of the database routes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

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
		add_filter( 'beltoft_services_token_path_fragments', array( __CLASS__, 'token_fragments' ) );
		add_filter( 'beltoft_services_api_reference_tags', array( __CLASS__, 'tag_databases' ), 20 );
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
		$fragments['resource'] = array_merge( (array) ( $fragments['resource'] ?? array() ), array( 'db-ca' ) );
		$fragments['root']     = array_merge( (array) ( $fragments['root'] ?? array() ), array(  ) );

		return $fragments;
	}

	/**
	 * Filter beltoft_services_api_reference_tags: the databases group.
	 *
	 * @param mixed $tags Tags so far.
	 * @return array<string, mixed>
	 */
	public static function tag_databases( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		$tags['databases'] = array(
			0       => __( 'Databases', 'beltoft-services' ),
			1       => __( 'Which addresses may connect to a database.', 'beltoft-services' ),
			'paths' => array( 'db-access' ),
		);

		return $tags;
	}
}
