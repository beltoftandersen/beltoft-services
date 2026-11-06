<?php
/** Display values of the certificate emails. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Only display values: the name, what validation needs (the CNAME or file rows come from the registrar and are public by
 * nature), dates and the address of the pull route. Never a key, a certificate text or any other secret.
 */
final class SslEmail {

	/**
	 * The values of one send.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $data Send data (ssl_reason, ssl_by_customer).
	 * @return array<string, mixed>
	 */
	public static function values( Resource $r, array $data ): array {
		$to   = (string) $r->get( 'ssl_valid_to' );
		$rows = array();

		foreach ( (array) $r->get( 'ssl_validation_rows' ) as $row ) {
			if ( is_array( $row ) && in_array( (string) ( $row['type'] ?? '' ), array( 'CNAME', 'file' ), true ) ) {
				$rows[] = array( 'name' => sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 'value' => sanitize_textarea_field( (string) ( $row['value'] ?? '' ) ) );
			}
		}

		return array(
			'name'        => (string) $r->get( 'ssl_name' ),
			'smime'       => Provision::is_smime( $r ),
			'reason'      => in_array( $data['ssl_reason'] ?? '', array( 'dns', 'file', 'email' ), true ) ? (string) $data['ssl_reason'] : 'dns',
			'rows'        => $rows,
			'approver'    => (string) $r->get( 'ssl_approver' ),
			'valid_to'    => '' !== $to ? wp_date( get_option( 'date_format' ), (int) strtotime( $to . ' UTC' ) ) : '',
			'by_customer' => ! empty( $data['ssl_by_customer'] ),
			'pull_url'    => esc_url_raw( rest_url( \BeltoftServices\Api\Routes::NAMESPACE . '/resources/' . $r->id() . '/ssl-certificate' ) ),
			'key_waiting' => Keys::revealable( $r ),
		);
	}
}
