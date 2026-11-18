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

	/** The distributions the fake lists for every product, in the form of the documentation example ("Rescue system" included). */
	public const DISTS = array(
		'Rescue system',
		'Alma Linux 9.4 minimal',
		'Arch Linux latest minimal',
		'CentOS Stream 9 minimal',
		'Debian 11.9 minimal',
		'Debian 12.5 minimal',
		'Rocky Linux 9.4 minimal',
		'Ubuntu 22.04.4 LTS minimal',
		'Ubuntu 24.04 LTS minimal',
	);

	/**
	 * Makes a server known to the fake without an order (tests).
	 *
	 * @param int      $number Server number.
	 * @param string[] $types  Reset types it lists.
	 * @param bool     $wol    Whether Wake on LAN is available.
	 */
	public static function add_server( int $number, array $types = array( 'sw', 'hw', 'man' ), bool $wol = true ): void {
		$state                                = self::state();
		$state['servers'][ $number ]          = array( 'types' => $types, 'wol' => $wol, 'rescue' => array( 'active' => false, 'password' => null, 'keys' => array(), 'os' => '' ), 'rdns' => array(), 'firewall' => self::default_firewall() );
		self::save( $state );
	}

	/**
	 * What the fake was asked to do, for tests: a list of arrays with the call and its public arguments (never a password).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function calls(): array {
		return (array) ( self::state()['calls'] ?? array() );
	}

	/**
	 * The last rescue password the fake gave, for tests of the code that must never store or show it by mistake.
	 *
	 * @param int $number Server number.
	 */
	public static function rescue_password( int $number ): string {
		return (string) ( self::state()['servers'][ $number ]['rescue']['password'] ?? '' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function default_firewall(): array {
		return array( 'status' => 'disabled', 'filter_ipv6' => false, 'whitelist_hos' => true, 'port' => 'main', 'rules' => array( 'input' => array(), 'output' => array() ) );
	}

	/**
	 * Does the fake know this server (ordered through it, or added)? Creates its control state on first use.
	 *
	 * @param array<string, mixed> $state  State, by reference.
	 * @param int                  $number Server number.
	 */
	private static function known( array &$state, int $number ): bool {
		if ( isset( $state['servers'][ $number ] ) ) {
			return true;
		}
		foreach ( (array) ( $state['tx'] ?? array() ) as $entry ) {
			if ( (int) $entry['number'] === $number ) {
				$state['servers'][ $number ] = array( 'types' => array( 'sw', 'hw', 'man' ), 'wol' => true, 'rescue' => array( 'active' => false, 'password' => null, 'keys' => array(), 'os' => '' ), 'rdns' => array(), 'firewall' => self::default_firewall() );
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $state State, by reference.
	 * @param string               $call  Call.
	 * @param array<string, mixed> $args  Public arguments.
	 */
	private static function log( array &$state, string $call, array $args ): void {
		$state['calls'][] = array( 'call' => $call ) + $args;
	}

	/**
	 * The addresses of a server: its IPv4, and a /64 net: an address is inside when its first 64 bits are the net.
	 *
	 * @param int    $number Server number.
	 * @param string $ip     Address.
	 */
	private static function owns( int $number, string $ip ): bool {
		$v4 = '192.0.2.' . ( $number % 250 + 1 );
		if ( $ip === $v4 ) {
			return true;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid address is a plain no.
		$net    = @inet_pton( '2001:db8:' . dechex( $number % 65535 ) . '::' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $packed && false !== $net && 16 === strlen( $packed ) && substr( $packed, 0, 8 ) === substr( $net, 0, 8 );
	}

	public function reset_options( int $number ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with id ' . $number . ' not found' );
		}
		$server = $state['servers'][ $number ];
		if ( ! $server['types'] ) {
			return self::error( 404, 'RESET_NOT_AVAILABLE', 'The server has no reset option' );
		}
		return array( 'server_ip' => '192.0.2.' . ( $number % 250 + 1 ), 'server_ipv6_net' => '2001:db8:' . dechex( $number % 65535 ) . '::', 'server_number' => $number, 'type' => $server['types'], 'operating_status' => 'not supported' );
	}

	public function reset_server( int $number, string $type ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with id ' . $number . ' not found' );
		}
		if ( ! $state['servers'][ $number ]['types'] ) {
			return self::error( 404, 'RESET_NOT_AVAILABLE', 'The server has no reset option' );
		}
		if ( ! in_array( $type, $state['servers'][ $number ]['types'], true ) || 'man' === $type ) {
			return self::error( 400, 'INVALID_INPUT', 'Invalid input parameters' );
		}
		self::log( $state, 'reset', array( 'number' => $number, 'type' => $type ) );
		self::save( $state );
		return array( 'server_ip' => '192.0.2.' . ( $number % 250 + 1 ), 'server_ipv6_net' => '2001:db8:' . dechex( $number % 65535 ) . '::', 'type' => $type );
	}

	public function wol_available( int $number ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server not found' );
		}
		return $state['servers'][ $number ]['wol'] ? array( 'server_number' => $number ) : self::error( 404, 'WOL_NOT_AVAILABLE', 'Wake On LAN is not available on this server' );
	}

	public function wol( int $number ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server not found' );
		}
		if ( ! $state['servers'][ $number ]['wol'] ) {
			return self::error( 404, 'WOL_NOT_AVAILABLE', 'Wake On LAN is not available on this server' );
		}
		self::log( $state, 'wol', array( 'number' => $number ) );
		self::save( $state );
		return array( 'server_number' => $number );
	}

	public function rescue_status( int $number ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with id ' . $number . ' not found' );
		}
		return self::rescue_object( $number, $state['servers'][ $number ]['rescue'] );
	}

	/**
	 * @param int                  $number Server number.
	 * @param array<string, mixed> $rescue Stored rescue state.
	 * @return array<string, mixed>
	 */
	private static function rescue_object( int $number, array $rescue ): array {
		return array(
			'server_ip'       => '192.0.2.' . ( $number % 250 + 1 ),
			'server_ipv6_net' => '2001:db8:' . dechex( $number % 65535 ) . '::',
			'server_number'   => $number,
			'os'              => $rescue['active'] ? $rescue['os'] : array( 'linux', 'vkvm' ),
			'active'          => (bool) $rescue['active'],
			'password'        => $rescue['active'] ? $rescue['password'] : null,
			'authorized_key'  => $rescue['active'] ? array_map( static fn( $f ) => array( 'key' => array( 'fingerprint' => $f ) ), $rescue['keys'] ) : array(),
			'host_key'        => array(),
		);
	}

	public function rescue_activate( int $number, string $os, array $keys ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with id ' . $number . ' not found' );
		}
		if ( ! in_array( $os, array( 'linux', 'vkvm' ), true ) ) {
			return self::error( 400, 'INVALID_INPUT', 'Invalid input parameters' );
		}
		foreach ( $keys as $fingerprint ) {
			if ( ! isset( $state['keys'][ (string) $fingerprint ] ) ) {
				return self::error( 400, 'INVALID_INPUT', 'Invalid input parameters' );
			}
		}
		// Doc, example of POST /boot/{n}/rescue without a key: the answer holds the generated root password.
		$state['servers'][ $number ]['rescue'] = array( 'active' => true, 'os' => $os, 'password' => $keys ? null : wp_generate_password( 16, false, false ), 'keys' => array_values( array_map( 'strval', $keys ) ) );
		self::log( $state, 'rescue_activate', array( 'number' => $number, 'os' => $os, 'keys' => array_values( array_map( 'strval', $keys ) ) ) );
		self::save( $state );
		return self::rescue_object( $number, $state['servers'][ $number ]['rescue'] );
	}

	public function rescue_deactivate( int $number ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with id ' . $number . ' not found' );
		}
		$state['servers'][ $number ]['rescue'] = array( 'active' => false, 'password' => null, 'keys' => array(), 'os' => '' );
		self::log( $state, 'rescue_deactivate', array( 'number' => $number ) );
		self::save( $state );
		return self::rescue_object( $number, $state['servers'][ $number ]['rescue'] );
	}

	public function rdns_list( string $server_ip ) {
		$state = self::state();
		$rows  = array();
		foreach ( (array) ( $state['servers'] ?? array() ) as $number => $server ) {
			if ( '192.0.2.' . ( (int) $number % 250 + 1 ) === $server_ip ) {
				foreach ( (array) $server['rdns'] as $ip => $ptr ) {
					$rows[] = array( 'ip' => (string) $ip, 'ptr' => (string) $ptr );
				}
			}
		}
		return $rows;
	}

	/**
	 * The server number that owns an address, or 0.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $ip    Address.
	 */
	private static function owner_of( array $state, string $ip ): int {
		foreach ( array_keys( (array) ( $state['servers'] ?? array() ) ) as $number ) {
			if ( self::owns( (int) $number, $ip ) ) {
				return (int) $number;
			}
		}
		return 0;
	}

	public function rdns_set( string $ip, string $ptr ) {
		$state  = self::state();
		$number = self::owner_of( $state, $ip );
		if ( $number < 1 ) {
			return self::error( 404, 'IP_NOT_FOUND', 'The IP address ' . $ip . ' was not found' );
		}
		if ( '' === trim( $ptr ) || ! preg_match( '/^[A-Za-z0-9.-]+$/D', $ptr ) ) {
			return self::error( 400, 'INVALID_INPUT', 'Invalid input parameters' );
		}
		$state['servers'][ $number ]['rdns'][ $ip ] = $ptr;
		self::log( $state, 'rdns_set', array( 'ip' => $ip, 'ptr' => $ptr ) );
		self::save( $state );
		return array( 'ip' => $ip, 'ptr' => $ptr );
	}

	public function rdns_delete( string $ip ) {
		$state  = self::state();
		$number = self::owner_of( $state, $ip );
		if ( $number < 1 ) {
			return self::error( 404, 'IP_NOT_FOUND', 'The IP address ' . $ip . ' was not found' );
		}
		unset( $state['servers'][ $number ]['rdns'][ $ip ] );
		self::log( $state, 'rdns_delete', array( 'ip' => $ip ) );
		self::save( $state );
		return true;
	}

	public function firewall( int $number ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with ID ' . $number . ' not found' );
		}
		return array( 'server_ip' => '192.0.2.' . ( $number % 250 + 1 ), 'server_number' => $number ) + $state['servers'][ $number ]['firewall'];
	}

	public function firewall_set( int $number, array $config ) {
		$state = self::state();
		if ( ! self::known( $state, $number ) ) {
			return self::error( 404, 'SERVER_NOT_FOUND', 'Server with ID ' . $number . ' not found' );
		}
		$current = $state['servers'][ $number ]['firewall'];
		$status  = (string) ( $config['status'] ?? $current['status'] );
		if ( ! in_array( $status, array( 'active', 'disabled' ), true ) ) {
			return self::error( 400, 'INVALID_INPUT', 'Invalid input parameters' );
		}
		$rules = array( 'input' => array(), 'output' => array() );
		foreach ( array( 'input', 'output' ) as $direction ) {
			foreach ( array_values( (array) ( $config['rules'][ $direction ] ?? array() ) ) as $rule ) {
				$rule = (array) $rule;
				if ( ! in_array( (string) ( $rule['action'] ?? '' ), array( 'accept', 'discard' ), true ) ) {
					return self::error( 400, 'INVALID_INPUT', 'Invalid input parameters' );
				}
				$row = array();
				foreach ( array( 'ip_version', 'name', 'dst_ip', 'src_ip', 'dst_port', 'src_port', 'protocol', 'tcp_flags', 'action' ) as $field ) {
					$row[ $field ] = isset( $rule[ $field ] ) && '' !== (string) $rule[ $field ] ? (string) $rule[ $field ] : null;
				}
				$rules[ $direction ][] = $row;
			}
		}
		if ( count( $rules['input'] ) > 30 ) {
			return self::error( 409, 'FIREWALL_RULE_LIMIT_EXCEEDED', 'The firewall rule limit is exceeded' );
		}
		$state['servers'][ $number ]['firewall'] = array(
			'status'        => $status,
			'filter_ipv6'   => (bool) ( $config['filter_ipv6'] ?? $current['filter_ipv6'] ),
			'whitelist_hos' => (bool) ( $config['whitelist_hos'] ?? $current['whitelist_hos'] ),
			'port'          => 'main',
			'rules'         => $rules,
		);
		self::log( $state, 'firewall_set', array( 'number' => $number, 'status' => $status, 'input' => count( $rules['input'] ) ) );
		self::save( $state );
		return array( 'server_ip' => '192.0.2.' . ( $number % 250 + 1 ), 'server_number' => $number ) + $state['servers'][ $number ]['firewall'];
	}

	public function products() {
		$out = array();
		foreach ( array( 'EX44', 'AX52', 'AX102' ) as $id ) {
			$out[] = array( 'id' => $id, 'name' => 'Dedicated Server ' . $id, 'dist' => self::DISTS, 'lang' => array( 'en' ), 'location' => array( 'FSN1', 'HEL1' ) );
		}
		return $out;
	}
}
