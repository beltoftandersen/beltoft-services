<?php
/**
 * The client when live ordering is switched on but cannot be used. Never the fake: it would record an order that never happened.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

final class UnavailableRobotClient implements RobotClient {

	/**
	 * @param array<string, mixed> $fields Fields.
	 */
	public function order( array $fields ) {
		unset( $fields );

		return $this->no();
	}

	public function add_key( string $name, string $data ) {
		unset( $name, $data );

		return $this->no();
	}

	public function keys() {
		return $this->no();
	}

	public function transaction( string $id ) {
		unset( $id );

		return $this->no();
	}

	public function server( int $number ) {
		unset( $number );

		return $this->no();
	}

	public function cancel( int $number ) {
		unset( $number );

		return $this->no();
	}

	public function reset_options( int $number ) {
		unset( $number );
		return $this->no();
	}

	public function reset_server( int $number, string $type ) {
		unset( $number, $type );
		return $this->no();
	}

	public function wol_available( int $number ) {
		unset( $number );
		return $this->no();
	}

	public function wol( int $number ) {
		unset( $number );
		return $this->no();
	}

	public function rescue_status( int $number ) {
		unset( $number );
		return $this->no();
	}

	public function rescue_activate( int $number, string $os, array $keys ) {
		unset( $number, $os, $keys );
		return $this->no();
	}

	public function rescue_deactivate( int $number ) {
		unset( $number );
		return $this->no();
	}

	public function rdns_list( string $server_ip ) {
		unset( $server_ip );
		return $this->no();
	}

	public function rdns_set( string $ip, string $ptr ) {
		unset( $ip, $ptr );
		return $this->no();
	}

	public function rdns_delete( string $ip ) {
		unset( $ip );
		return $this->no();
	}

	public function firewall( int $number ) {
		unset( $number );
		return $this->no();
	}

	public function firewall_set( int $number, array $config ) {
		unset( $number, $config );
		return $this->no();
	}

	public function products() {
		return $this->no();
	}

	private function no(): \WP_Error {
		return new \WP_Error( 'robot_unavailable', 'Automatic ordering is not usable: the address or login is missing, or the live switch is off.', array( 'status' => 0 ) );
	}
}
