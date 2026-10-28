<?php
/** Registrant handle creation and reuse. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * A customer's registrant handle is reused while the billing details on the order are the ones it was made from. When they
 * differ (name, company, address, email, country, VAT) a new handle is made for the new order. A handle is never changed in
 * place: the domains already on it stay as they are, because changing a registrant can start registry checks.
 */
final class Contacts {
	private const META_HANDLE  = '_beltoft_services_domain_handle';
	private const META_DETAILS = '_beltoft_services_domain_contact';

	/** The handle to register with for this order: the customer's current one, or a new one when the billing details changed. */
	public static function handle_for( int $user_id, \WC_Order $order ): string|\WP_Error {
		$cached = (string) get_user_meta( $user_id, self::META_HANDLE, true );
		$read   = self::read( $order );
		if ( is_wp_error( $read ) ) {
			// Nothing to compare with an incomplete order: an existing handle stays, otherwise the order waits for its details.
			return '' !== $cached ? $cached : $read;
		}
		$hash  = self::hash( $read['key'] );
		$saved = self::details( $user_id );
		if ( '' !== $cached && ( $saved['handle'] ?? '' ) === $cached && ( $saved['hash'] ?? '' ) === $hash ) { return $cached; }
		$result = Clients::registrar()->request( 'POST', '/customers', $read['body'] );
		if ( is_wp_error( $result ) ) { return $result; }
		$handle = (string) ( $result['handle'] ?? '' );
		if ( '' === $handle ) { return new \WP_Error( 'bad_response', 'Registrar did not return a contact handle.' ); }
		update_user_meta( $user_id, self::META_HANDLE, $handle );
		update_user_meta( $user_id, self::META_DETAILS, array(
			'handle'   => $handle,
			'hash'     => $hash,
			'country'  => $read['key']['country'],
			'vat'      => '' !== $read['key']['vat'],
			'private'  => 'private' === $read['type'],
		) );
		return $handle;
	}

	/** What is known of the registrant behind a handle, or an empty array when the handle was made before the details were kept. */
	public static function details_of( int $user_id, string $handle ): array {
		$saved = self::details( $user_id );
		return '' !== $handle && ( $saved['handle'] ?? '' ) === $handle ? $saved : array();
	}

	/**
	 * Does the registrant behind the handle that is sent qualify for this extension? Unknown details pass: the registrar decides.
	 *
	 * @param string               $domain  Domain name.
	 * @param array<string, mixed> $details Result of details_of().
	 */
	public static function eligible( string $domain, array $details ): true|\WP_Error {
		if ( array() === $details ) { return true; }
		if ( str_ends_with( $domain, '.eu' ) && ! TldRules::eu_country( (string) ( $details['country'] ?? '' ) ) ) {
			return new \WP_Error( 'eu_registrant', 'A .eu domain needs a registrant in the EU; the registrant on this order is outside it.' );
		}
		if ( str_ends_with( $domain, '.dk' ) && empty( $details['private'] ) && empty( $details['vat'] ) ) {
			return new \WP_Error( 'dk_registrant', 'A .dk domain ordered by a business needs a VAT number; the registrant on this order has none.' );
		}
		return true;
	}

	private static function details( int $user_id ): array {
		$saved = get_user_meta( $user_id, self::META_DETAILS, true );
		return is_array( $saved ) ? $saved : array();
	}

	/** The fields that make a registrant, as compared; a change in any of them is a new handle. */
	private static function hash( array $key ): string {
		return hash( 'sha256', (string) wp_json_encode( $key ) );
	}

	/** @return array{body: array<string, mixed>, key: array<string, string>, type: string}|\WP_Error */
	private static function read( \WC_Order $order ): array|\WP_Error {
		$first = trim( $order->get_billing_first_name() );
		$last = trim( $order->get_billing_last_name() );
		$email = (string) $order->get_billing_email();
		$country = strtoupper( (string) $order->get_billing_country() );
		$street = trim( (string) $order->get_billing_address_1() );
		$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		if ( '' === $first || '' === $last || ! is_email( $email ) || '' === $country || '' === $street || strlen( $phone ) < 6 ) {
			return new \WP_Error( 'contact_incomplete', 'The billing contact needs a name, email, address, country and phone number.' );
		}
		$full_street = $street;
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
		// The EU VAT plugin stores the number as _billing_eu_vat_number; _billing_vat is kept for sites that set it themselves.
		$vat = (string) $order->get_meta( '_billing_eu_vat_number', true );
		if ( '' === trim( $vat ) ) { $vat = (string) $order->get_meta( '_billing_vat', true ); }
		$vat = (string) preg_replace( '/\s+/', '', $vat );
		if ( '' !== $vat ) { $body['vat'] = $vat; }
		$key = array(
			'first'   => mb_strtolower( $first ),
			'last'    => mb_strtolower( $last ),
			'company' => mb_strtolower( trim( (string) $order->get_billing_company() ) ),
			'email'   => strtolower( $email ),
			'street'  => mb_strtolower( $full_street ),
			'zipcode' => strtolower( trim( (string) $order->get_billing_postcode() ) ),
			'city'    => mb_strtolower( trim( (string) $order->get_billing_city() ) ),
			'state'   => strtolower( trim( (string) $order->get_billing_state() ) ),
			'country' => $country,
			'vat'     => strtoupper( $vat ),
			// Private or business decides the .dk rule, so it is part of who the registrant is.
			'type'    => sanitize_key( (string) $order->get_meta( '_billing_customer_type', true ) ),
		);
		return array( 'body' => $body, 'key' => $key, 'type' => $key['type'] );
	}
}
