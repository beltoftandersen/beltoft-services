<?php
/**
 * The firewall in front of a customer's server: the only writer of VM firewall rules and options.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One class owns every Proxmox firewall write (the managed-database work reuses it; it never writes options or
 * rules itself). Rules are told apart by the prefix of their comment:
 *
 * - `beltoft-services: block outgoing SMTP` (the original SMTP rule) and `bs-sys:<name>`: the plugin's own, never
 *   shown as editable, never changed or removed by the dashboard.
 * - `bs-fw:<8 hex>`: a customer rule, the only rules the dashboard adds, changes or removes. The 8 hex are a hash of
 *   what the rule says (protocol, ports, sources), so a rule never changes in place: it is added and the old one removed.
 * - `bs-db:<name>`: reserved for the managed-database work, which uses add_system_rule()-style helpers with this prefix.
 * - Anything else (an admin's own rule) is never touched.
 *
 * A server's firewall is "taken over" when its resource meta `fw_managed` is true. Only then does the helper write
 * `policy_in`, and it always writes it explicitly (DROP with ACCEPT rules for the chosen ports, or ACCEPT when the
 * customer turned the firewall off): the default of an unset `policy_in` is never relied on (on the real host an
 * unset policy lets SSH and HTTP in; the daemon's code says DROP). A server that is not taken over keeps what it
 * has: only `enable=1` and the SMTP block are written, as before. Every customer rule is inbound ACCEPT on net0, so
 * it never reaches the private NIC (net1) nor the system rules.
 *
 * Meta on the server resource: fw_managed, fw_on, fw_rules (list of {id, proto, from, to, sources, label, origin,
 * created}), fw_rev and fw_applied_rev, fw_state (idle, applying, failed), fw_error (admin only, never shown),
 * fw_applied_at, fw_ssh_ack, fw_history (admin only), and fw_locked (set by the managed-database work: the dashboard
 * never edits such a server's firewall).
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>), all under
 * /nodes/{node}/qemu/{vmid}/firewall: GET/PUT options (VM.Audit / VM.Config.Network), GET/POST rules and
 * GET/PUT/DELETE rules/{pos} (a rule made without `enable` is created disabled; `digest` guards a stale write),
 * and ipset (ipfilter-net0 holds the server's own address for the optional anti-spoofing filter).
 */
final class Firewall {

	use Steps;
	use Prepares;

	public const ACTIONS = array( 'fw_apply', 'fw_reset' );

	public const TAG_CUSTOMER = 'bs-fw:';
	public const TAG_SYSTEM   = 'bs-sys:';
	public const TAG_DB       = 'bs-db:';

	public const PRIVATE_RULE = 'bs-sys:private';

	public const MANAGED = 'fw_managed';
	public const ON      = 'fw_on';
	public const RULES   = 'fw_rules';
	public const REV     = 'fw_rev';
	public const APPLIED = 'fw_applied_rev';
	public const STATE   = 'fw_state';
	public const ERROR   = 'fw_error';
	public const AT      = 'fw_applied_at';
	public const ACK     = 'fw_ssh_ack';
	public const HISTORY = 'fw_history';
	public const LOCKED  = 'fw_locked';

	public const MAX_LABEL   = 40;
	public const MAX_SOURCES = 5;
	public const MAX_CEILING = 60;

	/**
	 * Writes (rules and options) one run makes. More work returns a poll and the next run continues.
	 */
	public const WRITE_BUDGET = 12;

	/**
	 * Cluster settings (token secret decrypted).
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * Writes made by this run.
	 *
	 * @var int
	 */
	private $writes = 0;

	/**
	 * The most writes this run may make.
	 *
	 * @var int
	 */
	private $budget = self::WRITE_BUDGET;

	/**
	 * What this run changed: opened and closed port labels, for the history line.
	 *
	 * @var array{opened: string[], closed: string[]}
	 */
	private $changed = array( 'opened' => array(), 'closed' => array() );

	/**
	 * @param Resource    $r       Resource.
	 * @param Client|null $client  A client already built (the build step passes its own); null builds it from the VM record.
	 * @param array|null  $cluster Cluster settings (token secret decrypted) that go with the client.
	 */
	public function __construct( Resource $r, ?Client $client = null, ?array $cluster = null ) {
		$this->r = $r;

		if ( null !== $client && null !== $cluster ) {
			$this->client  = $client;
			$this->cluster = $cluster;
			$this->secrets = array( (string) ( $cluster['token_secret'] ?? '' ) );
			$this->budget  = PHP_INT_MAX; // The build step retries the whole part: no polling there.
		}
	}

	// -- Settings and rules of the feature -----------------------------------------------------------

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
		return 'proxmox' === $r->get( 'driver' )
			&& true !== $r->get( 'linked' )
			&& true !== $r->get( self::LOCKED )
			&& 'ready' === $r->get( 'state' )
			&& self::switched_on( $r );
	}

	/**
	 * Has the dashboard taken over the server's firewall?
	 *
	 * @param Resource $r Resource.
	 */
	public static function managed( Resource $r ): bool {
		return true === $r->get( self::MANAGED );
	}

	/**
	 * Most rules a server may have.
	 *
	 * @param Resource $r Resource.
	 */
	public static function max_rules( Resource $r ): int {
		$cluster = self::cluster_of( $r );
		$max     = null === $cluster ? 30 : (int) ( $cluster['fw_max_rules'] ?? 30 );

		return max( 1, min( self::MAX_CEILING, $max ) );
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
		$label = trim( substr( $label, 0, self::MAX_LABEL ) );

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
		$rules = $r->get( self::RULES );

		return is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : array();
	}

	// -- Validation ---------------------------------------------------------------------------------

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
			return new \WP_Error( 'beltoft_services_refused', sprintf( /* translators: %d: most rules */ __( 'A server can have up to %d rules.', 'beltoft-services' ), self::max_rules( $r ) ), array( 'status' => 422 ) );
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

			if ( ! is_array( $sources ) || count( $sources ) > self::MAX_SOURCES ) {
				return self::rule_error( sprintf( /* translators: %d: most addresses */ __( 'Up to %d addresses can be named in one rule.', 'beltoft-services' ), self::MAX_SOURCES ), $i );
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

	// -- What the page shows --------------------------------------------------------------------------

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
		$state   = (string) $r->get( self::STATE );
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
			'on'       => $managed && true === $r->get( self::ON ),
			'rules'    => $rules,
			'defaults' => $defaults,
			'fixed'    => self::fixed( $r ),
			'max'      => self::max_rules( $r ),
			'state'    => in_array( $state, array( 'applying', 'failed' ), true ) ? $state : 'idle',
			'applied'  => $managed && (int) $r->get( self::REV ) === (int) $r->get( self::APPLIED ),
			'ssh_ack'  => true === $r->get( self::ACK ),
			'my_ip'    => $my_ip,
			'app'      => $has_app,
		);
	}

	// -- The customer's actions -----------------------------------------------------------------------

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
		$rev     = (int) $r->get( self::REV ) + 1;
		$history = array_slice( array_merge( is_array( $r->get( self::HISTORY ) ) ? $r->get( self::HISTORY ) : array(), array( array( 'at' => time(), 'user' => $user, 'on' => $on, 'rules' => count( $rules ) ) ) ), -20 );

		return $r->set(
			array(
				self::MANAGED => true,
				self::ON      => $on,
				self::RULES   => $rules,
				self::REV     => $rev,
				self::STATE   => 'applying',
				self::ACK     => $ack,
				self::HISTORY => $history,
			)
		) ? $rev : 0;
	}

	// -- Run ------------------------------------------------------------------------------------------

	/**
	 * The job of fw_apply and fw_reset (called from Actions::run() under the resource lock).
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function run( string $action, array $args ): StepResult {
		unset( $args );

		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'VM record missing; check Proxmox manually' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, 'firewall' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( ! self::editable( $this->r ) || ( 'fw_apply' === $action && ! self::managed( $this->r ) ) ) {
			return StepResult::done( array(), 'Firewall refused: not available for this server' );
		}

		if ( 'fw_reset' === $action ) {
			$rev = (int) $this->r->get( self::REV ) + 1;

			if ( ! $this->r->set( array( self::MANAGED => true, self::ON => true, self::RULES => self::defaults( $this->r ), self::REV => $rev, self::STATE => 'applying', self::ACK => false ) ) ) {
				return StepResult::retry( 0, 'firewall: progress could not be stored' );
			}
		}

		$result = $this->apply( $record, $v );

		if ( $result instanceof StepResult ) {
			return $result->is_done() ? $result : $this->noted( $result );
		}

		return $result;
	}

	/**
	 * What a failed or waiting run records: the customer sees a fixed text, the admin sees the scrubbed error.
	 *
	 * @param StepResult $result Result.
	 */
	private function noted( StepResult $result ): StepResult {
		if ( $result->is_failed() ) {
			return StepResult::fail( $result->error(), array( self::STATE => 'failed', self::ERROR => $this->scrub( $result->error(), 200 ) ) );
		}

		return $result;
	}

	/**
	 * Takes the stored wanted state to Proxmox and checks it by reading it back.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function apply( array $record, array $v ): StepResult {
		$rev     = (int) $this->r->get( self::REV );
		$desired = self::rules_of( $this->r );
		$on      = true === $this->r->get( self::ON );
		$base    = self::base( $record, $v );

		for ( $try = 0; $try < 2; $try++ ) {
			$synced = $this->sync( $base, $v, $desired, $on );

			if ( $synced instanceof StepResult ) {
				return $synced;
			}

			$same = $this->verify( $base, $v, $desired, $on );

			if ( $same instanceof StepResult ) {
				return $same;
			}

			if ( true === $same ) {
				return $this->finished( $rev, $on );
			}
		}

		return StepResult::fail( 'firewall: Proxmox does not show the rules that were written', array( self::STATE => 'failed', self::ERROR => 'The rules read back from Proxmox differ from the stored ones.' ) );
	}

	/**
	 * The success result: meta, and a history line with counts and ports (never addresses).
	 *
	 * @param int  $rev Revision that was applied.
	 * @param bool $on  On or off.
	 */
	private function finished( int $rev, bool $on ): StepResult {
		$opened = $this->changed['opened'];
		$closed = $this->changed['closed'];
		$text   = sprintf(
			'Firewall %1$s: %2$d opened%3$s, %4$d closed%5$s',
			$on ? 'on' : 'off',
			count( $opened ),
			$opened ? ' (' . implode( ', ', array_slice( $opened, 0, 12 ) ) . ')' : '',
			count( $closed ),
			$closed ? ' (' . implode( ', ', array_slice( $closed, 0, 12 ) ) . ')' : ''
		);

		return StepResult::done(
			array(
				self::MANAGED => true,
				self::APPLIED => $rev,
				self::STATE   => (int) $this->r->get( self::REV ) === $rev ? 'idle' : 'applying',
				self::ERROR   => null,
				self::AT      => time(),
			),
			$text
		);
	}

	// -- Proxmox -------------------------------------------------------------------------------------

	/**
	 * The firewall path of a VM.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public static function base( array $record, array $v ): string {
		return '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . (int) $record['vmid'] . '/firewall';
	}

	/**
	 * A read.
	 *
	 * @param string $path Path.
	 * @return array|\WP_Error
	 */
	private function read_list( string $path ) {
		$answer = $this->client->request( 'GET', $path );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return is_array( $answer ) ? $answer : array();
	}

	/**
	 * A write: counted against the run's budget.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 * @return mixed|\WP_Error
	 */
	private function write( string $method, string $path, array $params = array() ) {
		if ( $this->writes >= $this->budget ) {
			return new \WP_Error( 'fw_budget', 'The run has made as many writes as it may.' );
		}

		++$this->writes;

		return $this->client->request( $method, $path, $params );
	}

	/**
	 * The answer of an error: out of budget polls (the next run continues from what is there), anything else is classified.
	 *
	 * @param \WP_Error $e Error.
	 */
	private function stop( \WP_Error $e ): StepResult {
		if ( 'fw_budget' === $e->get_error_code() ) {
			return StepResult::poll( 3, 'firewall: more rules to write', 300 )->with_progress();
		}

		return $this->classify( $e, 'firewall' );
	}

	/**
	 * Is this comment one of the plugin's own system rules?
	 *
	 * @param string $comment Comment.
	 */
	private static function is_system( string $comment ): bool {
		return Provision::SMTP_COMMENT === $comment || 0 === strpos( $comment, self::TAG_SYSTEM );
	}

	/**
	 * The PVE parameters of a customer rule.
	 *
	 * @param array<string, mixed> $rule Rule.
	 * @return array<string, mixed>
	 */
	private static function params( array $rule ): array {
		$p = array(
			'type'    => 'in',
			'action'  => 'ACCEPT',
			'proto'   => $rule['proto'],
			'iface'   => 'net0',
			'enable'  => 1,
			'comment' => self::TAG_CUSTOMER . $rule['id'],
		);

		if ( 'icmp' !== $rule['proto'] ) {
			$p['dport'] = $rule['from'] === $rule['to'] ? (string) $rule['from'] : $rule['from'] . ':' . $rule['to'];
		}

		if ( $rule['sources'] ) {
			$p['source'] = implode( ',', $rule['sources'] );
		}

		return $p;
	}

	/**
	 * Does a rule Proxmox lists say what the stored rule says?
	 *
	 * @param array<string, mixed> $remote Listed rule.
	 * @param array<string, mixed> $rule   Stored rule.
	 */
	private static function same( array $remote, array $rule ): bool {
		$want = self::params( $rule );
		$have = array(
			'type'   => (string) ( $remote['type'] ?? '' ),
			'action' => (string) ( $remote['action'] ?? '' ),
			'proto'  => (string) ( $remote['proto'] ?? '' ),
			'iface'  => (string) ( $remote['iface'] ?? '' ),
			'enable' => (int) ( $remote['enable'] ?? 0 ),
			'dport'  => (string) ( $remote['dport'] ?? '' ),
			'source' => (string) ( $remote['source'] ?? '' ),
		);
		$want = array(
			'type'   => $want['type'],
			'action' => $want['action'],
			'proto'  => $want['proto'],
			'iface'  => $want['iface'],
			'enable' => 1,
			'dport'  => (string) ( $want['dport'] ?? '' ),
			'source' => (string) ( $want['source'] ?? '' ),
		);

		foreach ( array( &$have, &$want ) as &$side ) {
			$parts = array_filter( explode( ',', $side['source'] ) );
			sort( $parts );
			$side['source'] = implode( ',', $parts );
		}

		return $have === $want;
	}

	/**
	 * Rules that carry a given comment prefix, from a listing.
	 *
	 * @param array<int, mixed> $rules  Listing.
	 * @param string            $prefix Comment prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private static function tagged( array $rules, string $prefix ): array {
		return array_values(
			array_filter(
				$rules,
				static fn( $rule ) => is_array( $rule ) && 0 === strpos( (string) ( $rule['comment'] ?? '' ), $prefix )
			)
		);
	}

	/**
	 * Does the VM have a private NIC?
	 *
	 * @param array<string, mixed> $v Verified VM.
	 */
	private static function has_private_nic( array $v ): bool {
		return ! empty( $v['config']['net1'] );
	}

	/**
	 * Brings the VM's firewall to the stored state: options and the plugin's own rules, then the customer's rules
	 * (added before the obsolete ones are removed, so a port is never closed during a change), and the inbound
	 * policy last, so the rules exist before the policy tightens.
	 *
	 * @param string                           $base    Firewall path.
	 * @param array<string, mixed>             $v       Verified VM.
	 * @param array<int, array<string, mixed>> $desired Customer rules.
	 * @param bool                             $on      On or off.
	 * @return true|StepResult
	 */
	private function sync( string $base, array $v, array $desired, bool $on ) {
		$options = $this->read_list( $base . '/options' );

		if ( is_wp_error( $options ) ) {
			return $this->stop( $options );
		}

		$rules = $this->read_list( $base . '/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->stop( $rules );
		}

		$done = $this->base_options( $base, $options, $v );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$done = $this->system_rules( $base, $rules, $v );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		// Add what is missing.
		$remote = self::tagged( $rules, self::TAG_CUSTOMER );

		foreach ( $desired as $rule ) {
			$found = false;

			foreach ( $remote as $listed ) {
				if ( self::TAG_CUSTOMER . $rule['id'] === (string) $listed['comment'] && self::same( $listed, $rule ) ) {
					$found = true;
					break;
				}
			}

			if ( $found ) {
				continue;
			}

			$answer = $this->write( 'POST', $base . '/rules', self::params( $rule ) );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}

			$this->changed['opened'][] = 'icmp' === $rule['proto'] ? 'ping' : ( $rule['from'] === $rule['to'] ? (string) $rule['from'] : $rule['from'] . '-' . $rule['to'] );
		}

		$done = $this->remove_obsolete( $base, $desired );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$policy = $on ? 'DROP' : 'ACCEPT';

		if ( (string) ( $options['policy_in'] ?? '' ) !== $policy ) {
			$answer = $this->write( 'PUT', $base . '/options', array( 'policy_in' => $policy ) );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		return true;
	}

	/**
	 * Removes the customer rules that are not wanted, highest position first; the list is read again before each
	 * delete (a delete changes the positions and the digest).
	 *
	 * @param string                           $base    Firewall path.
	 * @param array<int, array<string, mixed>> $desired Customer rules.
	 * @return true|StepResult
	 */
	private function remove_obsolete( string $base, array $desired ) {
		$by_tag = array();

		foreach ( $desired as $rule ) {
			$by_tag[ self::TAG_CUSTOMER . $rule['id'] ] = $rule;
		}

		for ( $guard = 0; $guard < self::MAX_CEILING + 10; $guard++ ) {
			$rules = $this->read_list( $base . '/rules' );

			if ( is_wp_error( $rules ) ) {
				return $this->stop( $rules );
			}

			$keep   = array();
			$target = null;

			foreach ( $rules as $i => $listed ) {
				if ( ! is_array( $listed ) || 0 !== strpos( (string) ( $listed['comment'] ?? '' ), self::TAG_CUSTOMER ) ) {
					continue;
				}

				$tag  = (string) $listed['comment'];
				$good = isset( $by_tag[ $tag ] ) && self::same( $listed, $by_tag[ $tag ] ) && ! isset( $keep[ $tag ] );

				if ( $good ) {
					$keep[ $tag ] = true;
					continue;
				}

				$target = array( 'pos' => (int) ( $listed['pos'] ?? $i ), 'digest' => (string) ( $listed['digest'] ?? '' ), 'listed' => $listed );
			}

			if ( null === $target ) {
				return true;
			}

			$params = '' !== $target['digest'] ? array( 'digest' => $target['digest'] ) : array();
			$answer = $this->write( 'DELETE', $base . '/rules/' . $target['pos'], $params );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}

			$listed = $target['listed'];

			if ( empty( $by_tag[ (string) $listed['comment'] ] ) ) {
				$this->changed['closed'][] = 'icmp' === ( $listed['proto'] ?? '' ) ? 'ping' : (string) str_replace( ':', '-', (string) ( $listed['dport'] ?? '' ) );
			}
		}

		return StepResult::retry( 0, 'firewall: the rule list kept changing' );
	}

	/**
	 * The options every managed server gets: the firewall on, outgoing accepted, MAC filter on, and (when the
	 * cluster asks for it) the IP filter built from the server's own address.
	 *
	 * @param string               $base    Firewall path.
	 * @param array<string, mixed> $options Options read.
	 * @param array<string, mixed> $v       Verified VM.
	 * @return true|StepResult
	 */
	private function base_options( string $base, array $options, array $v ) {
		$want = array(
			'enable'     => 1,
			'policy_out' => 'ACCEPT',
			'macfilter'  => 1,
		);

		if ( ! empty( $this->cluster['fw_ipfilter'] ) ) {
			$ready = $this->ipfilter_sets( $base, $v );

			if ( $ready instanceof StepResult ) {
				return $ready;
			}

			if ( true === $ready ) {
				$want['ipfilter'] = 1;
			}
		}

		$change = array();

		foreach ( $want as $key => $value ) {
			if ( ! array_key_exists( $key, $options ) || (string) $options[ $key ] !== (string) $value ) {
				$change[ $key ] = $value;
			}
		}

		if ( ! $change ) {
			return true;
		}

		$answer = $this->write( 'PUT', $base . '/options', $change );

		return is_wp_error( $answer ) ? $this->stop( $answer ) : true;
	}

	/**
	 * Makes the ipfilter-net sets hold the server's own address (and the private one on net1) and read them back;
	 * ipfilter is only turned on when they are right, because a wrong set cuts all outgoing traffic.
	 *
	 * @param string               $base Firewall path.
	 * @param array<string, mixed> $v    Verified VM.
	 * @return bool|StepResult True when the sets are right.
	 */
	private function ipfilter_sets( string $base, array $v ) {
		$sets = self::expected_ipsets( $this->r, self::has_private_nic( $v ) );

		if ( null === $sets ) {
			return false;
		}

		$list = $this->read_list( $base . '/ipset' );

		if ( is_wp_error( $list ) ) {
			return $this->stop( $list );
		}

		$names = array_column( $list, 'name' );

		foreach ( $sets as $name => $address ) {
			if ( ! in_array( $name, $names, true ) ) {
				$answer = $this->write( 'POST', $base . '/ipset', array( 'name' => $name ) );

				if ( is_wp_error( $answer ) ) {
					return $this->stop( $answer );
				}
			}

			$entries = $this->read_list( $base . '/ipset/' . $name );

			if ( is_wp_error( $entries ) ) {
				return $this->stop( $entries );
			}

			$has = false;

			foreach ( $entries as $entry ) {
				$cidr = (string) ( $entry['cidr'] ?? '' );

				if ( $cidr === $address || $cidr === $address . '/32' ) {
					$has = true;
					continue;
				}

				$answer = $this->write( 'DELETE', $base . '/ipset/' . $name . '/' . $cidr );

				if ( is_wp_error( $answer ) ) {
					return $this->stop( $answer );
				}
			}

			if ( ! $has ) {
				$answer = $this->write( 'POST', $base . '/ipset/' . $name, array( 'cidr' => $address ) );

				if ( is_wp_error( $answer ) ) {
					return $this->stop( $answer );
				}
			}

			$entries = $this->read_list( $base . '/ipset/' . $name );

			if ( is_wp_error( $entries ) ) {
				return $this->stop( $entries );
			}

			$cidrs = array_map( static fn( $e ) => (string) ( $e['cidr'] ?? '' ), $entries );

			if ( array( $address ) !== $cidrs && array( $address . '/32' ) !== $cidrs ) {
				return false;
			}
		}

		// A private NIC that is gone leaves no allowed private address behind.
		if ( ! isset( $sets['ipfilter-net1'] ) && in_array( 'ipfilter-net1', $names, true ) ) {
			$stale = $this->drop_ipset( $base, 'ipfilter-net1' );

			if ( $stale instanceof StepResult ) {
				return $stale;
			}
		}

		return true;
	}

	/**
	 * The ipfilter sets a server should have: the public address in ipfilter-net0 when its profile has a public
	 * address (null while that address is not known), and the private one in ipfilter-net1 when it has a private NIC.
	 * A server with neither gets no filter (null). A server whose profile has no public address (a managed
	 * database) is filtered on net1 alone.
	 *
	 * @param Resource $r           Resource.
	 * @param bool     $private_nic Whether the VM has a private NIC (net1).
	 * @return array<string, string>|null Set name => address.
	 */
	public static function expected_ipsets( Resource $r, bool $private_nic ): ?array {
		$sets = array();

		if ( Profiles::for_resource( $r )->uses_public_ip() ) {
			$ip = (string) $r->get( 'ip' );

			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return null;
			}

			$sets['ipfilter-net0'] = $ip;
		}

		if ( $private_nic ) {
			$private = PrivateNets::address_for_resource( $r->id() );

			if ( null === $private ) {
				return null;
			}

			$sets['ipfilter-net1'] = $private;
		}

		return $sets ? $sets : null;
	}

	/**
	 * Empties and deletes one ipset (Proxmox deletes only an empty set).
	 *
	 * @param string $base Firewall path.
	 * @param string $name Set name.
	 * @return true|StepResult
	 */
	private function drop_ipset( string $base, string $name ) {
		$entries = $this->read_list( $base . '/ipset/' . $name );

		if ( is_wp_error( $entries ) ) {
			return $this->stop( $entries );
		}

		foreach ( $entries as $entry ) {
			$answer = $this->write( 'DELETE', $base . '/ipset/' . $name . '/' . (string) ( $entry['cidr'] ?? '' ) );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		$answer = $this->write( 'DELETE', $base . '/ipset/' . $name );

		return is_wp_error( $answer ) ? $this->stop( $answer ) : true;
	}

	/**
	 * Keeps the ipfilter sets right after the private NIC came or went, for a server whose filter is already on
	 * (the filter off, or the cluster flag off: nothing to do). The private address is in the set before the server
	 * starts with the card; after a detach the set is gone.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return true|StepResult
	 */
	public function ensure_ipfilter( array $record, array $v ) {
		if ( empty( $this->cluster['fw_ipfilter'] ) ) {
			return true;
		}

		$base    = self::base( $record, $v );
		$options = $this->read_list( $base . '/options' );

		if ( is_wp_error( $options ) ) {
			return $this->stop( $options );
		}

		if ( 1 !== (int) ( $options['ipfilter'] ?? 0 ) ) {
			return true;
		}

		$ready = $this->ipfilter_sets( $base, $v );

		if ( $ready instanceof StepResult ) {
			return $ready;
		}

		return true === $ready ? true : StepResult::retry( 0, 'firewall: the ipfilter sets could not be confirmed' );
	}

	/**
	 * The plugin's own rules: the SMTP block (when the cluster blocks mail) and, for a server with a private NIC,
	 * the rule that keeps the private side open under a DROP policy. Each is added once, found by its comment.
	 *
	 * @param string               $base  Firewall path.
	 * @param array<int, mixed>    $rules Listing.
	 * @param array<string, mixed> $v     Verified VM.
	 * @return true|StepResult
	 */
	private function system_rules( string $base, array $rules, array $v ) {
		if ( ! empty( $this->cluster['block_smtp'] ) ) {
			$done = $this->add_rule_once( $base, $rules, Provision::SMTP_COMMENT, array( 'action' => 'DROP', 'type' => 'out', 'proto' => 'tcp', 'dport' => '25', 'iface' => 'net0' ) );

			if ( $done instanceof StepResult ) {
				return $done;
			}
		}

		if ( self::has_private_nic( $v ) ) {
			$done = $this->add_rule_once( $base, $rules, self::PRIVATE_RULE, array( 'action' => 'ACCEPT', 'type' => 'in', 'iface' => 'net1' ) );

			if ( $done instanceof StepResult ) {
				return $done;
			}
		}

		return true;
	}

	/**
	 * The rule that keeps the private NIC (net1) open under a DROP inbound policy, added once. Called right after a
	 * private NIC is attached or put back, so the private network works without waiting for the next apply.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return true|StepResult
	 */
	public function ensure_private( array $record, array $v ) {
		$base  = self::base( $record, $v );
		$rules = $this->read_list( $base . '/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->classify( $rules, 'private' );
		}

		$done = $this->add_rule_once( $base, $rules, self::PRIVATE_RULE, array( 'action' => 'ACCEPT', 'type' => 'in', 'iface' => 'net1' ) );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		return $this->ensure_ipfilter( $record, $v );
	}

	/**
	 * The firewall of a managed database server (plugin-owned, never edited by the customer): the firewall on,
	 * inbound DROP, outgoing accepted, MAC filter on, and the rule that keeps the private NIC (net1) open. The
	 * rule goes first, so the policy never closes the private side; the options are read back.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, mixed>|StepResult
	 */
	public function ensure_database( array $record, array $v ) {
		$base  = self::base( $record, $v );
		$rules = $this->read_list( $base . '/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->classify( $rules, 'configure' );
		}

		$done = $this->add_rule_once( $base, $rules, self::PRIVATE_RULE, array( 'action' => 'ACCEPT', 'type' => 'in', 'iface' => 'net1' ) );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$want = array( 'enable' => 1, 'policy_in' => 'DROP', 'policy_out' => 'ACCEPT', 'macfilter' => 1 );

		if ( ! empty( $this->cluster['fw_ipfilter'] ) ) {
			$ready = $this->ipfilter_sets( $base, $v );

			if ( $ready instanceof StepResult ) {
				return $ready;
			}

			if ( true === $ready ) {
				$want['ipfilter'] = 1;
			}
		}

		$options = $this->read_list( $base . '/options' );

		if ( is_wp_error( $options ) ) {
			return $this->classify( $options, 'configure' );
		}

		$change = array();

		foreach ( $want as $key => $value ) {
			if ( ! array_key_exists( $key, $options ) || (string) $options[ $key ] !== (string) $value ) {
				$change[ $key ] = $value;
			}
		}

		if ( $change ) {
			$answer = $this->write( 'PUT', $base . '/options', $change );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		return array();
	}

	/**
	 * Adds a plugin rule unless one with the same comment is there (the shared helper for the plugin's own rules:
	 * `bs-sys:<name>` for the system, `bs-db:<name>` for the managed databases). Always enabled.
	 *
	 * @param string               $base    Firewall path.
	 * @param array<int, mixed>    $rules   Listing.
	 * @param string               $comment Comment (the tag).
	 * @param array<string, mixed> $params  Rule parameters, without enable and comment.
	 * @return true|StepResult
	 */
	public function add_rule_once( string $base, array $rules, string $comment, array $params ) {
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && $comment === (string) ( $rule['comment'] ?? '' ) ) {
				return true;
			}
		}

		$answer = $this->write( 'POST', $base . '/rules', array_merge( $params, array( 'enable' => 1, 'comment' => $comment ) ) );

		return is_wp_error( $answer ) ? $this->stop( $answer ) : true;
	}

	/**
	 * Reads the firewall back and compares it with the stored state.
	 *
	 * @param string                           $base    Firewall path.
	 * @param array<string, mixed>             $v       Verified VM.
	 * @param array<int, array<string, mixed>> $desired Customer rules.
	 * @param bool                             $on      On or off.
	 * @return bool|StepResult
	 */
	private function verify( string $base, array $v, array $desired, bool $on ) {
		$options = $this->read_list( $base . '/options' );
		$rules   = is_wp_error( $options ) ? $options : $this->read_list( $base . '/rules' );

		if ( is_wp_error( $options ) || is_wp_error( $rules ) ) {
			return $this->stop( is_wp_error( $options ) ? $options : $rules );
		}

		if ( 1 !== (int) ( $options['enable'] ?? 0 ) || (string) ( $options['policy_in'] ?? '' ) !== ( $on ? 'DROP' : 'ACCEPT' ) || 'ACCEPT' !== (string) ( $options['policy_out'] ?? 'ACCEPT' ) || 1 !== (int) ( $options['macfilter'] ?? 1 ) ) {
			return false;
		}

		$remote = self::tagged( $rules, self::TAG_CUSTOMER );

		if ( count( $remote ) !== count( $desired ) ) {
			return false;
		}

		foreach ( $desired as $rule ) {
			$found = false;

			foreach ( $remote as $listed ) {
				if ( self::TAG_CUSTOMER . $rule['id'] === (string) $listed['comment'] && self::same( $listed, $rule ) ) {
					$found = true;
				}
			}

			if ( ! $found ) {
				return false;
			}
		}

		$comments = array_map( static fn( $rule ) => (string) ( is_array( $rule ) ? ( $rule['comment'] ?? '' ) : '' ), $rules );

		if ( ! empty( $this->cluster['block_smtp'] ) && ! in_array( Provision::SMTP_COMMENT, $comments, true ) ) {
			return false;
		}

		return ! self::has_private_nic( $v ) || in_array( self::PRIVATE_RULE, $comments, true );
	}

	// -- The build step ------------------------------------------------------------------------------

	/**
	 * The firewall part of a build or a rebuild (Provision::configure_firewall delegates here). A server the
	 * dashboard manages, and a new server of a cluster that has the feature on with the standard default, gets the
	 * stored rules (the standard set and the app's ports for a new one) applied with an explicit inbound policy.
	 * Any other server gets what it always got: the firewall switched on and the SMTP block.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, mixed>|StepResult
	 */
	public function ensure( array $record, array $v ) {
		$base    = self::base( $record, $v );
		$managed = self::managed( $this->r );
		$fresh   = ! $managed && self::on_for( (array) $this->cluster, $this->r ) && 'standard' === (string) ( $this->cluster['fw_default_mode'] ?? 'standard' ) && true !== $this->r->get( self::LOCKED );

		if ( $managed || $fresh ) {
			if ( true === $this->r->get( self::LOCKED ) ) {
				return $this->legacy( $base, $v );
			}

			$rules = $managed ? $this->with_app_ports( self::rules_of( $this->r ) ) : self::defaults( $this->r );
			$on    = $managed ? false !== $this->r->get( self::ON ) : true;
			$rev   = (int) $this->r->get( self::REV ) + 1;

			if ( ! $this->r->set( array( self::MANAGED => true, self::ON => $on, self::RULES => $rules, self::REV => $rev, self::STATE => 'applying' ) ) ) {
				return StepResult::retry( 0, 'configure: progress could not be stored' );
			}

			$done = $this->sync( $base, $v, $rules, $on );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$same = $this->verify( $base, $v, $rules, $on );

			if ( $same instanceof StepResult ) {
				return $same;
			}

			if ( true !== $same ) {
				return StepResult::retry( 0, 'configure: the firewall rules read back from Proxmox differ from the ones written' );
			}

			$this->r->set( array( self::APPLIED => $rev, self::STATE => 'idle', self::ERROR => null, self::AT => time() ) );

			return array();
		}

		return $this->legacy( $base, $v );
	}

	/**
	 * The stored rules with the app's ports refreshed: only the rules marked origin app change, the customer's stay.
	 *
	 * @param array<int, array<string, mixed>> $rules Stored rules.
	 * @return array<int, array<string, mixed>>
	 */
	private function with_app_ports( array $rules ): array {
		$out = array_values( array_filter( $rules, static fn( $rule ) => 'app' !== ( $rule['origin'] ?? '' ) ) );
		$ids = array_column( $out, 'id' );

		foreach ( self::defaults( $this->r ) as $rule ) {
			if ( 'app' === $rule['origin'] && ! in_array( $rule['id'], $ids, true ) ) {
				$out[] = $rule;
			}
		}

		return $out;
	}

	/**
	 * What every server got before the dashboard firewall: the firewall switched on, and the SMTP block once. No
	 * inbound policy is written.
	 *
	 * @param string               $base Firewall path.
	 * @param array<string, mixed> $v    Verified VM.
	 * @return array<string, mixed>|StepResult
	 */
	private function legacy( string $base, array $v ) {
		unset( $v );

		// The policies are written, never left unset: Proxmox drops inbound traffic for a VM firewall without a
		// policy_in, so an unset one would close a new server the moment the host enforces VM rules.
		$answer = $this->write( 'PUT', $base . '/options', array( 'enable' => 1, 'policy_in' => 'ACCEPT', 'policy_out' => 'ACCEPT' ) );

		if ( is_wp_error( $answer ) ) {
			return $this->classify( $answer, 'configure' );
		}

		if ( empty( $this->cluster['block_smtp'] ) ) {
			return array();
		}

		$rules = $this->read_list( $base . '/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->classify( $rules, 'configure' );
		}

		$done = $this->add_rule_once( $base, $rules, Provision::SMTP_COMMENT, array( 'action' => 'DROP', 'type' => 'out', 'proto' => 'tcp', 'dport' => '25', 'iface' => 'net0' ) );

		return $done instanceof StepResult ? $done : array();
	}

	// -- What Proxmox says now, for a server that is not managed here --------------------------------

	/**
	 * What the VM's firewall does now, read from Proxmox: open (off, or an inbound policy of ACCEPT), closed (policy
	 * DROP or REJECT), or unknown (no policy is set, so what applies is not ours to say). One call (two with the
	 * VM check); null when Proxmox cannot be read.
	 *
	 * @return string|null
	 */
	public function live(): ?string {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record || null !== $this->prepare( $record ) ) {
			return null;
		}

		$v = $this->verified( $record, 'firewall' );

		if ( $v instanceof StepResult ) {
			return null;
		}

		$options = $this->read_list( self::base( $record, $v ) . '/options' );

		if ( is_wp_error( $options ) ) {
			return null;
		}

		if ( 1 !== (int) ( $options['enable'] ?? 0 ) ) {
			return 'open';
		}

		$policy = (string) ( $options['policy_in'] ?? '' );

		if ( '' === $policy ) {
			return 'unknown';
		}

		return 'ACCEPT' === $policy ? 'open' : 'closed';
	}
}
