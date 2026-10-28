<?php
/** Public domain details for the service ready email. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class EmailDetails {
	public static function register(): void { add_filter( 'beltoft_services_email_details', array( __CLASS__, 'details' ), 10, 3 ); }
	public static function details( $details, $email_id, $r ) {
		if ( ! is_array( $details ) || 'beltoft_services_ready' !== $email_id || ! $r instanceof Resource || ! in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ) { return $details; }
		$details[ __( 'Domain', 'beltoft-services' ) ] = (string) $r->get( 'domain' );
		$details[ __( 'Name servers', 'beltoft-services' ) ] = implode( ', ', Zone::nameservers() );
		return $details;
	}
}
