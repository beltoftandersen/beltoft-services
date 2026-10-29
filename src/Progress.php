<?php
/**
 * The progress of what a service is doing now, as data a page can draw.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftServices\Proxmox\DiskDriver;
use BeltoftServices\Proxmox\SshKeyField;

defined( 'ABSPATH' ) || exit;

/**
 * One builder, no route: build() takes a resource and returns a plain array that holds only
 * fixed texts (never last_error, an address or a password). The account route and any order page
 * route call it the same way, and assets/progress.js draws it.
 *
 * Payload keys: kind, phase (idle, queued, running, failed), active, title, steps (list of
 * key, label, status done|current|todo|failed, status_label), current (step key or ''), minutes, eta, text,
 * leave (a sentence that says the customer may go away while it runs, empty unless active), started (unix time or 0).
 */
final class Progress {

	/**
	 * Meta key of the marker a customer action leaves between the request and the job.
	 */
	public const MARKER = 'action_pending';

	/**
	 * Seconds a marker is believed.
	 */
	public const MARKER_TTL = 900;

	/**
	 * Seconds after a rollback during which a pending plan change still shows as part of it.
	 */
	public const ROLLBACK_PLAN_WINDOW = 6 * HOUR_IN_SECONDS;

	/**
	 * Notes that a customer asked for an action, before its job is queued.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action name.
	 * @param string   $label  The action's label for the customer (what the card calls it).
	 */
	public static function mark( Resource $r, string $action, string $label = '' ): void {
		if ( '' !== $action ) {
			$r->set( array( self::MARKER => array( 'action' => $action, 'at' => time(), 'label' => $label ) ) );
		}
	}

	/**
	 * Removes the marker (the job ran, or was dropped).
	 *
	 * @param Resource $r Resource.
	 */
	public static function clear( Resource $r ): void {
		if ( null !== $r->get( self::MARKER ) ) {
			$r->set( array( self::MARKER => null ) );
		}
	}

	/**
	 * The marker, unless it is older than MARKER_TTL and no job of the service is waiting or
	 * running (a job that waits or retries keeps it alive; only a vanished job lets it lapse).
	 *
	 * @param Resource $r Resource.
	 * @return array{action: string, at: int, label: string}|null
	 */
	public static function pending( Resource $r ): ?array {
		$marker = $r->get( self::MARKER );

		if ( ! is_array( $marker ) || ! isset( $marker['action'], $marker['at'] ) || '' === (string) $marker['action'] ) {
			return null;
		}

		if ( (int) $marker['at'] < time() - self::MARKER_TTL && ! Jobs::acting( $r->id() ) ) {
			return null;
		}

		return array( 'action' => (string) $marker['action'], 'at' => (int) $marker['at'], 'label' => (string) ( $marker['label'] ?? '' ) );
	}

	/**
	 * Steps of a build, by driver id: key => label. A driver without an entry has no step list.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function build_steps(): array {
		return array(
			SshKeyField::DRIVER => array(
				'ip'        => __( 'Reserve an IP address', 'beltoft-services' ),
				'vmid'      => __( 'Prepare the server', 'beltoft-services' ),
				'clone'     => __( 'Create the server', 'beltoft-services' ),
				'configure' => __( 'Apply your settings', 'beltoft-services' ),
				'start'     => __( 'Start the server', 'beltoft-services' ),
				'ready'     => __( 'Final checks', 'beltoft-services' ),
			),
			DiskDriver::ID      => array(
				'server'   => __( 'Find the server', 'beltoft-services' ),
				'slot'     => __( 'Choose a slot', 'beltoft-services' ),
				'allocate' => __( 'Create the disk', 'beltoft-services' ),
				'attach'   => __( 'Attach it to the server', 'beltoft-services' ),
				'verify'   => __( 'Final checks', 'beltoft-services' ),
			),
			'domain'            => array(
				'contact'       => __( 'Save the owner details', 'beltoft-services' ),
				'zone_prepared' => __( 'Prepare the DNS', 'beltoft-services' ),
				'order'         => __( 'Send the order to the registry', 'beltoft-services' ),
				'active'        => __( 'Wait for the registry', 'beltoft-services' ),
				'zone'          => __( 'Set up the DNS', 'beltoft-services' ),
				'dates'         => __( 'Final checks', 'beltoft-services' ),
			),
			'dns'               => array(
				'claim'      => __( 'Reserve the domain', 'beltoft-services' ),
				'zone'       => __( 'Create the DNS zone', 'beltoft-services' ),
				'delegation' => __( 'Check the name servers', 'beltoft-services' ),
			),
		);
	}

	/**
	 * The steps of a server reinstall and restore.
	 *
	 * @param string $kind reinstall, restore or rollback.
	 * @return array<string, string>
	 */
	private static function server_steps( string $kind ): array {
		if ( 'rollback' === $kind ) {
			return array(
				'rollback' => __( 'Go back to the snapshot', 'beltoft-services' ),
				'plan'     => __( 'Apply your plan', 'beltoft-services' ),
				'start'    => __( 'Start the server', 'beltoft-services' ),
			);
		}

		if ( 'restore' === $kind ) {
			return array(
				'restore' => __( 'Restore the backup', 'beltoft-services' ),
				'plan'    => __( 'Apply your plan', 'beltoft-services' ),
				'start'   => __( 'Start the server', 'beltoft-services' ),
			);
		}

		return array(
			'wipe'      => __( 'Erase the old system', 'beltoft-services' ),
			'clone'     => __( 'Install the system', 'beltoft-services' ),
			'configure' => __( 'Apply your settings', 'beltoft-services' ),
			'start'     => __( 'Start the server', 'beltoft-services' ),
			'ready'     => __( 'Final checks', 'beltoft-services' ),
		);
	}

	/**
	 * The title of a kind of work.
	 *
	 * @param string $kind   Kind.
	 * @param string $driver Driver id.
	 */
	private static function title( string $kind, string $driver, ?array $marker = null ): string {
		$disk   = DiskDriver::ID === $driver;
		$server = SshKeyField::DRIVER === $driver;

		switch ( $kind ) {
			case 'reinstall':
				return __( 'Reinstalling your server', 'beltoft-services' );
			case 'restore':
				return __( 'Restoring your server from a backup', 'beltoft-services' );
			case 'rollback':
				return __( 'Going back to your snapshot', 'beltoft-services' );
			case 'delete':
				return __( 'Removing your service', 'beltoft-services' );
			case 'update':
				return $disk ? __( 'Making your disk larger', 'beltoft-services' ) : __( 'Applying your change', 'beltoft-services' );
			case 'pause':
				return $disk ? __( 'Detaching your disk', 'beltoft-services' ) : __( 'Pausing your service', 'beltoft-services' );
			case 'resume':
				return __( 'Starting your service again', 'beltoft-services' );
			case 'action':
				return $marker && '' !== $marker['label'] ? $marker['label'] : __( 'Working on your request', 'beltoft-services' );
			default:
				if ( $disk ) {
					return __( 'Adding your disk', 'beltoft-services' );
				}

				return $server ? __( 'Setting up your server', 'beltoft-services' ) : __( 'Setting up your service', 'beltoft-services' );
		}
	}

	/**
	 * Typical minutes of a kind of work, before the filter.
	 *
	 * @param string $kind   Kind.
	 * @param string $driver Driver id.
	 */
	private static function minutes( string $kind, string $driver ): int {
		$defaults = array(
			'build'     => 3,
			'reinstall' => 3,
			'restore'   => 10,
			'rollback'  => 5,
			'delete'    => 1,
			'update'    => 2,
			'pause'     => 1,
			'resume'    => 1,
			'action'    => 1,
		);
		$minutes  = $defaults[ $kind ] ?? 2;

		if ( 'domain' === $driver && 'build' === $kind ) {
			$minutes = 5;
		}

		/**
		 * Typical minutes a kind of work takes, shown as "usually about N minutes".
		 *
		 * @param int    $minutes Minutes.
		 * @param string $kind    build, reinstall, restore, delete, update, pause or resume.
		 * @param string $driver  Driver id.
		 */
		return max( 1, (int) apply_filters( 'beltoft_services_progress_minutes', $minutes, $kind, $driver ) );
	}

	/**
	 * What the resource is doing now, with its steps.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function build( Resource $r ): array {
		$state   = (string) $r->get( 'state' );
		$desired = (string) $r->get( 'desired' );
		$driver  = (string) $r->get( 'driver' );
		$marker  = self::pending( $r );
		$kind    = '';
		$phase   = 'running';

		if ( 'failed' === $state ) {
			$kind  = self::kind_of_operation( (string) $r->get( 'failed_op' ), $r );
			$phase = '' === $kind ? 'idle' : 'failed';
		} elseif ( 'deleted' === $state ) {
			$phase = 'idle';
		} elseif ( 'deleted' === $desired || 'deleting' === $state ) {
			$kind = 'delete';
		} elseif ( 'paused' === $desired && 'paused' !== $state ) {
			$kind = 'pause';
		} elseif ( in_array( $state, array( 'requested', 'provisioning' ), true ) ) {
			$kind  = self::is_reinstall( $r ) ? 'reinstall' : 'build';
			$phase = 'requested' === $state && ! $r->get( 'steps' ) ? 'queued' : 'running';
		} elseif ( 'paused' === $state && 'ready' === $desired ) {
			$kind = 'resume';
		} elseif ( is_array( $r->get( 'restore' ) ) ) {
			$kind = 'restore';
		} elseif ( is_array( $r->get( 'snapshot_rollback' ) ) || ( $r->get( 'plan_dirty' ) && (int) $r->get( 'snapshot_rolled_at' ) > time() - self::ROLLBACK_PLAN_WINDOW ) ) {
			// The marker is gone once the plan is queued; the plan change after a rollback is still the rollback's.
			$kind = 'rollback';
		} elseif ( $r->get( 'plan_dirty' ) ) {
			$kind = 'update';
		} elseif ( $marker ) {
			$kinds = array( 'reinstall' => 'reinstall', 'restore' => 'restore', 'snapshot_rollback' => 'rollback' );
			$kind  = $kinds[ $marker['action'] ] ?? 'action';
			$phase = 'queued';
		}

		if ( '' === $kind ) {
			$phase = 'idle';
		}

		$steps   = self::steps( $r, $kind, $driver, $phase );
		$current = '';

		foreach ( $steps as $step ) {
			if ( in_array( $step['status'], array( 'current', 'failed' ), true ) ) {
				$current = $step['key'];
				break;
			}
		}

		$minutes = '' === $kind ? 0 : self::minutes( $kind, $driver );
		$eta     = $minutes ? sprintf(
			/* translators: %d: minutes. */
			_n( 'Usually about %d minute.', 'Usually about %d minutes.', $minutes, 'beltoft-services' ),
			$minutes
		) : '';

		return array(
			'kind'    => $kind,
			'phase'   => $phase,
			'active'  => in_array( $phase, array( 'queued', 'running' ), true ),
			'title'   => '' === $kind ? '' : self::title( $kind, $driver, $marker ),
			'steps'   => $steps,
			'current' => $current,
			'minutes' => $minutes,
			'eta'     => $eta,
			'text'    => self::text( $kind, $phase, $marker, $eta, $driver ),
			'leave'   => in_array( $phase, array( 'queued', 'running' ), true ) ? self::leave() : '',
			'started' => self::started( $r, $kind, $marker ),
		);
	}

	/**
	 * Is a provision a reinstall? The wipe marker is gone once the old system is erased, but a
	 * server only provisions again after it has been reinstalled (its first build never set
	 * last_reinstall_at).
	 *
	 * @param Resource $r Resource.
	 */
	private static function is_reinstall( Resource $r ): bool {
		return (bool) $r->get( 'wipe' ) || (int) $r->get( 'last_reinstall_at' ) > 0;
	}

	/**
	 * The kind of work a failed operation was.
	 *
	 * @param string   $operation Reconciler operation.
	 * @param Resource $r         Resource.
	 */
	private static function kind_of_operation( string $operation, Resource $r ): string {
		switch ( $operation ) {
			case 'provision':
				return self::is_reinstall( $r ) ? 'reinstall' : 'build';
			case 'update_plan':
				return 'update';
			case 'delete':
				return 'delete';
			case 'pause':
				return 'pause';
			case 'resume':
				return 'resume';
			default:
				return '';
		}
	}

	/**
	 * The sentence a screen reader hears and the card shows under the title.
	 *
	 * @param string                            $kind   Kind.
	 * @param string                            $phase  Phase.
	 * @param array{action: string, at: int, label: string}|null $marker Marker.
	 * @param string                            $eta    Typical duration sentence.
	 * @param string                            $driver Driver id.
	 */
	private static function text( string $kind, string $phase, ?array $marker, string $eta, string $driver ): string {
		if ( 'idle' === $phase ) {
			return '';
		}

		if ( 'failed' === $phase ) {
			// The card's own message above already says what to do; never the error itself.
			return __( 'This stopped before it was finished.', 'beltoft-services' );
		}

		if ( 'queued' === $phase ) {
			if ( 'reinstall' === $kind && $marker ) {
				return __( 'Reinstall requested. It starts in a moment.', 'beltoft-services' );
			}

			if ( 'restore' === $kind && $marker ) {
				return __( 'Restore requested. It starts in a moment.', 'beltoft-services' );
			}

			if ( 'rollback' === $kind && $marker ) {
				return __( 'Going back to the snapshot was requested. It starts in a moment.', 'beltoft-services' );
			}

			if ( 'action' === $kind && $marker && '' !== $marker['label'] ) {
				/* translators: %s: what the customer asked for, such as "Replace SSH key". */
				return sprintf( __( '%s requested. It starts in a moment.', 'beltoft-services' ), $marker['label'] );
			}

			if ( 'action' === $kind ) {
				return __( 'Your request is on its way. This can take a minute.', 'beltoft-services' );
			}

			return __( 'Waiting to start. It begins in a moment.', 'beltoft-services' );
		}

		return trim( self::title( $kind, $driver, $marker ) . '. ' . $eta );
	}

	/**
	 * The line under the steps of work in progress: the customer does not have to wait here.
	 */
	public static function leave(): string {
		return __( 'You can leave this site. The work goes on, and the same progress is in your account.', 'beltoft-services' );
	}

	/**
	 * When the work began, as far as known (0 when not).
	 *
	 * @param Resource                          $r      Resource.
	 * @param string                            $kind   Kind.
	 * @param array{action: string, at: int, label: string}|null $marker Marker.
	 */
	private static function started( Resource $r, string $kind, ?array $marker ): int {
		if ( '' === $kind ) {
			return 0;
		}

		if ( 'reinstall' === $kind && (int) $r->get( 'last_reinstall_at' ) > 0 ) {
			return (int) $r->get( 'last_reinstall_at' );
		}

		if ( $marker && ( $marker['action'] === $kind || 'action' === $kind ) ) {
			return $marker['at'];
		}

		if ( 'build' === $kind ) {
			$created = (string) ( $r->license()['created_at'] ?? '' );
			$time    = '' === $created ? false : strtotime( $created . ' UTC' );

			return $time ? (int) $time : 0;
		}

		return 0;
	}

	/**
	 * The labelled steps of a kind of work with each one's status.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $kind   Kind.
	 * @param string   $driver Driver id.
	 * @param string   $phase  Phase (a failed one marks its first open step; a queued one has no current step).
	 * @return array<int, array{key: string, label: string, status: string, status_label: string}>
	 */
	private static function steps( Resource $r, string $kind, string $driver, string $phase ): array {
		$done = array();

		if ( 'reinstall' === $kind && SshKeyField::DRIVER === $driver ) {
			$labels = self::server_steps( 'reinstall' );
			$done   = array_merge( array_intersect( (array) $r->get( 'steps' ), array_keys( $labels ) ), $r->get( 'wipe' ) ? array() : array( 'wipe' ) );
		} elseif ( 'restore' === $kind && SshKeyField::DRIVER === $driver ) {
			$labels = self::server_steps( 'restore' );
			$state  = $r->get( 'restore' );
			$phase  = is_array( $state ) ? (string) ( $state['phase'] ?? '' ) : '';
			$order  = array( 'restored' => 1, 'planned' => 2 );
			$count  = $order[ $phase ] ?? 0;
			$done   = array_slice( array_keys( $labels ), 0, $count );
		} elseif ( 'rollback' === $kind && SshKeyField::DRIVER === $driver ) {
			$labels = self::server_steps( 'rollback' );
			$state  = $r->get( 'snapshot_rollback' );
			// No marker: the rollback itself is over and only its plan change is left.
			$mark   = is_array( $state ) ? (string) ( $state['phase'] ?? '' ) : 'rolled';
			$done   = array_slice( array_keys( $labels ), 0, 'rolled' === $mark ? 1 : 0 );
		} elseif ( 'build' === $kind ) {
			$labels = self::build_steps()[ $driver ] ?? array();
			$done   = array_intersect( (array) $r->get( 'steps' ), array_keys( $labels ) );
		} else {
			$labels = array();
		}

		// A request that has not started has nothing done yet, whatever an earlier build left behind.
		$done = 'queued' === $phase ? array() : $done;

		/**
		 * The labelled steps of a kind of work (key => label); an empty list shows only the title.
		 *
		 * @param array<string, string> $labels Steps.
		 * @param string                $kind   build, reinstall, restore, delete, update, pause or resume.
		 * @param string                $driver Driver id.
		 */
		$labels = apply_filters( 'beltoft_services_progress_steps', $labels, $kind, $driver );
		$labels = is_array( $labels ) ? $labels : array();
		$out    = array();
		$open   = false;

		foreach ( $labels as $key => $label ) {
			$key = (string) $key;

			if ( in_array( $key, $done, true ) ) {
				$status = 'done';
			} elseif ( ! $open && 'queued' !== $phase ) {
				$status = 'failed' === $phase ? 'failed' : 'current';
				$open   = true;
			} else {
				$status = 'todo';
			}

			$out[] = array(
				'key'          => $key,
				'label'        => (string) $label,
				'status'       => $status,
				'status_label' => self::status_label( $status ),
			);
		}

		return $out;
	}

	/**
	 * The words a screen reader hears after a step.
	 *
	 * @param string $status done, current, todo or failed.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'done'    => __( 'Done', 'beltoft-services' ),
			'current' => __( 'In progress', 'beltoft-services' ),
			'failed'  => __( 'Failed', 'beltoft-services' ),
			'todo'    => __( 'Waiting', 'beltoft-services' ),
		);

		return $labels[ $status ] ?? '';
	}

	/**
	 * Prints the progress block for a payload (also when idle: the live region must exist before
	 * its text changes). The script draws updates into it.
	 *
	 * @param array<string, mixed> $payload Result of build().
	 * @param string               $url     Optional URL the script polls itself (route independent).
	 */
	public static function render( array $payload, string $url = '' ): void {
		( static function ( array $progress, string $poll_url ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/progress.php';
		} )( $payload, $url );
	}
}
