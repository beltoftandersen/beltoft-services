<?php
/**
 * The API documents of the email routes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

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
		add_filter( 'beltoft_services_api_reference_tags', array( __CLASS__, 'tag_email' ), 40 );
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

		return (array) $operations + array(
			'GET /resources/{id}/mail'                   => array(
				'summary' => 'One email domain: plan limits, mailboxes with their storage use, aliases, the DNS records with their status, and the settings for a mail program. Nothing secret is in the reply.',
				'scope'   => 'read',
				'reply'   => array(
					'domain'    => $text,
					'kind'      => $text,
					'ready'     => $bool,
					'limits'    => array( 'type' => 'object' ),
					'mailboxes' => array( 'type' => array( 'array', 'null' ), 'items' => array( 'type' => 'object' ) ),
					'aliases'   => array( 'type' => array( 'array', 'null' ), 'items' => array( 'type' => 'object' ) ),
					'dns'       => array( 'type' => 'object' ),
					'connect'   => array( 'type' => 'object' ),
				),
				'errors'  => array( 401, 403, 409, 429 ),
			),
			'POST /resources/{id}/mail/mailboxes'        => array(
				'summary' => 'Make a mailbox in an email domain. The work runs in the background (answer 202): read GET /resources/{id} until `working` is false and look at `last_result` (action mailbox_create, ok, text); GET /resources/{id}/mail lists the mailbox when it is ready. Without `password` it gets a generated secret that is never in the reply: the customer shows it once in the dashboard. With `password` (10 to 128 characters, at least one letter and one digit, not containing the address) the mailbox gets that password and nothing is shown afterwards; the password is never in a reply or a log.',
				'scope'   => 'actions',
				'body'    => array( 'local' => array( 'type' => 'string', 'description' => 'The part before the @.' ), 'name' => array( 'type' => 'string', 'description' => 'The name shown for the mailbox. Optional.' ), 'quota_mb' => array( 'type' => 'integer', 'description' => 'The size in MB, up to the size of the plan. Optional.' ), 'password' => array( 'type' => 'string', 'description' => 'The password of the mailbox. Optional: without it one is generated.' ) ),
				'required' => array( 'local' ),
				'reply'   => array( 'queued' => $bool, 'action' => $text, 'at' => $int, 'status' => $text, 'email' => $text, 'quota_mb' => $int, 'message' => $text ),
				'status'  => 202,
				'errors'  => array( 401, 403, 409, 422, 429, 503 ),
			),
			'POST /resources/{id}/mail/aliases'          => array(
				'summary' => 'Make an alias: an address that delivers to one or more other addresses. There is no catch-all.',
				'scope'   => 'actions',
				'body'    => array( 'address' => array( 'type' => 'string', 'description' => 'The part before the @.' ), 'goto' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'One to ten addresses.' ) ),
				'required' => array( 'address', 'goto' ),
				'reply'   => array( 'address' => $text, 'goto' => array( 'type' => 'array', 'items' => $text ), 'message' => $text ),
				'status'  => 201,
				'errors'  => array( 401, 403, 409, 422, 429, 503 ),
			),
			'DELETE /resources/{id}/mail/aliases/{address}' => array(
				'summary' => 'Delete an alias of an email domain.',
				'scope'   => 'actions',
				'reply'   => array( 'deleted' => $text ),
				'errors'  => array( 401, 403, 409, 422, 429, 503 ),
			),
			'POST /resources/{id}/mail/dns/check'        => array(
				'summary' => 'Look at the DNS of an email domain now. The look runs in the background (answer 202): read GET /resources/{id} until `working` is false and look at `last_result` (action mail_dns_check); GET /resources/{id}/mail shows which records are ok, missing or in conflict. The reply carries the records as they were when you asked.',
				'scope'   => 'actions',
				'reply'   => array( 'queued' => $bool, 'action' => $text, 'at' => $int, 'status' => $text, 'message' => $text, 'dns' => array( 'type' => 'object' ) ),
				'status'  => 202,
				'errors'  => array( 401, 403, 409, 429 ),
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
		$fragments['resource'] = array_merge( (array) ( $fragments['resource'] ?? array() ), array( 'mail(/(mailboxes|aliases(/[^/]+)?|dns/check))?' ) );
		$fragments['root']     = array_merge( (array) ( $fragments['root'] ?? array() ), array(  ) );

		return $fragments;
	}

	/**
	 * Filter beltoft_services_api_reference_tags: the email group.
	 *
	 * @param mixed $tags Tags so far.
	 * @return array<string, mixed>
	 */
	public static function tag_email( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		$tags['email'] = array(
			0       => __( 'Email', 'beltoft-services' ),
			1       => __( 'Read an email domain, make mailboxes and aliases, and check its DNS records. Deleting a mailbox and everything to do with its secret are only possible in the dashboard, or through Claude with Full access.', 'beltoft-services' ),
			'paths' => array( 'mail' ),
		);

		return $tags;
	}
}
