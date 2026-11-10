<?php
/**
 * A customer deleting a pay-as-you-go server or database, to stop paying for it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Jobs;
use BeltoftServices\Progress;
use BeltoftServices\Proxmox\Disk\DiskMeta;
use BeltoftServices\Proxmox\Vm\ServerName;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Usage servers and databases (licenses with source `usage`) are billed for every hour they exist, running or stopped, so
 * the customer needs a way to end one. The flow reuses the machinery of the admin's Delete:
 *
 * 1. The desired state is set to deleted with no origin (Resource::desire()). A deletion that retention or a breach hold
 *    asked for is held while deletion_mode is not on and can be called off; one without an origin is the customer's or the
 *    admin's, so it is neither held nor called off. The reconciler then deletes through the driver (backups, snapshots and
 *    disks go with the machine) and closes the activation, which is where the meter stops: the hour it is closed in is the
 *    last one billed.
 * 2. The license is revoked. A revoked license stays as it is when the usage account is paid or reactivated (an expired one
 *    would come back active, and Lifecycle would make a new service for it). The customer is not emailed about it.
 * 3. The usage account (the subscription), the card and the credit are not touched, so the other services keep running.
 *
 * The customer must type the name of the server, or DELETE when it has none and for a database. A service that is protected,
 * held for a breach, being worked on, or a server with an extra disk is refused with a message.
 */
final class CustomerDelete {

	/**
	 * The word typed when the service has no name.
	 */
	public const CONFIRM = 'DELETE';

	/**
	 * The history text of the deletion.
	 */
	public const EVENT = 'Customer deleted the service';

	/**
	 * Driver kind => what the customer calls it.
	 */
	private const KINDS = array(
		'vm' => 'server',
		'db' => 'database',
		'disk' => 'disk',
	);

	/**
	 * Handle of the script of the Delete block.
	 */
	public const HANDLE = 'beltoft-services-delete';

	/**
	 * Loads the script of the Delete block (once per page, however many blocks are printed).
	 */
	public static function assets(): void {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/delete.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Account\Panel::HANDLE, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/delete.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesDelete',
			array(
				'strings' => array(
					'working' => __( 'Deleting. This can take a minute. You are taken to the list when it is done.', 'beltoft-services' ),
					'failed'  => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'limit'   => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
					'expired' => __( 'This page expired. Reload it and try again.', 'beltoft-services' ),
					/* translators: %s: the word to type */
					'type'    => __( 'Type %s to confirm the deletion.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * Is it a pay-as-you-go server or database that is live?
	 *
	 * @param Resource $r Resource.
	 * @return string server, database, disk (a pay as you go extra disk), or '' when it is none of them (a monthly service, a domain).
	 */
	public static function kind( Resource $r ): string {
		$driver  = \BeltoftServices\Drivers\Registry::get( (string) $r->get( 'driver' ) );
		$license = $r->license();

		if ( ! $driver || ! $license || 'usage' !== (string) ( $license['source'] ?? '' ) || ! $r->is_live() || ! $r->is_valid() ) {
			return '';
		}

		return self::KINDS[ $driver->kind() ] ?? '';
	}

	/**
	 * The word the customer types: the server's name when it has one, else DELETE.
	 *
	 * @param Resource $r Resource.
	 */
	public static function word( Resource $r ): string {
		$name = 'server' === self::kind( $r ) ? trim( (string) $r->get( ServerName::META ) ) : '';

		return '' !== $name ? $name : self::CONFIRM;
	}

	/**
	 * Why it cannot be deleted now, in words for the customer, or null when it can.
	 *
	 * @param Resource $r Resource.
	 */
	public static function blocker( Resource $r ): ?string {
		$kind = self::kind( $r );

		if ( '' === $kind ) {
			return __( 'This service cannot be deleted here.', 'beltoft-services' );
		}

		if ( $r->get( 'protected' ) ) {
			return __( 'This service is protected. Please contact support to delete it.', 'beltoft-services' );
		}

		if ( $r->on_hold() ) {
			return __( 'This service is on hold. Please contact support.', 'beltoft-services' );
		}

		if ( 'deleted' === $r->get( 'desired' ) || in_array( $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) || ( null !== $r->pending_desire() && 'deleted' === $r->pending_desire()['state'] ) ) {
			return __( 'This service is already being deleted.', 'beltoft-services' );
		}

		if ( 'ready' !== $r->get( 'state' ) || null !== $r->pending_desire() || ! empty( Progress::build( $r )['active'] ) ) {
			if ( 'disk' === $kind ) {
				return __( 'Something is still running on this disk, such as a setup or a resize. Please try again when it has finished.', 'beltoft-services' );
			}

			return 'server' === $kind
				? __( 'Something is still running on this server, such as a setup, a reinstall or a restore. Please try again when it has finished.', 'beltoft-services' )
				: __( 'Something is still running on this database, such as a setup or a restore. Please try again when it has finished.', 'beltoft-services' );
		}

		if ( 'server' === $kind ) {
			foreach ( DiskMeta::for_server( $r ) as $disk ) {
				if ( 'deleted' !== $disk->get( 'state' ) ) {
					return __( 'This server has an extra disk. Remove the disk first, then delete the server.', 'beltoft-services' );
				}
			}
		}

		return null;
	}

	/**
	 * What deleting removes and when billing stops, for the dialog, the page and the confirmation of a tool.
	 *
	 * @param string $kind server or database.
	 */
	public static function text( string $kind ): string {
		if ( 'disk' === $kind ) {
			return __( 'Removing deletes the extra disk and all its data, including the snapshots that include it. It cannot be undone. Billing stops in the hour it is removed. The server and the usage account are not touched.', 'beltoft-services' );
		}

		return 'database' === $kind
			? __( 'Deleting removes the database and all its data, including backups. It cannot be undone. Billing stops in the hour it is deleted.', 'beltoft-services' )
			: __( 'Deleting removes the server and all its data, including snapshots and backups. It cannot be undone. Billing stops in the hour it is deleted.', 'beltoft-services' );
	}

	/**
	 * The billing line shown with the delete.
	 *
	 * @param string $kind server or database.
	 */
	public static function billing_note( string $kind ): string {
		if ( 'disk' === $kind ) {
			return __( 'The disk is billed by the hour while it exists. Remove it to stop the charge.', 'beltoft-services' );
		}

		return 'database' === $kind
			? __( 'The database is billed every hour it exists. Delete it to stop the charge.', 'beltoft-services' )
			: __( 'A stopped server is still billed. Delete it to stop the charge.', 'beltoft-services' );
	}

	/**
	 * The line under Shut down and Start: a stopped service is still billed.
	 *
	 * @param string $kind server or database.
	 */
	public static function stopped_note( string $kind ): string {
		return 'database' === $kind
			? __( 'A stopped database is still billed. Delete it to stop the charge.', 'beltoft-services' )
			: __( 'A stopped server is still billed. Delete it to stop the charge.', 'beltoft-services' );
	}

	/**
	 * Deletes the customer's own service.
	 *
	 * @param Resource $r        Resource.
	 * @param int      $user_id  The customer asking.
	 * @param string   $confirm  What was typed (self::word()); '' when the caller has its own confirmation and passes $checked.
	 * @param bool     $checked  The caller confirmed already (a tool's confirmation token), so no typing is asked.
	 * @return true|\WP_Error
	 */
	public static function run( Resource $r, int $user_id, string $confirm, bool $checked = false ) {
		$license = $r->license();

		if ( $user_id < 1 || empty( $license['user_id'] ) || (int) $license['user_id'] !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), array( 'status' => 403 ) );
		}

		$why = self::blocker( $r );

		if ( null !== $why ) {
			return new \WP_Error( 'beltoft_services_delete_refused', $why, array( 'status' => 422 ) );
		}

		if ( ! $checked && trim( $confirm ) !== self::word( $r ) ) {
			/* translators: %s: the word to type. */
			return new \WP_Error( 'beltoft_services_delete_confirm', sprintf( __( 'Type %s to confirm the deletion.', 'beltoft-services' ), self::word( $r ) ), array( 'status' => 422 ) );
		}

		$refused = '';
		$stored  = $r->locked(
			static function () use ( $r, &$refused ): bool {
				// Read again under the lock: a job or an admin may have changed it since.
				$refused = (string) self::blocker( $r );

				// The lock is re-entrant within this connection.
				return '' === $refused && $r->desire( 'deleted', self::EVENT, false );
			}
		);

		if ( '' !== $refused ) {
			return new \WP_Error( 'beltoft_services_delete_refused', $refused, array( 'status' => 422 ) );
		}

		// The lock stayed busy: desire() keeps the request and the next step applies it.
		if ( null === $stored ) {
			$r->desire( 'deleted', self::EVENT, false );
		} elseif ( false === $stored ) {
			return new \WP_Error( 'beltoft_services_delete_failed', __( 'The service could not be deleted now. Please try again.', 'beltoft-services' ), array( 'status' => 500 ) );
		}

		self::end_license( $license );
		Jobs::enqueue( $r->id() );

		return true;
	}

	/**
	 * Ends the license without telling the customer: revoked is not undone by the usage account's payments.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private static function end_license( array $license ): void {
		$quiet = static fn( $send, $row = array() ) => (int) ( $row['id'] ?? 0 ) === (int) $license['id'] ? false : $send;

		add_filter( 'beltoft_licenses_send_status_email', $quiet, 10, 2 );

		try {
			Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		} finally {
			remove_filter( 'beltoft_licenses_send_status_email', $quiet, 10 );
		}

		Events::add( (int) $license['id'], 'service', 'License ended with the deletion; it is not renewed or made again' );
	}
}
