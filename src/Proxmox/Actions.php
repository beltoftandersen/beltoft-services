<?php
/**
 * The customer's actions on a Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * start, shutdown, reboot, reset_password, replace_ssh_key and reinstall. Every action runs from
 * Jobs::act() (under the resource lock, only while the resource is ready and the license active),
 * verifies the VM as ours first (Ownership::verify through Steps::verified), re-reads the remote
 * state before it acts, and is idempotent on a repeat. Texts that reach the customer are fixed
 * strings; a Proxmox error only ever goes to the admin alert, scrubbed.
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>):
 * /nodes/{node}/qemu/{vmid}/status/start, .../status/shutdown (`timeout`), .../status/reboot
 * (`timeout`), PUT .../config (`cipassword`, `sshkeys`), POST .../agent/set-user-password
 * (`username`, `password`).
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

		$out['replace_ssh_key'] = self::has_key( $r ) ? __( 'Replace SSH key', 'beltoft-services' ) : __( 'Add SSH key', 'beltoft-services' );
		$out['reinstall']      = __( 'Reinstall', 'beltoft-services' );

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
			unset( $actions['replace_ssh_key'], $actions['reinstall'] );
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
		if ( true === $r->get( 'linked' ) && in_array( $action, array( 'replace_ssh_key', 'reinstall' ), true ) ) {
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

		// The raw key: the client URL-encodes the sshkeys value itself, once.
		$answer = $this->client->request( 'PUT', self::base( $record, $v ) . '/config', array( 'sshkeys' => $clean . "\n" ) );

		if ( is_wp_error( $answer ) ) {
			return $this->classify( $answer, 'replace_ssh_key' );
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
}
