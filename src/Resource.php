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

		if ( ! self::acquire( $lock ) ) {
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
	 * @param string $name Lock name.
	 */
	private static function acquire( string $name ): bool {
		global $wpdb;

		/**
		 * Seconds to wait for the license lock when creating a resource.
		 *
		 * @param int $seconds Seconds.
		 */
		$timeout = max( 0, (int) apply_filters( 'beltoft_services_lock_timeout', 5 ) );

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
				return (bool) $value;
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
	 * Sets the desired state and writes a history event. Pausing records when; wanting it
	 * ready again clears that. A change of the desired state resets the reconciler's attempt
	 * count (meta op, attempts) and its alert memory. Never deletes anything by itself. The
	 * event is written only when the change was stored.
	 *
	 * @param string $state  ready, paused or deleted.
	 * @param string $reason What happened, for the history.
	 * @return bool Whether it was stored.
	 */
	public function desire( string $state, string $reason ): bool {
		if ( ! in_array( $state, self::DESIRED, true ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Unknown desired state "%s".', $state ) ), '0.1.0' );
			return false;
		}

		$meta = array( 'desired' => $state );

		// A new desired state is a new start for the reconciler: a fresh attempt count, and a
		// failure under it is alerted again.
		if ( $this->get( 'desired' ) !== $state ) {
			$meta['op']       = '';
			$meta['attempts'] = 0;
			$meta['alerted']  = '';
		}

		if ( 'paused' === $state ) {
			if ( null === $this->get( 'paused_at' ) ) {
				$meta['paused_at'] = time();
			}
		} elseif ( 'ready' === $state ) {
			$meta['paused_at'] = null;
			$meta['warned_at'] = null;
		}

		if ( ! $this->set( $meta ) ) {
			return false;
		}

		Events::add( (int) $this->row['license_id'], 'service', $reason );

		return true;
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
