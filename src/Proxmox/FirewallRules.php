<?php
/**
 * The firewall's rule model: what a server's stored rules, defaults, validation and customer view are. No Proxmox
 * call is made here; Firewall writes the result to the VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Customer rules (`bs-fw:<id>`), the standard set, input validation, the page's view, and the checks and stores of
 * the customer's actions. The meta keys and tags are the constants of Firewall.
 */
final class FirewallRules {

	/**
	 * The cluster settings of the server's VM (token secret decrypted), or null.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public static function cluster_of( Resource $r ): ?array {
		$record = VmRecords::for_resource( $r->id() );

		return null === $record ? null : Clusters::get( (string) $record['cluster'] );
	}

	/**
	 * Is the feature on for this server: the cluster's switch (fw_enabled) or its pilot list (fw_pilot)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function switched_on( Resource $r ): bool {
		$cluster = self::cluster_of( $r );

		return null !== $cluster && self::on_for( $cluster, $r );
	}

	/**
	 * The cluster switch, or the server in the cluster's pilot list (license IDs, setting fw_pilot).
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param Resource             $r       Resource.
	 */
	public static function on_for( array $cluster, Resource $r ): bool {
		return ! empty( $cluster['fw_enabled'] ) || in_array( (int) ( $r->license()['id'] ?? 0 ), array_map( 'intval', (array) ( $cluster['fw_pilot'] ?? array() ) ), true );
	}

	/**
	 * Does the customer get a Firewall tab for this server? Not for an adopted machine, one whose rules the
	 * managed-database work owns (meta fw_locked), a server that is not ready, or a cluster with the switch off.
	 *
	 * @param Resource $r Resource.
	 */
	public static function editable( Resource $r ): bool {
		return ProxmoxDriver::ID === $r->get( 'driver' )
			&& true !== $r->get( 'linked' )
			&& true !== $r->get( Firewall::LOCKED )
			&& 'ready' === $r->get( 'state' )
			&& self::switched_on( $r );
	}

	/**
	 * Has the dashboard taken over the server's firewall?
	 *
	 * @param Resource $r Resource.
	 */
	public static function managed( Resource $r ): bool {
		return true === $r->get( Firewall::MANAGED );
	}

	/**
	 * Most rules a server may have.
	 *
	 * @param Resource $r Resource.
	 */
	public static function max_rules( Resource $r ): int {
		$cluster = self::cluster_of( $r );
		$max     = null === $cluster ? 30 : (int) ( $cluster['fw_max_rules'] ?? 30 );

		return max( 1, min( Firewall::MAX_CEILING, $max ) );
	}

	/**
	 * The id of a rule: 8 hex characters of what it says.
	 *
	 * @param string            $proto   tcp, udp or icmp.
	 * @param int               $from    First port (0 for icmp).
	 * @param int               $to      Last port.
	 * @param array<int,string> $sources Normalised sources.
	 */
	public static function rule_id( string $proto, int $from, int $to, array $sources ): string {
		sort( $sources );

		return substr( sha1( $proto . '|' . $from . '|' . $to . '|' . implode( ',', $sources ) ), 0, 8 );
	}

	/**
	 * One rule in the stored shape.
	 *
	 * @param string            $proto   Protocol.
	 * @param int               $from    First port.
	 * @param int               $to      Last port.
	 * @param array<int,string> $sources Sources.
	 * @param string            $label   Label.
	 * @param string            $origin  default, app or customer.
	 */
	private static function make( string $proto, int $from, int $to, array $sources, string $label, string $origin ): array {
		return array(
			'id'      => self::rule_id( $proto, $from, $to, $sources ),
			'proto'   => $proto,
			'from'    => $from,
			'to'      => $to,
			'sources' => array_values( $sources ),
			'label'   => $label,
			'origin'  => $origin,
			'created' => time(),
		);
	}

	/**
	 * The standard set: SSH, HTTP, HTTPS and ping, from anywhere.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function standard(): array {
		return array(
			self::make( 'tcp', 22, 22, array(), __( 'SSH', 'beltoft-services' ), 'default' ),
			self::make( 'tcp', 80, 80, array(), __( 'HTTP', 'beltoft-services' ), 'default' ),
			self::make( 'tcp', 443, 443, array(), __( 'HTTPS', 'beltoft-services' ), 'default' ),
			self::make( 'icmp', 0, 0, array(), __( 'Ping', 'beltoft-services' ), 'default' ),
		);
	}

	/**
	 * The standard set plus the ports of the server's app (from the app definition), marked origin app.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function defaults( Resource $r ): array {
		$out = self::standard();
		$app = '' === (string) $r->get( 'app_id' ) ? null : Apps::definition( (string) $r->get( 'app_id' ) );

		foreach ( (array) ( $app['ports'] ?? array() ) as $port ) {
			$number = (int) ( $port['port'] ?? 0 );
			$proto  = in_array( $port['proto'] ?? '', array( 'tcp', 'udp' ), true ) ? $port['proto'] : 'tcp';
			$rule   = $number >= 1 && $number <= 65535 ? self::make( $proto, $number, $number, array(), self::clean_label( (string) ( $port['label'] ?? '' ), $proto, $number, $number ), 'app' ) : null;

			if ( null !== $rule && ! in_array( $rule['id'], array_column( $out, 'id' ), true ) ) {
				$out[] = $rule;
			}
		}

		return $out;
	}

	/**
	 * A label as it will be kept: letters, digits, space, dash and dot, at most MAX_LABEL. Empty gives a plain name.
	 *
	 * @param string $label Wanted label.
	 * @param string $proto Protocol.
	 * @param int    $from  First port.
	 * @param int    $to    Last port.
	 */
	public static function clean_label( string $label, string $proto, int $from, int $to ): string {
		$label = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^A-Za-z0-9 .\-]/', '', $label ) ) );
		$label = trim( substr( $label, 0, Firewall::MAX_LABEL ) );

		if ( '' !== $label ) {
			return $label;
		}

		if ( 'icmp' === $proto ) {
			return __( 'Ping', 'beltoft-services' );
		}

		return strtoupper( $proto ) . ' ' . ( $from === $to ? $from : $from . '-' . $to );
	}

	/**
	 * The stored rules as a list (a damaged value reads as empty).
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rules_of( Resource $r ): array {
		$rules = $r->get( Firewall::RULES );

		return is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : array();
	}


	/**
	 * Normalises one source: an IPv4 address or a network. Anything else, "anywhere" (0.0.0.0/0), private, loopback,
	 * link-local, multicast and reserved ranges are refused. A network is written as its first address.
	 *
	 * @param mixed $source Raw source.
	 * @return string|null Normalised, or null when it is not acceptable.
	 */
	private static function clean_source( $source ): ?string {
		$source = is_string( $source ) ? trim( $source ) : '';

		if ( 1 !== preg_match( '#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#D', $source, $m ) ) {
			return null;
		}

		$prefix = isset( $m[2] ) ? (int) $m[2] : 32;

		if ( $prefix < 1 || $prefix > 32 || false === filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return null;
		}

		$long = (int) ip2long( $m[1] );

		if ( $long >= (int) ip2long( '224.0.0.0' ) || $long < 0 ) {
			return null;
		}

		$mask = ( -1 << ( 32 - $prefix ) ) & 0xFFFFFFFF;
		$net  = long2ip( $long & $mask );

		return 32 === $prefix ? $net : $net . '/' . $prefix;
	}

	/**
	 * Is an address inside one of the sources?
	 *
	 * @param string            $ip      IPv4 address.
	 * @param array<int,string> $sources Normalised sources (empty: anywhere).
	 */
	public static function source_covers( string $ip, array $sources ): bool {
		if ( ! $sources ) {
			return true;
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		foreach ( $sources as $source ) {
			$bits = explode( '/', (string) $source );
			$len  = isset( $bits[1] ) ? (int) $bits[1] : 32;
			$mask = ( -1 << ( 32 - $len ) ) & 0xFFFFFFFF;

			if ( ( (int) ip2long( $ip ) & $mask ) === ( (int) ip2long( $bits[0] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An error for one rule of a request.
	 *
	 * @param string $message Message for the customer.
	 * @param int    $index   Position of the rule in the request.
	 */
	private static function rule_error( string $message, int $index ): \WP_Error {
		return new \WP_Error( 'beltoft_services_refused', $message, array( 'status' => 422, 'rule' => $index ) );
	}

	/**
	 * Checks the rules a customer sent and puts them in the stored shape. Only protocol, ports, sources and label
	 * are read; the id is made here, and the origin and the creation time are kept from a rule that was there before.
	 *
	 * @param mixed    $input Raw rule list.
	 * @param Resource $r     Resource.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function normalise( $input, Resource $r ) {
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'beltoft_services_refused', __( 'The rules were not understood. Reload the page and try again.', 'beltoft-services' ), array( 'status' => 422 ) );
		}

		if ( count( $input ) > self::max_rules( $r ) ) {
			return new \WP_Error( 'beltoft_services_refused', sprintf( /* translators: %d: most rules */ _n( 'A server can have up to %d rule.', 'A server can have up to %d rules.', self::max_rules( $r ), 'beltoft-services' ), self::max_rules( $r ) ), array( 'status' => 422 ) );
		}

		$before = array();

		foreach ( self::rules_of( $r ) as $rule ) {
			$before[ (string) ( $rule['id'] ?? '' ) ] = $rule;
		}

		$own = (string) $r->get( 'ip' );
		$out = array();

		foreach ( array_values( $input ) as $i => $raw ) {
			if ( ! is_array( $raw ) ) {
				return self::rule_error( __( 'This rule was not understood.', 'beltoft-services' ), $i );
			}

			$proto = strtolower( is_string( $raw['proto'] ?? null ) ? $raw['proto'] : '' );

			if ( ! in_array( $proto, array( 'tcp', 'udp', 'icmp' ), true ) ) {
				return self::rule_error( __( 'Choose TCP, UDP or Ping.', 'beltoft-services' ), $i );
			}

			$from = 0;
			$to   = 0;

			if ( 'icmp' !== $proto ) {
				$from = self::port( $raw['from'] ?? null );
				$to   = isset( $raw['to'] ) && '' !== $raw['to'] && null !== $raw['to'] ? self::port( $raw['to'] ) : $from;

				if ( null === $from || null === $to ) {
					return self::rule_error( __( 'A port is a number from 1 to 65535.', 'beltoft-services' ), $i );
				}

				if ( $to < $from ) {
					return self::rule_error( __( 'The end of a port range must be higher than its start.', 'beltoft-services' ), $i );
				}
			}

			$sources = $raw['sources'] ?? array();
			$sources = is_string( $sources ) ? preg_split( '/[\s,]+/', trim( $sources ), -1, PREG_SPLIT_NO_EMPTY ) : $sources;

			if ( ! is_array( $sources ) || count( $sources ) > Firewall::MAX_SOURCES ) {
				return self::rule_error( sprintf( /* translators: %d: most addresses */ _n( 'Up to %d address can be named in one rule.', 'Up to %d addresses can be named in one rule.', Firewall::MAX_SOURCES, 'beltoft-services' ), Firewall::MAX_SOURCES ), $i );
			}

			$clean = array();

			foreach ( $sources as $source ) {
				$one = self::clean_source( $source );

				if ( null === $one ) {
					return self::rule_error( __( 'Use public IPv4 addresses, like 203.0.113.7 or 203.0.113.0/24. Leave the field empty to allow anyone.', 'beltoft-services' ), $i );
				}

				if ( '' !== $own && $one === $own ) {
					return self::rule_error( __( 'The address of the server itself cannot be used here.', 'beltoft-services' ), $i );
				}

				$clean[] = $one;
			}

			$clean = array_values( array_unique( $clean ) );
			sort( $clean );
			$label = self::clean_label( is_string( $raw['label'] ?? null ) ? $raw['label'] : '', $proto, $from, $to );
			$rule  = self::make( $proto, $from, $to, $clean, $label, 'customer' );

			foreach ( $out as $other ) {
				if ( $other['id'] === $rule['id'] ) {
					return self::rule_error( __( 'The same rule is in the list twice.', 'beltoft-services' ), $i );
				}
			}

			if ( isset( $before[ $rule['id'] ] ) ) {
				$rule['origin']  = in_array( $before[ $rule['id'] ]['origin'] ?? '', array( 'default', 'app', 'customer' ), true ) ? $before[ $rule['id'] ]['origin'] : 'customer';
				$rule['created'] = (int) ( $before[ $rule['id'] ]['created'] ?? time() );
			}

			$out[] = $rule;
		}

		return $out;
	}

	/**
	 * A port number from 1 to 65535, or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function port( $value ): ?int {
		if ( ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^\d{1,5}$/D', trim( $value ) ) ) ) ) {
			return null;
		}

		$port = (int) $value;

		return $port >= 1 && $port <= 65535 ? $port : null;
	}

	/**
	 * Would these rules leave the customer without SSH from where they are now? True when no rule lets tcp 22 in
	 * from anywhere or from the address the customer is using.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 * @param string                           $my_ip The customer's address, or ''.
	 */
	public static function blocks_ssh( array $rules, string $my_ip ): bool {
		foreach ( $rules as $rule ) {
			if ( 'tcp' !== $rule['proto'] || $rule['from'] > 22 || $rule['to'] < 22 ) {
				continue;
			}

			if ( ! $rule['sources'] || ( '' !== $my_ip && self::source_covers( $my_ip, (array) $rule['sources'] ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The rules that let tcp 22 in, reduced to what matters (ports and sorted sources), to compare two rule sets.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 * @return array<int, array<string, mixed>>
	 */
	public static function ssh_rules( array $rules ): array {
		$out = array();

		foreach ( $rules as $rule ) {
			if ( 'tcp' === ( $rule['proto'] ?? '' ) && (int) $rule['from'] <= 22 && (int) $rule['to'] >= 22 ) {
				$sources = array_values( array_map( 'strval', (array) $rule['sources'] ) );
				sort( $sources );
				$out[] = array( (int) $rule['from'], (int) $rule['to'], $sources );
			}
		}

		sort( $out );

		return $out;
	}

	/**
	 * The text of a port for the customer: 22, 8000-8100 or Ping.
	 *
	 * @param array<string, mixed> $rule Rule.
	 */
	public static function port_text( array $rule ): string {
		if ( 'icmp' === $rule['proto'] ) {
			return __( 'Ping', 'beltoft-services' );
		}

		return strtoupper( (string) $rule['proto'] ) . ' ' . ( $rule['from'] === $rule['to'] ? (string) $rule['from'] : $rule['from'] . '-' . $rule['to'] );
	}


	/**
	 * The lines the customer can never change.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	public static function fixed( Resource $r ): array {
		$cluster = self::cluster_of( $r );
		$out     = array();

		if ( null === $cluster || ! empty( $cluster['block_smtp'] ) ) {
			$out[] = __( 'Outgoing mail on port 25 is blocked on every server.', 'beltoft-services' );
		}

		// macfilter is always written; the IP filter only where the cluster turned it on (fw_ipfilter).
		$out[] = null !== $cluster && ! empty( $cluster['fw_ipfilter'] )
			? __( 'Protection against address spoofing is always on.', 'beltoft-services' )
			: __( 'Protection against network card address spoofing is always on.', 'beltoft-services' );

		if ( null !== PrivateNets::for_resource( $r->id() ) ) {
			$out[] = __( 'Traffic on your private network is not affected by the rules above.', 'beltoft-services' );
		}

		return $out;
	}

	/**
	 * The command that opens a rule's port inside a server that has ufw (an app server), or ''.
	 *
	 * @param array<string, mixed> $rule Rule.
	 */
	private static function ufw( array $rule ): string {
		if ( 'icmp' === $rule['proto'] ) {
			return '';
		}

		$port = $rule['from'] === $rule['to'] ? (string) $rule['from'] : $rule['from'] . ':' . $rule['to'];

		if ( ! $rule['sources'] ) {
			return 'sudo ufw allow ' . $port . '/' . $rule['proto'];
		}

		return 'sudo ufw allow from ' . $rule['sources'][0] . ' to any port ' . $port . ' proto ' . $rule['proto'];
	}

	/**
	 * What the tab shows, from the stored meta (no Proxmox call). Never carries fw_error.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $my_ip The customer's address, or ''.
	 * @return array<string, mixed>
	 */
	public static function view( Resource $r, string $my_ip = '' ): array {
		$managed = self::managed( $r );
		$state   = (string) $r->get( Firewall::STATE );
		$has_app = '' !== (string) $r->get( 'app_id' );
		$rules   = array();

		foreach ( self::rules_of( $r ) as $rule ) {
			$rules[] = array(
				'id'      => (string) $rule['id'],
				'proto'   => (string) $rule['proto'],
				'from'    => (int) $rule['from'],
				'to'      => (int) $rule['to'],
				'sources' => array_values( (array) $rule['sources'] ),
				'label'   => (string) $rule['label'],
				'origin'  => (string) $rule['origin'],
				'port'    => self::port_text( $rule ),
				'ufw'     => $has_app ? self::ufw( $rule ) : '',
			);
		}

		$defaults = array();

		foreach ( self::defaults( $r ) as $rule ) {
			$defaults[] = array(
				'proto'   => $rule['proto'],
				'from'    => $rule['from'],
				'to'      => $rule['to'],
				'sources' => array(),
				'label'   => $rule['label'],
				'origin'  => $rule['origin'],
				'port'    => self::port_text( $rule ),
			);
		}

		return array(
			'editable' => self::editable( $r ),
			'managed'  => $managed,
			'on'       => $managed && true === $r->get( Firewall::ON ),
			'rules'    => $rules,
			'defaults' => $defaults,
			'fixed'    => self::fixed( $r ),
			'max'      => self::max_rules( $r ),
			'state'    => in_array( $state, array( 'applying', 'failed' ), true ) ? $state : 'idle',
			'applied'  => $managed && (int) $r->get( Firewall::REV ) === (int) $r->get( Firewall::APPLIED ),
			'ssh_ack'  => true === $r->get( Firewall::ACK ),
			'my_ip'    => $my_ip,
			'app'      => $has_app,
		);
	}


	/**
	 * The actions offered now.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		if ( ! self::editable( $r ) ) {
			return array();
		}

		return array(
			'fw_apply' => __( 'Apply firewall changes', 'beltoft-services' ),
			'fw_reset' => __( 'Start the firewall again', 'beltoft-services' ),
		);
	}

	/**
	 * Why an action cannot be accepted, in words for the customer, or null.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function check( Resource $r, string $action, array $args ): ?string {
		unset( $args );

		if ( ! self::editable( $r ) ) {
			return __( 'The firewall cannot be changed for this server.', 'beltoft-services' );
		}

		if ( 'fw_apply' === $action && ! self::managed( $r ) ) {
			return __( 'Set up the firewall first.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * Stores the wanted state and a new revision (the caller holds the resource lock). Returns the revision, or 0.
	 *
	 * @param Resource                         $r     Resource.
	 * @param bool                             $on    On or off.
	 * @param array<int, array<string, mixed>> $rules Normalised rules.
	 * @param bool                             $ack   The customer accepted that SSH may not work.
	 * @param int                              $user  Customer's user id (for the admin history).
	 */
	public static function store( Resource $r, bool $on, array $rules, bool $ack, int $user ): int {
		$rev     = (int) $r->get( Firewall::REV ) + 1;
		$history = array_slice( array_merge( is_array( $r->get( Firewall::HISTORY ) ) ? $r->get( Firewall::HISTORY ) : array(), array( array( 'at' => time(), 'user' => $user, 'on' => $on, 'rules' => count( $rules ) ) ) ), -20 );

		return $r->set(
			array(
				Firewall::MANAGED => true,
				Firewall::ON      => $on,
				Firewall::RULES   => $rules,
				Firewall::REV     => $rev,
				Firewall::STATE   => 'applying',
				Firewall::ACK     => $ack,
				Firewall::HISTORY => $history,
			)
		) ? $rev : 0;
	}
}
