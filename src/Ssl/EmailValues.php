<?php
/**
 * The SSL certificate values of the service emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the ssl values, the certificate's domain and the link to the SSL page to the service emails
 * (beltoft_services_email_values). The key is always set.
 */
final class EmailValues {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_email_values', array( __CLASS__, 'values' ), 10, 3 );
	}

	/**
	 * The values.
	 *
	 * @param mixed $values Values so far.
	 * @param mixed $r      Resource.
	 * @param mixed $data   Trigger data.
	 * @return mixed
	 */
	public static function values( $values, $r, $data ) {
		if ( ! is_array( $values ) || ! $r instanceof Resource ) {
			return $values;
		}

		$values['ssl'] = SslEmail::values( $r, is_array( $data ) ? $data : array() );

		if ( 'ssl' === $r->get( 'driver' ) ) {
			$values['domain_name'] = HostName::display( (string) $r->get( 'ssl_name' ) );
			$license               = $r->license();

			if ( $license && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
				$values['account_url'] = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Ssl::class )->url( $license );
			}
		}

		return $values;
	}
}
