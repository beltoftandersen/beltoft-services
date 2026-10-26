<?php
/** Registrant handle creation and reuse. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class Contacts {
	private const META_HANDLE = '_beltoft_services_domain_handle';

	public static function handle_for( int $user_id, \WC_Order $order ): string|\WP_Error {
		$handle = (string) get_user_meta( $user_id, self::META_HANDLE, true );
		if ( '' !== $handle ) { return $handle; }
		$first = trim( $order->get_billing_first_name() );
		$last = trim( $order->get_billing_last_name() );
		$email = (string) $order->get_billing_email();
		$country = strtoupper( (string) $order->get_billing_country() );
		$street = trim( (string) $order->get_billing_address_1() );
		$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		if ( '' === $first || '' === $last || ! is_email( $email ) || '' === $country || '' === $street || strlen( $phone ) < 6 ) {
			return new \WP_Error( 'contact_incomplete', 'The billing contact needs a name, email, address, country and phone number.' );
		}
		$number = '';
		if ( preg_match( '/^(.*?)[,\s]+(\d+\S*)$/u', $street, $match ) ) { $street = trim( $match[1] ); $number = $match[2]; }
		$calling = WC()->countries->get_country_calling_code( $country );
		$calling = is_array( $calling ) ? (string) reset( $calling ) : (string) $calling;
		$calling = '+' . ltrim( $calling, '+' );
		$national = preg_replace( '/\D+/', '', (string) $calling );
		if ( str_starts_with( $phone, $national ) ) { $phone = substr( $phone, strlen( $national ) ); }
		$body = array(
			'name' => array( 'first_name' => $first, 'last_name' => $last ),
			'company_name' => (string) $order->get_billing_company(),
			'email' => $email,
			'address' => array( 'street' => $street, 'number' => $number, 'zipcode' => (string) $order->get_billing_postcode(), 'city' => (string) $order->get_billing_city(), 'state' => (string) $order->get_billing_state(), 'country' => $country ),
			'phone' => array( 'country_code' => $calling, 'area_code' => substr( $phone, 0, 2 ), 'subscriber_number' => substr( $phone, 2 ) ),
		);
		$vat = (string) $order->get_meta( '_billing_vat', true );
		if ( '' !== $vat ) { $body['vat'] = $vat; }
		$result = Clients::registrar()->request( 'POST', '/customers', $body );
		if ( is_wp_error( $result ) ) { return $result; }
		$handle = (string) ( $result['handle'] ?? '' );
		if ( '' === $handle ) { return new \WP_Error( 'bad_response', 'Registrar did not return a contact handle.' ); }
		update_user_meta( $user_id, self::META_HANDLE, $handle );
		return $handle;
	}
}
