<?php
/**
 * The customer's actions on a Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * start, shutdown, reboot, reset_password, replace_ssh_key, reinstall and restore. Every action runs from
 * Jobs::act() (under the resource lock, only while the resource is ready and the license active),
 * verifies the VM as ours first (Ownership::verify through Steps::verified), re-reads the remote
 * state before it acts, and is idempotent on a repeat. Texts that reach the customer are fixed
 * strings; a Proxmox error only ever goes to the admin alert, scrubbed.
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>):
 * /nodes/{node}/qemu/{vmid}/status/start, .../status/shutdown (`timeout`), .../status/reboot
 * (`timeout`), PUT .../config (`cipassword`, `sshkeys`), POST .../agent/set-user-password
 * (`username`, `password`), POST .../agent/file-write (`file`, `content`; needs the privilege
 * VM.GuestAgent.FileWrite or VM.GuestAgent.Unrestricted, PVE 9 pveum documentation), and for a
 * restore POST /nodes/{node}/qemu (`vmid`, `archive`, `force`, `storage`; with `archive` it is
 * enough to hold VM.Backup on the existing VM, plus Datastore.AllocateSpace on the disk storage).
 *
 * A password reset sends only `cipassword` (and the guest agent while the VM runs): never
 * ipconfig0, cicustom or any other cloud-init value.
 */
final class Actions {

	use Steps;

	/**
	 * The word a customer types to confirm a reinstall.
	 */
	public const CONFIRM = 'REINSTALL';

	/**
	 * The word a customer types to confirm a restore.
	 */
	public const CONFIRM_RESTORE = 'RESTORE';

	/**
	 * The file the guest agent replaces on a key change (the login user is root).
	 */
	public const AUTHORIZED_KEYS = '/root/.ssh/authorized_keys';

	/**
	 * Seconds between two reinstalls of one VM.
	 */
	public const REINSTALL_GAP = HOUR_IN_SECONDS;

	/**
	 * Fixed floors (user, action) that no filter lifts: requests per window.
	 *
	 * @var array<string, array{0:int,1:int}>
	 */
	private const FLOORS = array(
		'start'          => array( 20, 600 ),
		'shutdown'       => array( 20, 600 ),
		'reboot'         => array( 20, 600 ),
		'reset_password' => array( 5, HOUR_IN_SECONDS ),
		'replace_ssh_key' => array( 10, HOUR_IN_SECONDS ),
		'reinstall'      => array( 5, HOUR_IN_SECONDS ),
		'restore'        => array( 3, HOUR_IN_SECONDS ),
	);

	/**
	 * Password resets per hour, counted in the resource.
	 */
	public const RESET_LIMIT = 3;

	/**
	 * The timestamps within a window.
	 *
	 * @param array<int, mixed> $stamps Timestamps.
	 * @param int               $window Seconds.
	 * @return int[]
	 */
	private static function recent( array $stamps, int $window ): array {
		return array_values( array_filter( array_map( 'intval', $stamps ), static fn( $t ) => $t > 0 && time() - $t < $window ) );
	}

	/**
	 * Cluster settings (token secret decrypted).
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Hooks the early refusal of the customer route.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_action_refusal', array( __CLASS__, 'refusal' ), 10, 4 );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 2 );
	}

	// -- What is offered -------------------------------------------------------------------------

	/**
	 * The actions offered now. The power state comes from the status kept by the last refresh (no
	 * Proxmox call): a VM known to run offers shutdown and reboot, one known to be off offers start,
	 * an unknown state offers all three (each re-reads the VM and refuses what does not apply).
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		$status = Operations::cached_status( $r->id() );
		$power  = null === $status ? '' : (string) $status['power'];
		$out    = array();

		if ( 'running' !== $power ) {
			$out['start'] = __( 'Start', 'beltoft-services' );
		}

		if ( 'stopped' !== $power ) {
			$out['shutdown'] = __( 'Shut down', 'beltoft-services' );
			$out['reboot']   = __( 'Reboot', 'beltoft-services' );
		}

		if ( self::agent_ok( $r ) ) {
			$out['reset_password'] = __( 'Reset password', 'beltoft-services' );
		}

		// An adopted machine keeps what it has: no key swap, no wipe.
		if ( true === $r->get( 'linked' ) ) {
			return $out;
		}
		$has_disks = (bool) DiskMeta::for_server( $r );

		// A restore that has begun is finished even when the plan lost its backups meanwhile.
		if ( Backups::enabled( $r ) || is_array( $r->get( 'restore' ) ) ) {
			$out['restore'] = __( 'Restore from a backup', 'beltoft-services' );
		}

		$out['replace_ssh_key'] = self::has_key( $r ) ? __( 'Replace SSH key', 'beltoft-services' ) : __( 'Add SSH key', 'beltoft-services' );
		if ( ! $has_disks ) {
			$out['reinstall'] = __( 'Reinstall', 'beltoft-services' );
		}

		return $out;
	}

	/**
	 * Is the guest agent known to work? The build records the outcome of its last ping (it only
	 * pings when the template enables the agent), so "ok" means enabled and answering. Without it a
	 * new password could only be set for a later cloud-init run, which nobody can promise.
	 *
	 * @param Resource $r Resource.
	 */
	public static function agent_ok( Resource $r ): bool {
		return 'ok' === $r->get( 'agent' );
	}

	/**
	 * Does the service have an SSH key stored?
	 *
	 * @param Resource $r Resource.
	 */
	public static function has_key( Resource $r ): bool {
		return is_string( $r->get( 'ssh_key' ) ) && '' !== $r->get( 'ssh_key' );
	}

	/**
	 * Listener of beltoft_services_account_buttons: the actions with arguments get forms, not generic buttons.
	 *
	 * @param mixed $actions  Actions so far.
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function buttons( $actions, $resource ) {
		if ( is_array( $actions ) && $resource instanceof Resource && SshKeyField::DRIVER === $resource->get( 'driver' ) ) {
			unset( $actions['replace_ssh_key'], $actions['reinstall'], $actions['restore'] );
		}

		return $actions;
	}

	// -- Checks that need no Proxmox call ----------------------------------------------------------

	/**
	 * Why an action with these arguments cannot be accepted, in words for the customer; null when
	 * it can. Used by the route (answered at once) and again by act() as a second line.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function check( Resource $r, string $action, array $args ): ?string {
		if ( 'reinstall' === $action && DiskMeta::for_server( $r ) ) {
			return __( 'Remove the extra disks before reinstalling this server.', 'beltoft-services' );
		}
		if ( true === $r->get( 'linked' ) && in_array( $action, array( 'replace_ssh_key', 'reinstall', 'restore' ), true ) ) {
			return __( 'This is not available for this server. Please contact support.', 'beltoft-services' );
		}

		if ( 'replace_ssh_key' === $action && null === SshKeyField::normalise( $args['key'] ?? null ) ) {
			return SshKeyField::invalid_message();
		}

		if ( 'reset_password' === $action ) {
			if ( ! self::agent_ok( $r ) ) {
				return __( 'Resetting the password is not available for this server.', 'beltoft-services' );
			}

			return count( self::recent( (array) $r->get( 'reset_times' ), HOUR_IN_SECONDS ) ) >= self::RESET_LIMIT ? __( 'The password can be reset three times an hour. Please try again later.', 'beltoft-services' ) : null;
		}

		if ( 'restore' === $action ) {
			return self::check_restore( $r, $args );
		}

		if ( 'reinstall' !== $action ) {
			return null;
		}

		if ( self::CONFIRM !== ( $args['confirm'] ?? '' ) ) {
			/* translators: %s: the word to type, REINSTALL. */
			return sprintf( __( 'Type %s to confirm. Reinstalling erases everything on the server.', 'beltoft-services' ), self::CONFIRM );
		}

		if ( isset( $args['os'] ) && '' !== $args['os'] ) {
			$record = VmRecords::for_resource( $r->id() );
			$list   = null === $record ? array() : Clusters::templates( $record['cluster'] );

			if ( ! ctype_digit( (string) $args['os'] ) || ! isset( $list[ (int) $args['os'] ] ) ) {
				return __( 'Choose one of the listed operating systems.', 'beltoft-services' );
			}
		}

		$last = (int) $r->get( 'last_reinstall_at' );

		if ( $last > 0 && time() - $last < self::REINSTALL_GAP ) {
			return __( 'A server can be reinstalled once an hour. Please try again later.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * The restore's checks that need no Proxmox call. That the backup is really listed for this
	 * server (and newer than its record) is checked again when the action runs.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args Arguments.
	 */
	private static function check_restore( Resource $r, array $args ): ?string {
		// A restore under way is finished whatever happened to the plan meanwhile: the server is off.
		$state   = $r->get( 'restore' );
		$running = is_array( $state ) && isset( $args['volid'] ) && ( $state['volid'] ?? '' ) === $args['volid'];

		if ( ! $running && ! Backups::enabled( $r ) ) {
			return __( 'Restoring is not available for this server.', 'beltoft-services' );
		}

		if ( self::CONFIRM_RESTORE !== ( $args['confirm'] ?? '' ) ) {
			/* translators: %s: the word to type, RESTORE. */
			return sprintf( __( 'Type %s to confirm. Restoring replaces everything on the server with the backup.', 'beltoft-services' ), self::CONFIRM_RESTORE );
		}

		if ( ! $running && ! Backups::volid_fits( $r, $args['volid'] ?? null ) ) {
			return __( 'Choose one of the listed backups.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * Listener of beltoft_services_action_refusal: bad arguments and the fixed floors, answered by the route.
	 *
	 * @param mixed $refusal  Refusal so far.
	 * @param mixed $resource Resource.
	 * @param mixed $action   Action.
	 * @param mixed $args     Arguments.
	 * @return mixed
	 */
	public static function refusal( $refusal, $resource, $action, $args ) {
		if ( null !== $refusal || ! $resource instanceof Resource || SshKeyField::DRIVER !== $resource->get( 'driver' ) || ! is_string( $action ) || ! is_array( $args ) ) {
			return $refusal;
		}

		$why = self::check( $resource, $action, $args );

		if ( null !== $why ) {
			return new \WP_Error( 'beltoft_services_refused', $why, array( 'status' => 422 ) );
		}

		if ( isset( self::FLOORS[ $action ] ) && RateLimit::exceeded( get_current_user_id(), 'beltoft-services:vm-action:' . $resource->id() . ':' . $action, self::FLOORS[ $action ][0], self::FLOORS[ $action ][1] ) ) {
			return new \WP_Error( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a while and try again.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		return null;
	}

	// -- Run -------------------------------------------------------------------------------------

	/**
	 * Runs one action (called by ProxmoxDriver::act() inside Jobs::act()'s lock).
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function run( string $action, array $args ): StepResult {
		if ( ! isset( self::FLOORS[ $action ] ) ) {
			return StepResult::fail( 'This action is not available' );
		}

		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'VM record missing; check Proxmox manually' );
		}

		$why = self::check( $this->r, $action, $args );

		// A refusal is an answer, not an error: no alert for a stray click.
		if ( null !== $why ) {
			return StepResult::done( array(), ucfirst( str_replace( '_', ' ', $action ) ) . ' refused: ' . $this->scrub( $why, 160 ) );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, $action );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		switch ( $action ) {
			case 'start':
				return $this->start( $record, $v );
			case 'shutdown':
				return $this->shutdown( $record, $v );
			case 'reboot':
				return $this->reboot( $record, $v );
			case 'reset_password':
				return $this->reset_password( $record, $v );
			case 'replace_ssh_key':
				return $this->replace_ssh_key( $record, $v, (string) $args['key'] );
			case 'restore':
				return $this->restore( $record, (string) $args['volid'] );
			default:
				return $this->reinstall( $record, $args );
		}
	}

	/**
	 * Builds the client of the record's cluster. Failures are permanent.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function prepare( array $record ): ?StepResult {
		$cluster = Clusters::get( (string) $record['cluster'] );

		if ( null === $cluster ) {
			return StepResult::fail( 'The cluster of this VM is not configured' );
		}

		$this->cluster = $cluster;
		$this->secrets = array( (string) $cluster['token_secret'] );

		$gate = Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() );

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

	/**
	 * The VM's path.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private static function base( array $record, array $v ): string {
		return '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];
	}

	/**
	 * Start (only a VM that is off).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function start( array $record, array $v ): StepResult {
		if ( 'running' === $v['status'] ) {
			Operations::remember_power( $this->r->id(), 'running' );
			$this->r->set( array( 'stopped_by_customer' => null ) );

			return StepResult::done( array(), 'Start: the server was already running' );
		}

		$done = $this->run_task( 'act', 'POST', self::base( $record, $v ) . '/status/start', array(), 'start' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$again = $this->verified( $record, 'start' );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		if ( 'running' !== $again['status'] ) {
			return StepResult::retry( 0, 'start: the start task did not leave the VM running (' . ( true === $done ? 'OK' : $done['failed'] ) . ')' );
		}

		Operations::remember_power( $this->r->id(), 'running' );
		$this->r->set( array( 'stopped_by_customer' => null ) );

		return StepResult::done( array(), 'Customer started the server' );
	}

	/**
	 * Graceful shutdown (ACPI). A system that does not power off in time is left running: a plug
	 * pull is the shop's decision, never a customer button.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function shutdown( array $record, array $v ): StepResult {
		if ( 'running' !== $v['status'] ) {
			Operations::remember_power( $this->r->id(), 'stopped' );
			$this->r->set( array( 'stopped_by_customer' => time() ) );

			return StepResult::done( array(), 'Shutdown: the server was already off' );
		}

		$done = $this->run_task( 'act', 'POST', self::base( $record, $v ) . '/status/shutdown', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'shutdown' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$again = $this->verified( $record, 'shutdown' );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		if ( 'running' === $again['status'] ) {
			return StepResult::done( array(), 'Shutdown did not finish: the system did not power off in time; it was left running' );
		}

		Operations::remember_power( $this->r->id(), 'stopped' );
		$this->r->set( array( 'stopped_by_customer' => time() ) );

		return StepResult::done( array(), 'Customer shut the server down' );
	}

	/**
	 * Graceful reboot of a running VM.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function reboot( array $record, array $v ): StepResult {
		if ( 'running' !== $v['status'] ) {
			Operations::remember_power( $this->r->id(), 'stopped' );

			return StepResult::done( array(), 'Reboot refused: the server is not running' );
		}
		$this->r->set( array( 'stopped_by_customer' => time() ) );

		$done = $this->run_task( 'act', 'POST', self::base( $record, $v ) . '/status/reboot', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'reboot' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		if ( true !== $done ) {
			return StepResult::done( array(), 'Reboot did not finish: the system did not restart in time' );
		}

		return StepResult::done( array(), 'Customer restarted the server' );
	}

	/**
	 * A new password: stored first, then sent as `cipassword` alone and, while the VM runs with
	 * the guest agent, to the agent. Nothing else of the cloud-init config is written.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function reset_password( array $record, array $v ): StepResult {
		// A retry of a reset in progress is not a new reset; a new one is counted before anything is generated.
		if ( ! $this->r->get( Access::META_RESET ) && ! $this->r->set( array( 'reset_times' => array_merge( self::recent( (array) $this->r->get( 'reset_times' ), HOUR_IN_SECONDS ), array( time() ) ) ) ) ) {
			return StepResult::retry( 0, 'reset_password: the request could not be stored' );
		}

		try {
			$password = Access::reset_password( $this->r );
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 0, 'reset_password: the new password could not be stored' );
		}

		$this->secrets[] = $password;
		$base            = self::base( $record, $v );

		$answer = $this->client->request( 'PUT', $base . '/config', array( 'cipassword' => $password ) );

		if ( is_wp_error( $answer ) ) {
			$result = $this->classify( $answer, 'reset_password' );

			// Refused for good: the customer keeps the password they had not read yet.
			if ( $result->is_failed() ) {
				Access::abort_reset( $this->r );
			}

			return $result;
		}

		$agent = 'running' === $v['status'] && Provision::agent_enabled( (string) ( $v['config']['agent'] ?? '' ) );

		if ( $agent ) {
			$set = $this->client->request(
				'POST',
				$base . '/agent/set-user-password',
				array(
					'username' => Provision::LOGIN_USER,
					'password' => $password,
				)
			);

			if ( is_wp_error( $set ) ) {
				return StepResult::retry( 0, 'reset_password: the guest agent did not take the password: ' . $this->text( $set ) );
			}
		}

		if ( ! Access::finish_reset( $this->r ) ) {
			return StepResult::retry( 0, 'reset_password: the result could not be stored' );
		}

		return StepResult::done( array(), $agent ? 'Customer reset the server password' : 'Customer reset the server password; it applies when the server next runs cloud-init' );
	}

	/**
	 * Adds or replaces the SSH key. Stored first (a reinstall then uses it too), then written.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $key    The key as sent (checked again here).
	 */
	private function replace_ssh_key( array $record, array $v, string $key ): StepResult {
		$clean = SshKeyField::normalise( $key );

		if ( null === $clean ) {
			return StepResult::done( array(), 'SSH key change refused: the key is not valid' );
		}

		if ( ! $this->r->set( array( 'ssh_key' => $clean ) ) ) {
			return StepResult::retry( 0, 'replace_ssh_key: the key could not be stored' );
		}

		$base = self::base( $record, $v );

		// The raw key: the client URL-encodes the sshkeys value itself, once. This is what a
		// reinstall or a regenerated cloud-init drive uses from now on.
		$answer = $this->client->request( 'PUT', $base . '/config', array( 'sshkeys' => $clean . "\n" ) );

		if ( is_wp_error( $answer ) ) {
			return $this->classify( $answer, 'replace_ssh_key' );
		}

		// Cloud-init only appends keys, and only at the next boot. A running VM with the guest
		// agent gets the file replaced now, so the new key works at once and the old one stops.
		if ( 'running' !== $v['status'] || ! Provision::agent_enabled( (string) ( $v['config']['agent'] ?? '' ) ) ) {
			return StepResult::done( array(), 'Customer changed the SSH key; it applies when the server next starts, and the old key keeps working until the server is reinstalled' );
		}

		$written = $this->client->request(
			'POST',
			$base . '/agent/file-write',
			array(
				'file'    => self::AUTHORIZED_KEYS,
				'content' => $clean . "\n",
			)
		);

		if ( is_wp_error( $written ) ) {
			$data   = $written->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

			// No right to write through the agent: retrying cannot help, the owner must grant it.
			if ( 403 === $status ) {
				$text = 'Replace SSH key: the Proxmox token may not write files through the guest agent, so the new key is only in the cloud-init settings (it is added at the next boot and the old key keeps working). Grant the token the privilege VM.GuestAgent.FileWrite (or VM.GuestAgent.Unrestricted) on the pool of the customer VMs.';
				Alerts::admin( $this->r, $text );

				return StepResult::done( array(), 'Customer changed the SSH key; ' . $text );
			}

			return StepResult::retry( 0, 'replace_ssh_key: the guest agent did not take the key: ' . $this->text( $written ) );
		}

		return StepResult::done( array(), 'Customer changed the SSH key' );
	}

	/**
	 * Bookkeeping only: the provision's `wipe` step does the destroying, with retries and
	 * ownership checks, on the same VMID and address, with a new password.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $args   Arguments.
	 */
	private function reinstall( array $record, array $args ): StepResult {
		$meta = array(
			'wipe'              => true,
			'steps'             => array_values( array_intersect( (array) $this->r->get( 'steps' ), array( 'ip', 'vmid' ) ) ),
			'task'              => null,
			'agent'             => null,
			'configured'        => array(),
			// A new password: the old one is gone, and the build stores a fresh one before sending it.
			Access::META_REVEAL => '',
			Access::META_UNTIL  => 0,
			Access::META_RESET  => null,
			Access::META_PREV   => null,
			'last_reinstall_at' => time(),
		);

		if ( isset( $args['os'] ) && '' !== $args['os'] ) {
			$meta[ OsField::META ] = (int) $args['os'];
		}

		if ( ! $this->r->set( $meta ) ) {
			return StepResult::retry( 0, 'reinstall: the request could not be stored' );
		}

		if ( ! $this->r->transition( 'provisioning', 'Reinstall started by the customer' ) ) {
			return StepResult::retry( 0, 'reinstall: the state could not be stored' );
		}

		Jobs::enqueue( $this->r->id() );

		return StepResult::done();
	}

	/**
	 * Restores the whole server to one backup: off, restored over the same VMID (`force`), the
	 * tag checked in the restored config, the plan re-applied (the backup may predate an upgrade:
	 * cores, memory and a larger disk come back, a disk is never shrunk), and the VM, still off, is
	 * started by that plan change (meta was_running), once. Every round
	 * starts from the remote state and the marker in meta `restore` ({volid, phase, ctime}), so
	 * a retry never restores twice. The customer is told that the password and the SSH key are
	 * the backup's. A failed restore may have destroyed the VM: it is left as it is and the
	 * admin is alerted.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param string               $volid  Backup volume id (shape already checked).
	 */
	private function restore( array $record, string $volid ): StepResult {
		$state = $this->r->get( 'restore' );
		$state = is_array( $state ) && ( $state['volid'] ?? '' ) === $volid ? $state : array();
		$phase = (string) ( $state['phase'] ?? '' );

		// The restore task of an earlier round has ended: that decides, not another restore.
		if ( '' === $phase && $this->finished && 'restore' === $this->finished['phase'] ) {
			if ( ! $this->finished['ok'] && ! self::accepted_exit( (string) $this->finished['exit'] ) ) {
				return $this->restore_failed( (string) $this->finished['exit'] );
			}

			// The backup's time was not kept while the task ran: read it from the listing again.
			$ctime = 0;
			$list  = ( new Backups( $this->r ) )->list();

			foreach ( is_array( $list ) ? $list : array() as $backup ) {
				if ( $backup['volid'] === $volid ) {
					$ctime = (int) $backup['ctime'];
				}
			}

			$state = array(
				'volid' => $volid,
				'ctime' => $ctime,
			);
			$phase = 'task';
		}

		if ( '' === $phase ) {
			$ran = $this->restore_run( $record, $volid );

			if ( $ran instanceof StepResult ) {
				return $ran;
			}

			$state = array(
				'volid' => $volid,
				'ctime' => $ran,
			);
			$phase = 'task';
		}

		if ( 'task' === $phase ) {
			$state['phase'] = 'restored';

			if ( ! $this->r->set( array( 'restore' => $state ) ) ) {
				return StepResult::retry( 0, 'restore: progress could not be stored' );
			}

			$phase = 'restored';
		}

		if ( 'restored' === $phase ) {
			$planned = $this->restore_plan( $record );

			if ( null !== $planned ) {
				return $planned;
			}

			$state['phase'] = 'planned';

			if ( ! $this->r->set( array( 'restore' => $state ) ) ) {
				return StepResult::retry( 0, 'restore: progress could not be stored' );
			}
		}

		return StepResult::done(
			array( 'restore' => null ),
			(int) ( $state['ctime'] ?? 0 ) > 0 ? 'Server restored from the backup of ' . gmdate( 'Y-m-d H:i', (int) $state['ctime'] ) . ' UTC' : 'Server restored from a backup'
		);
	}

	/**
	 * The first half: the backup is listed for this server, the VM is off, the restore task ran.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param string               $volid  Backup volume id.
	 * @return int|StepResult The backup's time, or the round's answer.
	 */
	private function restore_run( array $record, string $volid ) {
		$list = ( new Backups( $this->r ) )->list();

		if ( is_wp_error( $list ) ) {
			return StepResult::retry( 0, 'restore: ' . $this->scrub( $list->get_error_message(), 200 ) );
		}

		$ctime = null;

		foreach ( $list as $backup ) {
			if ( $backup['volid'] === $volid ) {
				$ctime = (int) $backup['ctime'];
			}
		}

		if ( null === $ctime ) {
			return StepResult::done( array( 'restore' => null ), 'Restore refused: that backup is not available for this server' );
		}

		// A backup in progress holds the VM (lock=backup): wait for it before anything is switched off.
		$running = ( new Backups( $this->r ) )->collect_running();

		if ( null !== $running ) {
			return StepResult::retry( 0, 'restore: ' . $running->error() );
		}

		$locked = $this->verified( $record, 'restore' );

		if ( $locked instanceof StepResult ) {
			return $locked;
		}

		if ( '' !== (string) $locked['lock'] ) {
			return StepResult::retry( 0, 'restore: the server is locked (' . sanitize_key( (string) $locked['lock'] ) . ')' );
		}

		$off = ( new Operations( $this->r ) )->pause();

		if ( ! $off->is_done() ) {
			return $off;
		}

		$v = $this->verified( $record, 'restore' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' === $v['status'] ) {
			return StepResult::retry( 0, 'restore: the server did not switch off' );
		}

		$done = $this->run_task(
			'act',
			'POST',
			'/nodes/' . rawurlencode( $v['node'] ) . '/qemu',
			array(
				'vmid'    => $record['vmid'],
				'archive' => $volid,
				'force'   => 1,
				'storage' => (string) $this->cluster['storage'],
			),
			'restore'
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		return true === $done || self::accepted_exit( $done['failed'] ) ? $ctime : $this->restore_failed( $done['failed'] );
	}

	/**
	 * Did the task end with warnings only?
	 *
	 * @param string $exit Exit status.
	 */
	private static function accepted_exit( string $exit ): bool {
		return 0 === strpos( $exit, 'WARNINGS' );
	}

	/**
	 * A restore that ended badly.
	 *
	 * @param string $exit Exit status (admin text).
	 */
	private function restore_failed( string $exit ): StepResult {
		$this->tell_customer();

		return StepResult::fail( 'Restore failed (' . $this->scrub( $exit, 160 ) . '); the server was left switched off and may need a manual check', array( 'restore' => null ) );
	}

	/**
	 * A restore ended badly: the customer gets one short, fixed note (no Proxmox text). The server may
	 * be off and the admin has been alerted.
	 */
	private function tell_customer(): void {
		$to = is_email( (string) ( $this->r->license()['email'] ?? '' ) ) ? (string) $this->r->license()['email'] : '';

		if ( '' === $to ) {
			return;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		wp_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( '[%s] Your server restore did not finish', 'beltoft-services' ), $site ),
			__( 'The restore of your server did not finish. We have been told and will look into it. You do not need to do anything. If the server stays off, please contact support.', 'beltoft-services' )
		);
	}

	/**
	 * The second half: the tag is in the restored config, the disk size is recorded, and the plan
	 * is queued again. Null: done.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function restore_plan( array $record ): ?StepResult {
		$pool = $this->restore_pool( $record );

		if ( null !== $pool ) {
			return $pool;
		}

		$v = $this->verified( $record, 'restore' );

		if ( $v instanceof StepResult ) {
			if ( $v->is_failed() ) {
				$this->tell_customer();
			}

			return $v->is_failed() ? StepResult::fail( $v->error() . '; the restored server could not be proved ours', array( 'restore' => null ) ) : $v;
		}

		$size = Provision::gigabytes( (string) ( $v['config'][ (string) $this->cluster['boot_disk'] ] ?? '' ) );

		if ( null === $size ) {
			return StepResult::fail( 'Restore: the boot disk was not found on the restored server', array( 'restore' => null ) );
		}

		// The queued plan change owns the one start (was_running): it applies the plan to the stopped VM and starts it, so a VM that has only just booted is never shut down again.
		$disks = $this->restore_disks();

		if ( null !== $disks ) {
			return $disks;
		}

		if ( ! $this->r->set( array( 'disk_gb' => (int) ceil( $size ), 'was_running' => true ) ) ) {
			return StepResult::retry( 0, 'restore: progress could not be stored' );
		}

		$stored = $this->r->change_plan( (array) $this->r->get( 'plan' ), 'Plan re-applied after restore' );

		if ( true !== $stored ) {
			return StepResult::retry( 0, 'restore: the plan could not be queued again' );
		}

		Jobs::enqueue( $this->r->id() );

		return null;
	}

	/**
	 * The extra disks are not part of the backup: a forced restore keeps their volumes as unusedN entries.
	 * Each one that was attached goes back to its own slot, while the server is still off. One that cannot
	 * (volume missing, slot taken) is failed with one admin alert and left alone; the restore goes on.
	 * Null: done.
	 */
	private function restore_disks(): ?StepResult {
		foreach ( DiskMeta::for_server( $this->r ) as $disk ) {
			$result = ( new DiskSteps( $disk ) )->reattach_after_restore( $this->r );

			if ( $result->is_done() ) {
				continue;
			}

			if ( ! $result->is_failed() ) {
				return $result;
			}

			$disk->set( array( 'last_error' => $result->error() ) );
			$disk->transition( 'failed', 'Extra disk could not be attached again after the restore: ' . $result->error() );
			Alerts::admin( $disk, 'After the restore of the server the extra disk could not be attached again: ' . $result->error() . ' The volume was left alone.' );
		}

		return null;
	}

	/**
	 * A forced restore recreates the VM, and whether it stays in the cluster's pool is not known
	 * for sure. The token sees only the pool, so a VM that left it would vanish for the plugin: the
	 * membership is read and the VM put back when it is missing. Null: it is in the pool (or the
	 * cluster has none).
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function restore_pool( array $record ): ?StepResult {
		$pool = (string) $this->cluster['pool'];

		if ( '' === $pool ) {
			return null;
		}

		$by_id = 'pools_id' === $this->cluster['pool_api'];
		$read  = $this->client->request( 'GET', $by_id ? '/pools/' . rawurlencode( $pool ) : '/pools', $by_id ? array() : array( 'poolid' => $pool ) );

		if ( is_wp_error( $read ) ) {
			return $this->classify( $read, 'restore' );
		}

		$members = array();

		// PVE answers the pool itself (pools_id) or a list of pools (pools).
		foreach ( is_array( $read ) ? ( isset( $read['members'] ) ? array( $read ) : $read ) : array() as $one ) {
			if ( is_array( $one ) && ( $by_id || ( $one['poolid'] ?? $pool ) === $pool ) ) {
				foreach ( (array) ( $one['members'] ?? array() ) as $member ) {
					$members[] = is_array( $member ) ? (int) ( $member['vmid'] ?? 0 ) : (int) $member;
				}
			}
		}

		if ( in_array( (int) $record['vmid'], $members, true ) ) {
			return null;
		}

		$put = $this->client->request(
			'PUT',
			$by_id ? '/pools/' . rawurlencode( $pool ) : '/pools',
			$by_id ? array( 'vms' => (string) $record['vmid'] ) : array(
				'poolid' => $pool,
				'vms'    => (string) $record['vmid'],
			)
		);

		if ( is_wp_error( $put ) ) {
			$text = 'Restore: the server could not be put back into the pool "' . $pool . '": ' . $this->text( $put );
			Alerts::admin( $this->r, $text );
			$this->tell_customer();

			return StepResult::fail( $text, array( 'restore' => null ) );
		}

		return null;
	}
}
