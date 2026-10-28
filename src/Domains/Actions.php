<?php
/** Customer domain and DNS actions. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Actions {
	public static function register(): void {
		add_filter( 'beltoft_services_action_refusal', array( __CLASS__, 'refusal' ), 10, 4 );
	}

	public static function offered( Resource $r ): array {
		if ( 'ready' !== $r->get( 'state' ) ) { return array(); }
		$common = array( 'set_records' => __( 'Save DNS records', 'beltoft-services' ) );
		if ( 'dns' === $r->get( 'driver' ) ) { return $common + array( 'check_delegation' => __( 'Check name servers', 'beltoft-services' ) ); }
		return $common + array(
			'set_nameservers' => __( 'Change name servers', 'beltoft-services' ),
			'lock' => __( 'Lock transfer', 'beltoft-services' ),
			'unlock' => __( 'Unlock transfer', 'beltoft-services' ),
		);
	}

	public static function refusal( $refusal, $r, $action, $args ) {
		if ( null !== $refusal || ! $r instanceof Resource || ! in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ) { return $refusal; }
		$error = self::validate( $r, (string) $action, (array) $args );
		return is_wp_error( $error ) ? $error : null;
	}

	private static function validate( Resource $r, string $action, array $args ): true|\WP_Error {
		if ( 'set_records' === $action && $r->get( 'dns_external' ) ) { return new \WP_Error( 'dns_external', 'This domain\'s DNS is hosted elsewhere.' ); }
		if ( 'set_records' === $action && $r->get( 'records_off' ) ) { return new \WP_Error( 'paused', 'DNS hosting is paused.' ); }
		if ( ! isset( self::offered( $r )[ $action ] ) ) { return new \WP_Error( 'unavailable', 'This action is unavailable.' ); }
		if ( 'set_records' === $action ) {
			if ( strlen( (string) ( $args['records'] ?? '' ) ) > 100000 ) { return new \WP_Error( 'bad_records', 'Enter a valid DNS record list.' ); }
			$records = json_decode( (string) ( $args['records'] ?? '' ), true );
			if ( ! is_array( $records ) || ! array_is_list( $records ) ) { return new \WP_Error( 'bad_records', 'Enter a valid DNS record list.' ); }
			$checked = Records::validate( (string) $r->get( 'domain' ), $records );
			return is_wp_error( $checked ) ? $checked : true;
		}
		if ( 'set_nameservers' === $action ) {
			$mode = (string) ( $args['mode'] ?? '' );
			// Moving DNS here needs the zone built and the records in it before the switch: not offered while the DNS is hosted elsewhere.
			if ( 'ours' === $mode ) { return $r->get( 'dns_external' ) ? new \WP_Error( 'dns_external', 'This domain\'s DNS is hosted elsewhere. Contact support to move it here.' ) : true; }
			$hosts = self::hosts( (string) ( $args['hosts'] ?? '' ) );
			return 'custom' === $mode && ! is_wp_error( $hosts ) ? true : new \WP_Error( 'bad_nameservers', 'Enter two to six valid name servers.' );
		}
		if ( 'check_delegation' === $action && (int) $r->get( 'delegation_checked' ) > time() - MINUTE_IN_SECONDS ) { return new \WP_Error( 'rate_limited', 'Check again in a minute.' ); }
		return true;
	}

	private static function hosts( string $raw ): array|\WP_Error {
		$hosts = preg_split( '/[\s,]+/', strtolower( trim( $raw ) ) );
		$hosts = array_values( array_unique( array_map( static fn( $host ) => rtrim( $host, '.' ), $hosts ) ) );
		if ( count( $hosts ) < 2 || count( $hosts ) > 6 ) { return new \WP_Error( 'bad_nameservers', 'Enter two to six valid name servers.' ); }
		foreach ( $hosts as $host ) { if ( is_wp_error( Search::normalise( $host ) ) ) { return new \WP_Error( 'bad_nameservers', 'Enter two to six valid name servers.' ); } }
		return $hosts;
	}

	public static function run( Resource $r, string $action, array $args ): StepResult {
		$valid = self::validate( $r, $action, $args );
		if ( is_wp_error( $valid ) ) { return StepResult::fail( $valid->get_error_message() ); }
		$name = (string) $r->get( 'domain' );
		if ( 'set_records' === $action ) {
			$result = Records::replace( $name, json_decode( (string) $args['records'], true ) );
			return is_wp_error( $result ) ? StepResult::retry( 60, 'DNS records could not be saved.' ) : StepResult::done();
		}
		if ( 'check_delegation' === $action ) {
			$status = Delegation::refresh( $r, true );
			return is_wp_error( $status ) ? StepResult::fail( $status->get_error_message() ) : StepResult::done( array( 'delegation' => $status, 'delegation_checked' => time() ) );
		}
		$id = rawurlencode( (string) $r->get( 'remote_id' ) );
		if ( '' === $id ) { return StepResult::fail( 'Registrar id is unavailable.' ); }
		if ( 'set_nameservers' === $action ) {
			$mode = (string) $args['mode'];
			$hosts = 'ours' === $mode ? Zone::nameservers() : self::hosts( (string) $args['hosts'] );
			$keys = array();
			if ( 'custom' === $mode && ! empty( $r->get( 'dnssec_keys' ) ) ) {
				$cleared = Clients::registrar()->request( 'PUT', '/domains/' . $id, array( 'dnssec_keys' => array(), 'is_dnssec_enabled' => false ) );
				if ( is_wp_error( $cleared ) ) { return StepResult::retry( 60, 'DNSSEC could not be removed.' ); }
				$r->set( array( 'dnssec_keys' => array() ) );
			}
			if ( 'ours' === $mode ) {
				$zone = Zone::ensure( $r, true, false );
				if ( ! $zone->is_done() ) { return $zone; }
				$keys = (array) ( $zone->meta()['dnssec_keys'] ?? array() );
				// The DS is published only once every name server serves the keys; nothing was changed yet, so a retry is safe.
				$signed = $keys ? Zone::served_signed( $name ) : StepResult::done();
				if ( ! $signed->is_done() ) { return $signed; }
			}
			$reply = Clients::registrar()->request( 'PUT', '/domains/' . $id, array( 'ns_group' => '', 'name_servers' => array_map( static fn( $host ) => array( 'name' => $host ), $hosts ) ) );
			if ( is_wp_error( $reply ) ) { return StepResult::retry( 60, 'Name servers could not be changed.' ); }
			if ( 'ours' === $mode ) {
				$ds = Clients::registrar()->request( 'PUT', '/domains/' . $id, array( 'dnssec_keys' => $keys, 'is_dnssec_enabled' => true ) );
				if ( is_wp_error( $ds ) ) { return StepResult::retry( 60, 'DNSSEC could not be published.' ); }
			}
			Security::notify( $r, 'ns_changed' );
			return StepResult::done( array( 'ns_mode' => $mode, 'nameservers' => $hosts, 'dnssec_keys' => $keys ) );
		}
		$locked = 'lock' === $action;
		$reply = Clients::registrar()->request( 'PUT', '/domains/' . $id, array( 'is_locked' => $locked ) );
		if ( is_wp_error( $reply ) ) { return StepResult::retry( 60, 'Transfer lock could not be changed.' ); }
		// The days an unlocked domain stays unlocked count from the unlock.
		if ( ! $locked ) { Security::notify( $r, 'unlocked' ); }
		return StepResult::done( array( 'is_locked' => $locked, 'unlocked_at' => $locked ? null : time() ) );
	}
}
