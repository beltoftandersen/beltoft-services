<?php
/** Encrypted registrar and DNS credentials. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Crypto;

defined( 'ABSPATH' ) || exit;

final class Account {
	public const OPTION = 'beltoft_services_domains_account';

	/** Return decrypted credentials only to the client factory. */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'username' => (string) ( $saved['username'] ?? '' ),
			'password' => Crypto::decrypt( (string) ( $saved['password'] ?? '' ) ) ?? '',
			'handle'   => (string) ( $saved['handle'] ?? '' ),
			'dns_key'  => Crypto::decrypt( (string) ( $saved['dns_key'] ?? '' ) ) ?? '',
		);
	}

	/** Blank secret values leave existing encrypted secrets untouched. */
	public static function save( array $input ): void {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( array( 'username', 'handle' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$saved[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		foreach ( array( 'password', 'dns_key' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
				$saved[ $key ] = Crypto::encrypt( (string) $input[ $key ] );
			}
		}
		update_option( self::OPTION, $saved, false );
	}
}
