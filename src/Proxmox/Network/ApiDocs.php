<?php
/**
 * The API documents of the server routes (usage figures and the firewall).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

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
		add_filter( 'beltoft_services_api_reference_tags', array( __CLASS__, 'tag_servers' ), 10 );
	}

	/**
	 * Filter beltoft_services_api_reference_tags: the servers group.
	 *
	 * @param mixed $tags Tags so far.
	 * @return array<string, mixed>
	 */
	public static function tag_servers( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		$tags['servers'] = array(
			0       => __( 'Servers', 'beltoft-services' ),
			1       => __( 'Usage figures and the firewall of your servers.', 'beltoft-services' ),
			'paths' => array( 'metrics', 'firewall' ),
		);

		return $tags;
	}
}
