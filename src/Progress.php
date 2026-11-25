<?php
/**
 * The progress of what a service is doing now, as data a page can draw.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftServices\Drivers\ProvisionSteps;
use BeltoftServices\Drivers\Registry;

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
	 * @param array<string, mixed> $args The job's arguments (never a secret), kept so a lapsed action can still tell features what it was about.
	 */
	public static function mark( Resource $r, string $action, string $label = '', array $args = array() ): void {
		if ( '' !== $action ) {
			$r->set( array( self::MARKER => array( 'action' => $action, 'at' => time(), 'label' => $label ) + ( $args ? array( 'args' => $args ) : array() ) ) );
		}
	}

	/**
	 * The last time something worked on the marker's action: the larger of the click (`at`) and the last run of its job (`seen`,
	 * set by touch()). Liveness only; `at` stays the click time the page may show.
	 *
	 * @param mixed $marker A marker as stored.
	 */
	public static function alive( $marker ): int {
		return is_array( $marker ) ? max( (int) ( $marker['at'] ?? 0 ), (int) ( $marker['seen'] ?? 0 ) ) : 0;
	}

	/**
	 * Notes that the job of the marker's action runs now (each run of a long action, each poll, refreshes it), so a
	 * long action is not given up while it is worked on. Never changes `at`.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action the job runs.
	 */
	public static function touch( Resource $r, string $action ): void {
		$marker = $r->get( self::MARKER );

		if ( is_array( $marker ) && (string) ( $marker['action'] ?? '' ) === $action && self::alive( $marker ) < time() ) {
			$marker['seen'] = time();
			$r->set( array( self::MARKER => $marker ) );
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
	 * The marker, unless nothing worked on it for MARKER_TTL (its click or its last job run, see alive()) and no job of the service is waiting or
	 * running (a job that waits or retries keeps it alive; only a vanished job lets it lapse). A marker that lapses ends
	 * the action: JobFailures::lapsed() writes a failed result, tells staff once and lets features clean up.
	 *
	 * @param Resource $r Resource.
	 * @return array{action: string, at: int, label: string}|null
	 */
	public static function pending( Resource $r ): ?array {
		$marker = $r->get( self::MARKER );

		if ( ! is_array( $marker ) || ! isset( $marker['action'], $marker['at'] ) || '' === (string) $marker['action'] ) {
			return null;
		}

		if ( self::alive( $marker ) < time() - self::MARKER_TTL && ! Jobs::acting( $r->id() ) ) {
			JobFailures::lapsed( $r, (string) $marker['action'], (int) $marker['at'] );

			return null;
		}

		return array( 'action' => (string) $marker['action'], 'at' => (int) $marker['at'], 'label' => (string) ( $marker['label'] ?? '' ) );
	}

	/**
	 * Steps of a build, key => label: the resource's driver says what they are. A driver that does not
	 * implement ProvisionSteps (or is not registered) has no step list.
	 *
	 * @param string $driver Driver id.
	 * @return array<string, string>
	 */
	private static function build_steps( string $driver ): array {
		$found = Registry::get( $driver );

		return $found instanceof ProvisionSteps ? $found->step_labels() : array();
	}

	/**
	 * The title of a kind of work.
	 *
	 * @param string $kind   Kind.
	 * @param string $driver Driver id.
	 */
	private static function title( string $kind, string $driver, ?array $marker = null ): string {
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
				$title = __( 'Applying your change', 'beltoft-services' );
				break;
			case 'pause':
				$title = __( 'Pausing your service', 'beltoft-services' );
				break;
			case 'resume':
				return __( 'Starting your service again', 'beltoft-services' );
			case 'action':
				return $marker && '' !== $marker['label'] ? $marker['label'] : __( 'Working on your request', 'beltoft-services' );
			default:
				$title = __( 'Setting up your service', 'beltoft-services' );
		}

		/**
		 * The title of an update, a pause or a build of a kind of service (a server and a disk word these differently).
		 *
		 * @param string $title  The generic title.
		 * @param string $kind   update, pause or build (any other kind of work).
		 * @param string $driver Driver id.
		 */
		return (string) apply_filters( 'beltoft_services_progress_title', $title, $kind, $driver );
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
			'app'       => 3,
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
			/**
			 * A kind of work a service that looks idle is still doing (the app of a ready server being installed), or empty.
			 *
			 * @param string   $kind Empty by default.
			 * @param Resource $r    Resource.
			 */
			$idle_kind = (string) apply_filters( 'beltoft_services_progress_idle_kind', '', $r );

			if ( '' !== $idle_kind ) {
				$kind  = $idle_kind;
				$phase = 'running';
			}
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

		/**
		 * What a service waits for at its current step, for the customer: an array with step_label, text, leave, note, hosts, link,
		 * eta and no_eta (true: no time estimate; any of them), or empty. The domain feature answers for a transfer.
		 *
		 * @param array    $wait    Empty by default.
		 * @param Resource $r       Resource.
		 * @param string   $driver  Driver id.
		 * @param string   $current Key of the current step.
		 */
		$wait = 'build' === $kind && 'running' === $phase ? (array) apply_filters( 'beltoft_services_progress_wait', array(), $r, $driver, $current ) : array();
		$wait = $wait + array( 'step_label' => '', 'text' => '', 'leave' => '', 'note' => '', 'hosts' => array(), 'link' => array(), 'eta' => '', 'no_eta' => false, 'steps' => array() );

		// A service may show its own list of steps for the customer instead of the driver's.
		if ( $wait['steps'] ) {
			$steps   = array();
			$current = '';

			foreach ( $wait['steps'] as $step ) {
				$step['status_label'] = self::status_label( (string) $step['status'] );
				$steps[]              = $step;

				if ( '' === $current && 'current' === $step['status'] ) {
					$current = (string) $step['key'];
				}
			}
		}

		if ( '' !== $wait['step_label'] ) {
			foreach ( $steps as $i => $step ) {
				if ( $step['key'] === $current ) {
					$steps[ $i ]['label'] = $wait['step_label'];
				}
			}
		}

		$minutes = '' === $kind || '' !== $wait['eta'] || $wait['no_eta'] ? 0 : self::minutes( $kind, $driver );
		$eta     = '' !== $wait['eta'] ? $wait['eta'] : ( $minutes ? sprintf(
			/* translators: %d: minutes. */
			_n( 'Usually about %d minute.', 'Usually about %d minutes.', $minutes, 'beltoft-services' ),
			$minutes
		) : '' );

		$text  = 'running' === $phase && 'build' === $kind && \BeltoftServices\Emails\Emails::delay_running( $r ) ? __( "There's a delay setting up your service. We're on it.", 'beltoft-services' ) : ( '' !== $wait['text'] ? $wait['text'] : self::text( $kind, $phase, $marker, $eta, $driver ) );
		$title = '' === $kind ? '' : self::title( $kind, $driver, $marker );

		/**
		 * The title and the sentence under it, which a kind of work may word itself (the install of an app does).
		 *
		 * @param array{title: string, text: string} $words Title and text.
		 * @param Resource                           $r     Resource.
		 * @param string                             $kind  Kind of work, empty when idle.
		 * @param string                             $phase idle, queued, running or failed.
		 * @param string                             $eta   The "usually about N minutes" sentence, or empty.
		 */
		$words = (array) apply_filters( 'beltoft_services_progress_text', array( 'title' => $title, 'text' => $text ), $r, $kind, $phase, $eta );
		$words = array( 'title' => (string) ( $words['title'] ?? $title ), 'text' => (string) ( $words['text'] ?? $text ) );

		return array(
			'kind'    => $kind,
			'phase'   => $phase,
			'active'  => in_array( $phase, array( 'queued', 'running' ), true ),
			'title'   => $words['title'],
			'steps'   => $steps,
			'current' => $current,
			'minutes' => $minutes,
			'eta'     => $eta,
			'text'    => $words['text'],
			'leave'   => in_array( $phase, array( 'queued', 'running' ), true ) ? ( '' !== $wait['leave'] ? $wait['leave'] : self::leave() ) : '',
			'started' => self::started( $r, $kind, $marker ),
			'note'    => $wait['note'],
			'hosts'   => $wait['hosts'],
			'link'    => $wait['link'],
		);
	}

	/**
	 * How far along a payload is, in whole percent: the finished steps of all steps (assets/handoff-flow in thankyou.js
	 * counts the same way in the browser). A ready service is 100 unless it is busy; no steps is 0 (the line then moves).
	 *
	 * @param array<string, mixed> $payload Result of build().
	 * @param bool                 $ready   Whether the service is ready.
	 */
	public static function percent( array $payload, bool $ready = false ): int {
		$steps = (array) ( $payload['steps'] ?? array() );

		if ( $ready && empty( $payload['active'] ) ) {
			return 100;
		}

		// No steps: a ready service that is busy (a reboot) has no measure, so the bar moves instead of claiming 100.
		if ( ! $steps ) {
			return 0;
		}

		$done = 0;

		foreach ( $steps as $step ) {
			if ( in_array( $step['status'] ?? '', array( 'done', 'skipped' ), true ) ) {
				++$done;
			}
		}

		return (int) round( 100 * $done / count( $steps ) );
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

		if ( 'app' === $kind ) {
			return (int) $r->get( 'app_started_at' );
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

		/**
		 * The steps of a kind of work that is not a plain build: array( labels => key => label, done => keys done, phase => optional
		 * phase to judge the steps by ), or null. The server feature answers for a reinstall, a restore, a rollback and an app install.
		 *
		 * @param array|null $work   Null by default.
		 * @param Resource   $r      Resource.
		 * @param string     $kind   Kind.
		 * @param string     $driver Driver id.
		 */
		$work = apply_filters( 'beltoft_services_progress_work', null, $r, $kind, $driver );

		if ( is_array( $work ) ) {
			$labels = (array) ( $work['labels'] ?? array() );
			$done   = (array) ( $work['done'] ?? array() );
			$phase  = (string) ( $work['phase'] ?? $phase );
		} elseif ( 'build' === $kind ) {
			$labels = self::build_steps( $driver );

			/**
			 * Build steps this resource will not run (a server without IPv4 never reserves an address), so the view does not wait on them.
			 *
			 * @param string[]              $skipped Step keys, empty by default.
			 * @param Resource              $r       Resource.
			 * @param string                $kind    Kind (build).
			 * @param string                $driver  Driver id.
			 */
			$skipped = (array) apply_filters( 'beltoft_services_progress_skipped_steps', array(), $r, $kind, $driver );
			$labels  = array_diff_key( $labels, array_flip( array_map( 'strval', $skipped ) ) );
			$done    = array_values( array_intersect( (array) $r->get( 'steps' ), array_keys( $labels ) ) );

			// Safety net: a step that is not done while a later one is done was skipped, so it never blocks the view.
			$last = -1;
			$pos  = 0;

			foreach ( array_keys( $labels ) as $key ) {
				$last = in_array( (string) $key, $done, true ) ? $pos : $last;
				++$pos;
			}

			$pos = 0;

			foreach ( array_keys( $labels ) as $key ) {
				if ( $pos < $last && ! in_array( (string) $key, $done, true ) ) {
					unset( $labels[ $key ] );
				}

				++$pos;
			}
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

		/**
		 * Steps still to do after the driver's own, key => label (a server that will get an app lists the app's steps).
		 *
		 * @param array<string, string> $extra  Empty by default.
		 * @param Resource              $r      Resource.
		 * @param string                $kind   Kind.
		 * @param string                $driver Driver id.
		 */
		foreach ( (array) apply_filters( 'beltoft_services_progress_extra_steps', array(), $r, $kind, $driver ) as $key => $label ) {
			$out[] = array(
				'key'          => (string) $key,
				'label'        => (string) $label,
				'status'       => 'todo',
				'status_label' => self::status_label( 'todo' ),
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
			'skipped' => __( 'Not connected', 'beltoft-services' ),
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
