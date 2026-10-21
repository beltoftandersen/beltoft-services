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

		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,180}$/', $uuid ) ) {
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

		if ( ! self::acquire( $lock, $timeout ) ) {
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
			self::release( $lock );
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
	 * Takes a named database lock.
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait.
	 */
	private static function acquire( string $name, int $timeout ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, $timeout ) );
	}

	/**
	 * Releases a named database lock.
	 *
	 * @param string $name Lock name.
	 */
	private static function release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
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
		if ( ! $this->is_live() || 'ready' !== $this->get( 'desired' ) || in_array( $this->get( 'state' ), array( 'paused', 'deleting', 'deleted' ), true ) ) {
			return false;
		}

		$license = $this->license();

		return $license && Licenses::STATUS_ACTIVE === Licenses::effective_status( $license );
	}

	/**
	 * May its customer run actions on it now? Only when it shows its status, is ready, is valid
	 * and no other desired state is waiting to be applied.
	 */
	public function accepts_actions(): bool {
		return $this->is_valid()
			&& 'ready' === $this->get( 'state' )
			&& null === $this->pending_desire()
			&& $this->shows_status();
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
				return null === $value || '' === $value ? null : (int) $value;
			case 'state':
				return is_string( $value ) ? $value : 'requested';
			case 'desired':
				return is_string( $value ) ? $value : 'ready';
			case 'uuid':
			case 'driver':
			case 'last_error':
			case 'remote_id':
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
	 * @return bool Whether it was stored now.
	 */
	public function desire( string $state, string $reason, bool $replace = false ): bool {
		return $this->store_desire( $state, $reason, $replace, microtime( true ) );
	}

	/**
	 * Stores a desired state requested at a given time (see desire()).
	 *
	 * @param string $state   ready, paused or deleted.
	 * @param string $reason  What happened, for the history.
	 * @param bool   $replace For deleted: make a new service once the deletion completes.
	 * @param float  $at      When it was requested (microtime).
	 * @return bool Whether it was stored now.
	 */
	private function store_desire( string $state, string $reason, bool $replace, float $at ): bool {
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

		if ( ! self::acquire( $lock, $wait ) ) {
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

			if ( 'deleted' === $this->get( 'desired' ) && 'deleted' !== $state ) {
				$this->forget_pending( $at );
				Events::add( (int) $this->row['license_id'], 'service', $this->deletion_in_progress() );
				return false;
			}

			$meta = array(
				'desired'    => $state,
				'desired_at' => $at,
			);

			// A new desired state is a new start for the reconciler: a fresh attempt count, and a
			// failure under it is alerted again.
			if ( $this->get( 'desired' ) !== $state ) {
				$meta['op']       = '';
				$meta['attempts'] = 0;
				$meta['alerted']  = '';
			}

			if ( 'deleted' === $state ) {
				// Once a deletion is desired, "no replacement" wins: a later request (retention)
				// cannot turn replacement back on after a deliberate deletion.
				$meta['replace_after_delete'] = 'deleted' === $this->get( 'desired' ) ? ( $replace && $this->get( 'replace_after_delete' ) ) : $replace;
			} elseif ( 'paused' === $state ) {
				if ( null === $this->get( 'paused_at' ) ) {
					$meta['paused_at'] = time();
				}
			} else {
				$meta['paused_at'] = null;
				$meta['warned_at'] = null;
				$meta['reported']  = '';
			}

			if ( ! $this->set( $meta ) ) {
				Alerts::write_failed( $this, sprintf( self::DESIRE_ERROR, $state ) );
				return false;
			}

			// Stored: a pending desire requested up to now is done (a newer one stays).
			$this->forget_pending( $at );
		} finally {
			self::release( $lock );
		}

		Events::add( (int) $this->row['license_id'], 'service', $event );

		return true;
	}

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
		$meta = array_intersect_key( $meta, array_flip( array( 'warned_at', 'reported' ) ) );

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
				return $this->desire( 'deleted', $reason, true );
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

		if ( ! self::acquire( $lock, $wait ) ) {
			return null;
		}

		try {
			$fresh = Licenses::activation( $this->id() );

			if ( $fresh ) {
				$this->row = $fresh;
			}

			return (bool) $callback();
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * Moves to a state and writes a history event, only when the change was stored.
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
