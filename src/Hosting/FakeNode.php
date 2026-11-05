<?php
/**
 * An in-memory hosting node (Proxmox VE with containers) for tests and staging.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Proxmox\Client;
use BeltoftServices\Proxmox\Tasks;

defined( 'ABSPATH' ) || exit;

/**
 * Models the Proxmox VE API calls the hosting driver makes on a node, the LXC ones (API viewer:
 * https://pve.proxmox.com/pve-docs/api-viewer/index.html#/nodes/{node}/lxc), with the documented parameter
 * names. Unknown or missing parameters are refused with HTTP 400, as the API's JSON schema does. Behaviour
 * beyond what the docs state is a modelling convenience, marked "modelled", and is no proof of what a real node
 * answers. This is a new file: the virtual machine fake (Proxmox\FakeClient) is not touched and knows no
 * containers.
 *
 * Seeding: add_node(), add_pool(), add_template(), add_ct(). Scripting: fail_next(), next_task(). Inspection:
 * calls(), state().
 */
class FakeNode implements Client {

	use Tasks;

	public const OPTION = 'beltoft_services_fake_hosting_node';

	/**
	 * Persist in the option (staging) instead of in memory.
	 *
	 * @var bool
	 */
	private $persist;

	/**
	 * Node state: nodes, cts, pools, tasks, seq.
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
	 * Keys the container config accepts (the documented ones the driver uses; modelled as a closed list).
	 */
	private const CONFIG_KEYS = array( 'cores', 'cpulimit', 'cpuunits', 'memory', 'swap', 'net0', 'mp0', 'onboot', 'tags', 'hostname', 'description', 'delete', 'digest' );

	/**
	 * Keys the container firewall options accept.
	 */
	private const FIREWALL_KEYS = array( 'enable', 'ipfilter', 'macfilter', 'policy_in', 'policy_out', 'log_level_in', 'log_level_out', 'dhcp', 'ndp', 'radv', 'digest' );

	/**
	 * @param bool $persist Keep the state in the option beltoft_services_fake_hosting_node (autoload off).
	 */
	public function __construct( bool $persist = false ) {
		$this->persist = $persist;
		$this->state   = self::blank();
		$this->load();
	}

	/**
	 * Empty node.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank(): array {
		return array(
			'nodes' => array(),
			'cts'   => array(),
			'pools' => array(),
			'tasks' => array(),
			'seq'   => 0,
		);
	}

	// Seeding and inspection.

	/**
	 * Adds a Proxmox node name.
	 *
	 * @param string $name Node name.
	 */
	public function add_node( string $name ): void {
		$this->load();
		$this->state['nodes'][ $name ] = array(
			'node'   => $name,
			'status' => 'online',
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
	 * Adds a container template (a stopped container with the template flag).
	 *
	 * @param int                  $vmid   Container id.
	 * @param string               $node   Node name.
	 * @param array<string, mixed> $config Config overrides.
	 */
	public function add_template( int $vmid, string $node, array $config = array() ): void {
		$this->add_ct(
			array(
				'vmid'     => $vmid,
				'node'     => $node,
				'name'     => 'template-' . $vmid,
				'template' => 1,
				'config'   => $config,
			)
		);
	}

	/**
	 * Adds a container: vmid, node, name, status, template, pool, config.
	 *
	 * @param array<string, mixed> $ct Container.
	 */
	public function add_ct( array $ct ): void {
		$this->load();
		$ct = array_merge(
			array(
				'name'             => 'ct' . $ct['vmid'],
				'status'           => 'stopped',
				'template'         => 0,
				'pool'             => '',
				'config'           => array(),
				'firewall_options' => array(),
			),
			$ct
		);

		$ct['config'] = array_merge(
			array(
				'rootfs' => 'fake-store:subvol-' . $ct['vmid'] . '-disk-0,size=4G',
				'memory' => 512,
				'cores'  => 1,
				'swap'   => 0,
			),
			$ct['config']
		);

		$this->state['cts'][ (int) $ct['vmid'] ] = $ct;

		if ( '' !== (string) $ct['pool'] ) {
			$this->state['pools'][ $ct['pool'] ]['members'][] = (int) $ct['vmid'];
		}

		$this->save();
	}

	/**
	 * Makes the next call matching the method and the path pattern fail. Consumed once. An array is an error
	 * spec: code (default http_500), message, status (default 500). It applies after the parameters were
	 * validated.
	 *
	 * @param string          $method     Method.
	 * @param string          $path_regex Regex with delimiters, matched against the path.
	 * @param \WP_Error|array $result     Error.
	 */
	public function fail_next( string $method, string $path_regex, $result ): void {
		$this->failures[] = array( strtoupper( $method ), $path_regex, $result );
	}

	/**
	 * Scripts the next task created: polls (status running that many times, default 0), exitstatus (default OK)
	 * and effect (apply the change; default: only when OK).
	 *
	 * @param array<string, mixed> $outcome Outcome.
	 */
	public function next_task( array $outcome ): void {
		$this->outcomes[] = $outcome;
	}

	/**
	 * Sets what GET .../status/current reports besides the state: cpu (0 to 1), mem, maxmem, disk, maxdisk (bytes).
	 *
	 * @param int                  $vmid  Container id.
	 * @param array<string, mixed> $usage Values.
	 */
	public function set_usage( int $vmid, array $usage ): void {
		$this->load();

		if ( isset( $this->state['cts'][ $vmid ] ) ) {
			$this->state['cts'][ $vmid ]['usage'] = $usage;
			$this->save();
		}
	}

	/**
	 * Every call made so far.
	 *
	 * @return array<int, array{method: string, path: string, params: array<string, mixed>}>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * The calls whose path matches a regex, optionally of one method.
	 *
	 * @param string $path_regex Regex with delimiters.
	 * @param string $method     Method, or '' for any.
	 * @return array<int, array{method: string, path: string, params: array<string, mixed>}>
	 */
	public function calls_to( string $path_regex, string $method = '' ): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn( $c ) => 1 === preg_match( $path_regex, $c['path'] ) && ( '' === $method || strtoupper( $method ) === $c['method'] )
			)
		);
	}

	/**
	 * A copy of the node state.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$this->load();

		return $this->state;
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
		$method        = strtoupper( $method );
		$this->calls[] = array(
			'method' => $method,
			'path'   => $path,
			'params' => $params,
		);
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

	/**
	 * The routes: method, path regex, handler, schema (name => [type, required]).
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: array<string, array>}>
	 */
	private static function routes(): array {
		$ct = '#^/nodes/([A-Za-z0-9.\-]+)/lxc/(\d+)';

		return array(
			array( 'GET', '#^/cluster/nextid$#', 'h_nextid', array( 'vmid' => array( 'int', false ) ) ),
			array( 'GET', '#^/cluster/resources$#', 'h_resources', array( 'type' => array( 'string', false ) ) ),
			array( 'GET', '#^/nodes/([A-Za-z0-9.\-]+)/tasks/([^/]+)/status$#', 'h_task_status', array() ),
			array( 'POST', $ct . '/clone$#', 'h_clone', array(
				'newid'       => array( 'int', true ),
				'hostname'    => array( 'string', false ),
				'pool'        => array( 'string', false ),
				'description' => array( 'string', false ),
				'full'        => array( 'bool', false ),
			) ),
			array( 'GET', $ct . '/config$#', 'h_config_get', array() ),
			array( 'PUT', $ct . '/config$#', 'h_config_put', array_fill_keys( self::CONFIG_KEYS, array( 'string', false ) ) ),
			array( 'PUT', $ct . '/resize$#', 'h_resize', array(
				'disk' => array( 'string', true ),
				'size' => array( 'string', true ),
			) ),
			array( 'GET', $ct . '/status/current$#', 'h_status', array() ),
			array( 'POST', $ct . '/status/start$#', 'h_start', array() ),
			array( 'POST', $ct . '/status/shutdown$#', 'h_shutdown', array( 'timeout' => array( 'int', false ) ) ),
			array( 'POST', $ct . '/status/stop$#', 'h_stop', array() ),
			array( 'GET', $ct . '/firewall/options$#', 'h_fw_get', array() ),
			array( 'PUT', $ct . '/firewall/options$#', 'h_fw_put', array_fill_keys( self::FIREWALL_KEYS, array( 'string', false ) ) ),
			array( 'DELETE', $ct . '$#', 'h_delete', array(
				'purge'                      => array( 'bool', false ),
				'destroy-unreferenced-disks' => array( 'bool', false ),
			) ),
		);
	}

	// Handlers.

	/**
	 * GET /cluster/nextid[?vmid=].
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_nextid( array $a, array $p ) {
		unset( $a );

		if ( isset( $p['vmid'] ) ) {
			return isset( $this->state['cts'][ $p['vmid'] ] ) ? $this->error( 400, 'VM ' . $p['vmid'] . ' already exists' ) : $p['vmid'];
		}

		$id = 100;

		while ( isset( $this->state['cts'][ $id ] ) ) {
			++$id;
		}

		return $id;
	}

	/**
	 * GET /cluster/resources?type=vm: containers (type lxc) with their node and status.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_resources( array $a, array $p ) {
		unset( $a );

		if ( isset( $p['type'] ) && 'vm' !== $p['type'] ) {
			return array();
		}

		$list = array();

		foreach ( $this->state['cts'] as $ct ) {
			$list[] = array(
				'id'       => 'lxc/' . $ct['vmid'],
				'type'     => 'lxc',
				'vmid'     => (int) $ct['vmid'],
				'node'     => $ct['node'],
				'name'     => $ct['name'],
				'status'   => $ct['status'],
				'template' => (int) $ct['template'],
				'pool'     => $ct['pool'],
			);
		}

		return $list;
	}

	/**
	 * GET /nodes/{node}/tasks/{upid}/status.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_task_status( array $a, array $p ) {
		unset( $p );
		$upid = rawurldecode( $a[1] );
		$task = $this->state['tasks'][ $upid ] ?? null;

		if ( null === $task ) {
			return $this->error( 500, 'no such task' );
		}

		if ( $task['polls'] > 0 ) {
			--$this->state['tasks'][ $upid ]['polls'];

			return array(
				'status' => 'running',
				'upid'   => $upid,
				'node'   => $task['node'],
			);
		}

		return array(
			'status'     => 'stopped',
			'exitstatus' => $task['exitstatus'],
			'upid'       => $upid,
			'node'       => $task['node'],
		);
	}

	/**
	 * POST .../lxc/{vmid}/clone. A linked clone (full=0) needs a template as its source.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_clone( array $a, array $p ) {
		$src = $this->find( $a );

		if ( is_wp_error( $src ) ) {
			return $src;
		}

		if ( isset( $this->state['cts'][ $p['newid'] ] ) ) {
			return $this->error( 400, 'VM ' . $p['newid'] . ' already exists' );
		}

		if ( isset( $p['full'] ) && ! $p['full'] && ! $src['template'] ) {
			return $this->error( 500, 'clone feature is not available' ); // Modelled: a linked clone needs a template.
		}

		$pool = (string) ( $p['pool'] ?? '' );

		if ( '' !== $pool && ! isset( $this->state['pools'][ $pool ] ) ) {
			return $this->error( 500, "pool '" . $pool . "' does not exist" );
		}

		$id = (int) $p['newid'];

		return $this->new_task(
			$a[0],
			'vzclone',
			(string) $id,
			function () use ( $src, $id, $p, $pool, $a ) {
				$config = $src['config'];
				unset( $config['hostname'], $config['description'] );

				if ( isset( $p['description'] ) ) {
					$config['description'] = $p['description'];
				}

				$name = (string) ( $p['hostname'] ?? 'CT' . $id );

				$config['hostname'] = $name;

				$this->state['cts'][ $id ] = array(
					'vmid'             => $id,
					'node'             => $a[0],
					'name'             => $name,
					'status'           => 'stopped',
					'template'         => 0,
					'pool'             => $pool,
					'config'           => $config,
					'firewall_options' => array(),
				);

				if ( '' !== $pool ) {
					$this->state['pools'][ $pool ]['members'][] = $id;
				}
			}
		);
	}

	/**
	 * GET .../lxc/{vmid}/config, with a digest.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_config_get( array $a, array $p ) {
		unset( $p );
		$ct = $this->find( $a );

		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		return array_merge( $ct['config'], array( 'digest' => self::digest( $ct['config'] ) ) );
	}

	/**
	 * PUT .../lxc/{vmid}/config: set the given keys, delete the ones listed in `delete`.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_config_put( array $a, array $p ) {
		$ct = $this->find( $a );

		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		if ( isset( $p['digest'] ) && self::digest( $ct['config'] ) !== $p['digest'] ) {
			return $this->error( 500, 'detected modified configuration - file changed by other user? Try again.' );
		}

		foreach ( array( 'net0' => 'bridge=', 'mp0' => 'mp=' ) as $key => $needle ) {
			if ( isset( $p[ $key ] ) && false === strpos( (string) $p[ $key ], $needle ) ) {
				return $this->error( 400, 'invalid format - ' . $key . ' needs ' . $needle, array( 'errors' => array( $key => 'invalid format' ) ) );
			}
		}

		unset( $p['digest'] );

		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $p['delete'] ?? '' ) ) ) ) as $key ) {
			unset( $this->state['cts'][ $ct['vmid'] ]['config'][ $key ] );
		}

		unset( $p['delete'] );

		foreach ( $p as $key => $value ) {
			$this->state['cts'][ $ct['vmid'] ]['config'][ $key ] = ctype_digit( (string) $value ) && 'description' !== $key && 'hostname' !== $key ? (int) $value : $value;
		}

		return null;
	}

	/**
	 * PUT .../lxc/{vmid}/resize: a task. `+NG` adds, `NG` sets; a smaller size is refused (the pct manual:
	 * "Shrinking disk size is not supported").
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_resize( array $a, array $p ) {
		$ct = $this->find( $a );

		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		if ( ! isset( $ct['config'][ $p['disk'] ] ) || 1 !== preg_match( '/size=(\d+)G/', (string) $ct['config'][ $p['disk'] ], $m ) || 1 !== preg_match( '/^(\+?)(\d+)G$/D', (string) $p['size'], $s ) ) {
			return $this->error( 500, 'unable to parse the disk or the size' );
		}

		$new = '+' === $s[1] ? (int) $m[1] + (int) $s[2] : (int) $s[2];

		if ( $new < (int) $m[1] ) {
			return $this->error( 500, 'shrinking disk size is not supported' );
		}

		$vmid = (int) $ct['vmid'];
		$disk = (string) $p['disk'];

		return $this->new_task(
			$a[0],
			'vzresize',
			(string) $vmid,
			function () use ( $vmid, $disk, $new ) {
				$cur = (string) $this->state['cts'][ $vmid ]['config'][ $disk ];
				$this->state['cts'][ $vmid ]['config'][ $disk ] = preg_replace( '/size=\d+G/', 'size=' . $new . 'G', $cur );
			}
		);
	}

	/**
	 * GET .../status/current.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_status( array $a, array $p ) {
		unset( $p );
		$ct = $this->find( $a );

		return is_wp_error( $ct ) ? $ct : array_merge(
			array(
				'vmid'   => (int) $ct['vmid'],
				'status' => $ct['status'],
				'name'   => $ct['name'],
			),
			(array) ( $ct['usage'] ?? array() )
		);
	}

	/**
	 * POST .../status/start: a task. A template cannot be started.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_start( array $a, array $p ) {
		unset( $p );

		return $this->power( $a, 'vzstart', 'running' );
	}

	/**
	 * POST .../status/shutdown: a task.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_shutdown( array $a, array $p ) {
		unset( $p );

		return $this->power( $a, 'vzshutdown', 'stopped' );
	}

	/**
	 * POST .../status/stop: a task.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_stop( array $a, array $p ) {
		unset( $p );

		return $this->power( $a, 'vzstop', 'stopped' );
	}

	/**
	 * A power task.
	 *
	 * @param array<int, string> $a      Captures.
	 * @param string             $type   Task type.
	 * @param string             $target Status after the task.
	 */
	private function power( array $a, string $type, string $target ) {
		$ct = $this->find( $a );

		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		if ( $ct['template'] ) {
			return $this->error( 500, 'unable to modify a template' );
		}

		$vmid = (int) $ct['vmid'];

		return $this->new_task(
			$a[0],
			$type,
			(string) $vmid,
			function () use ( $vmid, $target ) {
				$this->state['cts'][ $vmid ]['status'] = $target;
			}
		);
	}

	/**
	 * GET .../firewall/options.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_fw_get( array $a, array $p ) {
		unset( $p );
		$ct = $this->find( $a );

		return is_wp_error( $ct ) ? $ct : $ct['firewall_options'];
	}

	/**
	 * PUT .../firewall/options.
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_fw_put( array $a, array $p ) {
		$ct = $this->find( $a );

		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		unset( $p['digest'] );

		foreach ( $p as $key => $value ) {
			$this->state['cts'][ $ct['vmid'] ]['firewall_options'][ $key ] = ctype_digit( (string) $value ) ? (int) $value : $value;
		}

		return null;
	}

	/**
	 * DELETE .../lxc/{vmid}: a task. A running container is refused (modelled: "CT is running").
	 *
	 * @param array<int, string>   $a Captures.
	 * @param array<string, mixed> $p Parameters.
	 */
	private function h_delete( array $a, array $p ) {
		unset( $p );
		$ct = $this->find( $a );

		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		if ( 'running' === $ct['status'] ) {
			return $this->error( 500, 'CT is running - destroy failed' );
		}

		$vmid = (int) $ct['vmid'];

		return $this->new_task(
			$a[0],
			'vzdestroy',
			(string) $vmid,
			function () use ( $vmid ) {
				$pool = (string) $this->state['cts'][ $vmid ]['pool'];

				if ( '' !== $pool && isset( $this->state['pools'][ $pool ] ) ) {
					$this->state['pools'][ $pool ]['members'] = array_values( array_diff( $this->state['pools'][ $pool ]['members'], array( $vmid ) ) );
				}

				unset( $this->state['cts'][ $vmid ] );
			}
		);
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
	 * Finds the container of a path (node, vmid captures). A missing container is HTTP 500, as the API answers
	 * for a missing config file (the status is not documented: modelled).
	 *
	 * @param array<int, string> $a Captures.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function find( array $a ) {
		$ct = $this->state['cts'][ (int) $a[1] ] ?? null;

		if ( null === $ct || $ct['node'] !== $a[0] ) {
			return $this->error( 500, "Configuration file 'nodes/" . $a[0] . '/lxc/' . (int) $a[1] . ".conf' does not exist" );
		}

		return $ct;
	}

	/**
	 * The config digest.
	 *
	 * @param array<string, mixed> $config Config.
	 */
	private static function digest( array $config ): string {
		ksort( $config );

		return md5( (string) wp_json_encode( $config ) );
	}

	/**
	 * Checks parameters against a schema: unknown names, missing required ones and wrong types are HTTP 400.
	 *
	 * @param array<string, array> $schema Schema.
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>|\WP_Error The parameters, typed.
	 */
	private function validate( array $schema, array $params ) {
		$out = array();

		foreach ( $params as $name => $value ) {
			if ( ! isset( $schema[ $name ] ) ) {
				return $this->error( 400, 'Parameter verification failed. ' . $name . ': property is not defined in schema' );
			}

			$type = $schema[ $name ][0];

			if ( 'int' === $type ) {
				if ( ! is_numeric( $value ) || (string) (int) $value !== (string) $value ) {
					return $this->error( 400, 'Parameter verification failed. ' . $name . ': type check failed' );
				}

				$value = (int) $value;
			} elseif ( 'bool' === $type ) {
				if ( ! in_array( $value, array( 0, 1, '0', '1', true, false ), true ) ) {
					return $this->error( 400, 'Parameter verification failed. ' . $name . ': type check failed' );
				}

				$value = (bool) (int) $value;
			} elseif ( ! is_scalar( $value ) ) {
				return $this->error( 400, 'Parameter verification failed. ' . $name . ': type check failed' );
			} else {
				$value = (string) $value;
			}

			$out[ $name ] = $value;
		}

		foreach ( $schema as $name => $spec ) {
			if ( $spec[1] && ! array_key_exists( $name, $out ) ) {
				return $this->error( 400, 'Parameter verification failed. ' . $name . ': property is missing and it is not optional' );
			}
		}

		return $out;
	}

	/**
	 * A scripted failure.
	 *
	 * @param \WP_Error|array $spec Spec.
	 */
	private function scripted_error( $spec ): \WP_Error {
		if ( $spec instanceof \WP_Error ) {
			return $spec;
		}

		$status = (int) ( $spec['status'] ?? 500 );

		return new \WP_Error( (string) ( $spec['code'] ?? 'http_' . $status ), (string) ( $spec['message'] ?? 'scripted failure' ), array( 'status' => $status ) );
	}

	/**
	 * An API error: code http_<status> with the status in the data, like the live client.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $message Message.
	 * @param array<string, mixed> $extra   Extra data.
	 */
	private function error( int $status, string $message, array $extra = array() ): \WP_Error {
		return new \WP_Error( 'http_' . $status, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	/**
	 * Reads the persisted state.
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
	 * Writes the persisted state.
	 */
	private function save(): void {
		if ( $this->persist ) {
			update_option( self::OPTION, $this->state, false );
		}
	}
}
