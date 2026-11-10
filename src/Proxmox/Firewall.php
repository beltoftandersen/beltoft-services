<?php
/**
 * The firewall in front of a customer's server: the only writer of VM firewall rules and options.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
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
	use FirewallIpfilter;
	use FirewallDatabase;
	use FirewallQuarantine;

	public const ACTIONS = array( 'fw_apply', 'fw_reset' );

	/**
	 * Jobs only the plugin queues (network quarantine and its lift), never offered to the customer.
	 */
	public const INTERNAL = array( 'fw_quarantine', 'fw_quarantine_lift' );

	public const QUARANTINE_TAG = 'bs-sys:quarantine';

	public const TAG_CUSTOMER = 'bs-fw:';
	public const TAG_DB       = 'bs-db:';
	public const DB_WORLD     = 'bs-db:world';

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

		if ( in_array( $action, self::INTERNAL, true ) ) {
			return 'fw_quarantine' === $action ? $this->quarantine( $record, $v ) : $this->lift( $record, $v );
		}

		if ( ! FirewallRules::editable( $this->r ) || ( 'fw_apply' === $action && ! FirewallRules::managed( $this->r ) ) ) {
			return StepResult::done( array(), 'Firewall refused: not available for this server' );
		}

		if ( 'fw_reset' === $action ) {
			$rev = (int) $this->r->get( self::REV ) + 1;

			if ( ! $this->r->set( array( self::MANAGED => true, self::ON => true, self::RULES => FirewallRules::defaults( $this->r ), self::REV => $rev, self::STATE => 'applying', self::ACK => false ) ) ) {
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
		$desired = FirewallRules::rules_of( $this->r );
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
			'policy_out' => $this->policy_out(),
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

		// A quarantined server keeps its quarantine through every reconcile.
		return Quarantine::active( $this->r ) ? $this->add_quarantine_rules( $base, $rules, $v ) : true;
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

		if ( 1 !== (int) ( $options['enable'] ?? 0 ) || (string) ( $options['policy_in'] ?? '' ) !== ( $on ? 'DROP' : 'ACCEPT' ) || $this->policy_out() !== (string) ( $options['policy_out'] ?? 'ACCEPT' ) || 1 !== (int) ( $options['macfilter'] ?? 1 ) ) {
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

		if ( Quarantine::active( $this->r ) ) {
			foreach ( array_keys( $this->quarantine_rules( $v ) ) as $comment ) {
				if ( ! in_array( $comment, $comments, true ) ) {
					return false;
				}
			}
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
		$managed = FirewallRules::managed( $this->r );
		$fresh   = ! $managed && FirewallRules::on_for( (array) $this->cluster, $this->r ) && 'standard' === (string) ( $this->cluster['fw_default_mode'] ?? 'standard' ) && true !== $this->r->get( self::LOCKED );

		if ( $managed || $fresh ) {
			if ( true === $this->r->get( self::LOCKED ) ) {
				return $this->legacy( $base, $v );
			}

			$rules = $managed ? $this->with_app_ports( FirewallRules::rules_of( $this->r ) ) : FirewallRules::defaults( $this->r );
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

		foreach ( FirewallRules::defaults( $this->r ) as $rule ) {
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
		$answer = $this->write( 'PUT', $base . '/options', array( 'enable' => 1, 'policy_in' => 'ACCEPT', 'policy_out' => $this->policy_out() ) );

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
