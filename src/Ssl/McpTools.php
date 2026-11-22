<?php
/**
 * The MCP tools of an SSL certificate.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;

defined( 'ABSPATH' ) || exit;

/**
 * Display values only. The private key has no tool at any level, and nothing here reads the certificate text (that is
 * get_ssl_certificate, which reaches the public route).
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'ssl_info'         => Definitions::read( 'Shows one SSL certificate: its name, state, when it is valid and when the yearly service ends, how the domain is validated and what validation still waits for (a DNS record, a file or an approval email, with the exact values). No key and no certificate text is shown here.' . Definitions::DATA, Definitions::by_id(), 30 ),
			'get_ssl_certificate' => Definitions::read( 'Shows the public certificate and its chain of one SSL certificate, the text the customer installs on the server. It is public. The private key is never available through Claude.', Definitions::by_id(), 20 ),
			'ssl_resend_email' => Definitions::act( 'Sends the approval email of an SSL certificate again, when it is validated by email and still waits for the approval.', Definitions::by_id(), false, true ),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'get_ssl_certificate'      => array( 'GET #^/resources/\d+/ssl-certificate$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			'ssl_resend_email'   => 'ssl_resend_email',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function excluded(): array {
		return array(
			'ssl_reissue'       => 'never: a reissue replaces the key and the certificate that works; it is done in the dashboard, where the key is taken once',
			'ssl_change_validation' => 'never: the validation method, approver and request are chosen on the certificate page, in the dashboard',
			'ssl_cancel'        => 'never: cancelling is irreversible and ends in a refund a person decides; it is only done in the dashboard',
		);
	}

	/**
	 * Runs a tool of this set.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		$id = (int) $args['id'];

		switch ( $name ) {
			case 'ssl_info':
				return self::info( $id );

			case 'get_ssl_certificate':
				$view = Tools::route( 'GET', '/resources/' . $id . '/ssl-certificate', array(), null, $id, 'SSL certificate read' );

				return $view instanceof \WP_Error ? $view : array( 'name' => (string) $view['name'], 'valid_to' => (string) $view['valid_to'], 'certificate_with_chain' => (string) $view['pem'] );
		}

		return Tools::action( $id, 'ssl_resend_email', array(), 'SSL approval email requested again' );
	}

	/**
	 * One certificate.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function info( int $id ) {
		$r = Tools::owned( $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		if ( SslDriver::ID !== $r->get( 'driver' ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This service is not an SSL certificate.', 'beltoft-services' ) );
		}

		$rows = array();

		foreach ( (array) $r->get( 'ssl_validation_rows' ) as $row ) {
			if ( is_array( $row ) && 'sent' !== ( $row['status'] ?? '' ) ) {
				$rows[] = array_intersect_key( $row, array_flip( array( 'type', 'name', 'value', 'status' ) ) );
			}
		}

		return array(
			'id'                  => $id,
			'name'                => (string) $r->get( 'ssl_name' ),
			'status'              => Panels::status_text( $r ),
			'registrar_status'    => (string) $r->get( 'ssl_status' ),
			'valid_from'          => (string) $r->get( 'ssl_valid_from' ),
			'valid_to'            => (string) $r->get( 'ssl_valid_to' ),
			'service_ends'        => (string) $r->get( 'ssl_order_expires' ),
			'validation_method'   => (string) $r->get( 'ssl_validation' ),
			'validation_waits_for' => 'REQ' === (string) $r->get( 'ssl_status' ) ? $rows : array(),
			'reissue_in_progress' => (bool) $r->get( 'ssl_reissue_pending' ),
			'private_key_waiting' => Keys::revealable( $r ),
			'dashboard_offers'    => array_keys( Operations::offered( $r ) ),
		);
	}
}
