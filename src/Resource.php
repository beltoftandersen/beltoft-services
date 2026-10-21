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
	 * Creates the license's resource, or returns the live one it already holds.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @param array<string, mixed> $plan    Plan snapshot.
	 * @param array<string, mixed> $meta    Initial meta merged over the defaults (for imports).
	 * @return Resource|\WP_Error
	 */
	public static function create( array $license, Driver $driver, array $plan, array $meta = array() ) {
		$existing = self::for_license( (int) $license['id'] );

		if ( $existing ) {
			return $existing;
		}

		$uuid = wp_generate_uuid4();
		$meta = array_merge(
			array(
				'uuid'       => $uuid,
				'driver'     => $driver->id(),
				'state'      => 'requested',
				'desired'    => 'ready',
				'plan'       => $plan,
				'plan_dirty' => false,
				'steps'      => array(),
				'attempts'   => 0,
				'protected'  => false,
			),
			$meta
		);

		$result = Licenses::add_resource( $license, $driver->kind(), $uuid, $meta );

		if ( empty( $result['success'] ) || empty( $result['activation_id'] ) ) {
			return new \WP_Error( (string) ( $result['code'] ?? 'error' ), (string) ( $result['message'] ?? 'Resource could not be stored' ) );
		}

		$resource = self::find( (int) $result['activation_id'] );

		return $resource ? $resource : new \WP_Error( 'error', 'Resource could not be stored' );
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
	 * ready again clears that. Never deletes anything by itself.
	 *
	 * @param string $state  ready, paused or deleted.
	 * @param string $reason What happened, for the history.
	 */
	public function desire( string $state, string $reason ): void {
		if ( ! in_array( $state, self::DESIRED, true ) ) {
			return;
		}

		$meta = array( 'desired' => $state );

		if ( 'paused' === $state ) {
			if ( null === $this->get( 'paused_at' ) ) {
				$meta['paused_at'] = time();
			}
		} elseif ( 'ready' === $state ) {
			$meta['paused_at'] = null;
			$meta['warned_at'] = null;
		}

		$this->set( $meta );
		Events::add( (int) $this->row['license_id'], 'service', $reason );
	}

	/**
	 * Moves to a state and writes a history event.
	 *
	 * @param string $state ready, paused, etc. (see STATES).
	 * @param string $event What happened, for the history.
	 */
	public function transition( string $state, string $event ): void {
		if ( ! in_array( $state, self::STATES, true ) ) {
			return;
		}

		$this->set( array( 'state' => $state ) );
		Events::add( (int) $this->row['license_id'], 'service', $event );
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
