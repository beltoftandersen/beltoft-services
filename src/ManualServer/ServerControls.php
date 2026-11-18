<?php
/**
 * The controls of a delivered dedicated server: restart and power, the rescue system, reverse DNS and the firewall.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftLicenses\Events;
use BeltoftServices\Crypto;
use BeltoftServices\JobActions;
use BeltoftServices\Locks;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every call is one documented call of the Robot webservice (see RobotClient), made through DedicatedClients::for(): the fake in tests and on a
 * site with the fake client, the real host only with the live gate. Nothing here names the supplier to a customer: errors are mapped to plain
 * sentences. A change is refused while another action of the service is pending (a reinstall), runs under the resource lock and writes a line to
 * the history. The emailed code, the typed name and the owner check are the route's (ControlRoutes); the rules of what needs a code are here as
 * constants so the routes and the MCP tools read the same ones.
 */
final class ServerControls {

	/** Reset types a customer may use, with their label (Robot webservice, Reset: the types a server lists). `man` (a manual reset by staff) is never sent. */
	public const RESETS = array(
		'sw'         => 'Restart',
		'hw'         => 'Hardware reset',
		'power'      => 'Press the power button',
		'power_long' => 'Hold the power button',
	);

	/** The reset types that need the emailed code (they cut the power or reset the hardware; a software restart does not). */
	public const CODE_RESETS = array( 'hw', 'power', 'power_long' );

	public const RESCUE_PASSWORD = 'ded_rescue_pw';
	public const RESCUE_AT       = 'ded_rescue_at';

	/** Seconds a stored rescue password is kept after the rescue system was switched on. */
	public const RESCUE_KEEP = DAY_IN_SECONDS;

	/**
	 * The label of a reset type, translated.
	 *
	 * @param string $type Type.
	 */
	public static function reset_label( string $type ): string {
		switch ( $type ) {
			case 'sw':
				return __( 'Restart', 'beltoft-services' );
			case 'hw':
				return __( 'Hardware reset', 'beltoft-services' );
			case 'power':
				return __( 'Press the power button', 'beltoft-services' );
			case 'power_long':
				return __( 'Hold the power button', 'beltoft-services' );
		}

		return $type;
	}

	/**
	 * The supplier server number of a server that is delivered and not going away, or 0.
	 *
	 * @param Resource $r Resource.
	 */
	public static function number( Resource $r ): int {
		if ( ManualServerDriver::ID !== $r->get( 'driver' ) || (int) $r->get( ManualServerDelivery::READY_AT ) < 1 || ! $r->is_live() || 'deleted' === (string) $r->get( 'desired' ) ) {
			return 0;
		}

		return (int) $r->get( RobotOrder::NUMBER );
	}

	/**
	 * Can the controls be used on this server now? The number, or a refusal. A change also needs no other action to be pending.
	 *
	 * @param Resource $r      Resource.
	 * @param bool     $change True for a change (it is refused while an action runs).
	 * @return int|\WP_Error
	 */
	public static function guard( Resource $r, bool $change ) {
		$number = self::number( $r );

		if ( $number < 1 ) {
			return self::refusal( 'dedicated_unavailable', __( 'These controls are available once your server is delivered. If it was set up by hand, we do these for you: write to support.', 'beltoft-services' ), 409 );
		}

		if ( $change ) {
			$busy = JobActions::busy( $r );

			if ( $busy ) {
				return $busy;
			}

			if ( 'running' === self::reinstall_state( $r ) ) {
				return self::refusal( 'dedicated_busy', __( 'The server is being reinstalled. Wait until that is done.', 'beltoft-services' ), 409 );
			}
		}

		return $number;
	}

	/**
	 * Where the reinstall of the server stands: running, failed, done or none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function reinstall_state( Resource $r ): string {
		$memo = $r->get( DedicatedReinstall::META );

		return is_array( $memo ) ? (string) ( $memo['state'] ?? '' ) : '';
	}

	/**
	 * What the server's page shows: each part is the answer of one call, or null with a plain reason when that part is not available.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function overview( Resource $r ) {
		$number = self::guard( $r, false );

		if ( is_wp_error( $number ) ) {
			return $number;
		}

		$client = DedicatedClients::for();
		$out    = array(
			'reinstall' => self::reinstall_state( $r ),
			'busy'      => null !== JobActions::busy( $r ),
		);

		$reset         = $client->reset_options( $number );
		$out['reset']  = is_wp_error( $reset ) ? null : array_values( array_filter( array_map( static fn( $t ) => isset( self::RESETS[ (string) $t ] ) ? array( 'type' => (string) $t, 'label' => self::reset_label( (string) $t ), 'code' => in_array( (string) $t, self::CODE_RESETS, true ) ) : null, (array) ( $reset['type'] ?? array() ) ) ) );
		$wol           = $client->wol_available( $number );
		$out['wol']    = ! is_wp_error( $wol );
		$rescue        = $client->rescue_status( $number );
		$out['rescue'] = is_wp_error( $rescue ) ? null : self::rescue_view( $r, $rescue );

		$out['addresses'] = array(
			'ipv4' => ManualServerDelivery::ips( $r ),
			'ipv6' => (string) $r->get( RobotOrder::IPV6 ),
		);
		$first            = $out['addresses']['ipv4'][0] ?? '';
		$rdns             = '' !== $first ? $client->rdns_list( $first ) : array();
		$out['rdns']      = is_wp_error( $rdns ) ? null : array_values( array_map( static fn( $row ) => array( 'ip' => (string) ( $row['ip'] ?? '' ), 'ptr' => (string) ( $row['ptr'] ?? '' ) ), $rdns ) );

		$firewall        = $client->firewall( $number );
		$out['firewall'] = is_wp_error( $firewall ) ? null : array(
			'status'        => (string) ( $firewall['status'] ?? '' ),
			'filter_ipv6'   => ! empty( $firewall['filter_ipv6'] ),
			'whitelist_hos' => ! empty( $firewall['whitelist_hos'] ),
			'input'         => FirewallRules::shown( $firewall['rules']['input'] ?? array() ),
		);

		return $out;
	}

	/**
	 * The state of the rescue system for the customer. Never the password: only whether there is one to show. A password that outlived the
	 * rescue system (it was switched off or the time ran out) is wiped here.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $rescue The `rescue` object.
	 * @return array<string, bool>
	 */
	private static function rescue_view( Resource $r, array $rescue ): array {
		$active = ! empty( $rescue['active'] );
		$stored = '' !== (string) $r->get( self::RESCUE_PASSWORD );

		if ( $stored && ( ! $active || time() - (int) $r->get( self::RESCUE_AT ) > self::RESCUE_KEEP ) ) {
			$r->set( array( self::RESCUE_PASSWORD => null, self::RESCUE_AT => null ) );
			$stored = false;
		}

		return array( 'active' => $active, 'password' => $active && $stored, 'key' => '' !== (string) $r->get( 'ssh_key' ) );
	}

	/**
	 * A reset (restart, hardware reset, power button). The caller has checked the code where the type needs one.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $type A key of RESETS.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function reset( Resource $r, string $type ) {
		if ( ! isset( self::RESETS[ $type ] ) ) {
			return self::refusal( 'dedicated_invalid', __( 'That is not an available action.', 'beltoft-services' ), 400 );
		}

		return self::change(
			$r,
			'reset',
			static function ( int $number, RobotClient $client ) use ( $type ) {
				$listed = $client->reset_options( $number );

				if ( is_wp_error( $listed ) ) {
					return $listed;
				}

				// Only a type the server lists is sent (Robot webservice, Reset).
				if ( ! in_array( $type, array_map( 'strval', (array) ( $listed['type'] ?? array() ) ), true ) ) {
					return self::refusal( 'dedicated_invalid', __( 'This server does not offer that action.', 'beltoft-services' ), 409 );
				}

				return $client->reset_server( $number, $type );
			},
			'Customer used "' . $type . '" on the server'
		);
	}

	/**
	 * Wake on LAN: powers the server on.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function wol( Resource $r ) {
		return self::change( $r, 'wol', static fn( int $number, RobotClient $client ) => $client->wol( $number ), 'Customer switched the server on (Wake on LAN)' );
	}

	/**
	 * Switches the rescue system on for the next start, with the customer's key when they have one (else the answer's one-time password is kept
	 * encrypted for the reveal), and, when asked, restarts the server into it.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $boot Restart now.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rescue_activate( Resource $r, bool $boot ) {
		return self::change(
			$r,
			'rescue',
			static function ( int $number, RobotClient $client ) use ( $r, $boot ) {
				$status = $client->rescue_status( $number );

				if ( is_wp_error( $status ) ) {
					return $status;
				}

				$os = in_array( 'linux', array_map( 'strval', (array) ( $status['os'] ?? array() ) ), true ) ? 'linux' : (string) ( ( (array) ( $status['os'] ?? array() ) )[0] ?? '' );

				if ( '' === $os ) {
					return self::refusal( 'dedicated_unavailable', __( 'The rescue system is not available for this server.', 'beltoft-services' ), 409 );
				}

				$keys = array();

				if ( '' !== (string) $r->get( 'ssh_key' ) ) {
					$print = RobotOrder::key_fingerprint( $r, $client );

					if ( '' !== $print ) {
						$keys[] = $print;
					}
				}

				$active = $client->rescue_activate( $number, $os, $keys );

				if ( is_wp_error( $active ) ) {
					return $active;
				}

				$password = isset( $active['password'] ) && is_string( $active['password'] ) ? $active['password'] : '';
				$r->set( array( self::RESCUE_PASSWORD => '' !== $password ? Crypto::encrypt( $password ) : null, self::RESCUE_AT => time() ) );

				if ( ! $boot ) {
					return array( 'active' => true, 'rebooting' => false );
				}

				$listed = $client->reset_options( $number );
				$types  = is_wp_error( $listed ) ? array() : array_map( 'strval', (array) ( $listed['type'] ?? array() ) );
				$type   = in_array( 'sw', $types, true ) ? 'sw' : ( in_array( 'hw', $types, true ) ? 'hw' : '' );

				if ( '' === $type ) {
					return array( 'active' => true, 'rebooting' => false, 'note' => __( 'The rescue system is on. Restart the server yourself to start it.', 'beltoft-services' ) );
				}

				$done = $client->reset_server( $number, $type );

				return is_wp_error( $done ) ? array( 'active' => true, 'rebooting' => false, 'note' => __( 'The rescue system is on, but the restart failed. Restart the server to start it.', 'beltoft-services' ) ) : array( 'active' => true, 'rebooting' => true );
			},
			$boot ? 'Customer switched the rescue system on and restarted the server' : 'Customer switched the rescue system on'
		);
	}

	/**
	 * Switches the rescue system off again (DELETE /boot/{n}/rescue) and forgets the password.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rescue_deactivate( Resource $r ) {
		return self::change(
			$r,
			'rescue',
			static function ( int $number, RobotClient $client ) use ( $r ) {
				$done = $client->rescue_deactivate( $number );

				if ( is_wp_error( $done ) ) {
					return $done;
				}

				$r->set( array( self::RESCUE_PASSWORD => null, self::RESCUE_AT => null ) );

				return array( 'active' => false );
			},
			'Customer switched the rescue system off'
		);
	}

	/**
	 * The one-time rescue password, decrypted, or null. Only the reveal route calls this, after the emailed code.
	 *
	 * @param Resource $r Resource.
	 */
	public static function rescue_password( Resource $r ): ?string {
		$stored = (string) $r->get( self::RESCUE_PASSWORD );

		if ( '' === $stored || time() - (int) $r->get( self::RESCUE_AT ) > self::RESCUE_KEEP ) {
			return null;
		}

		return Crypto::decrypt( $stored );
	}

	/**
	 * May this address get a reverse DNS name: an address of the server, or inside the IPv6 network of the server?
	 *
	 * @param Resource $r  Resource.
	 * @param string   $ip Address as typed.
	 * @return string The address in its normal form, or empty.
	 */
	public static function own_address( Resource $r, string $ip ): string {
		$ip = trim( $ip );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return '';
		}

		if ( 4 === strlen( $packed ) ) {
			return in_array( $ip, ManualServerDelivery::ips( $r ), true ) ? $ip : '';
		}

		$net = (string) $r->get( RobotOrder::IPV6 );
		$raw = '' !== $net ? inet_pton( $net ) : false;

		// The owner chose the /64 of the server: the first 64 bits are the net.
		return false !== $raw && 16 === strlen( $raw ) && substr( $raw, 0, 8 ) === substr( $packed, 0, 8 ) ? (string) inet_ntop( $packed ) : '';
	}

	/**
	 * Is the text a host name that can be a PTR record?
	 *
	 * @param string $ptr Host name.
	 */
	public static function valid_ptr( string $ptr ): bool {
		return \BeltoftServices\Domains\HostName::is_host( strtolower( rtrim( $ptr, '.' ) ), 253, true );
	}

	/**
	 * Sets the reverse DNS name of one address.
	 *
	 * @param Resource $r   Resource.
	 * @param string   $ip  Address.
	 * @param string   $ptr Host name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rdns_set( Resource $r, string $ip, string $ptr ) {
		$own = self::own_address( $r, $ip );
		$ptr = strtolower( rtrim( trim( $ptr ), '.' ) );

		if ( '' === $own ) {
			return self::refusal( 'dedicated_invalid', __( 'That address does not belong to this server.', 'beltoft-services' ), 400 );
		}

		if ( ! self::valid_ptr( $ptr ) ) {
			return self::refusal( 'dedicated_invalid', __( 'Enter a host name such as server.example.com.', 'beltoft-services' ), 400 );
		}

		return self::change( $r, 'rdns', static fn( int $number, RobotClient $client ) => $client->rdns_set( $own, $ptr ), 'Customer set the reverse DNS of ' . $own, false );
	}

	/**
	 * Removes the reverse DNS name of one address.
	 *
	 * @param Resource $r  Resource.
	 * @param string   $ip Address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rdns_delete( Resource $r, string $ip ) {
		$own = self::own_address( $r, $ip );

		if ( '' === $own ) {
			return self::refusal( 'dedicated_invalid', __( 'That address does not belong to this server.', 'beltoft-services' ), 400 );
		}

		return self::change(
			$r,
			'rdns',
			static function ( int $number, RobotClient $client ) use ( $own ) {
				$done = $client->rdns_delete( $own );

				return is_wp_error( $done ) ? $done : array( 'ip' => $own );
			},
			'Customer removed the reverse DNS of ' . $own,
			false
		);
	}

	/**
	 * Is a firewall change one that could lock the customer out (the code is then needed)?
	 *
	 * @param string                                $status Status after the change.
	 * @param array<int, array<string, string>> $rules  Input rules after the change.
	 */
	public static function firewall_risky( string $status, array $rules ): bool {
		return FirewallRules::risky( $status, $rules );
	}

	/**
	 * Applies a firewall configuration (POST /firewall/{n} replaces the rule set). The output rules and the flags the customer did not
	 * change are sent back as they are, so a change of the input rules never drops them.
	 *
	 * @param Resource                          $r      Resource.
	 * @param string                            $status active or disabled.
	 * @param array<int, array<string, string>> $input  Cleaned input rules.
	 * @param bool|null                         $hos    Whitelist of the supplier's own services (whitelist_hos), null: unchanged.
	 * @param bool|null                         $v6     IPv6 filter, null: unchanged.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function firewall_set( Resource $r, string $status, array $input, ?bool $hos = null, ?bool $v6 = null ) {
		if ( ! in_array( $status, array( 'active', 'disabled' ), true ) ) {
			return self::refusal( 'dedicated_invalid', __( 'The firewall is active or off.', 'beltoft-services' ), 400 );
		}

		return self::change(
			$r,
			'firewall',
			static function ( int $number, RobotClient $client ) use ( $status, $input, $hos, $v6 ) {
				$current = $client->firewall( $number );

				if ( is_wp_error( $current ) ) {
					return $current;
				}

				$config = array(
					'status'        => $status,
					'filter_ipv6'   => null === $v6 ? ! empty( $current['filter_ipv6'] ) : $v6,
					'whitelist_hos' => null === $hos ? ! empty( $current['whitelist_hos'] ) : $hos,
					'rules'         => array(
						'input'  => $input,
						'output' => array_values( array_map( static fn( $rule ) => array_filter( (array) $rule, static fn( $v ) => null !== $v && '' !== $v ), (array) ( $current['rules']['output'] ?? array() ) ) ),
					),
				);
				$done   = $client->firewall_set( $number, $config );

				return is_wp_error( $done ) ? $done : array( 'status' => (string) ( $done['status'] ?? '' ) );
			},
			'Customer changed the firewall (' . $status . ', ' . count( $input ) . ' incoming rules)',
			false
		);
	}

	/**
	 * One change: refused while busy, rate limited per server, under the resource lock, with a line in the history. The work gets the
	 * number and the client and returns the result or a WP_Error of the web service, which is turned into a customer sentence.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $kind    Kind for the rate limit.
	 * @param callable $work    Work.
	 * @param string   $history History line.
	 * @param bool     $busy    Refuse while an action runs (default true).
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function change( Resource $r, string $kind, callable $work, string $history, bool $busy = true ) {
		$number = self::guard( $r, $busy );

		if ( is_wp_error( $number ) ) {
			return $number;
		}

		// The supplier's own limits are lower (a reset 50 an hour, Wake on LAN 10): stay below them for one server.
		$limits = array( 'reset' => array( 6, 600 ), 'wol' => array( 3, 3600 ), 'rescue' => array( 10, 3600 ), 'rdns' => array( 60, 3600 ), 'firewall' => array( 30, 3600 ) );

		if ( RateLimit::exceeded( 'dedicated', 'dedicated:' . $kind . ':' . $r->id(), $limits[ $kind ][0], $limits[ $kind ][1] ) ) {
			return self::refusal( 'dedicated_rate', __( 'Too many requests for this server. Wait a while and try again.', 'beltoft-services' ), 429 );
		}

		list( $locked, $result ) = Locks::with(
			Locks::name( 'resource', $r->id() ),
			5,
			static function () use ( $number, $work ) {
				return $work( $number, DedicatedClients::for() );
			}
		);

		if ( ! $locked ) {
			return self::refusal( 'dedicated_busy', __( 'The server is busy. Try again in a moment.', 'beltoft-services' ), 409 );
		}

		if ( is_wp_error( $result ) ) {
			return self::explain( $result );
		}

		Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', $history );

		return (array) $result;
	}

	/**
	 * A plain sentence for an answer of the web service. The code of the answer is kept in the error data for staff and the tests.
	 *
	 * @param \WP_Error $error Error of a client.
	 */
	public static function explain( \WP_Error $error ): \WP_Error {
		if ( 0 === strpos( $error->get_error_code(), 'dedicated_' ) ) {
			return $error;
		}

		$code   = (string) $error->get_error_code();
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$map    = array(
			'RESET_NOT_AVAILABLE'                 => array( __( 'This server cannot be restarted from here.', 'beltoft-services' ), 409 ),
			'RESET_MANUAL_ACTIVE'                 => array( __( 'A reset is already running. Wait a few minutes.', 'beltoft-services' ), 409 ),
			'RESET_FAILED'                        => array( __( 'The reset did not work. Try again in a few minutes.', 'beltoft-services' ), 502 ),
			'WOL_NOT_AVAILABLE'                   => array( __( 'This server cannot be switched on from here.', 'beltoft-services' ), 409 ),
			'WOL_FAILED'                          => array( __( 'The signal could not be sent. Try again in a few minutes.', 'beltoft-services' ), 502 ),
			'BOOT_NOT_AVAILABLE'                  => array( __( 'The rescue system is not available for this server.', 'beltoft-services' ), 409 ),
			'BOOT_ACTIVATION_FAILED'              => array( __( 'The rescue system could not be switched on. Try again in a few minutes.', 'beltoft-services' ), 502 ),
			'BOOT_DEACTIVATION_FAILED'            => array( __( 'The rescue system could not be switched off. Try again in a few minutes.', 'beltoft-services' ), 502 ),
			'IP_NOT_FOUND'                        => array( __( 'That address was not found for this server.', 'beltoft-services' ), 404 ),
			'RDNS_CREATE_FAILED'                  => array( __( 'The name could not be saved. Try again in a few minutes.', 'beltoft-services' ), 502 ),
			'RDNS_UPDATE_FAILED'                  => array( __( 'The name could not be saved. Try again in a few minutes.', 'beltoft-services' ), 502 ),
			'RDNS_DELETE_FAILED'                  => array( __( 'The name could not be removed. Try again in a few minutes.', 'beltoft-services' ), 502 ),
			'FIREWALL_NOT_AVAILABLE'              => array( __( 'The firewall is not available for this server.', 'beltoft-services' ), 409 ),
			'FIREWALL_PORT_NOT_FOUND'             => array( __( 'The firewall is not available for this server.', 'beltoft-services' ), 409 ),
			'FIREWALL_IN_PROCESS'                 => array( __( 'The firewall is still being updated. Try again in a minute.', 'beltoft-services' ), 409 ),
			'FIREWALL_RULE_LIMIT_EXCEEDED'        => array( __( 'There are too many rules. Remove some and try again.', 'beltoft-services' ), 409 ),
			'FIREWALL_CANNOT_BE_DISABLED'         => array( __( 'The firewall cannot be switched off because internal rules are set.', 'beltoft-services' ), 409 ),
			'INVALID_INPUT'                       => array( __( 'The values were not accepted. Check them and try again.', 'beltoft-services' ), 400 ),
			'RATE_LIMIT_EXCEEDED'                 => array( __( 'Too many requests. Wait a while and try again.', 'beltoft-services' ), 429 ),
			'SERVER_NOT_FOUND'                    => array( __( 'The server was not found. We have been told.', 'beltoft-services' ), 404 ),
		);

		$known = $map[ $code ] ?? array( __( 'This did not work. Try again in a few minutes.', 'beltoft-services' ), 0 === $status || $status >= 500 ? 502 : 409 );

		return new \WP_Error( 'dedicated_' . strtolower( $code ), $known[0], array( 'status' => $known[1], 'robot' => $code ) );
	}

	/**
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  Status.
	 */
	public static function refusal( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
