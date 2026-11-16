<?php
/**
 * A paid app licence is kept when its server goes, and can be used on another server until its paid month is over.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Apps;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Disk\DiskCascade;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * DESIGN NOTE: kept licences. A paid app licence with its own subscription is paid a whole month in advance. When its server is
 * deleted, or the server is reinstalled without the app, the licence is not ended: it is KEPT. The resource stays alive and is unlinked
 * from the server (`server_license` null, the old server's license id in `applic_prev_server`, the time in `applic_kept`), the vendor
 * licence is not released, and its subscription goes to pending-cancel, so it runs to the end of the paid period and is not charged again.
 * Nothing is refunded and nothing is charged. Until then the customer can use it on another server (attach()): the subscription goes back
 * to active (WooCommerce Subscriptions restores the end date and sets the next payment to it, so nothing is charged now), the licence is linked to
 * the new server and the vendor driver moves the vendor licence to the new address (event `beltoft_services_app_licence_attached`).
 * When the subscription ends, the licence ends as any other (AppLicenceDriver::pause() -> AppLicence::end()). A licence billed by the hour
 * (usage_billed) and one that was never delivered are not kept.
 *
 * What a customer chooses is carried as the value `use-<licence resource id>` in the place of a tier key (checkout, the picker and the
 * reinstall form); it is checked again at every step and nothing is written until attach().
 */
final class AppLicenceKeep {

	/** Resource meta: when the licence was kept (unix time); null while it is linked to a server. */
	public const KEPT = 'applic_kept';

	/** Resource meta: the license id of the server the licence was kept from (for the admin). */
	public const PREV = 'applic_prev_server';

	/** Server meta: the kept licence (resource id) the customer chose for this server. */
	public const USE = 'app_licence_use';

	/** Order line meta (the new server's line): the kept licence chosen. */
	public const ITEM_USE = '_beltoft_services_app_use';

	/** Action fired when a kept licence is linked to a server: ( Resource $licence, Resource $server, string $app_id ). */
	public const ATTACHED = 'beltoft_services_app_licence_attached';

	/** A licence with less paid time than this is not worth keeping or offering. */
	private const MIN_LEFT = 6 * HOUR_IN_SECONDS;

	// -- The value of a choice ------------------------------------------------------------------

	/**
	 * The licence a tier value names: `use-<id>` gives the id, anything else (a tier key) null.
	 *
	 * @param string $tier Posted tier value.
	 */
	public static function use_choice( string $tier ): ?int {
		return 1 === preg_match( '/^use-([1-9][0-9]{0,9})$/D', $tier, $m ) ? (int) $m[1] : null;
	}

	/**
	 * The value that chooses a kept licence.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function value_of( Resource $licence ): string {
		return 'use-' . $licence->id();
	}

	// -- Reading --------------------------------------------------------------------------------

	/**
	 * The tier subscription of a licence, or null.
	 *
	 * @param Resource $licence Licence resource.
	 */
	private static function subscription( Resource $licence ): ?\WC_Subscription {
		$id  = (int) ( $licence->license()['subscription_id'] ?? 0 );
		$sub = $id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : null;

		return $sub instanceof \WC_Subscription ? $sub : null;
	}

	/**
	 * Is the subscription only this licence's: every line an app licence and no other licence on it?
	 *
	 * @param \WC_Subscription $sub     Subscription.
	 * @param Resource         $licence Licence resource.
	 */
	private static function only_this( \WC_Subscription $sub, Resource $licence ): bool {
		$items = $sub->get_items();

		if ( ! $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			$driver = \BeltoftServices\Product::driver_for( (int) $item->get_product_id() );

			if ( ! $driver || AppLicenceDriver::ID !== $driver->id() ) {
				return false;
			}
		}

		$mine = (int) ( $licence->license()['id'] ?? 0 );

		foreach ( Licenses::where( 'subscription_id', (int) $sub->get_id() ) as $license ) {
			if ( (int) $license['id'] !== $mine ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Until when the licence is paid (unix time): the next payment of an active subscription, the end of a pending-cancel one, else 0.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function paid_until( Resource $licence ): int {
		$sub = self::subscription( $licence );

		if ( ! $sub ) {
			return 0;
		}

		if ( $sub->has_status( 'pending-cancel' ) ) {
			return (int) $sub->get_time( 'end' );
		}

		return $sub->has_status( 'active' ) ? (int) $sub->get_time( 'next_payment' ) : 0;
	}

	/**
	 * The paid-until date as the customer reads it, '' when there is none.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function paid_until_text( Resource $licence ): string {
		$time = self::paid_until( $licence );

		return $time > 0 ? wp_date( get_option( 'date_format' ), $time ) : '';
	}

	/**
	 * Is this licence kept: marked, linked to no server and not ended?
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function kept( Resource $licence ): bool {
		return AppLicenceDriver::ID === $licence->get( 'driver' ) && (int) $licence->get( self::KEPT ) > 0 && (int) $licence->get( 'server_license' ) < 1 && 'ended' !== AppLicence::state( $licence );
	}

	/**
	 * May the licence be offered for a new server: kept, active, its subscription pending-cancel and able to be active again, paid time left.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function reusable( Resource $licence ): bool {
		if ( ! self::kept( $licence ) || 'active' !== AppLicence::state( $licence ) ) {
			return false;
		}

		$sub = self::subscription( $licence );

		// A customer who made the subscription active again on its own page has a kept licence that renews: it can be used just the same.
		return null !== $sub && ( $sub->has_status( 'active' ) || ( $sub->has_status( 'pending-cancel' ) && $sub->can_be_updated_to( 'active' ) ) ) && self::paid_until( $licence ) > time();
	}

	/**
	 * The kept licences of a customer, newest first, of one app or all.
	 *
	 * @param int    $user_id Customer.
	 * @param string $app_id  App id, '' for any.
	 * @return Resource[]
	 */
	public static function kept_of_user( int $user_id, string $app_id = '' ): array {
		$out = array();

		if ( $user_id < 1 ) {
			return $out;
		}

		foreach ( Resource::find_many( Resource::ids_with_meta_value( self::KEPT, array(), 'applic' ) ) as $licence ) {
			if ( $licence->is_live() && $licence->owned_by( $user_id ) && self::kept( $licence ) && ( '' === $app_id || self::app_of( $licence ) === $app_id ) ) {
				$out[] = $licence;
			}
		}

		return array_reverse( $out );
	}

	/**
	 * The kept licences a customer may use on a new server for an app (reusable now).
	 *
	 * @param int    $user_id Customer.
	 * @param string $app_id  App id.
	 * @return Resource[]
	 */
	public static function usable( int $user_id, string $app_id ): array {
		return array_values( array_filter( self::kept_of_user( $user_id, $app_id ), array( __CLASS__, 'reusable' ) ) );
	}

	/**
	 * One licence a customer chose: it is theirs, it is of this app and (strict) it can be used now.
	 *
	 * @param string $app_id App id.
	 * @param int    $id     Licence resource id.
	 * @param int    $user   Customer.
	 * @param bool   $strict False after the order is paid: then only the owner and the app are checked here, attach() decides.
	 */
	public static function offered( string $app_id, int $id, int $user, bool $strict = true ): ?Resource {
		$licence = $id > 0 ? Resource::find( $id ) : null;

		if ( ! $licence || AppLicenceDriver::ID !== $licence->get( 'driver' ) || ! $licence->owned_by( $user ) || self::app_of( $licence ) !== $app_id ) {
			return null;
		}

		return $strict && ! self::reusable( $licence ) ? null : $licence;
	}

	/**
	 * The app of a licence.
	 *
	 * @param Resource $licence Licence resource.
	 */
	private static function app_of( Resource $licence ): string {
		return (string) ( ( (array) $licence->get( 'plan' ) )['app'] ?? '' );
	}

	/**
	 * "Use your licence (Pro), paid until 12 December 2026": the words of the choice, no price.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function choice_label( Resource $licence ): string {
		/* translators: 1: tier name, 2: date */
		return sprintf( __( 'Use your licence (%1$s), paid until %2$s', 'beltoft-services' ), AppLicence::tier_label( $licence ), self::paid_until_text( $licence ) );
	}

	/**
	 * What the account says about a kept licence.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function kept_text( Resource $licence ): string {
		/* translators: %s: date */
		$base = sprintf( __( 'Paid until %s.', 'beltoft-services' ), self::paid_until_text( $licence ) );

		$sub = self::subscription( $licence );

		if ( $sub && $sub->has_status( 'active' ) ) {
			// Made active again by the customer: it renews, so "not renewed" would be wrong.
			return $base . ' ' . __( 'You can use it on a new server.', 'beltoft-services' );
		}

		return self::reusable( $licence ) ? $base . ' ' . __( 'You can use it on a new server until then. It is not renewed.', 'beltoft-services' ) : $base . ' ' . __( 'It is not renewed.', 'beltoft-services' );
	}

	// -- Keeping --------------------------------------------------------------------------------

	/**
	 * May this licence be kept now: its own subscription, delivered, running, paid time left, nothing else on the subscription.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function keepable( Resource $licence ): bool {
		if ( AppLicenceDriver::ID !== $licence->get( 'driver' ) || AppLicence::usage_billed( $licence ) || 'ready' !== $licence->get( 'state' ) || 'active' !== AppLicence::state( $licence ) || '' === self::app_of( $licence ) || ! AppLicence::paid( self::app_of( $licence ) ) ) {
			return false;
		}

		$sub = self::subscription( $licence );

		return null !== $sub && $sub->has_status( array( 'active', 'pending-cancel' ) ) && self::paid_until( $licence ) > time() + self::MIN_LEFT && self::only_this( $sub, $licence );
	}

	/**
	 * The add-on kind's answer when its server is deleted (AddonCascade): keep the licence when it can be kept.
	 *
	 * @param Resource $licence Licence resource.
	 * @param Resource $server  The deleted server.
	 */
	public static function for_deleted_server( Resource $licence, Resource $server ): bool {
		return self::keep( $licence, $server, 'The server was deleted.' );
	}

	/**
	 * Keeps a licence: unlinks it from its server, remembers the server and puts its subscription to the end of the paid period.
	 * False when it cannot be kept (the caller ends it as before); nothing is stored then.
	 *
	 * @param Resource      $licence Licence resource.
	 * @param Resource|null $server  The server it leaves, or null.
	 * @param string        $why     Words for the history.
	 */
	public static function keep( Resource $licence, ?Resource $server, string $why ): bool {
		if ( ! self::keepable( $licence ) ) {
			return false;
		}

		list( $locked, $kept ) = Locks::with( Locks::name( 'applic', (int) ( $licence->license()['id'] ?? 0 ) ), 10, fn() => self::keep_locked( $licence, $server, $why ) );

		return $locked && true === $kept;
	}

	/**
	 * The body of keep(), under the licence lock, on a fresh read.
	 *
	 * @param Resource      $licence Licence resource.
	 * @param Resource|null $server  The server it leaves.
	 * @param string        $why     Words for the history.
	 */
	private static function keep_locked( Resource $licence, ?Resource $server, string $why ): bool {
		$fresh = Resource::find( $licence->id() );
		$sub   = $fresh ? self::subscription( $fresh ) : null;
		$from  = $server ? (int) ( $server->license()['id'] ?? 0 ) : (int) ( $fresh ? $fresh->get( 'server_license' ) : 0 );

		if ( ! $fresh || ! $sub || ! self::keepable( $fresh ) || ( $server && (int) $fresh->get( 'server_license' ) !== $from ) ) {
			return false;
		}

		try {
			if ( $sub->has_status( 'active' ) ) {
				$sub->update_status( 'pending-cancel', __( 'The app licence is kept for a new server. It runs to the end of the paid period and is not renewed unless it is used.', 'beltoft-services' ) );
			}
		} catch ( \Exception $e ) {
			return false;
		}

		if ( ! $sub->has_status( 'pending-cancel' ) ) {
			return false;
		}

		if ( ! $fresh->set( array( 'server_license' => null, self::KEPT => time(), self::PREV => $from > 0 ? $from : null, 'applic_event' => null ) ) ) {
			return false;
		}

		Events::add( (int) ( $fresh->license()['id'] ?? 0 ), 'service', 'App licence kept for a new server (' . $why . ') until ' . gmdate( 'Y-m-d', self::paid_until( $fresh ) ) . '.' );
		return true;
	}

	// -- Using ----------------------------------------------------------------------------------

	/**
	 * Links a kept licence to a server, under the licence lock, on a fresh read. A licence is attached at most once: the second of two
	 * servers finds it no longer kept.
	 *
	 * @param Resource $licence Licence resource.
	 * @param Resource $server  The server that runs the app.
	 * @return string|null Null when it is linked, else why not.
	 */
	public static function attach( Resource $licence, Resource $server ): ?string {
		list( $locked, $why ) = Locks::with( Locks::name( 'applic', (int) ( $licence->license()['id'] ?? 0 ) ), 10, fn() => self::attach_locked( $licence, $server ) );

		return $locked ? $why : 'The licence is being changed. Try again.';
	}

	/**
	 * The body of attach().
	 *
	 * @param Resource $licence Licence resource.
	 * @param Resource $server  Server.
	 * @return string|null
	 */
	private static function attach_locked( Resource $licence, Resource $server ): ?string {
		$fresh = Resource::find( $licence->id() );
		$app   = (string) $server->get( 'app_id' );

		if ( ! $fresh || ProxmoxDriver::ID !== $server->get( 'driver' ) || in_array( $server->get( 'state' ), array( 'failed', 'deleting', 'deleted' ), true ) ) {
			return 'The server is not available.';
		}

		if ( ! $fresh->owned_by( (int) ( $server->license()['user_id'] ?? 0 ) ) ) {
			return 'The licence belongs to another customer.';
		}

		if ( '' === $app || self::app_of( $fresh ) !== $app || ! self::reusable( $fresh ) ) {
			return 'The licence is no longer kept for this app.';
		}

		$current = AppLicence::current( $server );

		if ( $current && 'ended' !== AppLicence::state( $current ) && $current->id() !== $fresh->id() ) {
			return 'The server has a licence for this app already.';
		}

		$sub = self::subscription( $fresh );

		try {
			// WooCommerce Subscriptions restores the end date and sets the next payment to it: nothing is charged now.
			$sub->update_status( 'active', __( 'The kept app licence is used on a new server.', 'beltoft-services' ) );
		} catch ( \Exception $e ) {
			return 'The licence subscription cannot be active again: ' . $e->getMessage();
		}

		if ( ! $sub->has_status( 'active' ) ) {
			return 'The licence subscription cannot be active again.';
		}

		$sub->delete_meta_data( DiskCascade::MARKER );
		$sub->delete_meta_data( DiskCascade::ORIGINAL_END );
		$sub->save();

		if ( ! $fresh->set( array( 'server_license' => (int) ( $server->license()['id'] ?? 0 ), self::KEPT => null ) ) ) {
			try {
				$sub->update_status( 'pending-cancel', __( 'The kept app licence could not be linked to the new server.', 'beltoft-services' ) );
			} catch ( \Exception $e ) {
				\BeltoftServices\Alerts::admin( $fresh, 'The kept licence could not be linked and its subscription could not be put back: check subscription #' . (int) $sub->get_id() . '.' );
			}

			return 'The licence could not be linked.';
		}

		Events::add( (int) ( $fresh->license()['id'] ?? 0 ), 'service', 'Kept app licence used on server license ' . (int) ( $server->license()['id'] ?? 0 ) . '.' );

		/**
		 * A kept licence was linked to a server. A vendor driver moves its vendor licence to the server (it must not order or release).
		 *
		 * @param Resource $licence The licence resource.
		 * @param Resource $server  The server.
		 * @param string   $app_id  App id.
		 */
		do_action( self::ATTACHED, $fresh, $server, $app );

		return null;
	}

	/**
	 * The install's first step for a paid app: the kept licence the customer chose is linked to the server. Null when nothing was chosen or it is linked.
	 * A licence that cannot be used fails the install in words the customer can read; the choice stays, so Try again repeats it.
	 *
	 * @param Resource $server Server.
	 */
	public static function claim_chosen( Resource $server ): ?StepResult {
		$id = (int) $server->get( self::USE );

		if ( $id < 1 ) {
			return null;
		}

		$current = AppLicence::current( $server );

		if ( $current && 'ended' !== AppLicence::state( $current ) && $current->id() === $id ) {
			$server->set( array( self::USE => null ) );

			return null;
		}

		$licence = Resource::find( $id );
		$why     = $licence ? self::attach( $licence, $server ) : 'The licence was not found.';

		if ( null === $why ) {
			$server->set( array( self::USE => null ) );

			return null;
		}

		return StepResult::fail( 'The app licence you chose can no longer be used. ' . $why . ' Choose the app again to buy a licence.' );
	}
}
