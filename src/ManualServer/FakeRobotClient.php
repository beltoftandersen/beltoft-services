<?php
/**
 * An in-process Robot webservice for tests and for a site with the fake client. Same shapes and error codes as the documentation.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * A transaction is "in process" until it has been read READS times (filter-free, a constant) or finish() is called, then "ready"
 * with a fake server number and an address from the documentation range 192.0.2.0/24. State lives in the option OPTION.
 */
final class FakeRobotClient implements RobotClient {
	public const OPTION = 'beltoft_services_fake_robot';

	/** Reads of a transaction before it is ready. */
	public const READS = 2;

	/** The orders the fake got, for tests: each a list of the fields (without the password). @var array<int, array<string, mixed>> */
	public static function orders(): array {
		return (array) ( self::state()['orders'] ?? array() );
	}

	/**
	 * The keys registered at the fake, fingerprint => key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function registered_keys(): array {
		return (array) ( self::state()['keys'] ?? array() );
	}

	/** Forgets everything. */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Makes a transaction ready at once.
	 *
	 * @param string $id Transaction id.
	 */
	public static function finish( string $id ): void {
		$state = self::state();

		if ( isset( $state['tx'][ $id ] ) ) {
			$state['tx'][ $id ]['reads'] = self::READS;
			self::save( $state );
		}
	}

	/**
	 * Cancels a transaction at the provider (status "cancelled").
	 *
	 * @param string $id Transaction id.
	 */
	public static function cancel_transaction( string $id ): void {
		$state = self::state();

		if ( isset( $state['tx'][ $id ] ) ) {
			$state['tx'][ $id ]['cancelled'] = true;
			self::save( $state );
		}
	}

	/**
	 * Server numbers that were cancelled.
	 *
	 * @return int[]
	 */
	public static function cancelled(): array {
		return array_map( 'intval', array_keys( (array) ( self::state()['cancelled'] ?? array() ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	private static function error( int $status, string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * @param array<string, mixed> $fields Fields.
	 */
	public function order( array $fields ) {
		$missing = array();

		if ( '' === (string) ( $fields['product_id'] ?? '' ) ) {
			$missing[] = 'product_id';
		}

		if ( $missing ) {
			return self::error( 400, 'INVALID_INPUT', 'invalid input' );
		}

		$state = self::state();
		$id    = 'B' . gmdate( 'Ymd' ) . '-' . ( 100000 + count( (array) ( $state['tx'] ?? array() ) ) ) . '-' . ( 200000 + count( (array) ( $state['orders'] ?? array() ) ) );
		$tx    = array(
			'id'             => $id,
			'date'           => gmdate( 'c' ),
			'status'         => 'in process',
			'server_number'  => null,
			'server_ip'      => null,
			'host_key'       => array(),
			'comment'        => null,
			'product'        => array( 'id' => (string) $fields['product_id'], 'name' => 'Dedicated Server ' . $fields['product_id'], 'dist' => (string) ( $fields['dist'] ?? '' ), 'location' => (string) ( $fields['location'] ?? '' ) ),
			'addons'         => array_values( (array) ( $fields['addon'] ?? array() ) ),
			'authorized_key' => array_map( static fn( $f ) => array( 'key' => array( 'fingerprint' => (string) $f ) ), array_values( (array) ( $fields['authorized_key'] ?? array() ) ) ),
		);

		// Either a key or a password (the documentation), never both.
		if ( ! empty( $fields['authorized_key'] ) && '' !== (string) ( $fields['password'] ?? '' ) ) {
			return self::error( 400, 'INVALID_INPUT', 'invalid input' );
		}

		$state['orders'][] = array_diff_key( $fields, array( 'password' => 1 ) );

		// "The order will not be processed if set to true": it is answered, and nothing is kept.
		if ( empty( $fields['test'] ) ) {
			$state['tx'][ $id ] = array( 'reads' => 0, 'cancelled' => false, 'number' => 400000 + count( (array) ( $state['tx'] ?? array() ) ) );
			$state['tx'][ $id ] += array( 'data' => $tx );
		}

		self::save( $state );

		return $tx;
	}

	public function add_key( string $name, string $data ) {
		$parts = preg_split( '/\s+/', trim( $data ) );

		if ( '' === trim( $name ) || ! is_array( $parts ) || count( $parts ) < 2 ) {
			return self::error( 400, 'INVALID_INPUT', 'invalid input' );
		}

		$state = self::state();

		foreach ( (array) ( $state['keys'] ?? array() ) as $key ) {
			if ( $key['data'] === $parts[0] . ' ' . $parts[1] ) {
				return self::error( 409, 'KEY_ALREADY_EXISTS', 'The supplied key already exists' );
			}
		}

		$print = implode( ':', str_split( md5( $parts[1] ), 2 ) );
		$key   = array( 'name' => $name, 'fingerprint' => $print, 'type' => 'ED25519', 'size' => 256, 'data' => $parts[0] . ' ' . $parts[1], 'created_at' => gmdate( 'Y-m-d H:i:s' ) );

		$state['keys'][ $print ] = $key;
		self::save( $state );

		return $key;
	}

	public function keys() {
		$keys = array_values( (array) ( self::state()['keys'] ?? array() ) );

		return $keys ? $keys : self::error( 404, 'NOT_FOUND', 'No keys found' );
	}

	public function transaction( string $id ) {
		$state = self::state();

		if ( ! isset( $state['tx'][ $id ] ) ) {
			return self::error( 404, 'NOT_FOUND', 'Transaction not found' );
		}

		$entry = $state['tx'][ $id ];
		$tx    = (array) $entry['data'];

		if ( ! empty( $entry['cancelled'] ) ) {
			$tx['status'] = 'cancelled';
		} elseif ( (int) $entry['reads'] >= self::READS ) {
			$tx['status']        = 'ready';
			$tx['server_number'] = (int) $entry['number'];
			$tx['server_ip']     = '192.0.2.' . ( (int) $entry['number'] % 250 + 1 );
		} else {
			++$state['tx'][ $id ]['reads'];
			self::save( $state );
		}

		return $tx;
	}

	public function server( int $number ) {
		if ( $number < 1 ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with id ' . $number . ' not found' );
		}

		return array(
			'server_ip'        => '192.0.2.' . ( $number % 250 + 1 ),
			'server_ipv6_net'  => '2001:db8:' . dechex( $number % 65535 ) . '::',
			'server_number'    => $number,
			'status'           => 'ready',
			'cancelled'        => isset( self::state()['cancelled'][ $number ] ),
		);
	}

	public function cancel( int $number ) {
		$state = self::state();
		$known = false;

		foreach ( (array) ( $state['tx'] ?? array() ) as $entry ) {
			$known = $known || (int) $entry['number'] === $number;
		}

		if ( ! $known ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with id ' . $number . ' not found' );
		}

		if ( isset( $state['cancelled'][ $number ] ) ) {
			return self::error( 409, 'CONFLICT', 'The server is already cancelled' );
		}

		$state['cancelled'][ $number ] = gmdate( 'Y-m-d' );
		self::save( $state );

		return array( 'server_number' => $number, 'cancelled' => true, 'reserved' => false, 'reservation_possible' => false, 'cancellation_date' => gmdate( 'Y-m-d' ), 'cancellation_reason' => null );
	}
}
