<?php
/**
 * The API documents of the backup storage routes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

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
		add_filter( 'beltoft_services_api_reference_tags', array( __CLASS__, 'tag_storage' ), 60 );
	}

	/**
	 * Filter beltoft_services_api_reference_tags: the backup storage group.
	 *
	 * @param mixed $tags Tags so far.
	 * @return array<string, mixed>
	 */
	public static function tag_storage( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		$tags['storage'] = array(
			0       => __( 'Backup storage', 'beltoft-services' ),
			1       => __( 'The connection details, usage and snapshots of a backup storage. Showing or changing the password and restoring a snapshot need the code we email to the account address, so they are only possible in the dashboard.', 'beltoft-services' ),
			'paths' => array( 'storage', 'storage-password', 'storage-password-reset', 'storage-rollback' ),
		);

		return $tags;
	}
}
