<?php
/**
 * The customer's actions on a Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Apps\AppInstall;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Disk\DiskMeta;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\FirewallRules;
use BeltoftServices\Proxmox\Network\PrivateAttach;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Proxmox\Snapshot\Snapshots;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

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
	use Prepares;

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
		'rename'         => array( 10, HOUR_IN_SECONDS ),
		'app_retry'      => array( 3, HOUR_IN_SECONDS ),
		'snapshot_take'     => array( 5, HOUR_IN_SECONDS ),
		'snapshot_delete'   => array( 10, HOUR_IN_SECONDS ),
		'snapshot_rollback' => array( 3, HOUR_IN_SECONDS ),
		'fw_apply'          => array( 20, HOUR_IN_SECONDS ),
		'fw_reset'          => array( 5, HOUR_IN_SECONDS ),
		'private_attach'    => array( 5, HOUR_IN_SECONDS ),
		'private_detach'    => array( 5, HOUR_IN_SECONDS ),
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
	public static function recent( array $stamps, int $window ): array {
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
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 3 );
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

		// Only the App tab draws this one (see buttons()): a failed or silent install, tried again without a reinstall.
		if ( AppInstall::can_retry( $r ) ) {
			$out[ AppInstall::RETRY ] = __( 'Try again', 'beltoft-services' );
		}

		if ( self::agent_ok( $r ) ) {
			$out['reset_password'] = __( 'Reset password', 'beltoft-services' );
		}

		// An adopted machine keeps what it has: no rename, no key swap, no wipe.
		if ( true === $r->get( 'linked' ) ) {
			return $out;
		}

		$out[ ServerName::ACTION ] = __( 'Rename', 'beltoft-services' );
		$out = array_merge( $out, Snapshots::offered( $r ), FirewallRules::offered( $r ), PrivateAttach::offered( $r ) );

		// A restore that has begun is finished even when the plan lost its backups meanwhile.
		if ( Backups::enabled( $r ) || is_array( $r->get( 'restore' ) ) ) {
			$out['restore'] = __( 'Restore from a backup', 'beltoft-services' );
		}

		$out['replace_ssh_key'] = self::has_key( $r ) ? __( 'Replace SSH key', 'beltoft-services' ) : __( 'Add SSH key', 'beltoft-services' );
		$out['reinstall']       = __( 'Reinstall', 'beltoft-services' );

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
	 * @param mixed $context  card, or page for a server page (its head and Access tab print the power and password buttons).
	 * @return mixed
	 */
	public static function buttons( $actions, $resource, $context = 'card' ) {
		if ( is_array( $actions ) && $resource instanceof Resource && ProxmoxDriver::ID === $resource->get( 'driver' ) ) {
			unset( $actions[ AppInstall::RETRY ], $actions[ ServerName::ACTION ], $actions['replace_ssh_key'], $actions['reinstall'], $actions['restore'], $actions['snapshot_take'], $actions['snapshot_delete'], $actions['snapshot_rollback'], $actions['fw_apply'], $actions['fw_reset'], $actions[ PrivateAttach::ATTACH ], $actions[ PrivateAttach::DETACH ] );

			if ( 'page' === $context ) {
				unset( $actions['start'], $actions['shutdown'], $actions['reboot'], $actions['reset_password'] );
			}
		}

		return $actions;
	}

	// -- Checks that need no Proxmox call ----------------------------------------------------------

	/**
	 * Why a restore may not start because of an extra disk, in words for the customer; null when none stops it.
	 * A disk that was attached once and is detached by the subscription policy (paused) waits for payment; a
	 * disk that failed, is being deleted or is otherwise not a ready drive needs support. A forced restore may
	 * not be started over either, because their volumes are not known to survive it.
	 *
	 * @param Resource $r      Server resource.
	 * @param string   $action restore or reinstall: names the action in the text.
	 */
	public static function disk_block( Resource $r, string $action = 'restore' ): ?string {
		$attention = false;
		$policy    = false;

		foreach ( DiskMeta::for_server( $r ) as $disk ) {
			$state = $disk->get( 'state' );

			if ( 'deleted' === $state || $disk->get( 'linked' ) || ! in_array( 'attach', (array) $disk->get( 'steps' ), true ) ) {
				continue;
			}

			if ( 'paused' === $state || ( 'ready' === $state && $disk->get( 'detached' ) ) ) {
				$policy = true;
			} elseif ( 'ready' !== $state ) {
				$attention = true;
			}
		}

		if ( ! $attention && ! $policy ) {
			return null;
		}

		// Whole sentences per action, so a translation never has to join pieces.
		$texts = array(
			'rollback'  => array(
				'attention' => __( 'An extra disk on this server needs attention from support before a rollback. Contact support.', 'beltoft-services' ),
				'policy'    => __( 'This server has an extra disk that is detached because its subscription is not active. Rolling back is possible again once the disk is active or removed.', 'beltoft-services' ),
			),
			'reinstall' => array(
				'attention' => __( 'An extra disk on this server needs attention from support before a reinstall. Contact support.', 'beltoft-services' ),
				'policy'    => __( 'This server has an extra disk that is detached because its subscription is not active. Reinstall is possible again once the disk is active or removed.', 'beltoft-services' ),
			),
			'restore'   => array(
				'attention' => __( 'An extra disk on this server needs attention from support before a restore. Contact support.', 'beltoft-services' ),
				'policy'    => __( 'This server has an extra disk that is detached because its subscription is not active. Restore is possible again once the disk is active or removed.', 'beltoft-services' ),
			),
		);
		$text  = $texts[ $action ] ?? $texts['restore'];

		return $attention ? $text['attention'] : $text['policy'];
	}

	/**
	 * Does the server have an extra disk (one that is not deleted)? A reinstall then replaces only the boot disk.
	 *
	 * @param Resource $r Server resource.
	 */
	public static function has_extra_disks( Resource $r ): bool {
		foreach ( DiskMeta::for_server( $r ) as $disk ) {
			if ( 'deleted' !== $disk->get( 'state' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Why an action with these arguments cannot be accepted, in words for the customer; null when
	 * it can. Used by the route (answered at once) and again by act() as a second line.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function check( Resource $r, string $action, array $args ): ?string {
		if ( in_array( $action, Snapshots::ACTIONS, true ) ) {
			return Snapshots::check( $r, $action, $args );
		}

		if ( in_array( $action, Firewall::ACTIONS, true ) ) {
			return FirewallRules::check( $r, $action, $args );
		}

		if ( in_array( $action, PrivateAttach::ACTIONS, true ) ) {
			return PrivateAttach::check( $r, $action, $args );
		}

		if ( 'reinstall' === $action ) {
			$block = self::disk_block( $r, 'reinstall' );

			if ( null !== $block ) {
				return $block;
			}
		}
		if ( AppInstall::RETRY === $action && ! AppInstall::can_retry( $r ) ) {
			return __( 'The app cannot be tried again now.', 'beltoft-services' );
		}

		if ( 'restore' === $action ) {
			// A restore that has begun is finished whatever happens to a disk meanwhile: the server is off and must start.
			$state = $r->get( 'restore' );
			$under = is_array( $state ) && isset( $args['volid'] ) && ( $state['volid'] ?? '' ) === $args['volid'];
			$block = $under ? null : self::disk_block( $r );

			if ( null !== $block ) {
				return $block;
			}
		}
		if ( true === $r->get( 'linked' ) && in_array( $action, array( 'replace_ssh_key', 'reinstall', 'restore', ServerName::ACTION ), true ) ) {
			return __( 'This is not available for this server. Please contact support.', 'beltoft-services' );
		}

		if ( ServerName::ACTION === $action ) {
			$name = ServerName::normalise( $args['name'] ?? null );

			if ( null === $name || '' === $name ) {
				return ServerName::invalid_message();
			}
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

		/**
		 * Lets an extension refuse a reinstall for its own reason (the app choice). Return the text for the customer, or null.
		 *
		 * @param string|null          $why  Refusal so far.
		 * @param Resource             $r    Resource.
		 * @param array<string, mixed> $args Arguments.
		 */
		$why = apply_filters( 'beltoft_services_reinstall_check', null, $r, $args );

		if ( is_string( $why ) && '' !== $why ) {
			return $why;
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
		if ( null !== $refusal || ! $resource instanceof Resource || ProxmoxDriver::ID !== $resource->get( 'driver' ) || ! is_string( $action ) || ! is_array( $args ) ) {
			return $refusal;
		}

		$why = self::check( $resource, $action, $args );

		if ( null !== $why ) {
			return new \WP_Error( 'beltoft_services_refused', $why, array( 'status' => 422 ) );
		}

		if ( self::floor_exceeded( $resource, $action ) ) {
			return new \WP_Error( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a while and try again.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		return null;
	}

	/**
	 * Has the user used up the fixed floor of this action on this service? Counts the request.
	 *
	 * @param Resource $resource Resource.
	 * @param string   $action   Action.
	 */
	public static function floor_exceeded( Resource $resource, string $action ): bool {
		return isset( self::FLOORS[ $action ] ) && RateLimit::exceeded( get_current_user_id(), 'beltoft-services:vm-action:' . $resource->id() . ':' . $action, self::FLOORS[ $action ][0], self::FLOORS[ $action ][1] );
	}

	// -- Run -------------------------------------------------------------------------------------

	/**
	 * Runs one action (called by ProxmoxDriver::act() inside Jobs::act()'s lock).
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function run( string $action, array $args ): StepResult {
		$internal = in_array( $action, Snapshots::INTERNAL, true );
		$plugin   = $internal || in_array( $action, Firewall::INTERNAL, true );

		if ( ! $plugin && ! isset( self::FLOORS[ $action ] ) ) {
			return StepResult::fail( 'This action is not available' );
		}

		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'VM record missing; check Proxmox manually' );
		}

		$why = $plugin ? null : self::check( $this->r, $action, $args );

		// A refusal is an answer, not an error: no alert for a stray click.
		if ( null !== $why ) {
			return StepResult::done( array(), ucfirst( str_replace( '_', ' ', $action ) ) . ' refused: ' . $this->scrub( $why, 160 ) );
		}

		// Snapshots have their own steps (verification, task polling and all).
		if ( $internal || in_array( $action, Snapshots::ACTIONS, true ) ) {
			return ( new Snapshots( $this->r ) )->run( $action, $args );
		}

		// The firewall has its own steps (read, add, remove, policy, read back).
		if ( in_array( $action, Firewall::ACTIONS, true ) || in_array( $action, Firewall::INTERNAL, true ) ) {
			return ( new Firewall( $this->r ) )->run( $action, $args );
		}

		// The private network has its own steps (probe, NIC, guest file, one restart).
		if ( in_array( $action, PrivateAttach::ACTIONS, true ) ) {
			return ( new PrivateAttach( $this->r ) )->run( $action, $args );
		}

		// No Proxmox call and no reinstall: the install job is queued again, so REINSTALL_GAP does not apply.
		if ( AppInstall::RETRY === $action ) {
			return AppInstall::retry( $this->r );
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
				return ( new PowerActions( $this->r, $this ) )->start( $record, $v );
			case 'shutdown':
				return ( new PowerActions( $this->r, $this ) )->shutdown( $record, $v );
			case 'reboot':
				return ( new PowerActions( $this->r, $this ) )->reboot( $record, $v );
			case 'reset_password':
				return ( new AccessActions( $this->r, $this ) )->reset_password( $record, $v );
			case 'replace_ssh_key':
				return ( new AccessActions( $this->r, $this ) )->replace_ssh_key( $record, $v, (string) $args['key'] );
			case 'restore':
				return ( new Restore( $this->r, $this ) )->restore( $record, (string) $args['volid'] );
			case ServerName::ACTION:
				return ( new AccessActions( $this->r, $this ) )->rename( $record, $v, (string) $args['name'] );
			default:
				return ( new Reinstall( $this->r, $this ) )->reinstall( $record, $args );
		}
	}

	/**
	 * The VM's path.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public static function base( array $record, array $v ): string {
		return '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];
	}

	/**
	 * What run() prepared (client, cluster, secrets, task wait, the task that finished), for the Vm classes it hands
	 * the action to.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return array(
			'client'   => $this->client,
			'cluster'  => $this->cluster,
			'secrets'  => $this->secrets,
			'wait'     => $this->wait,
			'finished' => $this->finished,
		);
	}
}
