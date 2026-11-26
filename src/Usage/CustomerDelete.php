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
 * held for a breach, being worked on, or a server with an extra disk is refused with a message. When the service stays busy
 * (its lock is held) nothing is stored, the license is not revoked and the customer is asked to try again.
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
		$spec    = Items::delete_of( $r );
		$license = $r->license();

		if ( null === $spec || ! $license || 'usage' !== (string) ( $license['source'] ?? '' ) || ! $r->is_live() || ! $r->is_valid() ) {
			return '';
		}

		return (string) $spec['kind'];
	}

	/**
	 * The word the customer types: the server's name when it has one, else DELETE.
	 *
	 * @param Resource $r Resource.
	 */
	public static function word( Resource $r ): string {
		// The driver may ask for a name (a server is confirmed with the name its page shows).
		$word = Items::delete_of( $r )['word'] ?? null;
		$name = is_callable( $word ) ? trim( (string) $word( $r ) ) : '';

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

		$spec  = (array) Items::delete_of( $r );
		$early = $spec['early'] ?? null;
		$why   = is_callable( $early ) ? $early( $r ) : null;

		if ( null !== $why ) {
			return $why;
		}

		$idle = $spec['idle'] ?? null;
		$free = is_callable( $idle ) ? $idle( $r ) : ( 'ready' === $r->get( 'state' ) && null === $r->pending_desire() && empty( Progress::build( $r )['active'] ) );

		if ( ! $free ) {
			return (string) $spec['busy'];
		}

		$extra = $spec['blocker'] ?? null;

		if ( is_callable( $extra ) ) {
			return $extra( $r );
		}

		return null;
	}

	/**
	 * What deleting removes and when billing stops, for the dialog, the page and the confirmation of a tool.
	 *
	 * @param string $kind server or database.
	 */
	public static function text( string $kind ): string {
		return (string) ( Items::delete_for( $kind ) ?? Items::delete_for( 'server' ) )['text'];
	}

	/**
	 * One sentence for a server with a paid app licence of its own subscription: the licence stays on the account until the end of
	 * the month paid and can be used on a new server. '' for any other service, and for a licence still billed by the hour with its server (sold
	 * before the licence became monthly), where the hourly billing note is already true.
	 *
	 * @param Resource $r Resource.
	 */
	public static function licence_note( Resource $r ): string {
		$note = Items::delete_of( $r )['licence'] ?? null;

		return is_callable( $note ) ? (string) $note( $r ) : '';
	}

	/**
	 * The billing line shown with the delete.
	 *
	 * @param string $kind server or database.
	 */
	public static function billing_note( string $kind ): string {
		return (string) ( Items::delete_for( $kind ) ?? Items::delete_for( 'server' ) )['billing'];
	}

	/**
	 * The line under Shut down and Start: a stopped service is still billed.
	 *
	 * @param string $kind server or database.
	 */
	public static function stopped_note( string $kind ): string {
		return (string) ( Items::delete_for( $kind ) ?? Items::delete_for( 'server' ) )['stopped'];
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

		// The lock stayed busy: nothing is stored and the license stays, so the customer tries again (a queued request would skip the checks above).
		if ( null === $stored ) {
			return new \WP_Error( 'beltoft_services_delete_busy', __( 'The service is busy right now. Please try again in a minute.', 'beltoft-services' ), array( 'status' => 409 ) );
		}

		if ( false === $stored ) {
			return new \WP_Error( 'beltoft_services_delete_failed', __( 'The service could not be deleted now. Please try again.', 'beltoft-services' ), array( 'status' => 500 ) );
		}

		self::end_license( $license );
		Jobs::enqueue( $r->id() );

		return true;
	}

	/**
	 * Ends the license without telling the customer: revoked is not undone by the usage account's payments.
	 *
	 * Public so that an add-on closed with its deleted parent ends the same way (Proxmox\Addon\AddonEnd).
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function end_license( array $license ): void {
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
