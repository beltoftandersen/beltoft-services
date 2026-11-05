<?php
/**
 * The read tool of an SSL certificate.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Ssl\Keys;
use BeltoftServices\Ssl\Operations;
use BeltoftServices\Ssl\Panels;
use BeltoftServices\Ssl\SslDriver;

defined( 'ABSPATH' ) || exit;

/**
 * Display values only. The private key has no tool at any level, and nothing here reads the certificate text (that is
 * get_ssl_certificate, which reaches the public route).
 */
final class SslTools {

	/**
	 * One certificate.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function info( int $id ) {
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
