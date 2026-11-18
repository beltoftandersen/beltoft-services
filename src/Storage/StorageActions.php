<?php
/**
 * What a customer can ask of a storage box, and the jobs that do it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Actions through Jobs::start_action (never a job queued by hand). `offered()` lists the ones the generic action route
 * accepts; they change nothing a customer cannot undo. The two that need the emailed code (a new password, a rollback) are
 * internal: their own routes ask for the code, then start them.
 */
final class StorageActions {

	public const SNAPSHOT        = 'storage_snapshot';
	public const SNAPSHOT_DELETE = 'storage_snapshot_delete';
	public const SNAPSHOT_PLAN   = 'storage_snapshot_plan';
	public const ACCESS          = 'storage_access';
	public const PASSWORD_RESET  = 'storage_password_reset';
	public const ROLLBACK        = 'storage_rollback';

	/**
	 * The actions queued by the routes of this feature, not by the generic action route.
	 *
	 * @return string[]
	 */
	public static function internal(): array {
		return array( self::PASSWORD_RESET, self::ROLLBACK );
	}

	/**
	 * The actions the dashboard offers now: name => label.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	public static function offered( Resource $r ): array {
		$out = array();

		if ( 'ready' !== (string) $r->get( 'state' ) || '' === Boxes::id( $r ) ) {
			return $out;
		}

		$out[ self::SNAPSHOT ]        = __( 'Take a snapshot', 'beltoft-services' );
		$out[ self::SNAPSHOT_DELETE ] = __( 'Delete a snapshot', 'beltoft-services' );
		$out[ self::SNAPSHOT_PLAN ]   = __( 'Change the snapshot plan', 'beltoft-services' );
		$out[ self::ACCESS ]          = __( 'Change the access', 'beltoft-services' );

		return $out;
	}

	/**
	 * The check of the arguments at once, before a job is queued (filter beltoft_services_action_refusal).
	 *
	 * @param mixed $refusal  Null so far.
	 * @param mixed $r        Resource.
	 * @param mixed $action   Action.
	 * @param mixed $args     Cleaned arguments.
	 * @return mixed
	 */
	public static function refusal( $refusal, $r = null, $action = '', $args = array() ) {
		if ( null !== $refusal || ! $r instanceof Resource || StorageDriver::ID !== $r->get( 'driver' ) || ! is_array( $args ) ) {
			return $refusal;
		}

		if ( self::SNAPSHOT_DELETE === $action && 1 !== preg_match( '/^\d{1,20}$/D', (string) ( $args['snapshot'] ?? '' ) ) ) {
			return new \WP_Error( 'beltoft_services_invalid_snapshot', __( 'Choose a snapshot.', 'beltoft-services' ), array( 'status' => 422 ) );
		}

		if ( self::SNAPSHOT_PLAN === $action ) {
			$plan = SnapshotPlan::from_input( $args['every'] ?? '', $args['hour'] ?? 0 );

			return $plan instanceof \WP_Error ? new \WP_Error( 'beltoft_services_invalid_plan', $plan->get_error_message(), array( 'status' => 422 ) ) : $refusal;
		}

		if ( self::ACCESS === $action ) {
			$wanted = self::access_from( $args );

			if ( array() === $wanted ) {
				return new \WP_Error( 'beltoft_services_invalid_access', __( 'Choose what to switch.', 'beltoft-services' ), array( 'status' => 422 ) );
			}

			if ( ( isset( $wanted['samba_enabled'] ) || isset( $wanted['webdav_enabled'] ) ) && ! \BeltoftServices\Settings::get( 'storage_extra_protocols' ) ) {
				return new \WP_Error( 'beltoft_services_invalid_access', __( 'This protocol is not available.', 'beltoft-services' ), array( 'status' => 422 ) );
			}
		}

		return $refusal;
	}

	/**
	 * The switches a request names: ssh, smb and webdav as 0 or 1. FTP and SFTP cannot be switched and are never in here.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, bool>
	 */
	public static function access_from( array $args ): array {
		$map = array( 'ssh' => 'ssh_enabled', 'smb' => 'samba_enabled', 'webdav' => 'webdav_enabled' );
		$out = array();

		foreach ( $map as $name => $key ) {
			if ( isset( $args[ $name ] ) && in_array( (string) $args[ $name ], array( '0', '1' ), true ) ) {
				$out[ $key ] = '1' === (string) $args[ $name ];
			}
		}

		return $out;
	}

	/**
	 * Runs an action in its job.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function run( Resource $r, string $action, array $args ): StepResult {
		$id = Boxes::id( $r );

		if ( '' === $id ) {
			return StepResult::refused( array(), 'Refused: the storage is not set up yet' );
		}

		$client   = StorageClients::for();
		$snapshot = (string) ( $args['snapshot'] ?? '' );

		if ( in_array( $action, array( self::SNAPSHOT, self::SNAPSHOT_DELETE, self::ROLLBACK ), true ) ) {
			Boxes::forget_snapshots( $r ); // the page reads the list again after this action (also when it fails)
		}

		switch ( $action ) {
			case self::SNAPSHOT:
				$done = $client->create_snapshot( $id, 'manual-' . gmdate( 'Ymd-His' ) );
				break;
			case self::SNAPSHOT_DELETE:
				$done = self::has_snapshot( $client, $id, $snapshot ) ? $client->delete_snapshot( $id, $snapshot ) : new \WP_Error( 'storage_not_found', 'There is no such snapshot.' );
				break;
			case self::ROLLBACK:
				$done = self::has_snapshot( $client, $id, $snapshot ) ? $client->rollback_snapshot( $id, $snapshot ) : new \WP_Error( 'storage_not_found', 'There is no such snapshot.' );
				break;
			case self::SNAPSHOT_PLAN:
				$plan = SnapshotPlan::from_input( $args['every'] ?? '', $args['hour'] ?? 0 );

				if ( $plan instanceof \WP_Error ) {
					return StepResult::refused( array(), 'Refused: the plan cannot be read' );
				}

				$done = $client->set_snapshot_plan( $id, $plan );

				if ( ! is_wp_error( $done ) ) {
					return StepResult::done( array( 'storage_snapshot_plan' => $plan ?? array() ), 'Snapshot plan changed' );
				}
				break;
			case self::ACCESS:
				$wanted = self::access_from( $args );

				if ( ( isset( $wanted['samba_enabled'] ) || isset( $wanted['webdav_enabled'] ) ) && ! \BeltoftServices\Settings::get( 'storage_extra_protocols' ) ) {
					return StepResult::refused( array(), 'Refused: this protocol is not offered' );
				}

				$done = array() === $wanted ? true : $client->set_access( $id, $wanted );

				if ( ! is_wp_error( $done ) ) {
					return StepResult::done( array( 'storage_access' => $wanted + (array) $r->get( 'storage_access' ) ), 'Access changed' );
				}
				break;
			case self::PASSWORD_RESET:
				return self::reset_password( $r, $id, $client );
			default:
				return StepResult::refused( array(), 'Refused: unknown action' );
		}

		if ( in_array( $action, array( self::SNAPSHOT, self::SNAPSHOT_DELETE, self::ROLLBACK ), true ) ) {
			Boxes::forget_snapshots( $r ); // again after the call: a page read while it ran must not keep the old list
		}

		if ( is_wp_error( $done ) ) {
			return self::failed( $done );
		}

		return StepResult::done( array( 'storage_checked_at' => 0 ), 'Storage action done: ' . $action );
	}

	/**
	 * Is the snapshot one of this box's? A snapshot id from a request is never passed on unchecked.
	 *
	 * @param StorageClient $client   Client.
	 * @param string        $id       Box id.
	 * @param string        $snapshot Snapshot id.
	 */
	private static function has_snapshot( StorageClient $client, string $id, string $snapshot ): bool {
		$list = $client->snapshots( $id );

		return is_array( $list ) && in_array( $snapshot, array_column( $list, 'id' ), true );
	}

	/**
	 * A new password: made here, kept encrypted before the provider hears of it, so a replay after a crash uses the same one.
	 *
	 * @param Resource      $r      Resource.
	 * @param string        $id     Box id.
	 * @param StorageClient $client Client.
	 */
	private static function reset_password( Resource $r, string $id, StorageClient $client ): StepResult {
		$next = (string) $r->get( 'storage_password_next' );

		if ( '' === $next ) {
			if ( ! Crypto::available() ) {
				return StepResult::refused( array(), 'Refused: the password cannot be stored' );
			}

			$next = Crypto::encrypt( Boxes::make_password() );

			if ( ! $r->set( array( 'storage_password_next' => $next ) ) ) {
				return StepResult::retry( 60, 'The new password could not be stored' );
			}
		}

		$plain = Crypto::decrypt( $next );
		$done  = null === $plain ? new \WP_Error( 'storage_password', 'The new password cannot be read.' ) : $client->reset_password( $id, $plain );

		if ( is_wp_error( $done ) ) {
			return self::failed( $done );
		}

		return StepResult::done( array( 'storage_password' => $next, 'storage_password_next' => '' ), 'Storage password changed' );
	}

	/**
	 * A failed call as a result: the provider's words for staff (history and alert), a plain sentence for the customer.
	 *
	 * @param \WP_Error $e Error.
	 */
	private static function failed( \WP_Error $e ): StepResult {
		$limit = 'storage_snapshot_limit_exceeded' === $e->get_error_code();

		return StepResult::fail(
			Boxes::why( $e ),
			array(
				Jobs::TEXT     => $limit ? __( 'The snapshot limit of this storage is reached. Delete a snapshot first.', 'beltoft-services' ) : __( 'The change could not be made. We have been told.', 'beltoft-services' ),
				Jobs::EXPECTED => $limit,
			)
		);
	}
}
