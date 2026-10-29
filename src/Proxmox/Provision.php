<?php
/**
 * Provisioning a Proxmox VM, step by step.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Reconciler;
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

	/**
	 * The login the cloud-init sets up (and the guest agent sets the password for).
	 */
	public const LOGIN_USER = 'root';

	/** The disk format of new volumes: qcow2 (snapshots need it). Existing raw volumes are left as they are. */
	public const DISK_FORMAT = 'qcow2';

	/**
	 * Recorded as the old boot volume when the VM had no boot drive.
	 */
	private const NO_BOOT = '-';

	use Steps;

	/**
	 * Order of the steps. `wipe` only runs while meta `wipe` is set.
	 */
	private const STEPS = array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'app', 'ready' );

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
			if ( 'app' === $name && '' === (string) $this->r->get( 'app_id' ) ) {
				continue;
			}
			if ( 'wipe' === $name ? ! $this->r->get( 'wipe' ) : in_array( $name, $steps, true ) ) {
				continue;
			}

			// A license that ended since this job was queued builds nothing and starts nothing; a VM already cloned is paused (it stays off).
			if ( in_array( $name, array( 'clone', 'start' ), true ) && Reconciler::guard( $this->r ) ) {
				return StepResult::retry( 0, 'The license is no longer active; provisioning stopped' );
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

			$this->progressed = true;

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

		// The customer's choice (checkout or reinstall) beats the plan's template, which is the fallback.
		$chosen = $this->r->get( OsField::META );

		if ( null !== $chosen && '' !== $chosen && 0 !== $chosen ) {
			$this->plan['template'] = is_int( $chosen ) || ( is_string( $chosen ) && ctype_digit( $chosen ) ) ? (int) $chosen : 0;

			if ( ! array_key_exists( $this->plan['template'], Clusters::templates( $this->cluster_id ) ) ) {
				return StepResult::fail( 'The operating system template chosen for this service (' . mb_substr( sanitize_text_field( (string) ( is_scalar( $chosen ) ? $chosen : '' ) ), 0, 20 ) . ') is not in the template list of this cluster' );
			}
		}

		// No choice (an admin-issued or imported license never saw the checkout field) and no plan
		// template: the cluster's default, the same one the checkout field and reinstall preselect.
		if ( 0 === $this->plan['template'] ) {
			$this->plan['template'] = Clusters::default_template( $this->cluster_id );
		}

		if ( ! array_key_exists( $this->plan['template'], Clusters::templates( $this->cluster_id ) ) ) {
			return StepResult::fail( 'Template ' . $this->plan['template'] . ' is not in the template list of this cluster' );
		}

		foreach ( array( 'cores', 'memory_mb', 'disk_gb' ) as $key ) {
			if ( $this->plan[ $key ] <= 0 ) {
				return StepResult::fail( 'The plan value "' . $key . '" must be a positive number' );
			}
		}

		if ( '' !== (string) $this->r->get( 'app_id' ) ) {
			$why = Apps::check( $cluster, $this->plan, (string) $this->r->get( 'app_id' ), (string) $this->r->get( 'app_version' ) );
			if ( null !== $why ) {
				return StepResult::fail( 'app: ' . $why, array( 'app_state' => 'failed' ) );
			}
		}

		$gate = Clients::gate( $this->cluster_id, $cluster, Clients::live_allowed() );

		if ( null !== $gate ) {
			return StepResult::retry( 0, $gate );
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

		if ( 'boot' === $this->r->get( 'wipe' ) ) {
			return $this->step_wipe_boot( $record );
		}

		for ( $round = 0; $round < 4; $round++ ) {
			if ( null === $record ) {
				return $this->wiped( true ); // Nothing was ever built.
			}

			$v = Ownership::verify( $this->client, $record, $this->r );

			if ( is_wp_error( $v ) && 'absent' === $v->get_error_code() ) {
				$free = Ownership::is_free( $this->client, $record['vmid'] );

				if ( true === $free ) {
					return $this->wiped( true );
				}

				return StepResult::retry( 0, 'wipe: the VM is not listed and its id is not confirmed free' );
			}

			if ( is_wp_error( $v ) ) {
				return $this->refuse_or_retry( $v, 'wipe' );
			}

			$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];

			$purged = $this->wipe_snapshots();

			if ( null !== $purged ) {
				return $purged;
			}

			if ( 'running' === $v['status'] ) {
				// The disk is destroyed anyway: no graceful shutdown, a plain stop.
				$off = $this->wipe_power_off( $record, $base, false );

				if ( $off instanceof StepResult ) {
					return $off;
				}

				continue;
			} else {
				$done = $this->run_task(
					'wipe',
					'DELETE',
					$base,
					array(
						'purge'                      => 1,
						'destroy-unreferenced-disks' => 1,
					),
					'delete'
				);
			}

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$this->finished = array(
				'step'  => 'wipe',
				'phase' => 'delete',
				'ok'    => true === $done,
				'exit'  => true === $done ? 'OK' : (string) $done['failed'],
			);
		}

		return StepResult::retry( 0, 'wipe: the VM did not reach the wanted state' );
	}

	/**
	 * D8: the server's snapshots go before the wipe touches the VM or its boot volume (Proxmox keeps a volume a
	 * snapshot still holds). Once per attempt: meta `wipe_snapshots` = done is cleared with the wipe. A snapshot
	 * that cannot be deleted is tried again, never skipped, and the admin is told once after the third round.
	 *
	 * @return StepResult|null Null when no snapshot is left.
	 */
	private function wipe_snapshots(): ?StepResult {
		if ( 'done' === $this->r->get( 'wipe_snapshots' ) ) {
			return null;
		}

		if ( Snapshots::tracked( $this->r ) ) {
			$purged = ( new Snapshots( $this->r ) )->purge_all();

			if ( ! $purged->is_done() ) {
				$tries = (int) $this->r->get( 'wipe_snapshot_tries' ) + 1;

				$this->r->set( array( 'wipe_snapshot_tries' => $tries ) );

				if ( $tries >= 3 && ! $this->r->get( 'wipe_snapshots_alerted' ) && $this->r->set( array( 'wipe_snapshots_alerted' => 1 ) ) ) {
					Alerts::admin( $this->r, 'The reinstall waits: a snapshot of the server could not be deleted. Last answer: ' . $this->scrub( $purged->error(), 160 ) );
				}

				// Never a failure: the reinstall must not run past a snapshot, and must not give up either.
				return StepResult::retry( $purged->delay(), 'wipe: ' . $purged->error() );
			}
		}

		return $this->r->set( array( 'wipe_snapshots' => 'done', 'wipe_snapshot_tries' => null, 'wipe_snapshots_alerted' => null ) ) ? null : StepResult::retry( 0, 'wipe: the snapshot clean-up could not be recorded' );
	}

	/**
	 * Switches the running VM of a reinstall off. With extra disks: a graceful shutdown first (their filesystems are
	 * flushed), then a stop when it is still running. Without: a plain stop, the disk is destroyed anyway. A task that
	 * ended is followed by re-reads (the status can lag), and a VM that still shows as running then is waited for
	 * with a retry, never counted as a failed round. Meta `wipe_power` = stop remembers that the graceful stage is
	 * over, so a later run does not start it again.
	 *
	 * @param array<string, mixed> $record   VM record.
	 * @param string               $base     VM path.
	 * @param bool                 $graceful Try a shutdown first.
	 * @return true|StepResult True when the VM is read as stopped.
	 */
	private function wipe_power_off( array $record, string $base, bool $graceful ) {
		// A stop was sent by an earlier run: the status may only be late, and a stop on a stopped VM is an error.
		if ( 'stop' === $this->r->get( 'wipe_power' ) ) {
			$late = $this->reread_power( $record, 'wipe', false );

			if ( $late instanceof StepResult ) {
				return $late;
			}

			if ( 'running' !== $late['status'] ) {
				return true;
			}

			// The stop ended OK a moment ago and the status still shows the VM: wait, a second hard stop is a second write.
			if ( $this->sent_recently( 'stop' ) ) {
				return $this->still_running( 'wipe: waiting for the server to stop' );
			}
		}

		$stop_first = ! $graceful || 'stop' === $this->r->get( 'wipe_power' )
			|| ( $this->finished && 'wipe' === $this->finished['step'] && 'shutdown' === $this->finished['phase'] );

		foreach ( $stop_first ? array( 'stop' ) : array( 'shutdown', 'stop' ) as $phase ) {
			if ( 'stop' === $phase && ! $this->r->set( array( 'wipe_power' => 'stop' ) ) ) {
				return StepResult::retry( 0, 'wipe: the power phase could not be recorded' );
			}

			$done = 'shutdown' === $phase
				? $this->run_task( 'wipe', 'POST', $base . '/status/shutdown', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), $phase )
				: $this->run_task( 'wipe', 'POST', $base . '/status/stop', array(), $phase );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$this->finished = array(
				'step'  => 'wipe',
				'phase' => $phase,
				'ok'    => true === $done,
				'exit'  => true === $done ? 'OK' : (string) $done['failed'],
			);

			$after = $this->reread_power( $record, 'wipe', false );

			if ( $after instanceof StepResult ) {
				return $after;
			}

			if ( 'running' !== $after['status'] ) {
				return true;
			}
		}

		return $this->still_running( 'wipe: waiting for the server to stop', $done );
	}

	/**
	 * Step 0 of a reinstall of a server that has extra disks: replace only the boot disk of the existing VM.
	 * Stop; detach the boot drive (it becomes unusedN); delete exactly that old volume; import the template's boot
	 * volume into the boot slot. Every round reads the VM and decides from what is there, so a crash or a retry
	 * never imports twice and never deletes the new boot disk. Only the boot slot and the one unusedN entry that
	 * holds the recorded old boot volume (meta `wipe_old_boot`, matched by volume id) are ever written; meta
	 * `wipe_boot_phase` = import marks that the old volume is gone, because the new volume may get the old name. The extra
	 * disks, their slots and every other unusedN entry are left alone.
	 *
	 * @param array<string, mixed>|null $record VM record.
	 * @return array<string, mixed>|StepResult
	 */
	private function step_wipe_boot( ?array $record ) {
		if ( null === $record ) {
			return StepResult::fail( 'wipe: no VM is recorded for this service; the extra disks were not touched' );
		}

		$boot = (string) $this->cluster['boot_disk'];

		if ( 1 !== preg_match( '/^(?:scsi|virtio|sata|ide)\\d+$/', $boot ) ) {
			return StepResult::fail( 'wipe: the cluster boot disk "' . mb_substr( sanitize_text_field( $boot ), 0, 20 ) . '" is not a drive name' );
		}

		$extra = DiskSteps::volumes( $this->r, (string) $this->cluster['storage'], (int) $record['vmid'], false );

		foreach ( $extra as $disk ) {
			if ( $disk['slot'] === $boot ) {
				return StepResult::fail( 'wipe: an extra disk uses the boot slot ' . $boot . '; nothing was changed' );
			}
		}

		$extra_ids = array_column( $extra, 'volid' );

		for ( $round = 0; $round < 8; $round++ ) {
			$v = Ownership::verify( $this->client, $record, $this->r );

			if ( is_wp_error( $v ) ) {
				return $this->refuse_or_retry( $v, 'wipe' );
			}

			$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];

			$purged = $this->wipe_snapshots();

			if ( null !== $purged ) {
				return $purged;
			}

			if ( 'running' === $v['status'] ) {
				// The extra disks keep their data: their filesystems get the graceful shutdown first.
				$off = $this->wipe_power_off( $record, $base, true );

				if ( $off instanceof StepResult ) {
					return $off;
				}

				continue;
			}

			if ( '' !== $v['lock'] ) {
				return StepResult::retry( 0, 'wipe: the VM is locked (' . sanitize_key( $v['lock'] ) . ')' );
			}

			$config = $v['config'];
			$drive  = isset( $config[ $boot ] ) && is_string( $config[ $boot ] ) ? $config[ $boot ] : '';
			$volid  = '' === $drive ? '' : explode( ',', $drive, 2 )[0];
			$old    = (string) $this->r->get( 'wipe_old_boot' );

			// The old boot volume is written down before anything is detached: it is the only volume this step may destroy.
			if ( '' === $old ) {
				if ( '' !== $volid && in_array( $volid, $extra_ids, true ) ) {
					return StepResult::fail( 'wipe: the boot slot holds an extra disk volume; nothing was changed' );
				}

				$old = '' === $volid ? self::NO_BOOT : $volid;

				if ( ! $this->r->set( array( 'wipe_old_boot' => $old ) ) ) {
					return StepResult::retry( 0, 'wipe: the old boot volume could not be recorded' );
				}
			}

			$path   = $base . '/config';
			$digest = (string) ( $config['digest'] ?? '' );
			$phase  = (string) $this->r->get( 'wipe_boot_phase' );

			// Once the old volume is gone and the import is under way, whatever the boot slot holds is the new boot disk
			// (it may even carry the old name): it is never detached again.
			if ( 'import' === $phase ) {
				if ( '' !== $volid ) {
					return $this->wiped_boot( $volid );
				}
			} elseif ( '' !== $volid && $volid !== $old ) {
				return StepResult::fail( 'wipe: the boot slot holds a volume that is not the recorded old boot volume; nothing was changed' );
			} elseif ( '' !== $volid ) {
				$answer = $this->client->request( 'PUT', $path, array( 'delete' => $boot, 'digest' => $digest ) );

				if ( is_wp_error( $answer ) ) {
					return $this->classify( $answer, 'wipe' );
				}

				continue;
			} else {
				$unused = '';

				foreach ( self::NO_BOOT === $old ? array() : $config as $key => $value ) {
					if ( 1 === preg_match( '/^unused\d+$/', (string) $key ) && is_string( $value ) && explode( ',', $value, 2 )[0] === $old ) {
						$unused = (string) $key;
						break;
					}
				}

				if ( '' !== $unused ) {
					$answer = $this->client->request( 'PUT', $path, array( 'delete' => $unused, 'digest' => $digest ) );

					if ( is_wp_error( $answer ) ) {
						return $this->classify( $answer, 'wipe' );
					}

					continue;
				}

				// The old volume is gone: written down before the import, so a retry never mistakes the new disk for the old one.
				if ( ! $this->r->set( array( 'wipe_boot_phase' => 'import' ) ) ) {
					return StepResult::retry( 0, 'wipe: the import phase could not be recorded' );
				}
			}

			$source = $this->template_boot_volume( $boot );

			if ( $source instanceof StepResult ) {
				return $source;
			}

			$done = $this->run_task(
				'wipe',
				'POST',
				$path,
				array(
					$boot    => $this->cluster['storage'] . ':0,import-from=' . $source . ',format=' . self::disk_format(),
					'boot'   => 'order=' . $boot,
					'digest' => $digest,
				),
				'import'
			);

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$this->finished = array(
				'step'  => 'wipe',
				'phase' => 'import',
				'ok'    => true === $done,
				'exit'  => true === $done ? 'OK' : (string) $done['failed'],
			);

			// Whatever the exit status said, the boot slot decides on the next round; a plain failure is tried again later.
			if ( true !== $done && 0 !== strpos( (string) $done['failed'], 'WARNINGS' ) ) {
				return StepResult::retry( 0, 'wipe: the boot disk import failed (' . $done['failed'] . ')' );
			}
		}

		return StepResult::retry( 0, 'wipe: the boot disk did not reach the wanted state' );
	}

	/**
	 * The volume the template boots from, for import-from.
	 *
	 * @param string $boot Boot drive key.
	 * @return string|StepResult
	 */
	private function template_boot_volume( string $boot ) {
		$list = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'wipe' );
		}

		$template = Ownership::entry( $list, $this->plan['template'] );

		if ( null === $template || empty( $template['template'] ) || '' === (string) ( $template['node'] ?? '' ) ) {
			return StepResult::fail( sprintf( 'wipe: VMID %d is not a template on this cluster; the boot disk was not replaced', $this->plan['template'] ) );
		}

		$config = $this->client->request( 'GET', '/nodes/' . rawurlencode( (string) $template['node'] ) . '/qemu/' . $this->plan['template'] . '/config' );

		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'wipe' );
		}

		$drive = is_array( $config ) && isset( $config[ $boot ] ) && is_string( $config[ $boot ] ) ? explode( ',', $config[ $boot ], 2 )[0] : '';

		if ( 1 !== preg_match( '#^[A-Za-z0-9._\\-]+:[A-Za-z0-9._\\-/]+$#', $drive ) ) {
			return StepResult::fail( sprintf( 'wipe: template %1$d has no boot disk %2$s to import', $this->plan['template'], $boot ) );
		}

		return $drive;
	}

	/**
	 * The boot disk was replaced: the VM exists, so clone is skipped; configure, start and ready run as usual
	 * and ready checks that every extra disk is still in its slot.
	 *
	 * @return array<string, mixed>
	 */
	private function wiped_boot( string $volid ): array {
		return array(
			'boot_format'     => Snapshots::format_of( $volid ),
			'wipe'            => null,
			'wipe_old_boot'   => null,
			'wipe_boot_phase' => null,
			'wipe_power'      => null,
			'wipe_snapshots'  => null,
			'disks_check'     => true,
			'task'            => null,
			'agent'           => null,
			'configured'      => array(),
			'steps'           => array_values( array_intersect( (array) $this->r->get( 'steps' ), array( 'ip', 'vmid', 'clone' ) ) ),
		);
	}

	/**
	 * The old VM is gone: forget what a rebuilt one needs afresh.
	 *
	 * @param bool $gone The VM is absent (or was never recorded): the snapshots went with it, so the list is forgotten.
	 * @return array<string, mixed>
	 */
	private function wiped( bool $gone = false ): array {
		$keep = array_values( array_intersect( $this->r->get( 'steps' ), array( 'ip', 'vmid' ) ) );
		$lost = $gone && Snapshots::tracked( $this->r )
			? array( Snapshots::LIST => array(), Snapshots::OP => null, Snapshots::ROLLBACK => null )
			: array();

		return $lost + array(
			'wipe'           => null,
			'wipe_power'     => null,
			'wipe_snapshots' => null,
			'task'           => null,
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
	 * The name the VM is cloned with, which cloud-init uses as the guest's hostname: the customer's server
	 * name when one is set, else vm-<first 8 characters of the uuid>.
	 *
	 * @param Resource $r Server resource.
	 */
	public static function vm_name( Resource $r ): string {
		$name = ServerName::of( $r );

		return '' !== $name ? $name : 'vm-' . substr( (string) $r->get( 'uuid' ), 0, 8 );
	}

	/**
	 * The format for new volumes: qcow2, or raw when the filter says so. Anything else is qcow2.
	 */
	public static function disk_format(): string {
		$format = apply_filters( 'beltoft_services_proxmox_disk_format', self::DISK_FORMAT );

		return in_array( $format, array( 'raw', 'qcow2' ), true ) ? $format : self::DISK_FORMAT;
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

		// The VM is gone (an admin removed the leftover): a clone may be tried again.
		if ( null !== $this->r->get( 'clone_failed' ) && ! $this->r->set( array( 'clone_failed' => null ) ) ) {
			return StepResult::retry( 0, 'clone: the failed-clone marker could not be cleared' );
		}

		$source = (string) ( $template['node'] ?? '' );
		$bytes  = max( 0, (int) $this->plan['memory_mb'] ) * 1048576;
		$node   = $record['node'];

		// A node already chosen for this VM (and committed) is kept on a retry.
		if ( '' === $node || ! in_array( $node, array_map( 'strval', (array) $this->cluster['nodes'] ), true ) ) {
			$node = Placement::node( $this->cluster_id, $this->cluster, $source, $bytes );

			if ( null === $node ) {
				if ( null === Capacity::rows( $this->cluster_id ) ) {
					return StepResult::retry( 0, 'clone: the capacity of the cluster is not known yet' );
				}

				// Retried along the backoff; when it runs out the service fails and the admin is told.
				Alerts::admin( $this->r, sprintf( 'No capacity on cluster %s', $this->cluster_id ) );

				return StepResult::retry( 0, sprintf( 'clone: No capacity on cluster %s', $this->cluster_id ) );
			}

			// The record and the committed memory change together, under the capacity lock.
			try {
				$placed = Capacity::place( $this->cluster_id, $node, $bytes, fn(): bool => VmRecords::move( $this->r->id(), $node ) );
			} catch ( \RuntimeException $e ) {
				unset( $e );

				return StepResult::retry( 0, 'clone: the capacity is busy' );
			}

			if ( ! $placed ) {
				return StepResult::retry( 0, 'clone: the placement could not be recorded' );
			}
		}

		$params = array(
			'newid'       => $vmid,
			'name'        => self::vm_name( $this->r ),
			'full'        => 1,
			'storage'     => (string) $this->cluster['storage'],
			'format'      => self::disk_format(),
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
		// The marker outlives this run, so a crash or an admin retry does not adopt the leftover.
		$failed = $this->finished && 'clone' === $this->finished['step'] && ! $this->finished['ok'] && 0 !== strpos( (string) $this->finished['exit'], 'WARNINGS' );

		if ( $failed || (int) $this->r->get( 'clone_failed' ) === $vmid ) {
			$this->r->set( array( 'clone_failed' => $vmid ) );

			return StepResult::fail( sprintf( 'Clone task failed; VMID %d still exists, check it in Proxmox', $vmid ), array( 'clone_failed' => $vmid ) );
		}

		if ( $node !== $record['node'] ) {
			VmRecords::move( $this->r->id(), $node );
		}

		// What the boot volume is (snapshots need qcow2), read from the config the build already has.
		return array( 'boot_format' => Snapshots::format_of( explode( ',', (string) ( $config[ (string) $this->cluster['boot_disk'] ] ?? '' ) )[0] ) );
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

		// A gateway the pool row brings must be on the address's own network; the cluster's is the routed default.
		if ( null !== $ip['gateway'] && '' !== $ip['gateway'] && ! self::gateway_on_network( $ip['address'], $ip['gateway'], (int) $this->cluster['prefix'] ) ) {
			return StepResult::fail( sprintf( 'configure: the gateway %1$s of address %2$s is outside %2$s/%3$d; nothing was written to the VM', $ip['gateway'], $ip['address'], (int) $this->cluster['prefix'] ) );
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

		$login = array();

		if ( is_string( $key ) && '' !== $key ) {
			$clean = SshKeyField::normalise( $key );

			if ( null === $clean ) {
				return StepResult::fail( 'configure: the stored SSH key is not valid' );
			}

			// The raw key: the client URL-encodes the sshkeys value itself, once.
			$login['sshkeys'] = $clean . "\n";
		} else {
			try {
				// Stored encrypted BEFORE it is sent: a crash after the call loses nothing.
				$password = Access::pending_password( $this->r );
			} catch ( \RuntimeException $e ) {
				unset( $e );

				return StepResult::retry( 0, 'configure: the VM password could not be stored' );
			}

			$login['cipassword'] = $password;
			$this->secrets[]     = $password;
		}

		$path = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/config';

		for ( $try = 1; $try <= 2; $try++ ) {
			$params = array(
				'cores'     => $this->plan['cores'],
				'memory'    => $this->plan['memory_mb'],
				'ipconfig0' => 'ip=' . $ip['address'] . '/' . (int) $this->cluster['prefix'] . ',gw=' . $gw,
				'ciuser'    => self::LOGIN_USER,
				'net0'      => $this->net0( (string) ( $v['config']['net0'] ?? '' ) ),
				'digest'    => (string) ( $v['config']['digest'] ?? '' ),
			);

			if ( array() !== (array) $this->cluster['nameservers'] ) {
				$params['nameserver'] = implode( ' ', (array) $this->cluster['nameservers'] );
			}

			if ( '' !== (string) $this->cluster['vendor_snippet'] ) {
				$params['cicustom'] = 'vendor=' . $this->cluster['vendor_snippet'];
			}

			$answer = $this->client->request( 'PUT', $path, $params + $login );

			if ( ! is_wp_error( $answer ) ) {
				return array();
			}

			// Somebody changed the config between our read and our write (the digest guard): read it again, once.
			if ( 1 === $try && 1 === preg_match( '/digest|modified configuration/i', $answer->get_error_message() ) ) {
				$v = $this->verified( $record, 'configure' );

				if ( $v instanceof StepResult ) {
					return $v;
				}

				continue;
			}

			return $this->classify( $answer, 'configure' );
		}

		return StepResult::retry( 0, 'configure: the config kept changing under the write' );
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
				$again = $this->reread_power( $record, 'start', true );

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
		if ( self::agent_enabled( (string) ( $v['config']['agent'] ?? '' ) ) ) {
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

		Operations::remember_power( $this->r->id(), 'running' );

		return array( 'agent' => $agent );
	}

	/** Queue a versioned install only for a newly built app server. */
	private function step_app() {
		return AppInstall::queue( $this->r, $this->client, $this->cluster, $this->plan );
	}

	/**
	 * Final step: the address is in use, and the meta says where everything is.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_ready() {
		$record = VmRecords::for_resource( $this->r->id() );
		$ip     = IpPool::for_resource( $this->r->id() );

		if ( null === $record || null === $ip ) {
			return StepResult::fail( 'ready: the VM record or the address is missing' );
		}

		// Where it runs now, from the listing, not from what was recorded earlier.
		$list = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'ready' );
		}

		$entry = Ownership::entry( $list, $record['vmid'] );

		if ( null === $entry || '' === (string) ( $entry['node'] ?? '' ) ) {
			return StepResult::retry( 0, 'ready: the VM is not listed' );
		}

		if ( (string) $entry['node'] !== $record['node'] ) {
			VmRecords::move( $this->r->id(), (string) $entry['node'] );
			$record['node'] = (string) $entry['node'];
		}

		if ( ! VmRecords::set_ip( $this->r->id(), $ip['id'] ) || ! IpPool::assign( $this->r->id() ) ) {
			return StepResult::retry( 0, 'ready: the address could not be recorded as in use' );
		}

		$checked = $this->check_extra_disks( $record );

		if ( $checked instanceof StepResult ) {
			return $checked;
		}

		return array(
			'disks_check' => null,
			'ip'          => $ip['address'],
			'node'        => $record['node'],
			'vmid'        => $record['vmid'],
			'vm'          => array(
				'cluster' => $record['cluster'],
				'vmid'    => $record['vmid'],
				'node'    => $record['node'],
			),
			'task'        => null,
		);
	}

	/**
	 * After a boot disk replacement: every extra disk that should be attached still is, in its own slot. One
	 * admin alert when not; nothing is changed automatically.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function check_extra_disks( array $record ): ?StepResult {
		if ( ! $this->r->get( 'disks_check' ) ) {
			return null;
		}

		$v = $this->verified( $record, 'ready' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$lost = array();

		foreach ( DiskSteps::volumes( $this->r, (string) $this->cluster['storage'], (int) $record['vmid'], true ) as $disk ) {
			$held = DiskSteps::holds( $v['config'], $disk['volid'] );

			if ( null === $held || $held['slot'] !== $disk['slot'] ) {
				$lost[] = '#' . $disk['id'] . ' (' . $disk['slot'] . ')';
			}
		}

		if ( array() !== $lost ) {
			Alerts::admin( $this->r, 'After the reinstall these extra disks are not attached in their slot: ' . implode( ', ', $lost ) . '. Nothing was changed automatically; check the server configuration.' );
		}

		return null;
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
		$props = NetConfig::parse( $current );

		if ( array() === $props ) {
			$props['virtio'] = null; // The MAC is generated when none is given.
		}

		$props['bridge']   = (string) $this->cluster['bridge'];
		$props['firewall'] = '1';

		if ( (int) $this->cluster['vlan'] > 0 ) {
			$props['tag'] = (string) (int) $this->cluster['vlan'];
		}

		return NetConfig::format( $props );
	}

	/**
	 * Does the `agent` property string enable the guest agent? `[enabled=]<bool>[,fstrim_cloned_disks=<bool>][,type=<t>]`: enabled may be a bare first value or a named one anywhere.
	 *
	 * @param string $agent Property string.
	 */
	public static function agent_enabled( string $agent ): bool {
		foreach ( '' === $agent ? array() : explode( ',', $agent ) as $token ) {
			if ( '1' === $token || 'enabled=1' === $token ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is the address's gateway on the address's network (address/prefix)?
	 *
	 * @param string $address IPv4 address.
	 * @param string $gateway IPv4 gateway.
	 * @param int    $prefix  Prefix length.
	 */
	private static function gateway_on_network( string $address, string $gateway, int $prefix ): bool {
		$a = ip2long( $address );
		$g = ip2long( $gateway );

		if ( false === $a || false === $g || $prefix < 1 || $prefix > 32 ) {
			return false;
		}

		$mask = ( 0xFFFFFFFF << ( 32 - $prefix ) ) & 0xFFFFFFFF;

		return ( $a & $mask ) === ( $g & $mask );
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
