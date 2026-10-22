<?php
/**
 * Provisioning a Proxmox VM, step by step.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs ProxmoxDriver::provision(). Every step is recorded in the resource meta (`steps`, and its
 * own values) right after it ran, and re-reads the remote state before it acts, so a crash, a
 * timeout or a retry resumes without doing anything twice.
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>):
 * /cluster/resources, /cluster/nextid, /nodes/{node}/qemu/{vmid}/clone, .../config (GET, PUT, POST),
 * .../resize, .../status/start, .../status/current, .../firewall/options, .../firewall/rules,
 * .../agent/ping, /nodes/{node}/tasks/{upid}/status (through Client::wait_task()).
 *
 * Waiting: a step waits at most `task_wait` seconds for one task, keeps its UPID in meta `task`
 * and asks to be run again. The reconciler counts that as one of its 5 attempts and waits at least
 * 60 seconds.
 *
 * Nothing here deletes a VM except the `wipe` step (a reinstall), and that goes through
 * Ownership::verify(). A failure never deletes anything.
 */
final class Provision {

	use Steps;

	/**
	 * Order of the steps. `wipe` only runs while meta `wipe` is set.
	 */
	private const STEPS = array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' );

	/**
	 * Fixed comment of the outgoing SMTP rule, how it is recognised on a retry.
	 */
	public const SMTP_COMMENT = 'beltoft-services: block outgoing SMTP';

	/**
	 * Cluster settings (token secret decrypted).
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * Cluster id.
	 *
	 * @var string
	 */
	private $cluster_id = '';

	/**
	 * The plan, typed.
	 *
	 * @var array<string, int|string>
	 */
	private $plan = array();

	/**
	 * Sleeps (a seam for tests).
	 *
	 * @var callable
	 */
	private $nap;

	/**
	 * Meta of the steps completed in this run, merged into the final result.
	 *
	 * @var array<string, mixed>
	 */
	private $out = array();

	/**
	 * @param Resource $r   Resource.
	 * @param callable $nap fn( int $seconds ): void.
	 */
	public function __construct( Resource $r, callable $nap ) {
		$this->r   = $r;
		$this->nap = $nap;
	}

	/**
	 * Runs what is left of the provision.
	 */
	public function run(): StepResult {
		$early = $this->preflight();

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$steps = $this->r->get( 'steps' );

		foreach ( self::STEPS as $name ) {
			if ( 'wipe' === $name ? ! $this->r->get( 'wipe' ) : in_array( $name, $steps, true ) ) {
				continue;
			}

			$meta = $this->{'step_' . $name}();

			if ( $meta instanceof StepResult ) {
				return $meta;
			}

			if ( 'wipe' !== $name ) {
				$steps[] = $name;
			}

			// The wipe step sets `steps` itself (what survives a reinstall).
			$stored = $this->r->set( 'wipe' === $name ? $meta : array_merge( $meta, array( 'steps' => $steps ) ) );

			if ( ! $stored ) {
				return StepResult::retry( 0, $name . ': the step result could not be stored' );
			}

			if ( 'wipe' === $name ) {
				$steps = $this->r->get( 'steps' );
			}

			$this->out = array_merge( $this->out, $meta );
		}

		return StepResult::done( array_merge( $this->out, array( 'steps' => $steps ) ) );
	}

	// -- Preflight -------------------------------------------------------------------------------

	/**
	 * Checks that do not depend on the remote state; they run on every call and are not recorded.
	 * Each failure is permanent.
	 */
	private function preflight(): ?StepResult {
		if ( ! Crypto::available() ) {
			return StepResult::fail( 'BELTOFT_SERVICES_KEY is not configured: a VM password cannot be stored safely' );
		}

		$plan   = (array) $this->r->get( 'plan' );
		$record = VmRecords::for_resource( $this->r->id() );

		// Once a VMID is recorded the cluster is the record's, whatever the plan says now.
		$this->cluster_id = $record ? $record['cluster'] : (string) ( $plan['cluster'] ?? '' );
		$cluster          = '' === $this->cluster_id ? null : Clusters::get( $this->cluster_id );

		if ( null === $cluster ) {
			return StepResult::fail( 'Unknown Proxmox cluster in the plan: "' . mb_substr( sanitize_text_field( $this->cluster_id ), 0, 40 ) . '"' );
		}

		$this->cluster = $cluster;
		$this->secrets = array( (string) $cluster['token_secret'] );

		foreach ( array( 'storage', 'cloudinit_storage', 'bridge', 'gateway' ) as $key ) {
			if ( '' === (string) $cluster[ $key ] ) {
				return StepResult::fail( 'The Proxmox cluster setting "' . $key . '" is empty' );
			}
		}

		if ( array() === (array) $cluster['nodes'] ) {
			return StepResult::fail( 'The Proxmox cluster has no nodes' );
		}

		$this->plan = array(
			'template'  => (int) ( $plan['template'] ?? 0 ),
			'cores'     => (int) ( $plan['cores'] ?? 0 ),
			'memory_mb' => (int) ( $plan['memory_mb'] ?? 0 ),
			'disk_gb'   => (int) ( $plan['disk_gb'] ?? 0 ),
		);

		if ( ! array_key_exists( $this->plan['template'], Clusters::templates( $this->cluster_id ) ) ) {
			return StepResult::fail( 'Template ' . $this->plan['template'] . ' is not in the template list of this cluster' );
		}

		foreach ( array( 'cores', 'memory_mb', 'disk_gb' ) as $key ) {
			if ( $this->plan[ $key ] <= 0 ) {
				return StepResult::fail( 'The plan value "' . $key . '" must be a positive number' );
			}
		}

		try {
			$this->client = Clients::for( $cluster );
		} catch ( \Throwable $e ) {
			unset( $e );

			return StepResult::fail( 'The Proxmox client could not be built for this cluster' );
		}

		$wait       = (int) Settings::get( 'task_wait' );
		$this->wait = $wait > 0 ? $wait : 25;

		return null;
	}

	// -- Steps -----------------------------------------------------------------------------------

	/**
	 * Step 0 (reinstall only): remove the old VM and keep its id and address.
	 *
	 * @return array<string, mixed>|StepResult Meta, or retry/fail.
	 */
	private function step_wipe() {
		$record = VmRecords::for_resource( $this->r->id() );

		for ( $round = 0; $round < 4; $round++ ) {
			if ( null === $record ) {
				return $this->wiped(); // Nothing was ever built.
			}

			$v = Ownership::verify( $this->client, $record, $this->r );

			if ( is_wp_error( $v ) && 'absent' === $v->get_error_code() ) {
				$free = Ownership::is_free( $this->client, $record['vmid'] );

				if ( true === $free ) {
					return $this->wiped();
				}

				return StepResult::retry( 0, 'wipe: the VM is not listed and its id is not confirmed free' );
			}

			if ( is_wp_error( $v ) ) {
				return $this->refuse_or_retry( $v, 'wipe' );
			}

			$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];

			if ( 'running' === $v['status'] ) {
				$phase = ( $this->finished && 'wipe' === $this->finished['step'] && 'shutdown' === $this->finished['phase'] ) ? 'stop' : 'shutdown';
				$call  = 'shutdown' === $phase
					? array( $base . '/status/shutdown', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ) )
					: array( $base . '/status/stop', array() );
				$done  = $this->run_task( 'wipe', 'POST', $call[0], $call[1], $phase );
			} else {
				$done = $this->run_task( 'wipe', 'DELETE', $base, array( 'purge' => 1 ), 'delete' );
			}

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$this->finished = array(
				'step'  => 'wipe',
				'phase' => 'running' === $v['status'] ? $phase : 'delete',
				'ok'    => true === $done,
				'exit'  => true === $done ? 'OK' : (string) $done['failed'],
			);
		}

		return StepResult::retry( 0, 'wipe: the VM did not reach the wanted state' );
	}

	/**
	 * The old VM is gone: forget what a rebuilt one needs afresh.
	 *
	 * @return array<string, mixed>
	 */
	private function wiped(): array {
		$keep = array_values( array_intersect( $this->r->get( 'steps' ), array( 'ip', 'vmid' ) ) );

		return array(
			'wipe'       => null,
			'task'       => null,
			'agent'      => null,
			'configured' => array(),
			'steps'      => $keep,
		);
	}

	/**
	 * Step 1: hold an address.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_ip() {
		try {
			$ip = IpPool::hold( $this->r->id() );
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 0, 'ip: the IP pool is busy' );
		}

		if ( null === $ip ) {
			return StepResult::fail( 'No free IP address' );
		}

		return array();
	}

	/**
	 * Step 2: a VMID that is free on the cluster and recorded for this resource.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_vmid() {
		$existing = VmRecords::for_resource( $this->r->id() );

		if ( null !== $existing ) {
			// Recorded by an earlier run whose result was not stored: nothing to choose again.
			return array(
				'vm' => array(
					'cluster' => $existing['cluster'],
					'vmid'    => $existing['vmid'],
				),
			);
		}

		$list = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'vmid' );
		}

		$taken  = array_map( static fn( $entry ) => (int) ( $entry['vmid'] ?? 0 ), $list );
		$client = $this->client;

		try {
			$vmid = VmRecords::allocate(
				$this->r->id(),
				$this->cluster_id,
				(int) $this->cluster['vmid_min'],
				(int) $this->cluster['vmid_max'],
				$taken,
				static function ( int $id ) use ( $client ): bool {
					$free = Ownership::is_free( $client, $id );

					if ( is_wp_error( $free ) ) {
						throw new \RuntimeException( 'The VMID could not be confirmed free.' );
					}

					return $free;
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 0, 'vmid: the allocation is busy or the VMID could not be confirmed free' );
		}

		if ( null === $vmid ) {
			return StepResult::fail( 'No free VMID in the configured range' );
		}

		return array(
			'vm' => array(
				'cluster' => $this->cluster_id,
				'vmid'    => $vmid,
			),
		);
	}

	/**
	 * Step 3: clone the template, unless a clone of ours is already there.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_clone() {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'clone: no VMID is recorded for this service' );
		}

		$vmid = $record['vmid'];
		$list = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'clone' );
		}

		$entry = Ownership::entry( $list, $vmid );

		if ( null !== $entry ) {
			return $this->adopt( $entry, $record );
		}

		// Not listed: only an answered "free" lets us create it.
		$guard = $this->may_create( $record );

		if ( null !== $guard ) {
			return $guard;
		}

		$free = Ownership::is_free( $this->client, $vmid );

		if ( is_wp_error( $free ) ) {
			return $this->classify( $free, 'clone' );
		}

		if ( false === $free ) {
			return StepResult::fail( sprintf( 'VMID %d is in use but not visible to the API token: check its privileges', $vmid ) );
		}

		$template = Ownership::entry( $list, $this->plan['template'] );

		if ( null === $template || 'qemu' !== (string) ( $template['type'] ?? 'qemu' ) || empty( $template['template'] ) ) {
			return StepResult::fail( sprintf( 'VMID %d is not a template on this cluster', $this->plan['template'] ) );
		}

		$source = (string) ( $template['node'] ?? '' );
		$node   = Placement::node( $this->cluster_id, $this->cluster, $this->client, $source );

		if ( null === $node ) {
			return StepResult::retry( 0, 'clone: no allowed node is online' );
		}

		$params = array(
			'newid'       => $vmid,
			'name'        => 'vm-' . substr( $this->r->get( 'uuid' ), 0, 8 ),
			'full'        => 1,
			'storage'     => (string) $this->cluster['storage'],
			'description' => Ownership::tag( $this->r ),
		);

		if ( '' !== (string) $this->cluster['pool'] ) {
			$params['pool'] = (string) $this->cluster['pool'];
		}

		if ( $node !== $source ) {
			$params['target'] = $node; // Needs shared storage: API viewer #/nodes/{node}/qemu/{vmid}/clone.
		}

		$done = $this->run_task( 'clone', 'POST', '/nodes/' . rawurlencode( $source ) . '/qemu/' . $this->plan['template'] . '/clone', $params );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$this->finished = array(
			'step'  => 'clone',
			'phase' => '',
			'ok'    => true === $done,
			'exit'  => true === $done ? 'OK' : (string) $done['failed'],
		);

		// Whatever the exit status said, what exists now decides.
		$list = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'clone' );
		}

		$entry = Ownership::entry( $list, $vmid );

		if ( null === $entry ) {
			return StepResult::retry( 0, 'clone: the task ended but the VM is not there (' . $this->finished['exit'] . ')' );
		}

		return $this->adopt( $entry, $record );
	}

	/**
	 * A VM with our VMID is listed: it must be ours, finished, and not left by a failed task.
	 *
	 * @param array<string, mixed> $entry  Listing entry.
	 * @param array<string, mixed> $record VM record.
	 * @return array<string, mixed>|StepResult
	 */
	private function adopt( array $entry, array $record ) {
		$vmid = $record['vmid'];
		$node = (string) ( $entry['node'] ?? '' );

		if ( 'qemu' !== (string) ( $entry['type'] ?? 'qemu' ) || ! empty( $entry['template'] ) ) {
			return StepResult::fail( sprintf( 'VMID %d exists and is not ours', $vmid ) );
		}

		$config = Ownership::config( $this->client, $node, $vmid );

		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'clone' );
		}

		if ( ! Ownership::has_tag( (string) ( $config['description'] ?? '' ), Ownership::tag( $this->r ) ) ) {
			return StepResult::fail( sprintf( 'VMID %d exists and is not ours', $vmid ) );
		}

		if ( '' !== (string) ( $entry['lock'] ?? '' ) ) {
			return StepResult::retry( 0, 'clone: the VM is locked (' . sanitize_key( (string) $entry['lock'] ) . ')' );
		}

		// A task that ended in error and left a VM: an admin looks at it. "WARNINGS: n" means it finished.
		if ( $this->finished && 'clone' === $this->finished['step'] && ! $this->finished['ok'] && 0 !== strpos( (string) $this->finished['exit'], 'WARNINGS' ) ) {
			return StepResult::fail( sprintf( 'Clone task failed; VMID %d still exists, check it in Proxmox', $vmid ) );
		}

		if ( $node !== $record['node'] ) {
			VmRecords::move( $this->r->id(), $node );
		}

		return array();
	}

	/**
	 * Before a call that creates a VM on this id.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function may_create( array $record ): ?StepResult {
		$vmid = $record['vmid'];

		if ( $vmid < (int) $this->cluster['vmid_min'] || $vmid > (int) $this->cluster['vmid_max'] ) {
			return StepResult::fail( sprintf( 'VMID %d is outside the customer range', $vmid ) );
		}

		if ( ! VmRecords::owned( $this->cluster_id, $vmid, $this->r->id() ) ) {
			return StepResult::fail( sprintf( 'VMID %d is not recorded for this service', $vmid ) );
		}

		return null;
	}

	/**
	 * Step 4: cloud-init drive, config, disk, firewall. Each part is recorded when done.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_configure() {
		$record = VmRecords::for_resource( $this->r->id() );
		$ip     = IpPool::for_resource( $this->r->id() );

		if ( null === $record || null === $ip ) {
			return StepResult::fail( 'configure: the VM record or the address is missing' );
		}

		$done = (array) $this->r->get( 'configured' );
		$disk = null;

		foreach ( array( 'cloudinit', 'config', 'resize', 'firewall' ) as $part ) {
			if ( in_array( $part, $done, true ) ) {
				continue;
			}

			$v = $this->verified( $record, 'configure' );

			if ( $v instanceof StepResult ) {
				return $v;
			}

			$result = $this->{'configure_' . $part}( $record, $v, $ip );

			if ( $result instanceof StepResult ) {
				return $result;
			}

			$done[] = $part;

			if ( 'resize' === $part ) {
				$disk = $result;
			}

			if ( ! $this->r->set( array( 'configured' => $done ) ) ) {
				return StepResult::retry( 0, 'configure: progress could not be stored' );
			}
		}

		return array(
			'configured' => $done,
			'disk_gb'    => null === $disk ? $this->plan['disk_gb'] : $disk,
		);
	}

	/**
	 * A cloud-init drive on ide2. One that is something else is never overwritten.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, mixed>|StepResult
	 */
	private function configure_cloudinit( array $record, array $v ) {
		$ide2 = $v['config']['ide2'] ?? null;

		if ( is_string( $ide2 ) && '' !== $ide2 ) {
			return false !== strpos( $ide2, 'cloudinit' ) ? array() : StepResult::fail( 'configure: ide2 is not a cloud-init drive and is not overwritten' );
		}

		$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/config';
		$done = $this->run_task( 'configure', 'POST', $base, array( 'ide2' => $this->cluster['cloudinit_storage'] . ':cloudinit' ) );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		if ( true !== $done ) {
			// Read it back: a task with warnings may have worked.
			$again = $this->verified( $record, 'configure' );

			if ( $again instanceof StepResult ) {
				return $again;
			}

			if ( empty( $again['config']['ide2'] ) ) {
				return StepResult::retry( 0, 'configure: the cloud-init drive task failed (' . $done['failed'] . ')' );
			}
		}

		return array();
	}

	/**
	 * One PUT with cores, memory, network, login and the firewall flag on net0.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param array<string, mixed> $ip     Pool address.
	 * @return array<string, mixed>|StepResult
	 */
	private function configure_config( array $record, array $v, array $ip ) {
		$key = $this->r->get( 'ssh_key' );
		$gw  = null !== $ip['gateway'] && '' !== $ip['gateway'] ? $ip['gateway'] : (string) $this->cluster['gateway'];

		$params = array(
			'cores'     => $this->plan['cores'],
			'memory'    => $this->plan['memory_mb'],
			'ipconfig0' => 'ip=' . $ip['address'] . '/' . (int) $this->cluster['prefix'] . ',gw=' . $gw,
			'ciuser'    => 'root',
			'net0'      => $this->net0( (string) ( $v['config']['net0'] ?? '' ) ),
			'digest'    => (string) ( $v['config']['digest'] ?? '' ),
		);

		if ( array() !== (array) $this->cluster['nameservers'] ) {
			$params['nameserver'] = implode( ' ', (array) $this->cluster['nameservers'] );
		}

		if ( '' !== (string) $this->cluster['vendor_snippet'] ) {
			$params['cicustom'] = 'vendor=' . $this->cluster['vendor_snippet'];
		}

		if ( is_string( $key ) && '' !== $key ) {
			$clean = SshKeyField::normalise( $key );

			if ( null === $clean ) {
				return StepResult::fail( 'configure: the stored SSH key is not valid' );
			}

			// The sshkeys parameter is URL-encoded (API viewer #/nodes/{node}/qemu/{vmid}/config).
			$params['sshkeys'] = rawurlencode( $clean . "\n" );
		} else {
			try {
				// Stored encrypted BEFORE it is sent: a crash after the call loses nothing.
				$password = Access::pending_password( $this->r );
			} catch ( \RuntimeException $e ) {
				unset( $e );

				return StepResult::retry( 0, 'configure: the VM password could not be stored' );
			}

			$params['cipassword'] = $password;
			$this->secrets[]      = $password;
		}

		$answer = $this->client->request( 'PUT', '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/config', $params );

		return is_wp_error( $answer ) ? $this->classify( $answer, 'configure' ) : array();
	}

	/**
	 * Grows the boot disk to the plan's size; never shrinks.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return int|StepResult The disk size in GB.
	 */
	private function configure_resize( array $record, array $v ) {
		$disk    = (string) $this->cluster['boot_disk'];
		$current = self::gigabytes( (string) ( $v['config'][ $disk ] ?? '' ) );

		if ( null === $current ) {
			return StepResult::fail( 'configure: the boot disk ' . $disk . ' was not found on the VM' );
		}

		if ( $this->plan['disk_gb'] <= $current ) {
			return (int) ceil( $current ); // Equal or larger already: never smaller.
		}

		$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/resize';
		$done = $this->run_task(
			'configure',
			'PUT',
			$base,
			array(
				'disk' => $disk,
				'size' => $this->plan['disk_gb'] . 'G',
			)
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		if ( true !== $done ) {
			// "WARNINGS: n" and other exits: what the disk is now decides.
			$again = $this->verified( $record, 'configure' );

			if ( $again instanceof StepResult ) {
				return $again;
			}

			$now = self::gigabytes( (string) ( $again['config'][ $disk ] ?? '' ) );

			if ( null === $now || $now < $this->plan['disk_gb'] ) {
				return StepResult::retry( 0, 'configure: the disk resize failed (' . $done['failed'] . ')' );
			}
		}

		return $this->plan['disk_gb'];
	}

	/**
	 * The VM firewall, and the rule that blocks outgoing SMTP, once.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, mixed>|StepResult
	 */
	private function configure_firewall( array $record, array $v ) {
		$base   = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/firewall';
		$answer = $this->client->request( 'PUT', $base . '/options', array( 'enable' => 1 ) );

		if ( is_wp_error( $answer ) ) {
			return $this->classify( $answer, 'configure' );
		}

		if ( empty( $this->cluster['block_smtp'] ) ) {
			return array();
		}

		$rules = $this->client->request( 'GET', $base . '/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->classify( $rules, 'configure' );
		}

		foreach ( (array) $rules as $rule ) {
			if ( is_array( $rule ) && self::SMTP_COMMENT === (string) ( $rule['comment'] ?? '' ) ) {
				return array();
			}
		}

		$added = $this->client->request(
			'POST',
			$base . '/rules',
			array(
				'action'  => 'DROP',
				'type'    => 'out',
				'proto'   => 'tcp',
				'dport'   => '25',
				'iface'   => 'net0',
				'enable'  => 1,
				'comment' => self::SMTP_COMMENT,
			)
		);

		return is_wp_error( $added ) ? $this->classify( $added, 'configure' ) : array();
	}

	/**
	 * Step 5: start the VM, then ask the guest agent a few times (not fatal).
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_start() {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'start: the VM record is missing' );
		}

		$v = $this->verified( $record, 'start' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];
			$done = $this->run_task( 'start', 'POST', $base . '/status/start' );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			if ( true !== $done ) {
				$again = $this->verified( $record, 'start' );

				if ( $again instanceof StepResult ) {
					return $again;
				}

				if ( 'running' !== $again['status'] ) {
					return StepResult::retry( 0, 'start: the start task failed (' . $done['failed'] . ')' );
				}

				$v = $again;
			}
		}

		$agent = 'unknown';

		// Only a template that enables the agent has one to ask (API viewer: config `agent`).
		if ( preg_match( '/^(enabled=)?1\b/', (string) ( $v['config']['agent'] ?? '' ) ) ) {
			for ( $try = 1; $try <= 3; $try++ ) {
				$ping = $this->client->request( 'POST', '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/agent/ping' );

				if ( ! is_wp_error( $ping ) ) {
					$agent = 'ok';
					break;
				}

				if ( $try < 3 ) {
					( $this->nap )( 5 );
				}
			}
		}

		return array( 'agent' => $agent );
	}

	/**
	 * Step 6: the address is in use, and the meta says where everything is.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_ready() {
		$record = VmRecords::for_resource( $this->r->id() );
		$ip     = IpPool::for_resource( $this->r->id() );

		if ( null === $record || null === $ip ) {
			return StepResult::fail( 'ready: the VM record or the address is missing' );
		}

		if ( ! VmRecords::set_ip( $this->r->id(), $ip['id'] ) || ! IpPool::assign( $this->r->id() ) ) {
			return StepResult::retry( 0, 'ready: the address could not be recorded as in use' );
		}

		return array(
			'ip'     => $ip['address'],
			'node'   => $record['node'],
			'vmid'   => $record['vmid'],
			'vm'     => array(
				'cluster' => $record['cluster'],
				'vmid'    => $record['vmid'],
				'node'    => $record['node'],
			),
			'task'   => null,
		);
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * The net0 string from the current one: the model and MAC and every other option stay; bridge,
	 * firewall and the VLAN tag are set. net0 is one property string: writing only `firewall=1`
	 * would drop the rest.
	 *
	 * @param string $current Current net0 (empty: none).
	 */
	private function net0( string $current ): string {
		$props = array();

		foreach ( '' === $current ? array() : explode( ',', $current ) as $token ) {
			$pair = explode( '=', $token, 2 );

			$props[ $pair[0] ] = $pair[1] ?? null;
		}

		if ( array() === $props ) {
			$props['virtio'] = null; // The MAC is generated when none is given.
		}

		$props['bridge']   = (string) $this->cluster['bridge'];
		$props['firewall'] = '1';

		if ( (int) $this->cluster['vlan'] > 0 ) {
			$props['tag'] = (string) (int) $this->cluster['vlan'];
		}

		$out = array();

		foreach ( $props as $key => $value ) {
			$out[] = null === $value ? $key : $key . '=' . $value;
		}

		return implode( ',', $out );
	}

	/**
	 * The size= of a disk string, in gigabytes.
	 *
	 * @param string $disk For example "store:vm-1-disk-0,size=30G".
	 * @return float|null
	 */
	public static function gigabytes( string $disk ): ?float {
		if ( 1 !== preg_match( '/(?:^|,)size=(\d+(?:\.\d+)?)([KMGT]?)/', $disk, $m ) ) {
			return null;
		}

		$factor = array(
			''  => 1 / 1073741824,
			'K' => 1 / 1048576,
			'M' => 1 / 1024,
			'G' => 1,
			'T' => 1024,
		);

		return (float) $m[1] * $factor[ $m[2] ];
	}
}
