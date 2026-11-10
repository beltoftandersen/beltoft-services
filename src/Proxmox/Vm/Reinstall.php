<?php
/**
 * The reinstall of a Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The reinstall: the VM goes back to provisioning. Built by Actions::run() after the VM is verified, and run under the resource lock.
 */
final class Reinstall {

	use Steps;
	use Prepares;

	/**
	 * Cluster settings (token secret decrypted), as Actions prepared them.
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r     Resource.
	 * @param Actions  $owner The Actions run that prepared the client and polled the stored task.
	 */
	public function __construct( Resource $r, Actions $owner ) {
		$this->r = $r;
		$state = $owner->state();

		$this->client    = $state['client'];
		$this->cluster   = $state['cluster'];
		$this->secrets   = $state['secrets'];
		$this->wait      = $state['wait'];
		$this->finished  = $state['finished'];
	}

	/**
	 * Bookkeeping only: the provision's `wipe` step does the destroying, with retries and
	 * ownership checks, on the same VMID and address, with a new password.
	 *
	 * @internal Public only so Actions can call it; not an API.
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function reinstall( array $record, array $args ): StepResult {
		// A server with extra disks keeps its VM: only the boot disk is replaced (the VM and its address stay, clone is skipped).
		$boot = Actions::has_extra_disks( $this->r );
		$meta = array(
			'wipe'              => $boot ? 'boot' : true,
			'wipe_old_boot'     => null,
			'wipe_boot_phase'   => null,
			'wipe_power'        => null,
			'wipe_snapshots'    => null,
			'wipe_snapshot_tries' => null,
			'wipe_snapshots_alerted' => null,
			'disks_check'       => null,
			'steps'             => array_values( array_intersect( (array) $this->r->get( 'steps' ), $boot ? array( 'ip', 'vmid', 'clone' ) : array( 'ip', 'vmid' ) ) ),
			'task'              => null,
			'agent'             => null,
			'configured'        => array(),
			// A new password: the old one is gone, and the build stores a fresh one before sending it.
			Access::META_REVEAL => '',
			Access::META_UNTIL  => 0,
			Access::META_RESET  => null,
			Access::META_PREV   => null,
			'last_reinstall_at' => time(),
			ServerName::PENDING => null, ServerName::PENDING_AT => null,
		);

		if ( isset( $args['os'] ) && '' !== $args['os'] ) {
			$meta[ OsField::META ] = (int) $args['os'];
		}

		/**
		 * Meta a reinstall also sets (the app choice).
		 *
		 * @param array<string, mixed> $meta Meta so far.
		 * @param Resource             $r    Resource.
		 * @param array<string, mixed> $args Arguments.
		 */
		$meta = (array) apply_filters( 'beltoft_services_reinstall_meta', $meta, $this->r, $args );

		if ( ! $this->r->set( $meta ) ) {
			return StepResult::retry( 0, 'reinstall: the request could not be stored' );
		}

		// Silent on purpose: no listener acts on provisioning (see Resource::transition()).
		if ( ! $this->r->transition( 'provisioning', 'Reinstall started by the customer' ) ) {
			return StepResult::retry( 0, 'reinstall: the state could not be stored' );
		}

		/** Fires after a reinstall request and its metadata are stored, before the server job is queued. */
		do_action( 'beltoft_services_reinstall_started', $this->r, $args );

		Jobs::enqueue( $this->r->id() );

		return StepResult::done();
	}
}
