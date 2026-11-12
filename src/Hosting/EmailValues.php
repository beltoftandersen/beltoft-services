<?php
/**
 * The hosting values of the service emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Emails\HostingNotice;
use BeltoftServices\Emails\HostingRestoreDone;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the hosting facts, the hosting notice and the restore result to the service emails
 * (beltoft_services_email_values). Every key is always set, with its empty default.
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

		$data = is_array( $data ) ? $data : array();

		$values['hosting']         = 'hosting' === $r->get( 'driver' ) ? HostingEmail::values( $r ) : array();
		$values['hosting_notice']  = in_array( $data['hosting_notice'] ?? '', HostingNotice::KINDS, true ) ? array(
			'kind'        => (string) $data['hosting_notice'],
			'percent'     => max( 0, (int) ( $data['percent'] ?? 0 ) ),
			'used'        => max( 0, (int) ( $data['used'] ?? 0 ) ),
			'limit'       => max( 0, (int) ( $data['limit'] ?? 0 ) ),
			'days'        => max( 0, (int) ( $data['days'] ?? 0 ) ),
			'php'         => isset( $data['php'] ) ? sanitize_text_field( (string) $data['php'] ) : '',
			'php_default' => isset( $data['php_default'] ) ? sanitize_text_field( (string) $data['php_default'] ) : '',
			'php_end'     => isset( $data['php_end'] ) ? sanitize_text_field( (string) $data['php_end'] ) : '',
			'site'        => isset( $data['site'] ) ? HostName::display( sanitize_text_field( (string) $data['site'] ) ) : '',
		) : array( 'kind' => 'storage_warning', 'percent' => 0, 'used' => 0, 'limit' => 0, 'days' => 0, 'php' => '', 'php_default' => '', 'php_end' => '', 'site' => '' );
		$values['hosting_restore'] = in_array( $data['hosting_restore'] ?? '', HostingRestoreDone::KINDS, true ) ? array(
			'kind'  => (string) $data['hosting_restore'],
			'ok'    => ! empty( $data['ok'] ),
			'until' => isset( $data['until'] ) ? sanitize_text_field( (string) $data['until'] ) : '',
			'site'  => isset( $data['site'] ) ? HostName::display( sanitize_text_field( (string) $data['site'] ) ) : '',
		) : array( 'kind' => 'account', 'ok' => true, 'until' => '', 'site' => '' );
		$values['hosting_malware'] = MalwareScan::email_values( $data );

		return $values;
	}
}
