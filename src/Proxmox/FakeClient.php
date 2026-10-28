<?php
/**
 * An in-memory Proxmox cluster for tests and staging.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * Models the Proxmox VE API calls the driver uses, with the documented parameter names (API
 * viewer: https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>) and rejects unknown or
 * missing parameters the way the API's JSON schema does (HTTP 400). Behaviour beyond what the
 * docs state is a modelling convenience, marked "modelled" below, and must not be taken as proof
 * of what a real cluster answers.
 *
 * Seeding: add_node(), add_pool(), add_vm(). Scripting: fail_next(), next_task(). Inspection:
 * calls() (secrets scrubbed), state().
 */
class FakeClient implements Client {

	use Tasks;

	const OPTION = 'beltoft_services_fake_proxmox';

	/**
	 * Parameters that never appear in records or in the persisted state.
	 */
	const SENSITIVE = array( 'cipassword', 'password' );

	/**
	 * Persist in the option (staging) instead of in memory.
	 *
	 * @var bool
	 */
	private $persist;

	/**
	 * Cluster state: nodes, vms, pools, tasks, seq.
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Recorded calls.
	 *
	 * @var array<int, array{method: string, path: string, params: array<string, mixed>}>
	 */
	private $calls = array();

	/**
	 * Scripted failures: method, regex, result.
	 *
	 * @var array<int, array{0: string, 1: string, 2: \WP_Error|array}>
	 */
	private $failures = array();

	/**
	 * Scripted task outcomes, used by the next tasks created.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $outcomes = array();

	/**
	 * @param bool $persist Keep the state in the option beltoft_services_fake_proxmox (autoload off).
	 */
	public function __construct( bool $persist = false ) {
		$this->persist = $persist;
		$this->state   = self::blank();
		$this->load();
	}

	/**
	 * Empty cluster.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank(): array {
		return array(
			'nodes' => array(),
			'vms'   => array(),
			'pools' => array(),
			'tasks' => array(),
			'seq'   => 0,
			'backups' => array(),
			'images' => array(),
		);
	}

	// Seeding and inspection.

	/**
	 * Adds a node.
	 *
	 * @param string               $name Node name.
	 * @param array<string, mixed> $data maxmem, mem (bytes), maxcpu, cpu, status, uptime.
	 */
	public function add_node( string $name, array $data = array() ): void {
		$this->load();
		$this->state['nodes'][ $name ] = array_merge(
			array(
				'node'   => $name,
				'status' => 'online',
				'maxmem' => 68719476736,
				'mem'    => 0,
				'maxcpu' => 16,
				'cpu'    => 0,
				'uptime' => 1000,
			),
			$data,
			array( 'node' => $name )
		);
		$this->save();
	}

	/**
	 * Adds a pool.
	 *
	 * @param string $poolid Pool id.
	 */
	public function add_pool( string $poolid ): void {
		$this->load();
		$this->state['pools'][ $poolid ] = array( 'members' => array() );
		$this->save();
	}

	/**
	 * Adds a VM: vmid, node, name, status, template, pool, lock, config, firewall_rules.
	 *
	 * @param array<string, mixed> $vm VM.
	 */
	public function add_vm( array $vm ): void {
		$this->load();
		$vm = array_merge(
			array(
				'name'             => 'vm' . $vm['vmid'],
				'status'           => 'stopped',
				'template'         => 0,
				'pool'             => '',
				'lock'             => '',
				'config'           => array(),
				'firewall_rules'   => array(),
				'firewall_options' => array(),
			),
			$vm
		);
		$this->state['vms'][ (int) $vm['vmid'] ] = $this->strip( $vm );

		if ( '' !== (string) $vm['pool'] ) {
			$this->state['pools'][ $vm['pool'] ]['members'][] = (int) $vm['vmid'];
		}

		$this->save();
	}

	/**
	 * Sets or clears the lock of a VM (a running backup holds `backup`).
	 *
	 * @param int    $vmid VMID.
	 * @param string $lock Lock name, '' to clear.
	 */
	public function set_lock( int $vmid, string $lock ): void {
		$this->load();

		if ( isset( $this->state['vms'][ $vmid ] ) ) {
			$this->state['vms'][ $vmid ]['lock'] = $lock;
			$this->save();
		}
	}

	/**
	 * A running VM without disk hotplug: a detach (delete=scsiN) becomes a pending change and the
	 * drive stays in the running configuration (modelled).
	 *
	 * @param int  $vmid VM id.
	 * @param bool $hide Whether the default config view (pending values) hides the pending drive, as PVE does.
	 */
	public function hold_detach( int $vmid, bool $hide = true ): void {
		$this->load();
		$this->state['hold_detach'][ $vmid ] = $hide;
		$this->save();
	}

	/**
	 * A status that lags: after the next power task of this VM (start, stop, shutdown) has ended OK and changed
	 * the VM, the next $reads status reads (cluster resources and status/current) still show the old status, as
	 * a VM that is still shutting down or a start that is not listed yet does. Writes and the task itself use the
	 * real state (modelled).
	 *
	 * @param int $vmid  VM id.
	 * @param int $reads How many reads show the old status.
	 */
	public function lag_status( int $vmid, int $reads ): void {
		$this->load();
		$this->state['lag_next'][ $vmid ] = max( 0, $reads );
		$this->save();
	}

	/**
	 * The status a read shows: the old one while a lag is left, which uses up one read.
	 *
	 * @param array<string, mixed> $vm VM as stored.
	 */
	private function shown_status( array $vm ): string {
		$id = (int) $vm['vmid'];

		if ( ( $this->state['lag'][ $id ]['reads'] ?? 0 ) > 0 ) {
			--$this->state['lag'][ $id ]['reads'];

			return (string) $this->state['lag'][ $id ]['shows'];
		}

		return (string) $vm['status'];
	}

	/**
	 * Applies a pending detach to the running configuration without an unusedN entry (modelled: the
	 * drive is gone from the VM and the volume stays on the storage, linked to nothing).
	 *
	 * @param int    $vmid VM id.
	 * @param string $key  Drive key.
	 */
	public function drop_running_drive( int $vmid, string $key ): void {
		$this->load();
		unset( $this->state['vms'][ $vmid ]['config'][ $key ], $this->state['pending'][ $vmid ][ $key ] );
		$this->save();
	}

	/**
	 * Seeds a backup in a storage: vmid, ctime, size, notes, config (the VM config it restores), volid
	 * (default storage:backup/vm/<vmid>/<iso8601 of ctime>).
	 *
	 * @param string               $storage Storage id.
	 * @param array<string, mixed> $backup  Backup.
	 */
	public function add_backup( string $storage, array $backup ): void {
		$this->load();
		$this->state['backups'][ $storage ][] = $this->backup_entry( $storage, $backup );
		$this->save();
	}

	/**
	 * A backup record as the content listing shows it, with the modelled config snapshot.
	 *
	 * @param string               $storage Storage id.
	 * @param array<string, mixed> $backup  vmid, ctime, size, notes, config, volid.
	 * @return array<string, mixed>
	 */
	private function backup_entry( string $storage, array $backup ): array {
		$vmid  = (int) $backup['vmid'];
		$ctime = (int) ( $backup['ctime'] ?? time() );

		return array(
			'volid'   => (string) ( $backup['volid'] ?? $storage . ':backup/vm/' . $vmid . '/' . gmdate( 'Y-m-d\TH:i:s\Z', $ctime ) ),
			'vmid'    => $vmid,
			'ctime'   => $ctime,
			'size'    => (int) ( $backup['size'] ?? 1073741824 ),
			'notes'   => (string) ( $backup['notes'] ?? '' ),
			'content' => 'backup',
			'format'  => 'pbs-vm',
			'config'  => (array) ( $backup['config'] ?? array() ),
		);
	}

	/**
	 * Sets what GET .../rrddata returns for a VM: a list of samples, each an array such as
	 * time and cpu (the keys are not documented; tests state their own).
	 *
	 * @param int                              $vmid    VMID.
	 * @param array<int, array<string, mixed>> $samples Samples.
	 */
	public function set_rrd( int $vmid, array $samples ): void {
		$this->load();

		if ( isset( $this->state['vms'][ $vmid ] ) ) {
			$this->state['vms'][ $vmid ]['rrd'] = $samples;
			$this->save();
		}
	}

	/** Seed the observed get-fsinfo wrapper for a fake running VM. */
	public function set_fsinfo( int $vmid, array $reply ): void {
		$this->load();
		if ( isset( $this->state['vms'][ $vmid ] ) ) {
			$this->state['vms'][ $vmid ]['fsinfo'] = $reply;
			$this->save();
		}
	}

	/**
	 * A VM as stored, or null.
	 *
	 * @param int $vmid VMID.
	 * @return array<string, mixed>|null
	 */
	public function vm( int $vmid ): ?array {
		$this->load();
		return $this->state['vms'][ $vmid ] ?? null;
	}

	/**
	 * A file written into a VM through the guest agent, or null.
	 *
	 * @param int    $vmid VMID.
	 * @param string $path Path in the guest.
	 */
	public function guest_file( int $vmid, string $path ): ?string {
		$this->load();

		return $this->state['vms'][ $vmid ]['guest_files'][ $path ] ?? null;
	}

	/**
	 * The whole state (secrets are never in it).
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$this->load();
		return $this->state;
	}

	/**
	 * Every call so far, with cipassword and password values replaced by ***, and sshkeys too.
	 *
	 * @return array<int, array{method: string, path: string, params: array<string, mixed>}>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * Makes the next call matching the method and the path pattern fail. Consumed once, in the
	 * order added. An array is an error spec: code (default http_500), message, status (default 500).
	 * It applies after the parameters were validated.
	 *
	 * @param string          $method     Method.
	 * @param string          $path_regex Regex with delimiters, matched against the path.
	 * @param \WP_Error|array $result     Error.
	 */
	public function fail_next( string $method, string $path_regex, $result ): void {
		$this->failures[] = array( strtoupper( $method ), $path_regex, $result );
	}

	/**
	 * Scripts the next task created: polls (status running that many times, default 0),
	 * exitstatus (default OK) and effect (apply the change; default: only when OK).
	 *
	 * @param array<string, mixed> $outcome Outcome.
	 */
	public function next_task( array $outcome ): void {
		$this->outcomes[] = $outcome;
	}

	// Client.

	/**
	 * One call.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 * @return array|string|int|float|bool|null|\WP_Error
	 */
	public function request( string $method, string $path, array $params = array() ) {
		$method = strtoupper( $method );
		$this->record( $method, $path, $params );
		$this->load();

		$matched = null;

		foreach ( self::routes() as $route ) {
			if ( $route[0] === $method && 1 === preg_match( $route[1], $path, $m ) ) {
				$matched = array( $route, $m );
				break;
			}
		}

		if ( null === $matched ) {
			return $this->error( 501, 'Method not implemented' );
		}

		$checked = $this->validate( $matched[0][3], $params );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		foreach ( $this->failures as $i => $failure ) {
			if ( $failure[0] === $method && 1 === preg_match( $failure[1], $path ) ) {
				array_splice( $this->failures, $i, 1 );
				return $this->scripted_error( $failure[2] );
			}
		}

		$result = $this->{$matched[0][2]}( array_slice( $matched[1], 1 ), $checked );
		$this->save();

		return $result;
	}

	/**
	 * No sleeping: a poll counts as 2 seconds of the timeout.
	 *
	 * @param int $seconds Seconds.
	 */
	protected function pause( int $seconds ): void {
	}

	// Routes: method, path regex, handler, schema (name => [type, required, options]).

	/**
	 * The endpoints of the plan's table.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: array<string, array>}>
	 */
	private static function routes(): array {
		$node = '#^/nodes/([A-Za-z0-9.\-]+)/qemu/(\d+)';
		$none = array();
		$cfg  = array(
			'cores'      => array( 'int', false, array( 'min' => 1 ) ),
			'memory'     => array( 'int', false, array( 'min' => 16 ) ),
			'ipconfig0'  => array( 'string' ),
			'ipconfig1'  => array( 'string' ),
			'nameserver' => array( 'string' ),
			'ciuser'     => array( 'string' ),
			'cipassword' => array( 'string' ),
			'sshkeys'    => array( 'string' ),
			'cicustom'   => array( 'string' ),
			'net0'       => array( 'string' ),
			'net1'       => array( 'string' ),
			'ide2'       => array( 'string' ),
			'description' => array( 'string', false, array( 'max' => 8192 ) ),
			'digest'     => array( 'string', false, array( 'max' => 40 ) ),
			'delete'     => array( 'string' ),
			'boot'       => array( 'string' ),
		);
		foreach ( range( 0, 30 ) as $number ) {
			$cfg[ 'scsi' . $number ] = array( 'string' );
		}

		return array(
			array( 'GET', '#^/cluster/resources$#', 'h_resources', array( 'type' => array( 'enum', false, array( 'values' => array( 'vm', 'storage', 'node', 'sdn' ) ) ) ) ),
			array( 'GET', '#^/cluster/nextid$#', 'h_nextid', array( 'vmid' => array( 'int', false, array( 'min' => 100 ) ) ) ),
			array( 'GET', '#^/nodes$#', 'h_nodes', $none ),
			array(
				'GET',
				$node . '/config$#',
				'h_config_get',
				array(
					'current'  => array( 'bool' ),
					'snapshot' => array( 'string' ),
				),
			),
			array( 'PUT', $node . '/config$#', 'h_config_put', $cfg ),
			array( 'POST', $node . '/config$#', 'h_config_post', $cfg ),
			array(
				'POST',
				$node . '/clone$#',
				'h_clone',
				array(
					'newid'       => array( 'int', true, array( 'min' => 100 ) ),
					'name'        => array( 'dnsname' ),
					'full'        => array( 'bool' ),
					'storage'     => array( 'string' ),
					'description' => array( 'string' ),
					'pool'        => array( 'string' ),
					'target'      => array( 'string' ),
				),
			),
			array(
				'PUT',
				$node . '/resize$#',
				'h_resize',
				array(
					'disk' => array( 'string', true ),
					'size' => array( 'size', true ),
				),
			),
			array( 'POST', $node . '/status/(start)$#', 'h_power', $none ),
			array( 'POST', $node . '/status/(stop)$#', 'h_power', $none ),
			array(
				'POST',
				$node . '/status/(shutdown)$#',
				'h_power',
				array(
					'timeout'   => array( 'int', false, array( 'min' => 0 ) ),
					'forceStop' => array( 'bool' ),
				),
			),
			array( 'POST', $node . '/status/(reboot)$#', 'h_power', array( 'timeout' => array( 'int', false, array( 'min' => 0 ) ) ) ),
			array( 'GET', $node . '/status/current$#', 'h_status', $none ),
			array( 'GET', $node . '/agent/get-fsinfo$#', 'h_fsinfo', $none ),
			array(
				'DELETE',
				$node . '$#',
				'h_delete',
				array(
					'purge'                      => array( 'bool' ),
					'destroy-unreferenced-disks' => array( 'bool' ),
				),
			),
			array( 'GET', $node . '/firewall/rules$#', 'h_rules_get', $none ),
			array(
				'POST',
				$node . '/firewall/rules$#',
				'h_rules_add',
				array(
					'action'  => array( 'string', true ),
					'type'    => array( 'enum', true, array( 'values' => array( 'in', 'out', 'group' ) ) ),
					'proto'   => array( 'string' ),
					'dport'   => array( 'string' ),
					'iface'   => array( 'string' ),
					'enable'  => array( 'bool' ),
					'comment' => array( 'string' ),
				),
			),
			array( 'PUT', $node . '/firewall/options$#', 'h_fw_options', array( 'enable' => array( 'bool' ) ) ),
			array( 'POST', $node . '/agent/ping$#', 'h_agent_ping', $none ),
			array(
				'POST',
				$node . '/agent/set-user-password$#',
				'h_agent_password',
				array(
					'username' => array( 'string', true ),
					'password' => array( 'string', true ),
					'crypted'  => array( 'bool' ),
				),
			),
			array(
				'POST',
				$node . '/agent/file-write$#',
				'h_agent_file_write',
				array(
					'file'    => array( 'string', true ),
					'content' => array( 'string', true ),
					'encode'  => array( 'bool' ),
				),
			),
			array(
				'GET',
				$node . '/rrddata$#',
				'h_rrd',
				array(
					'timeframe' => array( 'enum', true, array( 'values' => array( 'hour', 'day', 'week', 'month', 'year' ) ) ),
					'cf'        => array( 'enum', false, array( 'values' => array( 'AVERAGE', 'MAX' ) ) ),
				),
			),
			array(
				'POST',
				'#^/nodes/([A-Za-z0-9.\-]+)/vzdump$#',
				'h_vzdump',
				array(
					'vmid'           => array( 'int', true, array( 'min' => 100 ) ),
					'storage'        => array( 'string', true ),
					'mode'           => array( 'enum', false, array( 'values' => array( 'snapshot', 'suspend', 'stop' ) ) ),
					'notes-template' => array( 'string' ),
				),
			),
			array(
				'GET',
				'#^/nodes/([A-Za-z0-9.\-]+)/storage/([A-Za-z0-9._\-]+)/content$#',
				'h_content',
				array(
					'content' => array( 'enum', false, array( 'values' => array( 'backup', 'images' ) ) ),
					'vmid'    => array( 'int', false, array( 'min' => 100 ) ),
				),
			),
			array(
				'POST',
				'#^/nodes/([A-Za-z0-9.\-]+)/storage/([A-Za-z0-9._\-]+)/content$#',
				'h_content_create',
				array(
					'filename' => array( 'string', true ),
					'size'     => array( 'size', true ),
					'vmid'     => array( 'int', true, array( 'min' => 100 ) ),
					'format'   => array( 'enum', false, array( 'values' => array( 'raw' ) ) ),
				),
			),
			array( 'GET', '#^/nodes/([A-Za-z0-9.\-]+)/storage/([A-Za-z0-9._\-]+)/status$#', 'h_storage_status', $none ),
			array( 'DELETE', '#^/nodes/([A-Za-z0-9.\-]+)/storage/([A-Za-z0-9._\-]+)/content/(.+)$#', 'h_content_delete', $none ),
			array(
				'DELETE',
				'#^/nodes/([A-Za-z0-9.\-]+)/storage/([A-Za-z0-9._\-]+)/prunebackups$#',
				'h_prune',
				array(
					'vmid'         => array( 'int', false, array( 'min' => 100 ) ),
					'type'         => array( 'enum', false, array( 'values' => array( 'qemu', 'lxc' ) ) ),
					'prune-backups' => array( 'string', true ),
				),
			),
			array(
				'POST',
				'#^/nodes/([A-Za-z0-9.\-]+)/qemu$#',
				'h_restore',
				array(
					'vmid'    => array( 'int', true, array( 'min' => 100 ) ),
					'archive' => array( 'string', true ),
					'force'   => array( 'bool' ),
					'storage' => array( 'string' ),
				),
			),
			array( 'GET', '#^/nodes/([A-Za-z0-9.\-]+)/tasks/([^/]+)/status$#', 'h_task_status', $none ),
			array( 'GET', '#^/pools$#', 'h_pools_get', array( 'poolid' => array( 'string' ) ) ),
			array( 'GET', '#^/pools/([A-Za-z0-9._\-]+)$#', 'h_pools_get_id', $none ),
			array(
				'PUT',
				'#^/pools$#',
				'h_pools',
				array(
					'poolid'     => array( 'string', true ),
					'vms'        => array( 'string' ),
					'delete'     => array( 'bool' ),
					'allow-move' => array( 'bool' ),
				),
			),
			array(
				'PUT',
				'#^/pools/([A-Za-z0-9._\-]+)$#',
				'h_pools_id',
				array(
					'vms'        => array( 'string' ),
					'delete'     => array( 'bool' ),
					'allow-move' => array( 'bool' ),
				),
			),
		);
	}

	// Validation.

	/**
	 * Checks parameters against a schema (HTTP 400 like the API) and normalizes them.
	 *
	 * @param array<string, array> $schema Schema.
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate( array $schema, array $params ) {
		$out = array();

		foreach ( $params as $name => $value ) {
			if ( null === $value ) {
				continue;
			}

			if ( ! isset( $schema[ $name ] ) ) {
				return $this->error( 400, 'Parameter verification failed: ' . $name . ': property is not defined in schema and the schema does not allow additional properties' );
			}

			if ( ! is_scalar( $value ) ) {
				return $this->error( 400, 'Parameter verification failed: ' . $name . ': type check failed' );
			}

			$type = $schema[ $name ][0];
			$opts = $schema[ $name ][2] ?? array();

			switch ( $type ) {
				case 'int':
					if ( ! is_numeric( $value ) || (int) $value != $value || (int) $value < ( $opts['min'] ?? PHP_INT_MIN ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- numeric strings.
						return $this->error( 400, 'Parameter verification failed: ' . $name . ': value does not have a value in the range' );
					}
					$value = (int) $value;
					break;
				case 'bool':
					if ( ! in_array( $value, array( 0, 1, '0', '1', true, false ), true ) ) {
						return $this->error( 400, 'Parameter verification failed: ' . $name . ': type check failed' );
					}
					$value = (int) $value;
					break;
				case 'enum':
					if ( ! in_array( (string) $value, $opts['values'], true ) ) {
						return $this->error( 400, 'Parameter verification failed: ' . $name . ': value is not in the enumeration' );
					}
					$value = (string) $value;
					break;
				case 'dnsname':
					if ( 1 !== preg_match( '/^[A-Za-z0-9]([A-Za-z0-9\-.]*[A-Za-z0-9])?$/', (string) $value ) ) {
						return $this->error( 400, 'Parameter verification failed: ' . $name . ': value does not look like a valid DNS name' );
					}
					$value = (string) $value;
					break;
				case 'size':
					if ( 1 !== preg_match( '/^\+?\d+(\.\d+)?[KMGT]?$/', (string) $value ) ) {
						return $this->error( 400, 'Parameter verification failed: ' . $name . ': value does not match the format' );
					}
					$value = (string) $value;
					break;
				default:
					$value = (string) $value;
					if ( isset( $opts['max'] ) && strlen( $value ) > $opts['max'] ) {
						return $this->error( 400, 'Parameter verification failed: ' . $name . ': value may only be ' . $opts['max'] . ' characters long' );
					}
			}

			$out[ $name ] = $value;
		}

		foreach ( $schema as $name => $spec ) {
			if ( ! empty( $spec[1] ) && ! array_key_exists( $name, $out ) ) {
				return $this->error( 400, 'Parameter verification failed: ' . $name . ': property is missing and it is not optional' );
			}
		}

		return $out;
	}

	// Handlers. $a is the path captures, $p the checked parameters.

	/**
	 * GET /cluster/resources?type=vm.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_resources( array $a, array $p ) {
		unset( $a );

		if ( isset( $p['type'] ) && 'vm' !== $p['type'] ) {
			return array(); // Modelled: only VMs are kept.
		}

		$out = array();

		foreach ( $this->state['vms'] as $vm ) {
			$out[] = array(
				'id'       => 'qemu/' . $vm['vmid'],
				'type'     => 'qemu',
				'vmid'     => $vm['vmid'],
				'node'     => $vm['node'],
				'name'     => $vm['name'],
				'status'   => $this->shown_status( $vm ),
				'template' => (int) $vm['template'],
				'pool'     => $vm['pool'],
				'lock'     => $vm['lock'],
				'maxmem'   => (int) ( $vm['config']['memory'] ?? 512 ) * 1048576,
				'mem'      => 'running' === $vm['status'] ? (int) ( $vm['config']['memory'] ?? 512 ) * 524288 : 0,
				'maxcpu'   => (int) ( $vm['config']['cores'] ?? 1 ),
				'cpu'      => (float) ( $vm['cpu'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * GET /cluster/nextid[?vmid=].
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_nextid( array $a, array $p ) {
		unset( $a );

		if ( isset( $p['vmid'] ) ) {
			// API viewer: "Pass a VMID to assert that it's free." The HTTP status of the error is modelled.
			return isset( $this->state['vms'][ $p['vmid'] ] ) ? $this->error( 400, 'VM ' . $p['vmid'] . ' already exists' ) : $p['vmid'];
		}

		$id = 100;

		while ( isset( $this->state['vms'][ $id ] ) ) {
			++$id;
		}

		return $id;
	}

	/**
	 * GET /nodes.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_nodes( array $a, array $p ) {
		unset( $a, $p );
		return array_values( $this->state['nodes'] );
	}

	/**
	 * GET config.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_config_get( array $a, array $p ) {
		$vm = $this->find( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		$config = $vm['config'];
		// The default view merges pending changes (modelled): a pending detach hides the drive unless the listing is hold_detach( ..., false ).
		if ( empty( $p['current'] ) && ! empty( $this->state['hold_detach'][ (int) $a[1] ] ) ) {
			foreach ( array_keys( $this->state['pending'][ (int) $a[1] ] ?? array() ) as $key ) {
				unset( $config[ $key ] );
			}
		}

		return array_merge( $config, array( 'digest' => $this->digest( $vm ) ) );
	}

	/**
	 * PUT config (synchronous).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_config_put( array $a, array $p ) {
		$error = $this->apply_config( $a, $p );
		return is_wp_error( $error ) ? $error : null;
	}

	/**
	 * POST config (asynchronous variant, returns a task).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_config_post( array $a, array $p ) {
		$vm = $this->find_writable( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		return $this->new_task( $a[0], 'qmconfig', $a[1], function () use ( $a, $p ) {
			$this->apply_config( $a, $p );
		} );
	}

	/**
	 * Applies a config change: digest guard, delete list, values (never the passwords).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 * @return true|\WP_Error
	 */
	private function apply_config( array $a, array $p ) {
		$vm = $this->find_writable( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		if ( isset( $p['digest'] ) && $p['digest'] !== $this->digest( $vm ) ) {
			return $this->error( 500, 'detected modified configuration - file changed by other user? Try again.' );
		}

		$id = (int) $a[1];

		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $p['delete'] ?? '' ) ) ) ) as $key ) {
			if ( isset( $this->state['hold_detach'][ $id ] ) && 1 === preg_match( '/^scsi\d+$/', $key ) && isset( $this->state['vms'][ $id ]['config'][ $key ] ) ) {
				// Hot detach without disk hotplug (modelled): a pending change, the drive stays in the running configuration.
				$this->state['pending'][ $id ][ $key ] = true;
				continue;
			}
			if ( 1 === preg_match( '/^scsi(?:[0-9]|[12][0-9]|30)$/', $key ) && isset( $this->state['vms'][ $id ]['config'][ $key ] ) ) {
				$unused = 0;
				while ( isset( $this->state['vms'][ $id ]['config'][ 'unused' . $unused ] ) ) {
					++$unused;
				}
				$this->state['vms'][ $id ]['config'][ 'unused' . $unused ] = explode( ',', (string) $this->state['vms'][ $id ]['config'][ $key ] )[0];
			}
			if ( 1 === preg_match( '/^unused\d+$/', $key ) && isset( $this->state['vms'][ $id ]['config'][ $key ] ) ) {
				$volid = (string) $this->state['vms'][ $id ]['config'][ $key ];
				foreach ( $this->state['images'] as $storage => $images ) {
					unset( $this->state['images'][ $storage ][ $volid ] );
				}
			}
			unset( $this->state['vms'][ $id ]['config'][ $key ] );
		}

		foreach ( $p as $key => $value ) {
			if ( in_array( $key, array( 'digest', 'delete' ), true ) || in_array( $key, self::SENSITIVE, true ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^scsi(?:[0-9]|[12][0-9]|30)$/', $key ) && is_string( $value ) && false !== strpos( $value, 'import-from=' ) ) {
				// Importing a template volume into a free slot allocates a new volume (modelled on PVE 9.1.6).
				$value = $this->import_drive( $id, $key, $value );

				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
			if ( 1 === preg_match( '/^scsi(?:[0-9]|[12][0-9]|30)$/', $key ) && is_string( $value ) && false === strpos( $value, 'size=' ) ) {
				$volid = explode( ',', $value )[0];
				foreach ( $this->state['images'] as $images ) {
					if ( isset( $images[ $volid ] ) ) {
						$value .= ',size=' . (int) ( $images[ $volid ]['size'] / 1073741824 ) . 'G';
						break;
					}
				}
			}

			if ( 1 === preg_match( '/^scsi(?:[0-9]|[12][0-9]|30)$/', $key ) && is_string( $value ) ) {
				// Attaching a volume that sits in an unusedN entry takes it out of that entry (modelled).
				foreach ( $this->state['vms'][ $id ]['config'] as $other => $held ) {
					if ( 1 === preg_match( '/^unused\d+$/', (string) $other ) && $held === explode( ',', $value )[0] ) {
						unset( $this->state['vms'][ $id ]['config'][ $other ] );
					}
				}
			}

			$this->state['vms'][ $id ]['config'][ $key ] = $value;
		}

		return true;
	}

	/**
	 * `<storage>:0,import-from=<volid>` into a free slot: a new volume vm-<vmid>-disk-<n>.raw (the first free n)
	 * in the storage, the size of the source; the config value is the new volume with the other options.
	 * The slot must be empty (the old drive was detached and its unused volume deleted first).
	 *
	 * @param int    $id    VMID.
	 * @param string $slot  Drive key.
	 * @param string $value Drive string.
	 * @return string|\WP_Error The config value.
	 */
	private function import_drive( int $id, string $slot, string $value ) {
		$parts  = explode( ',', $value );
		$target = array_shift( $parts );
		$source = '';
		$rest   = array();

		foreach ( $parts as $part ) {
			if ( 0 === strpos( $part, 'import-from=' ) ) {
				$source = substr( $part, 12 );
			} else {
				$rest[] = $part;
			}
		}

		if ( 1 !== preg_match( '/^([A-Za-z0-9._\-]+):0$/', $target, $m ) ) {
			return $this->error( 400, 'Parameter verification failed: ' . $slot . ': invalid format' );
		}

		if ( isset( $this->state['vms'][ $id ]['config'][ $slot ] ) ) {
			return $this->error( 500, "drive '" . $slot . "' already exists" );
		}

		$gb = null;

		foreach ( $this->state['images'] as $images ) {
			if ( isset( $images[ $source ] ) ) {
				$gb = (int) ( $images[ $source ]['size'] / 1073741824 );
			}
		}

		foreach ( $this->state['vms'] as $vm ) {
			foreach ( $vm['config'] as $drive ) {
				if ( is_string( $drive ) && explode( ',', $drive )[0] === $source && 1 === preg_match( '/(?:^|,)size=(\d+)G/', $drive, $size ) ) {
					$gb = (int) $size[1];
				}
			}
		}

		if ( null === $gb ) {
			return $this->error( 500, 'unable to find the volume ' . $source . ' to import' );
		}

		for ( $n = 0;; $n++ ) {
			$volid = $m[1] . ':' . $id . '/vm-' . $id . '-disk-' . $n . '.raw';
			$taken = isset( $this->state['images'][ $m[1] ][ $volid ] );

			foreach ( $this->state['vms'][ $id ]['config'] as $drive ) {
				$taken = $taken || ( is_string( $drive ) && explode( ',', $drive )[0] === $volid );
			}

			if ( ! $taken ) {
				break;
			}
		}

		$this->state['images'][ $m[1] ][ $volid ] = array(
			'volid'   => $volid,
			'vmid'    => $id,
			'format'  => 'raw',
			'size'    => $gb * 1073741824,
			'content' => 'images',
		);

		return implode( ',', array_merge( array( $volid ), $rest, array( 'size=' . $gb . 'G' ) ) );
	}

	/**
	 * POST clone: the description is set at clone time (clone parameter).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_clone( array $a, array $p ) {
		$source = $this->find( $a );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( isset( $this->state['vms'][ $p['newid'] ] ) ) {
			return $this->error( 500, 'unable to create VM ' . $p['newid'] . ' - VM ' . $p['newid'] . ' already exists on node' );
		}

		if ( isset( $p['pool'] ) && ! isset( $this->state['pools'][ $p['pool'] ] ) ) {
			return $this->error( 500, "pool '" . $p['pool'] . "' does not exist" );
		}

		$node   = $p['target'] ?? $a[0];
		$config = $source['config'];
		unset( $config['description'], $config['digest'], $config['template'] );

		if ( isset( $p['description'] ) ) {
			$config['description'] = $p['description'];
		}

		return $this->new_task(
			$a[0],
			'qmclone',
			$a[1],
			function () use ( $p, $node, $config, $source ) {
				$id = (int) $p['newid'];
				$this->state['vms'][ $id ] = array(
					'vmid'             => $id,
					'node'             => $node,
					'name'             => $p['name'] ?? 'Copy-of-VM-' . $source['name'],
					'status'           => 'stopped',
					'template'         => 0,
					'pool'             => $p['pool'] ?? '',
					'lock'             => '',
					'config'           => $config,
					'firewall_rules'   => array(),
					'firewall_options' => array(),
				);

				if ( isset( $p['pool'] ) ) {
					$this->state['pools'][ $p['pool'] ]['members'][] = $id;
				}
			}
		);
	}

	/**
	 * PUT resize: absolute size, no shrinking ("Shrinking disk size is not supported").
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_resize( array $a, array $p ) {
		$vm = $this->find_writable( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		$disk = $vm['config'][ $p['disk'] ] ?? null;

		if ( ! is_string( $disk ) || 1 !== preg_match( '/size=(\d+(?:\.\d+)?)([KMGT]?)/', $disk, $m ) ) {
			return $this->error( 500, "disk '" . $p['disk'] . "' does not exist" );
		}

		$unit = array(
			''  => 1,
			'K' => 1024,
			'M' => 1048576,
			'G' => 1073741824,
			'T' => 1099511627776,
		);

		if ( 0 === strpos( $p['size'], '+' ) ) {
			return $this->error( 500, 'Modelled: this fake takes absolute sizes only.' );
		}

		preg_match( '/^(\d+(?:\.\d+)?)([KMGT]?)$/', $p['size'], $n );
		$old = (float) $m[1] * $unit[ $m[2] ];
		$new = (float) $n[1] * $unit[ $n[2] ];

		if ( $new < $old ) {
			return $this->error( 500, 'shrinking disks is not supported' );
		}

		return $this->new_task(
			$a[0],
			'qmresize',
			$a[1],
			function () use ( $a, $p, $disk ) {
				$this->state['vms'][ (int) $a[1] ]['config'][ $p['disk'] ] = preg_replace( '/size=[0-9.]+[KMGT]?/', 'size=' . $p['size'], $disk );
				$volid = explode( ',', $disk )[0];
				foreach ( $this->state['images'] as $storage => $images ) {
					if ( isset( $images[ $volid ] ) ) {
						$this->state['images'][ $storage ][ $volid ]['size'] = (int) $p['size'] * 1073741824;
					}
				}
			}
		);
	}

	/**
	 * POST status/start|stop|shutdown|reboot. Wrong-state errors are modelled.
	 *
	 * @param array<int, string>   $a Captures (node, vmid, action).
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_power( array $a, array $p ) {
		unset( $p );
		$vm = $this->find_writable( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		$action  = $a[2];
		$running = 'running' === $vm['status'];

		if ( 'start' === $action && ( $running || $vm['template'] ) ) {
			return $this->error( 500, $running ? 'VM is already running' : 'you can\'t start a vm if it\'s a template' );
		}

		if ( 'start' !== $action && ! $running ) {
			return $this->error( 500, 'VM is not running' );
		}

		return $this->new_task(
			$a[0],
			'qm' . $action,
			$a[1],
			function () use ( $a, $action ) {
				$id = (int) $a[1];

				if ( isset( $this->state['lag_next'][ $id ] ) && in_array( $action, array( 'start', 'stop', 'shutdown' ), true ) ) {
					$this->state['lag'][ $id ] = array(
						'reads' => (int) $this->state['lag_next'][ $id ],
						'shows' => $this->state['vms'][ $id ]['status'],
					);
					unset( $this->state['lag_next'][ $id ] );
				}

				if ( 'start' === $action ) {
					$this->state['vms'][ (int) $a[1] ]['status'] = 'running';
				} elseif ( in_array( $action, array( 'stop', 'shutdown' ), true ) ) {
					$this->state['vms'][ (int) $a[1] ]['status'] = 'stopped';
				}
			}
		);
	}

	/**
	 * GET status/current.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_status( array $a, array $p ) {
		unset( $p );
		$vm = $this->find( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		$shown   = $this->shown_status( $vm );
		$running = 'running' === $vm['status'];

		return array(
			'vmid'      => $vm['vmid'],
			'name'      => $vm['name'],
			'status'    => $shown,
			'qmpstatus' => $shown,
			'lock'      => $vm['lock'],
			'cpu'       => 0,
			'mem'       => $running ? (int) ( $vm['config']['memory'] ?? 512 ) * 524288 : 0,
			'maxmem'    => (int) ( $vm['config']['memory'] ?? 512 ) * 1048576,
			'uptime'    => $running ? 100 : 0,
		);
	}

	/**
	 * DELETE the VM (a running VM is refused: modelled).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_delete( array $a, array $p ) {
		unset( $p );
		$vm = $this->find_writable( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		if ( 'running' === $vm['status'] ) {
			return $this->error( 500, 'VM is running - destroy failed' );
		}

		return $this->new_task(
			$a[0],
			'qmdestroy',
			$a[1],
			function () use ( $a ) {
				$id = (int) $a[1];

				foreach ( $this->state['pools'] as $poolid => $pool ) {
					$this->state['pools'][ $poolid ]['members'] = array_values( array_diff( $pool['members'], array( $id ) ) );
				}

				unset( $this->state['vms'][ $id ] );
			}
		);
	}

	/**
	 * GET firewall/rules.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_rules_get( array $a, array $p ) {
		unset( $p );
		$vm = $this->find( $a );

		return is_wp_error( $vm ) ? $vm : $vm['firewall_rules'];
	}

	/**
	 * POST firewall/rules.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_rules_add( array $a, array $p ) {
		$vm = $this->find( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		$id           = (int) $a[1];
		$p['pos']     = count( $vm['firewall_rules'] );
		$this->state['vms'][ $id ]['firewall_rules'][] = $p;

		return null;
	}

	/**
	 * PUT firewall/options.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_fw_options( array $a, array $p ) {
		$vm = $this->find( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		$this->state['vms'][ (int) $a[1] ]['firewall_options'] = array_merge( $vm['firewall_options'], $p );

		return null;
	}

	/**
	 * POST agent/ping (a stopped VM has no agent: modelled).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_agent_ping( array $a, array $p ) {
		unset( $p );
		$vm = $this->find( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		return 'running' === $vm['status'] ? array( 'result' => null ) : $this->error( 500, 'VM ' . $a[1] . ' is not running' );
	}

	/**
	 * POST agent/set-user-password (the password is dropped, never stored).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_agent_password( array $a, array $p ) {
		unset( $p );

		return $this->h_agent_ping( $a, array() );
	}

	/**
	 * POST agent/file-write: replaces the file (a stopped VM has no agent: modelled). Content is
	 * kept as the guest would hold it (decoded when `encode` is false, as sent otherwise).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_agent_file_write( array $a, array $p ) {
		$vm = $this->find( $a );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		if ( 'running' !== $vm['status'] ) {
			return $this->error( 500, 'VM ' . $a[1] . ' is not running' );
		}

		$content = (string) $p['content'];

		if ( isset( $p['encode'] ) && 0 === (int) $p['encode'] ) {
			$content = (string) base64_decode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		$this->state['vms'][ (int) $a[1] ]['guest_files'][ (string) $p['file'] ] = $content;

		return null;
	}

	/**
	 * GET rrddata (the samples set with set_rrd(), else an empty list: keys are not documented).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_rrd( array $a, array $p ) {
		unset( $p );
		$vm = $this->find( $a );

		return is_wp_error( $vm ) ? $vm : (array) ( $vm['rrd'] ?? array() );
	}

	/** The guest-agent reply is explicitly seeded; otherwise the model says unavailable. */
	private function h_fsinfo( array $a, array $p ) {
		unset( $p );
		$vm = $this->find( $a );
		if ( is_wp_error( $vm ) ) {
			return $vm;
		}
		if ( 'running' !== (string) ( $vm['status'] ?? '' ) || ! isset( $vm['fsinfo'] ) ) {
			return $this->error( 500, 'QEMU guest agent is not running' );
		}
		return $vm['fsinfo'];
	}

	/**
	 * POST vzdump: stores a backup (with a snapshot of the VM config, modelled) in the storage.
	 *
	 * @param array<int, string>   $a Captures (node).
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_vzdump( array $a, array $p ) {
		$vm = $this->find( array( $a[0], $p['vmid'] ) );

		if ( is_wp_error( $vm ) ) {
			return $vm;
		}

		return $this->new_task(
			$a[0],
			'vzdump',
			(string) $p['vmid'],
			function () use ( $p, $vm ) {
				$this->state['backups'][ $p['storage'] ][] = $this->backup_entry(
					$p['storage'],
					array(
						'vmid'   => $p['vmid'],
						'ctime'  => $this->free_ctime( $p['storage'], (int) $p['vmid'] ),
						'notes'  => str_replace( '{{guestname}}', (string) $vm['name'], (string) ( $p['notes-template'] ?? '' ) ),
						'config' => $vm['config'],
					)
				);
			}
		);
	}

	/**
	 * A ctime now, moved on by seconds until no backup of the VM in the storage has it (volids hold the time).
	 *
	 * @param string $storage Storage.
	 * @param int    $vmid    VMID.
	 */
	private function free_ctime( string $storage, int $vmid ): int {
		$taken = array();

		foreach ( $this->state['backups'][ $storage ] ?? array() as $backup ) {
			if ( (int) $backup['vmid'] === $vmid ) {
				$taken[ (int) $backup['ctime'] ] = true;
			}
		}

		$ctime = time();

		while ( isset( $taken[ $ctime ] ) ) {
			++$ctime;
		}

		return $ctime;
	}

	/**
	 * GET storage content. Backup config snapshots are not part of the listing.
	 *
	 * @param array<int, string>   $a Captures (node, storage).
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_content( array $a, array $p ) {
		$out = array();

		if ( ! isset( $p['content'] ) || 'images' === $p['content'] ) {
			foreach ( $this->state['images'][ $a[1] ] ?? array() as $image ) {
				if ( ! isset( $p['vmid'] ) || (int) $image['vmid'] === $p['vmid'] ) {
					$out[] = $image;
				}
			}
		}
		if ( 'images' === ( $p['content'] ?? '' ) ) {
			return $out;
		}
		foreach ( $this->state['backups'][ $a[1] ] ?? array() as $backup ) {
			if ( isset( $p['vmid'] ) && (int) $backup['vmid'] !== $p['vmid'] ) {
				continue;
			}

			unset( $backup['config'] );
			$out[] = $backup;
		}

		return $out;
	}

	/** Allocate a named raw image on directory storage (modelled). */
	private function h_content_create( array $a, array $p ) {
		$vmid = (int) $p['vmid'];
		$name = (string) $p['filename'];
		if ( ! isset( $this->state['nodes'][ $a[0] ] ) || ! isset( $this->state['vms'][ $vmid ] ) ) {
			return $this->error( 500, 'node or VM not found' );
		}
		if ( 1 !== preg_match( '/^vm-' . $vmid . '-[A-Za-z0-9._-]+\.raw$/', $name ) ) {
			return $this->error( 400, 'illegal image name' );
		}
		$volid = $a[1] . ':' . $vmid . '/' . $name;
		if ( isset( $this->state['images'][ $a[1] ][ $volid ] ) ) {
			return $this->error( 500, 'volume already exists' );
		}
		if ( 1 !== preg_match( '/^(\d+)G$/', (string) $p['size'], $size ) ) {
			return $this->error( 400, 'size must be in GB' );
		}
		$this->state['images'][ $a[1] ][ $volid ] = array(
			'volid' => $volid,
			'vmid' => $vmid,
			'format' => 'raw',
			'size' => (int) $size[1] * 1073741824,
			'content' => 'images',
		);
		return $volid;
	}

	/** Storage status for capacity checks (modelled). */
	private function h_storage_status( array $a, array $p ) {
		unset( $p );
		if ( ! isset( $this->state['nodes'][ $a[0] ] ) ) {
			return $this->error( 500, 'node not found' );
		}
		$total = 4 * 1099511627776;
		$used  = 0;
		foreach ( $this->state['images'][ $a[1] ] ?? array() as $image ) {
			$used += (int) $image['size'];
		}
		return array( 'total' => $total, 'used' => $used, 'avail' => $total - $used, 'active' => 1 );
	}

	/**
	 * DELETE storage content/{volume}: the volume id arrives URL-encoded.
	 *
	 * @param array<int, string>   $a Captures (node, storage, volume).
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_content_delete( array $a, array $p ) {
		unset( $p );
		$volid = rawurldecode( $a[2] );

		if ( false === strpos( $volid, ':' ) ) {
			$volid = $a[1] . ':' . $volid;
		}

		foreach ( $this->state['backups'][ $a[1] ] ?? array() as $backup ) {
			if ( $backup['volid'] === $volid ) {
				return $this->new_task(
					$a[0],
					'imgdel',
					(string) $backup['vmid'],
					function () use ( $a, $volid ) {
						$this->drop_backups( $a[1], static fn( $b ) => $b['volid'] === $volid );
					}
				);
			}
		}

		if ( isset( $this->state['images'][ $a[1] ][ $volid ] ) ) {
			// Live (PVE 9.1.6, narrow token): deleting a disk volume through the storage is forbidden, for any volume (it needs Datastore.Allocate).
			return $this->error( 403, 'Permission check failed (/storage/' . $a[1] . ', Datastore.Allocate)' );
		}

		return $this->error( 500, "volume '" . $volid . "' does not exist" );
	}

	/**
	 * DELETE prunebackups: keeps the newest backup of each of the last N days that have one (modelled
	 * on keep-daily; no other keep option is understood).
	 *
	 * @param array<int, string>   $a Captures (node, storage).
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_prune( array $a, array $p ) {
		if ( 1 !== preg_match( '/^keep-daily=(\d+)$/', $p['prune-backups'], $m ) || (int) $m[1] < 1 ) {
			return $this->error( 400, 'Parameter verification failed: prune-backups: value does not match the format' );
		}

		$keep = (int) $m[1];
		$list = array_values(
			array_filter(
				$this->state['backups'][ $a[1] ] ?? array(),
				static fn( $b ) => ! isset( $p['vmid'] ) || (int) $b['vmid'] === $p['vmid']
			)
		);

		usort( $list, static fn( $x, $y ) => $y['ctime'] <=> $x['ctime'] );

		$kept_days = array();
		$remove    = array();

		foreach ( $list as $backup ) {
			$day = $backup['vmid'] . ' ' . gmdate( 'Y-m-d', (int) $backup['ctime'] );

			if ( isset( $kept_days[ $day ] ) ) {
				$remove[] = $backup['volid'];
				continue;
			}

			$days_of_vm = count( array_filter( array_keys( $kept_days ), static fn( $k ) => 0 === strpos( $k, $backup['vmid'] . ' ' ) ) );

			if ( $days_of_vm >= $keep ) {
				$remove[] = $backup['volid'];
				continue;
			}

			$kept_days[ $day ] = true;
		}

		return $this->new_task(
			$a[0],
			'prunebackups',
			isset( $p['vmid'] ) ? (string) $p['vmid'] : $a[1],
			function () use ( $a, $remove ) {
				$this->drop_backups( $a[1], static fn( $b ) => in_array( $b['volid'], $remove, true ) );
			}
		);
	}

	/**
	 * Removes the backups of a storage that a callback selects.
	 *
	 * @param string   $storage Storage.
	 * @param callable $match   Gets a backup record, true to remove it.
	 */
	private function drop_backups( string $storage, callable $match ): void {
		$this->state['backups'][ $storage ] = array_values( array_filter( $this->state['backups'][ $storage ] ?? array(), static fn( $b ) => ! $match( $b ) ) );
	}

	/**
	 * POST qemu with archive: restores a backup over an existing VM (force) or creates the VM. A
	 * running VM is refused, and so is force without a VM (as the plan states; modelled: a real
	 * qmrestore with force and no VM probably just restores). The config comes back from the snapshot
	 * taken at backup time; node, name and the lock stay, and so does the pool unless
	 * drop_pool_on_restore() is set (modelled assumptions: the real behaviour is unchecked).
	 *
	 * @param array<int, string>   $a Captures (node).
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_restore( array $a, array $p ) {
		$exists = isset( $this->state['vms'][ $p['vmid'] ] );
		$force  = ! empty( $p['force'] );
		$found  = null;

		foreach ( $this->state['backups'] as $backups ) {
			foreach ( $backups as $backup ) {
				if ( $backup['volid'] === $p['archive'] ) {
					$found = $backup;
				}
			}
		}

		if ( null === $found ) {
			return $this->error( 500, "volume '" . $p['archive'] . "' does not exist" );
		}

		if ( $exists && '' !== (string) $this->state['vms'][ $p['vmid'] ]['lock'] ) {
			return $this->error( 500, 'VM is locked (' . $this->state['vms'][ $p['vmid'] ]['lock'] . ')' );
		}

		if ( $exists && 'running' === $this->state['vms'][ $p['vmid'] ]['status'] ) {
			return $this->error( 500, 'VM is running - destroy failed' );
		}

		if ( $exists && ! $force ) {
			return $this->error( 500, 'unable to restore VM ' . $p['vmid'] . " - config file already exists (use 'force')" );
		}

		if ( ! $exists && $force ) {
			return $this->error( 500, 'unable to restore VM ' . $p['vmid'] . ' - VM does not exist, force is not allowed' );
		}

		return $this->new_task(
			$a[0],
			'qmrestore',
			(string) $p['vmid'],
			function () use ( $a, $p, $found, $exists ) {
				$id = (int) $p['vmid'];

				if ( $exists ) {
					$this->state['vms'][ $id ]['config'] = $this->restored_config( $id, $this->state['vms'][ $id ]['config'], $found['config'] );

					if ( ! empty( $this->state['restore_drops_pool'] ) ) {
						$pool = (string) $this->state['vms'][ $id ]['pool'];

						if ( '' !== $pool ) {
							$this->state['pools'][ $pool ]['members'] = array_values( array_diff( $this->state['pools'][ $pool ]['members'], array( $id ) ) );
						}

						$this->state['vms'][ $id ]['pool'] = '';
					}

					return;
				}

				$this->state['vms'][ $id ] = array(
					'vmid'             => $id,
					'node'             => $a[0],
					'name'             => 'vm' . $id,
					'status'           => 'stopped',
					'template'         => 0,
					'pool'             => '',
					'lock'             => '',
					'config'           => $found['config'],
					'firewall_rules'   => array(),
					'firewall_options' => array(),
				);
			}
		);
	}

	/**
	 * The configuration after a forced restore over an existing VM, as observed live (PVE 9.1.6): the
	 * boot disk comes back from the backup; a disk with backup=0 is not in the backup and its volume
	 * survives as an unusedN entry. A volume of the VM that no configuration entry referred to (never
	 * attached) shows up as unusedN too, first, then what the old configuration held as unusedN, then
	 * the attached backup=0 drives in slot order.
	 *
	 * @param int                  $id  VMID.
	 * @param array<string, mixed> $old Configuration before the restore.
	 * @param array<string, mixed> $new Configuration in the backup.
	 * @return array<string, mixed>
	 */
	private function restored_config( int $id, array $old, array $new ): array {
		$referenced = array();
		$extra      = array();

		foreach ( $old as $key => $value ) {
			if ( 1 === preg_match( '/^(?:scsi\d+|virtio\d+|sata\d+|ide\d+|efidisk0|tpmstate0|unused\d+)$/', (string) $key ) && is_string( $value ) ) {
				$referenced[ explode( ',', $value )[0] ] = true;
			}
		}

		// A drive with backup=0 is not part of the backup: its entry is not restored.
		foreach ( $new as $key => $value ) {
			if ( 1 === preg_match( '/^scsi\d+$/', (string) $key ) && is_string( $value ) && false !== strpos( ',' . $value . ',', ',backup=0,' ) ) {
				unset( $new[ $key ] );
			}
		}
		foreach ( $new as $key => $value ) {
			if ( 1 === preg_match( '/^(?:scsi\d+|unused\d+)$/', (string) $key ) && is_string( $value ) ) {
				$referenced[ explode( ',', $value )[0] ] = true;
			}
		}

		foreach ( $this->state['images'] as $images ) {
			foreach ( $images as $volid => $image ) {
				if ( (int) $image['vmid'] === $id && ! isset( $referenced[ $volid ] ) ) {
					$extra[] = $volid;
				}
			}
		}
		foreach ( $old as $key => $value ) {
			if ( 1 === preg_match( '/^unused\d+$/', (string) $key ) ) {
				$extra[] = (string) $value;
			}
		}

		$slots = array_filter(
			array_keys( $old ),
			static fn( $key ) => 1 === preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/', (string) $key ) && is_string( $old[ $key ] ) && false !== strpos( ',' . $old[ $key ] . ',', ',backup=0,' )
		);
		natsort( $slots );
		foreach ( $slots as $key ) {
			$extra[] = explode( ',', (string) $old[ $key ] )[0];
		}

		$n = 0;
		foreach ( array_unique( $extra ) as $volid ) {
			$new[ 'unused' . $n++ ] = $volid;
		}

		return $new;
	}

	/**
	 * GET tasks/{upid}/status: running for the scripted number of polls, then stopped.
	 *
	 * @param array<int, string>   $a Captures (node, encoded upid).
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_task_status( array $a, array $p ) {
		unset( $p );
		$upid = rawurldecode( $a[1] );
		$task = $this->state['tasks'][ $upid ] ?? null;

		if ( null === $task || $task['node'] !== $a[0] ) {
			return $this->error( 500, 'no such task' );
		}

		$base = array(
			'upid' => $upid,
			'node' => $task['node'],
			'type' => $task['type'],
		);

		if ( $task['polls'] > 0 ) {
			--$this->state['tasks'][ $upid ]['polls'];
			return $base + array( 'status' => 'running' );
		}

		return $base + array(
			'status'     => 'stopped',
			'exitstatus' => $task['exitstatus'],
		);
	}

	/**
	 * GET /pools?poolid=: a list holding the pool with its members (modelled on the PVE 8.1+ form).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_pools_get( array $a, array $p ) {
		unset( $a );
		$out = array();

		foreach ( $this->state['pools'] as $poolid => $pool ) {
			if ( ! isset( $p['poolid'] ) || $p['poolid'] === $poolid ) {
				$out[] = array(
					'poolid'  => $poolid,
					'members' => $this->pool_members( $pool ),
				);
			}
		}

		return isset( $p['poolid'] ) && array() === $out ? $this->error( 500, "pool '" . $p['poolid'] . "' does not exist" ) : $out;
	}

	/**
	 * GET /pools/{poolid}: the pool with its members.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_pools_get_id( array $a, array $p ) {
		unset( $p );

		if ( ! isset( $this->state['pools'][ $a[0] ] ) ) {
			return $this->error( 500, "pool '" . $a[0] . "' does not exist" );
		}

		return array( 'members' => $this->pool_members( $this->state['pools'][ $a[0] ] ) );
	}

	/**
	 * Pool members as the API lists them.
	 *
	 * @param array<string, mixed> $pool Pool.
	 * @return array<int, array<string, mixed>>
	 */
	private function pool_members( array $pool ): array {
		$out = array();

		foreach ( $pool['members'] as $vmid ) {
			$out[] = array(
				'vmid' => (int) $vmid,
				'type' => 'qemu',
			);
		}

		return $out;
	}

	/**
	 * Makes restores drop the VM from its pool (an assumption the real behaviour is unknown for).
	 *
	 * @param bool $drop Whether a restore removes the VM from its pool.
	 */
	public function drop_pool_on_restore( bool $drop ): void {
		$this->load();
		$this->state['restore_drops_pool'] = $drop;
		$this->save();
	}

	/**
	 * PUT /pools (poolid, vms; a VM in another pool is not moved without allow-move).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_pools( array $a, array $p ) {
		unset( $a );
		$poolid = $p['poolid'];
		unset( $p['poolid'] );

		return $this->pool_change( $poolid, $p );
	}

	/**
	 * PUT /pools/{poolid} (the deprecated path form).
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_pools_id( array $a, array $p ) {
		return $this->pool_change( $a[0], $p );
	}

	/**
	 * Adds or removes pool members.
	 *
	 * @param string               $poolid Pool.
	 * @param array<string, mixed> $p      vms, delete, allow-move.
	 */
	private function pool_change( string $poolid, array $p ) {
		if ( ! isset( $this->state['pools'][ $poolid ] ) ) {
			return $this->error( 500, "pool '" . $poolid . "' does not exist" );
		}

		$ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) ( $p['vms'] ?? '' ) ) ), 'strlen' ) );

		foreach ( $ids as $id ) {
			if ( ! isset( $this->state['vms'][ $id ] ) ) {
				return $this->error( 500, 'VM ' . $id . ' does not exist' );
			}

			$current = (string) $this->state['vms'][ $id ]['pool'];

			if ( empty( $p['delete'] ) && '' !== $current && $current !== $poolid && empty( $p['allow-move'] ) ) {
				return $this->error( 500, 'VM ' . $id . ' is already in pool ' . $current );
			}
		}

		foreach ( $ids as $id ) {
			$current = (string) $this->state['vms'][ $id ]['pool'];

			if ( '' !== $current ) {
				$this->state['pools'][ $current ]['members'] = array_values( array_diff( $this->state['pools'][ $current ]['members'], array( $id ) ) );
			}

			if ( empty( $p['delete'] ) ) {
				$this->state['pools'][ $poolid ]['members'][] = $id;
				$this->state['vms'][ $id ]['pool']            = $poolid;
			} else {
				$this->state['vms'][ $id ]['pool'] = '';
			}
		}

		return null;
	}

	// Helpers.

	/**
	 * Creates a task, applies its effect now (or not, by the scripted outcome) and returns the UPID.
	 *
	 * @param string   $node   Node.
	 * @param string   $type   Task type.
	 * @param string   $id     Object id.
	 * @param callable $effect Applies the change.
	 */
	private function new_task( string $node, string $type, string $id, callable $effect ): string {
		$outcome = array_merge(
			array(
				'polls'      => 0,
				'exitstatus' => 'OK',
			),
			(array) array_shift( $this->outcomes )
		);

		++$this->state['seq'];
		$seq  = $this->state['seq'];
		$upid = sprintf( 'UPID:%s:%08X:%08X:%08X:%s:%s:fake@pve!fake:', $node, 1, $seq, 0x5F000000 + $seq, $type, $id );

		$this->state['tasks'][ $upid ] = array(
			'upid'       => $upid,
			'node'       => $node,
			'type'       => $type,
			'polls'      => (int) $outcome['polls'],
			'exitstatus' => (string) $outcome['exitstatus'],
		);

		if ( $outcome['effect'] ?? 'OK' === $outcome['exitstatus'] ) {
			$effect();
		}

		return $upid;
	}

	/**
	 * Finds the VM of a path (node, vmid captures). A missing VM is HTTP 500, as the API
	 * answers for a missing config file (the status is not documented: modelled).
	 *
	 * @param array<int, string> $a Captures.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function find( array $a ) {
		$vm = $this->state['vms'][ (int) $a[1] ] ?? null;

		if ( null === $vm || $vm['node'] !== $a[0] ) {
			return $this->error( 500, "Configuration file 'nodes/" . $a[0] . '/qemu-server/' . (int) $a[1] . ".conf' does not exist" );
		}

		return $vm;
	}

	/**
	 * Finds the VM of a path for a change: a VM with a lock (a running backup holds `lock: backup`)
	 * refuses it with HTTP 500, as qm does ("VM is locked (backup)"; modelled).
	 *
	 * @param array<int, string> $a Captures.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function find_writable( array $a ) {
		$vm = $this->find( $a );

		if ( ! is_wp_error( $vm ) && '' !== (string) $vm['lock'] ) {
			return $this->error( 500, 'VM is locked (' . $vm['lock'] . ')' );
		}

		return $vm;
	}

	/**
	 * Config digest: SHA-1 (40 hex, like the documented digest length) of the config.
	 *
	 * @param array<string, mixed> $vm VM.
	 */
	private function digest( array $vm ): string {
		return sha1( (string) wp_json_encode( $vm['config'] ) );
	}

	/**
	 * An HTTP error.
	 *
	 * @param int    $status  Status.
	 * @param string $message Message.
	 */
	private function error( int $status, string $message ): \WP_Error {
		return new \WP_Error( 'http_' . $status, $message, array( 'status' => $status ) );
	}

	/**
	 * Turns a scripted result into a WP_Error.
	 *
	 * @param \WP_Error|array $result Scripted.
	 */
	private function scripted_error( $result ): \WP_Error {
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$status = (int) ( $result['status'] ?? 500 );

		return new \WP_Error(
			(string) ( $result['code'] ?? 'http_' . $status ),
			(string) ( $result['message'] ?? 'Scripted failure' ),
			array( 'status' => $status )
		);
	}

	/**
	 * Records a call with the secrets replaced.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 */
	private function record( string $method, string $path, array $params ): void {
		foreach ( array_merge( self::SENSITIVE, array( 'sshkeys' ) ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$params[ $key ] = '***';
			}
		}

		$this->calls[] = array(
			'method' => $method,
			'path'   => $path,
			'params' => $params,
		);
	}

	/**
	 * Removes secret keys from a VM's config.
	 *
	 * @param array<string, mixed> $vm VM.
	 * @return array<string, mixed>
	 */
	private function strip( array $vm ): array {
		foreach ( self::SENSITIVE as $key ) {
			unset( $vm['config'][ $key ] );
		}

		return $vm;
	}

	/**
	 * Reloads the persisted state.
	 */
	private function load(): void {
		if ( ! $this->persist ) {
			return;
		}

		$stored = get_option( self::OPTION, null );

		if ( is_array( $stored ) ) {
			$this->state = array_merge( self::blank(), $stored );
		}
	}

	/**
	 * Saves the state when persisting (autoload off).
	 */
	private function save(): void {
		if ( $this->persist ) {
			update_option( self::OPTION, $this->state, false );
		}
	}
}
