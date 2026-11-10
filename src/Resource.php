<?php
/**
 * A provisioned service resource and its state machine.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps one beltoft-licenses activation row of a non-site kind. All state lives in the
 * row's meta, so a resource is always found from its license and nothing can go missing
 * between the two.
 *
 * States: requested, provisioning, ready, paused, deleting, deleted, failed.
 * Desired states: ready, paused, deleted.
 */
final class Resource {

	public const STATES  = array( 'requested', 'provisioning', 'ready', 'paused', 'deleting', 'deleted', 'failed' );
	public const DESIRED = array( 'ready', 'paused', 'deleted' );

	/**
	 * Activation row with meta decoded.
	 *
	 * @var array<string, mixed>
	 */
	private array $row;

	/**
	 * Use create(), find() or for_license().
	 *
	 * @param array<string, mixed> $row Activation row.
	 */
	private function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * Creates the license's resource, or returns the live one it already holds (one resource
	 * per license). A live resource that is already 'deleted' counts as gone: it is closed
	 * and a new one is made. A 'uuid' in $meta becomes the identifier, otherwise one is made.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @param array<string, mixed> $plan    Plan snapshot.
	 * @param array<string, mixed> $meta    Initial meta merged over the defaults (for imports).
	 * @return Resource|\WP_Error
	 */
	public static function create( array $license, Driver $driver, array $plan, array $meta = array() ) {
		$license_id = (int) $license['id'];
		$existing   = self::reusable( $license_id );

		if ( $existing ) {
			return $existing;
		}

		$uuid = isset( $meta['uuid'] ) ? (string) $meta['uuid'] : wp_generate_uuid4();

		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,180}$/D', $uuid ) ) {
			return new \WP_Error( 'invalid', 'Invalid resource identifier' );
		}

		$meta = array_merge(
			array(
				'driver'     => $driver->id(),
				'state'      => 'requested',
				'desired'    => 'ready',
				'plan'       => $plan,
				'plan_dirty' => false,
				'steps'      => array(),
				'attempts'   => 0,
				'protected'  => false,
			),
			$meta,
			array( 'uuid' => $uuid )
		);

		if ( false === json_encode( $meta ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- strict check: wp_json_encode would repair invalid UTF-8 silently.
			return new \WP_Error( 'invalid', 'Resource data cannot be stored' );
		}

		$lock = self::lock_name( $license_id );

		/**
		 * Seconds to wait for the license lock when creating a resource.
		 *
		 * @param int $seconds Seconds.
		 */
		$timeout = max( 0, (int) apply_filters( 'beltoft_services_lock_timeout', 5 ) );

		if ( ! Locks::acquire( $lock, $timeout ) ) {
			return new \WP_Error( 'locked', 'Another request is creating this resource' );
		}

		try {
			/**
			 * Fires under the license lock, before the one-resource-per-license check is made again.
			 *
			 * @param int $license_id License id.
			 */
			do_action( 'beltoft_services_resource_locked', $license_id );

			$existing = self::reusable( $license_id );

			if ( $existing ) {
				return $existing;
			}

			$result = Licenses::add_resource( $license, $driver->kind(), $uuid, $meta );
		} finally {
			Locks::release( $lock );
		}

		if ( empty( $result['success'] ) || empty( $result['activation_id'] ) ) {
			return new \WP_Error( (string) ( $result['code'] ?? 'error' ), (string) ( $result['message'] ?? 'Resource could not be stored' ) );
		}

		$resource = self::find( (int) $result['activation_id'] );

		if ( ! $resource ) {
			return new \WP_Error( 'error', 'Resource could not be stored' );
		}

		// Another request's resource (already_active) is returned as it is.
		if ( 'activated' === $result['code'] && ( ! $resource->is_valid() || $resource->get( 'uuid' ) !== $uuid || $resource->get( 'plan' ) !== $plan ) ) {
			$resource->close();

			return new \WP_Error( 'error', 'Resource data was not stored completely' );
		}

		return $resource;
	}

	/**
	 * The license's live resource, closing it first when it is already deleted (then null).
	 *
	 * @param int $license_id License id.
	 */
	private static function reusable( int $license_id ): ?Resource {
		$existing = self::for_license( $license_id );

		if ( $existing && $existing->is_valid() && 'deleted' === $existing->get( 'state' ) ) {
			$existing->close();
			return null;
		}

		return $existing;
	}

	/**
	 * Lock name for a license.
	 *
	 * @param int $license_id License id.
	 */
	private static function lock_name( int $license_id ): string {
		return Locks::name( 'license', $license_id );
	}

	/**
	 * A resource by activation id (live or closed), or null if the row is not a resource.
	 *
	 * @param int $activation_id Activation id.
	 */
	public static function find( int $activation_id ): ?Resource {
		$row = Licenses::activation( $activation_id );

		if ( ! $row || 'site' === $row['kind'] ) {
			return null;
		}

		return new self( $row );
	}

	/**
	 * The license's live resource, or null.
	 *
	 * @param int $license_id License id.
	 */
	public static function for_license( int $license_id ): ?Resource {
		$rows = Licenses::resources( $license_id );

		return $rows ? new self( $rows[0] ) : null;
	}

	/**
	 * A state in words, translated.
	 *
	 * @param string $state State (see STATES).
	 */
	public static function state_label( string $state ): string {
		$labels = array(
			'requested'    => __( 'Requested', 'beltoft-services' ),
			'provisioning' => __( 'Being set up', 'beltoft-services' ),
			'ready'        => __( 'Ready', 'beltoft-services' ),
			'paused'       => __( 'Paused', 'beltoft-services' ),
			'deleting'     => __( 'Being removed', 'beltoft-services' ),
			'deleted'      => __( 'Removed', 'beltoft-services' ),
			'failed'       => __( 'Needs attention', 'beltoft-services' ),
		);

		return $labels[ $state ] ?? $state;
	}

	/**
	 * Ids of the live resources of a kind, oldest first unless told otherwise. The one query behind every
	 * sweep, so the licences table is read in this class only.
	 *
	 * @param string $kind  Activation kind.
	 * @param int    $after Only ids above this one (0 = all), for a paged run.
	 * @param int    $limit At most this many (0 = no limit).
	 * @param string $order 'ASC' (default) or 'DESC'.
	 * @return int[]
	 */
	public static function live_ids( string $kind, int $after = 0, int $limit = 0, string $order = 'ASC' ): array {
		return self::query_ids( $kind, array(), $after, $limit, $order );
	}

	/**
	 * Ids of the live resources of a kind whose stored meta has a key set to a value. A fast first filter in
	 * SQL: it looks at the meta text, so the caller still reads the resource and checks the value it needs.
	 * The match is exact: 12 does not find 112, and a text value is JSON-encoded and escaped as stored.
	 * Pass a list of values to find any of them.
	 *
	 * @param string                                       $kind  Activation kind.
	 * @param string                                       $key   Meta key.
	 * @param string|int|float|bool|null|array<int, mixed> $value One scalar, or a list of scalars (any of them).
	 * @param string                                       $order 'ASC' (default) or 'DESC'.
	 * @return int[]
	 */
	public static function find_by_meta( string $kind, string $key, $value, string $order = 'ASC' ): array {
		global $wpdb;

		$likes = array();

		foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
			$needle = wp_json_encode( $key ) . ':' . wp_json_encode( $one );

			if ( is_string( $one ) ) {
				// A closing quote ends the value.
				$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
			} else {
				// A number, true, false or null ends at a comma or the closing brace.
				$likes[] = '%' . $wpdb->esc_like( $needle . ',' ) . '%';
				$likes[] = '%' . $wpdb->esc_like( $needle . '}' ) . '%';
			}
		}

		return $likes ? self::query_ids( $kind, $likes, 0, 0, $order ) : array();
	}

	/**
	 * Ids of the live resources of a kind whose meta holds a list under a key (stored as `"key":[`, empty or not).
	 *
	 * @param string $kind  Activation kind.
	 * @param string $key   Meta key.
	 * @param string $order 'ASC' (default) or 'DESC'.
	 * @return int[]
	 */
	public static function find_with_list( string $kind, string $key, string $order = 'ASC' ): array {
		global $wpdb;

		return self::query_ids( $kind, array( '%' . $wpdb->esc_like( wp_json_encode( $key ) . ':[' ) . '%' ), 0, 0, $order );
	}

	/**
	 * Is any live resource (of any kind) holding a value under a key? Set means present and not null.
	 *
	 * @param string $key Meta key.
	 */
	public static function any_meta_set( string $key ): bool {
		global $wpdb;

		$needle = wp_json_encode( $key ) . ':';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE deactivated_at IS NULL AND meta LIKE %s AND meta NOT LIKE %s', \BeltoftLicenses\Install::tables()['activations'], '%' . $wpdb->esc_like( $needle ) . '%', '%' . $wpdb->esc_like( $needle . 'null' ) . '%' ) ) > 0;
	}

	/**
	 * The one select on the live activations of a kind, in one query whatever the number of resources.
	 *
	 * @param string   $kind  Activation kind.
	 * @param string[] $likes Ready LIKE patterns on the meta column; any of them (empty = no meta filter).
	 * @param int      $after Only ids above this one (0 = all).
	 * @param int      $limit At most this many (0 = no limit).
	 * @param string   $order 'ASC' or 'DESC'.
	 * @return int[]
	 */
	private static function query_ids( string $kind, array $likes, int $after, int $limit, string $order ): array {
		global $wpdb;

		$sql  = 'SELECT id FROM %i WHERE kind = %s AND deactivated_at IS NULL';
		$args = array( \BeltoftLicenses\Install::tables()['activations'], $kind );

		if ( $likes ) {
			$sql .= ' AND (' . implode( ' OR ', array_fill( 0, count( $likes ), 'meta LIKE %s' ) ) . ')';
			$args = array_merge( $args, $likes );
		}

		if ( $after > 0 ) {
			$sql   .= ' AND id > %d';
			$args[] = $after;
		}

		$sql .= 'DESC' === strtoupper( $order ) ? ' ORDER BY id DESC' : ' ORDER BY id ASC';

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, always fresh; the pieces are fixed text and placeholders.
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * The activation id.
	 */
	public function id(): int {
		return (int) $this->row['id'];
	}

	/**
	 * The license row.
	 *
	 * @return array<string, mixed>
	 */
	public function license(): array {
		$license = Licenses::find( (int) $this->row['license_id'] );

		return $license ? $license : array();
	}

	/**
	 * Does the row carry its metadata? A row without a uuid is corrupt: it reads as failed
	 * and must never be provisioned again.
	 */
	public function is_valid(): bool {
		$meta = is_array( $this->row['meta'] ?? null ) ? $this->row['meta'] : array();

		return isset( $meta['uuid'] ) && is_scalar( $meta['uuid'] ) && '' !== (string) $meta['uuid'];
	}

	/**
	 * Is the service gone: its activation closed, or its state deleted? Such a service is never listed as live.
	 * Pass the resource a lookup gave (null when the license has no live resource).
	 *
	 * @param Resource|null $resource Resource, or null when there is none.
	 */
	public static function gone( ?Resource $resource ): bool {
		// A service the customer asked to delete is gone for the lists from that moment, also while the deletion still runs:
		// its licence is revoked at once, so a row would only say "Working" until the next page load.
		return ! $resource || ! $resource->is_live() || 'deleted' === $resource->get( 'state' ) || 'deleted' === $resource->get( 'desired' );
	}

	/**
	 * Is the resource still live (not closed)?
	 */
	public function is_live(): bool {
		return ! empty( $this->row['live_domain'] );
	}

	/**
	 * May its customer see its state? While it is live, its license is active and it is
	 * meant to run: not when it is paused or on its way out. A failed resource shows (as
	 * failed), so the customer knows to get in touch.
	 */
	public function shows_status(): bool {
		if ( ! $this->is_live() || $this->on_hold() || 'ready' !== $this->get( 'desired' ) || in_array( $this->get( 'state' ), array( 'paused', 'deleting', 'deleted' ), true ) ) {
			return false;
		}

		$license = $this->license();

		return $license && Licenses::STATUS_ACTIVE === Licenses::effective_status( $license );
	}

	/**
	 * Is the service held for a breach of the terms (meta breach_at)? A held service stays
	 * paused: no license event, renewal, admin resume or customer action lifts the hold, only
	 * "Lift hold" does (Admin\Panel).
	 */
	public function on_hold(): bool {
		return null !== $this->get( 'breach_at' );
	}

	/**
	 * May its customer run actions on it now? Only when it shows its status, is ready, is valid
	 * and no other desired state is waiting to be applied.
	 */
	public function accepts_actions(): bool {
		return $this->is_valid()
			&& ! $this->on_hold()
			&& 'ready' === $this->get( 'state' )
			&& null === $this->pending_desire()
			&& $this->shows_status();
	}

	/**
	 * The domain resource this DNS hosting was folded into (0 when it was not). A folded resource
	 * is history: it sends no mail, is not listed, and never touches the zone the domain owns.
	 */
	public function folded_into(): int {
		return (int) $this->get( 'folded_into' );
	}

	/**
	 * One property, typed, with its default when unset.
	 *
	 * @param string $key Property.
	 * @return mixed
	 */
	public function get( string $key ) {
		$meta  = is_array( $this->row['meta'] ?? null ) ? $this->row['meta'] : array();
		$value = $meta[ $key ] ?? null;

		if ( ! $this->is_valid() ) {
			if ( 'state' === $key ) {
				return 'failed';
			}
			if ( 'last_error' === $key ) {
				return 'Resource metadata missing';
			}
		}

		switch ( $key ) {
			case 'plan':
			case 'steps':
				return is_array( $value ) ? $value : array();
			case 'attempts':
			case 'progress_resets':
			case 'wait_since':
				return (int) $value;
			case 'plan_dirty':
			case 'protected':
			case 'replace_after_delete':
				return (bool) $value;
			case 'desired_at':
				return is_numeric( $value ) ? (float) $value : 0.0;
			case 'next_attempt_at':
			case 'paused_at':
			case 'warned_at':
			case 'breach_at':
				return null === $value || '' === $value ? null : (int) $value;
			case 'state':
				return is_string( $value ) ? $value : 'requested';
			case 'desired':
				return is_string( $value ) ? $value : 'ready';
			case 'uuid':
			case 'driver':
			case 'last_error':
			case 'waiting':
			case 'remote_id':
			case 'breach_reason':
				return is_scalar( $value ) ? (string) $value : '';
			default:
				return $value;
		}
	}

	/**
	 * Merges values into the meta. Returns false when nothing could be stored.
	 *
	 * @param array<string, mixed> $meta Values.
	 */
	public function set( array $meta ): bool {
		if ( false === json_encode( $meta ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- strict check: wp_json_encode would repair invalid UTF-8 silently.
			return false;
		}

		if ( ! Licenses::update_resource_meta( $this->id(), $meta ) ) {
			return false;
		}

		$fresh = Licenses::activation( $this->id() );

		if ( $fresh ) {
			$this->row = $fresh;
		}

		return true;
	}

	/**
	 * Option prefix for a desired state that is waiting for the resource lock.
	 */
	public const PENDING_DESIRE_PREFIX = 'beltoft_services_pending_desire_';

	/**
	 * The option that holds a resource's desired state waiting for its lock.
	 *
	 * @param int $activation_id Activation id.
	 */
	public static function pending_desire_option( int $activation_id ): string {
		return self::PENDING_DESIRE_PREFIX . $activation_id;
	}

	/**
	 * The desired state waiting for the lock, or null. Read from the database, not the
	 * option cache: another request may have written or applied it.
	 *
	 * @return array{state: string, reason: string, replace: bool, at: float}|null
	 */
	public function pending_desire(): ?array {
		$raw = self::pending_raw( $this->id() );

		return null === $raw ? null : self::decode_pending( $raw );
	}

	/**
	 * Queues a desired state for the next step instead of storing it now: what desire() does
	 * when the resource lock stays busy. A pending desire that was requested later is kept.
	 *
	 * @param string $state   ready, paused or deleted.
	 * @param string $reason  What happened, for the history.
	 * @param bool   $replace For deleted: make a new service once the deletion completes.
	 */
	public function queue_desire( string $state, string $reason, bool $replace = false ): void {
		$this->queue( $state, $reason, $replace, microtime( true ) );
	}

	/**
	 * Applies the desired state waiting for the lock, through desire(). Called by the
	 * reconciler under the resource lock. The option is removed by desire() once the desire
	 * is stored (or refused), never before, so a failed write keeps it for the next step.
	 *
	 * @return bool Whether a desire was stored.
	 */
	public function apply_pending_desire(): bool {
		$pending = $this->pending_desire();

		if ( ! $pending ) {
			return false;
		}

		return $this->store_desire( $pending['state'], $pending['reason'], $pending['replace'], $pending['at'] );
	}

	/**
	 * Seconds desire() waits for the resource lock.
	 */
	public const DESIRE_LOCK_WAIT = 2;

	/**
	 * History text when a desired state other than deleted arrives during a deletion.
	 */
	public const DELETION_IN_PROGRESS = 'Deletion in progress.';

	/**
	 * Added to DELETION_IN_PROGRESS when a new service follows the deletion.
	 */
	public const DELETION_REPLACED = ' A new service will follow when it completes.';

	/**
	 * Error alerted when a desired state cannot be stored.
	 */
	public const DESIRE_ERROR = 'Desired state "%s" could not be stored';

	/**
	 * Sets the desired state and writes a history event. Pausing records when; wanting it
	 * ready again clears that. A change of the desired state resets the reconciler's attempt
	 * count (meta op, attempts) and its alert memory. Never deletes anything by itself. The
	 * event is written only when the change was stored.
	 *
	 * Runs under the resource lock (the reconciler's, re-entrant within one connection), so
	 * a running job never overwrites it. When the lock stays busy, the change is kept in its
	 * own option (queue_desire(), so no meta write can drop it), the resource is queued, and
	 * the next step applies it. Desires are ordered by when they were requested (meta
	 * `desired_at`): an older pending desire never overrides a newer stored one.
	 *
	 * Deleted is terminal: once desired, it is never left. With $replace, the reconciler
	 * makes a new service when the deletion completes on an active license (meta
	 * `replace_after_delete`); the history of a refused desire says so.
	 *
	 * A desired state that cannot be stored alerts the admin (at most once an hour) and keeps
	 * a pending desire in place for the next step.
	 *
	 * @param string $state   ready, paused or deleted.
	 * @param string $reason  What happened, for the history.
	 * @param bool   $replace For deleted: make a new service once the deletion completes.
	 * @param string $origin  For deleted: retention or breach when that asked for it (such a deletion that has not
	 *                        started can still be cancelled, see delete_cancellable()); empty for anyone else, which is terminal.
	 * @return bool Whether it was stored now.
	 */
	public function desire( string $state, string $reason, bool $replace = false, string $origin = '' ): bool {
		return $this->store_desire( $state, $reason, $replace, microtime( true ), $origin );
	}

	/**
	 * Stores a desired state requested at a given time (see desire()).
	 *
	 * @param string $state   ready, paused or deleted.
	 * @param string $reason  What happened, for the history.
	 * @param bool   $replace For deleted: make a new service once the deletion completes.
	 * @param float  $at      When it was requested (microtime).
	 * @param string $origin  For deleted: retention, breach or empty.
	 * @return bool Whether it was stored now.
	 */
	private function store_desire( string $state, string $reason, bool $replace, float $at, string $origin = '' ): bool {
		if ( ! in_array( $state, self::DESIRED, true ) ) {
			_doing_it_wrong( __CLASS__ . '::desire', esc_html( sprintf( 'Unknown desired state "%s".', $state ) ), '0.1.0' );
			return false;
		}

		$lock = Locks::name( 'resource', $this->id() );

		/**
		 * Seconds desire() waits for the resource lock before it queues the desired state.
		 *
		 * @param int $seconds Seconds (default Resource::DESIRE_LOCK_WAIT).
		 */
		$wait = max( 0, (int) apply_filters( 'beltoft_services_desire_lock_wait', self::DESIRE_LOCK_WAIT ) );

		if ( ! Locks::acquire( $lock, $wait ) ) {
			$this->queue( $state, $reason, $replace, $at );
			return false;
		}

		$event = $reason;

		try {
			$fresh = Licenses::activation( $this->id() );

			if ( $fresh ) {
				$this->row = $fresh;
			}

			// A desire requested after this one was stored already: this one is superseded.
			if ( $at < $this->get( 'desired_at' ) ) {
				$this->forget_pending( $at );
				return false;
			}

			$cancel = false;

			if ( 'deleted' === $this->get( 'desired' ) && 'deleted' !== $state ) {
				// A renewal cancels a deletion that retention or a breach hold asked for, as long as
				// nothing was deleted yet. Anything else is terminal.
				if ( 'ready' === $state && $this->delete_cancellable() ) {
					$cancel = true;
				} else {
					$this->forget_pending( $at );
					Events::add( (int) $this->row['license_id'], 'service', $this->deletion_in_progress() );
					return false;
				}
			}

			// A breach hold is lifted by an admin only: nothing else may make the service ready.
			if ( 'ready' === $state && $this->on_hold() ) {
				$this->forget_pending( $at );
				Events::add( (int) $this->row['license_id'], 'service', self::HOLD_REFUSED );
				return false;
			}

			$meta = array(
				'desired'    => $state,
				'desired_at' => $at,
			);

			// A new desired state is a new start for the reconciler: a fresh attempt count, and a
			// failure under it is alerted again.
			if ( $this->get( 'desired' ) !== $state ) {
				$meta['op']              = '';
				$meta['attempts']        = 0;
				$meta['progress_resets'] = 0;
				$meta['alerted']         = '';
			}

			if ( 'deleted' === $state ) {
				// Once a deletion is desired, "no replacement" wins: a later request (retention)
				// cannot turn replacement back on after a deliberate deletion.
				$already                      = 'deleted' === $this->get( 'desired' );
				$meta['replace_after_delete'] = $already ? ( $replace && $this->get( 'replace_after_delete' ) ) : $replace;
				// Whoever asks again for a deletion that was started by retention makes it their own: terminal.
				$meta['delete_origin']        = $already ? ( '' === $origin ? '' : (string) $this->get( 'delete_origin' ) ) : $origin;
			} elseif ( 'paused' === $state ) {
				if ( null === $this->get( 'paused_at' ) ) {
					$meta['paused_at']     = time();
					$meta['paused_reason'] = $reason;
				}
			} else {
				if ( $cancel ) {
					$meta['delete_origin']        = '';
					$meta['delete_started']       = false;
					$meta['delete_held_alerted']  = null;
					$meta['delete_paused']        = false;
					$meta['replace_after_delete'] = false;

					// Nothing was deleted: the service goes back to what it was before the deletion started.
					if ( 'deleting' === $this->get( 'state' ) ) {
						$meta['state'] = 'paused';
					}

					$event = $reason . ': the deletion was cancelled';
				}

				$meta['paused_at']     = null;
				$meta['paused_reason'] = '';
				$meta['warned_at']       = null;
				$meta['warn_started_at'] = null;
				$meta['warn_alerted']    = null;
				$meta['reported']        = '';
			}

			if ( ! $this->set( $meta ) ) {
				Alerts::write_failed( $this, sprintf( self::DESIRE_ERROR, $state ) );
				return false;
			}

			// Stored: a pending desire requested up to now is done (a newer one stays).
			$this->forget_pending( $at );
		} finally {
			Locks::release( $lock );
		}

		Events::add( (int) $this->row['license_id'], 'service', $event );

		return true;
	}

	/**
	 * Can a pending deletion still be called off? Only one that retention or a breach hold asked
	 * for (meta delete_origin), before the first driver delete call (meta delete_started), and, for
	 * a retention deletion, only while the license is active again. A breach deletion needs the
	 * hold lifted. An admin's deletion is terminal.
	 */
	public function delete_cancellable(): bool {
		if ( 'deleted' !== $this->get( 'desired' ) || $this->get( 'delete_started' ) || ! in_array( $this->get( 'delete_origin' ), array( 'retention', 'breach' ), true ) ) {
			return false;
		}

		if ( 'breach' === $this->get( 'delete_origin' ) && $this->on_hold() ) {
			return false;
		}

		$license = $this->license();

		return $license && Licenses::STATUS_ACTIVE === Licenses::effective_status( $license );
	}

	/**
	 * History text when a ready desire is refused during a breach hold.
	 */
	public const HOLD_REFUSED = 'Resume refused: the service is on breach hold. Lift the hold first.';

	/**
	 * History text of a desire refused during a deletion.
	 */
	private function deletion_in_progress(): string {
		$text = self::DELETION_IN_PROGRESS;

		if ( $this->get( 'replace_after_delete' ) ) {
			$license = $this->license();

			if ( $license && Licenses::STATUS_ACTIVE === Licenses::effective_status( $license ) ) {
				$text .= self::DELETION_REPLACED;
			}
		}

		return $text;
	}

	/**
	 * Writes a pending desire, unless one requested later is already waiting, and queues the
	 * resource. A waiting deletion is terminal here too: a later other desire leaves it in
	 * place, and a later deletion keeps "no replacement" if the waiting one had it. A write
	 * that fails alerts the admin (at most once an hour), so no desire is lost silently.
	 *
	 * @param string $state   Desired state.
	 * @param string $reason  Reason.
	 * @param bool   $replace Replace after deletion.
	 * @param float  $at      When it was requested.
	 */
	private function queue( string $state, string $reason, bool $replace, float $at ): void {
		global $wpdb;

		$waiting = $this->pending_desire();

		if ( $waiting && 'deleted' === $waiting['state'] && $waiting['at'] <= $at ) {
			if ( 'deleted' !== $state ) {
				Jobs::enqueue( $this->id() );
				return;
			}

			$replace = $replace && $waiting['replace'];
		}

		if ( ! $waiting || $waiting['at'] <= $at ) {
			$name  = self::pending_desire_option( $this->id() );
			$value = maybe_serialize(
				array(
					'state'   => $state,
					'reason'  => $reason,
					'replace' => $replace,
					'at'      => $at,
					'token'   => wp_generate_uuid4(),
				)
			);

			// One statement, whether or not the option exists: no cached view of it can turn
			// the write into an update of nothing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- caches are cleared below.
			$written = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s ) ON DUPLICATE KEY UPDATE option_value = VALUES( option_value )', $wpdb->options, $name, $value, 'off' ) );
			self::forget_cached_option( $name );

			if ( false === $written ) {
				Alerts::write_failed( $this, sprintf( self::DESIRE_ERROR, $state ) );
			}
		}

		Jobs::enqueue( $this->id() );
	}

	/**
	 * Removes the pending desire if it was requested at or before $at. A newer one, or one
	 * written after it was read, stays.
	 *
	 * @param float $at Request time of the desire just handled.
	 */
	private function forget_pending( float $at ): void {
		global $wpdb;

		$name = self::pending_desire_option( $this->id() );
		$raw  = self::pending_raw( $this->id() );

		if ( null === $raw ) {
			return;
		}

		$pending = self::decode_pending( $raw );

		if ( $pending && $pending['at'] > $at ) {
			return;
		}

		// Only the value that was read: a pending desire written since then stays.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- caches are cleared below.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $raw ) );
		self::forget_cached_option( $name );
	}

	/**
	 * The stored value of a resource's pending desire option, or null.
	 *
	 * @param int $activation_id Activation id.
	 */
	private static function pending_raw( int $activation_id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- always fresh: other requests write it.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::pending_desire_option( $activation_id ) ) );

		return null === $raw ? null : (string) $raw;
	}

	/**
	 * A pending desire from its stored value, or null when it is unreadable.
	 *
	 * @param string $raw Stored value.
	 * @return array{state: string, reason: string, replace: bool, at: float}|null
	 */
	private static function decode_pending( string $raw ): ?array {
		$pending = maybe_unserialize( $raw );

		if ( ! is_array( $pending ) || ! isset( $pending['state'] ) ) {
			return null;
		}

		return array(
			'state'   => (string) $pending['state'],
			'reason'  => (string) ( $pending['reason'] ?? '' ),
			'replace' => ! empty( $pending['replace'] ),
			'at'      => (float) ( $pending['at'] ?? 0 ),
		);
	}

	/**
	 * Drops WordPress's cached copy of an option written with SQL.
	 *
	 * @param string $name Option name.
	 */
	private static function forget_cached_option( string $name ): void {
		wp_cache_delete( $name, 'options' );

		$missing = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
			unset( $missing[ $name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}

	/**
	 * Error alerted when a new plan cannot be stored.
	 */
	public const PLAN_ERROR = 'The new plan could not be stored';

	/**
	 * Stores a new plan snapshot and marks it dirty, so the reconciler applies it
	 * (update_plan), and writes a history event. Runs under the resource lock like desire(),
	 * so a running job never overwrites it or marks it applied by mistake. A resource whose
	 * deletion is desired keeps its plan: a replacement takes the product's plan anyway.
	 *
	 * @param array<string, mixed> $plan   Plan snapshot.
	 * @param string               $reason What happened, for the history.
	 * @return bool|null True when stored (or nothing to do), false when it could not be stored
	 *                   (the admin is alerted), null when the lock stayed busy (try again later).
	 */
	public function change_plan( array $plan, string $reason ): ?bool {
		return $this->locked(
			function () use ( $plan, $reason ): bool {
				// A resource on its way out keeps its plan.
				if ( 'deleted' === $this->get( 'desired' ) ) {
					return true;
				}

				if ( ! $this->set(
					array(
						'plan'       => $plan,
						'plan_dirty' => true,
					)
				) ) {
					Alerts::write_failed( $this, self::PLAN_ERROR );
					return false;
				}

				Events::add( (int) $this->row['license_id'], 'service', $reason );
				return true;
			}
		);
	}

	/**
	 * Error alerted when retention bookkeeping cannot be stored.
	 */
	public const RETENTION_ERROR = 'The retention state could not be stored';

	/**
	 * Stores retention bookkeeping (warned_at, reported) under the resource lock, only while
	 * the resource is still paused since $paused_at: a desire that changed it in the meantime
	 * (a renewal) wins, and the caller must then not warn.
	 *
	 * @param array<string, mixed> $meta      Values (warned_at, reported).
	 * @param int                  $paused_at The paused_at the caller decided on.
	 * @return bool Whether it was stored.
	 */
	public function record_retention( array $meta, int $paused_at ): bool {
		$meta = array_intersect_key( $meta, array_flip( array( 'warned_at', 'reported', 'warn_started_at', 'warn_alerted' ) ) );

		return true === $this->locked(
			function () use ( $meta, $paused_at ): bool {
				if ( 'paused' !== $this->get( 'desired' ) || $this->get( 'paused_at' ) !== $paused_at ) {
					return false;
				}

				if ( ! $this->set( $meta ) ) {
					Alerts::write_failed( $this, self::RETENTION_ERROR );
					return false;
				}

				return true;
			}
		);
	}

	/**
	 * Requests the deletion of a paused service after its retention period (with a new
	 * service to follow on a renewal), only if, under the resource lock, it is still meant to
	 * be paused since $paused_at, is not protected, has no desired state waiting, and its
	 * license is not active. A renewal stored in the meantime wins. When the lock stays busy
	 * nothing is stored or queued: the next retention run decides again.
	 *
	 * @param int    $paused_at The paused_at the caller decided on.
	 * @param string $reason    History text.
	 * @return bool Whether the deletion was stored.
	 */
	public function delete_if_still_paused( int $paused_at, string $reason ): bool {
		return true === $this->locked(
			function () use ( $paused_at, $reason ): bool {
				if ( 'paused' !== $this->get( 'desired' ) || $this->get( 'paused_at' ) !== $paused_at || $this->get( 'protected' ) ) {
					return false;
				}

				if ( null !== $this->pending_desire() ) {
					return false;
				}

				$license = $this->license();

				if ( ! $license || Licenses::STATUS_ACTIVE === Licenses::effective_status( $license ) ) {
					return false;
				}

				// The lock is re-entrant within this connection: desire() takes it again at once.
				return $this->desire( 'deleted', $reason, true, 'retention' );
			}
		);
	}

	/**
	 * Requests the deletion of a service whose breach hold began at $breach_at, under the
	 * resource lock and only while that hold is still the same one, the service is still meant
	 * to be paused and is not protected. No replacement follows, whatever the license says:
	 * billing is the admin's. A lifted hold, a protected service or a busy lock stores nothing.
	 *
	 * @param int    $breach_at The breach_at the caller decided on.
	 * @param string $reason    History text.
	 * @return bool Whether the deletion was stored.
	 */
	public function delete_if_still_held( int $breach_at, string $reason ): bool {
		return true === $this->locked(
			function () use ( $breach_at, $reason ): bool {
				if ( $this->get( 'breach_at' ) !== $breach_at || 'paused' !== $this->get( 'desired' ) || $this->get( 'protected' ) ) {
					return false;
				}

				return $this->desire( 'deleted', $reason, false, 'breach' );
			}
		);
	}

	/**
	 * Runs a callback under the resource lock (waiting as desire() does), on a fresh read of
	 * the row. Every meta write from outside a job (admin actions, the usage check) goes
	 * through here, so it never overwrites a running step's meta or is overwritten by it.
	 *
	 * @param callable $callback Returns whether it stored something.
	 * @return bool|null The callback's result, or null when the lock stayed busy.
	 */
	public function locked( callable $callback ): ?bool {
		$lock = Locks::name( 'resource', $this->id() );

		/** This filter is documented in src/Resource.php (store_desire()). */
		$wait = max( 0, (int) apply_filters( 'beltoft_services_desire_lock_wait', self::DESIRE_LOCK_WAIT ) );

		if ( ! Locks::acquire( $lock, $wait ) ) {
			return null;
		}

		try {
			$fresh = Licenses::activation( $this->id() );

			if ( $fresh ) {
				$this->row = $fresh;
			}

			return (bool) $callback();
		} finally {
			Locks::release( $lock );
		}
	}

	/**
	 * The four keys that clear a retry: the operation, its attempts, the next attempt and the admin alert mark.
	 * Merge it into a larger write when the same write changes more.
	 *
	 * @return array{op: string, attempts: int, next_attempt_at: null, alerted: string}
	 */
	public static function retry_reset(): array {
		return array(
			'op'              => '',
			'attempts'        => 0,
			'next_attempt_at' => null,
			'alerted'         => '',
		);
	}

	/**
	 * Clears the retry bookkeeping (see retry_reset()): the next run starts the operation afresh.
	 *
	 * @return bool Whether it was stored.
	 */
	public function reset_retries(): bool {
		return $this->set( self::retry_reset() );
	}

	/**
	 * Moves to a state and writes a history event, only when the change was stored.
	 *
	 * This fires nothing: no beltoft_services_state_changed, so no email, cascade or secret wipe follows (see
	 * ResourceTest::test_transition_fires_no_state_changed_action). Reconciler::move() is the path that tells
	 * listeners (transition, then Reconciler::notify()). Direct callers stay silent on purpose, and
	 * Structure\TransitionCallersTest lists them with the reason: Admin\Panel (forced delete; it calls
	 * Reconciler::notify() itself after the transition and the close), Proxmox\AppLicence (announces
	 * "cancelled" itself), Proxmox\DiskMeta (releases the disk capacity itself), Proxmox\SnapshotMeta (the
	 * parent's own email covers the customer), Proxmox\RestoreSteps (a failed disk keeps its volume, so no
	 * capacity release) and Proxmox\Vm\Reinstall (a reinstall to provisioning, which no listener acts on).
	 *
	 * @param string $state ready, paused, etc. (see STATES).
	 * @param string $event What happened, for the history.
	 * @return bool Whether it was stored.
	 */
	public function transition( string $state, string $event ): bool {
		if ( ! in_array( $state, self::STATES, true ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Unknown state "%s".', $state ) ), '0.1.0' );
			return false;
		}

		if ( ! $this->set( array( 'state' => $state ) ) ) {
			return false;
		}

		Events::add( (int) $this->row['license_id'], 'service', $event );

		return true;
	}

	/**
	 * Closes the activation once the resource is deleted. The row stays as history.
	 */
	public function close(): void {
		Licenses::close_resource( $this->license(), (string) $this->row['kind'], (string) $this->row['domain'] );

		$fresh = Licenses::activation( $this->id() );

		if ( $fresh ) {
			$this->row = $fresh;
		}
	}
}
