<?php
/**
 * Making a pay as you go extra disk larger, without touching any subscription.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A pay as you go disk is a licence of the usage account (source usage), billed from the usage ledger by Rates::items(): the
 * licence's variation gives the rate, the resource's plan gives the size. It has no subscription of its own, so the monthly
 * disk's WooCommerce Subscriptions switch (DiskCart::grow_url) and cancel are never used for it: the usage account's
 * subscription is never read or changed here.
 *
 * Grow moves the licence to the next larger pay as you go size of the same product. beltoft-licenses reports the change,
 * Lifecycle::apply_plan() stores the new plan on the disk and queues the update, and the disk driver's own resize path
 * (DiskSteps::grow(): capacity reserved, resize, verified) does the work. The meter bills the disk by the size it really
 * has until the grow has finished (Rates::items(): the resource's `size_gb` is what the driver measured and is set only
 * when the resize is verified), so the larger rate starts with the first hour the disk is larger. When the grow fails for
 * good the licence goes back to the size the disk has and the admin is told (on_state_changed()).
 */
final class UsageDisk {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
	}

	/**
	 * Action beltoft_services_state_changed: a pay as you go disk whose grow finally failed goes back to the variation of
	 * the size it really has (the meter bills the disk by its real size while a grow is pending, but the licence must not
	 * stay on the larger size either), and the admin is told. Never throws: it runs inside the failure itself.
	 *
	 * @param mixed $r   Resource.
	 * @param mixed $old Old state.
	 * @param mixed $new New state.
	 */
	public static function on_state_changed( $r, $old = '', $new = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( 'failed' !== $new || ! $r instanceof Resource || ! self::is_usage( $r ) || 'update_plan' !== (string) $r->get( 'failed_op' ) ) {
			return;
		}

		try {
			$real    = (int) $r->get( 'size_gb' );
			$plan    = (array) $r->get( 'plan' );
			$planned = (int) ( $plan['disk_gb'] ?? 0 );
			$license = $r->license();

			if ( $real < 1 || $planned <= $real || empty( $license['id'] ) ) {
				return;
			}

			$back    = null;
			$back_gb = 0;

			foreach ( DiskField::sizes_for( true ) as $variation ) {
				$gb = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['disk_gb'] ?? 0 );

				if ( $variation->get_parent_id() === (int) ( $license['product_id'] ?? 0 ) && $gb <= $real && $gb > $back_gb ) {
					$back    = $variation;
					$back_gb = $gb;
				}
			}

			/* translators: 1: resource id, 2: GB asked, 3: GB the disk has, 4: error text. */
			$lines = array( sprintf( __( 'The pay as you go disk of resource %1$d could not be made larger (%2$d GB asked, %3$d GB it has): %4$s', 'beltoft-services' ), $r->id(), $planned, $real, (string) $r->get( 'last_error' ) ) );

			if ( $back ) {
				$plan['disk_gb'] = $real;
				// Plan equal to the size and the retries cleared: the update returns the disk to ready, and the customer can grow it again once there is room.
				$r->set( array_merge( array( 'plan' => $plan ), Resource::retry_reset() ) );
				Licenses::update( (int) $license['id'], array( 'variation_id' => $back->get_id() ) );
				Events::add( (int) $license['id'], 'service', 'The disk could not be made larger: the licence is back on the ' . $back_gb . ' GB pay as you go size' );
				Jobs::enqueue( $r->id() );
				/* translators: 1: GB the disk has, 2: GB of the size the licence was put on. */
				$lines[] = sprintf( __( 'The disk has %1$d GB. The licence was put back on the %2$d GB pay as you go size and is billed as such.', 'beltoft-services' ), $real, $back_gb );

				if ( $back_gb !== $real ) {
					$lines[] = __( 'No pay as you go size matches what the disk has exactly, so the licence sits on the next smaller size. Check the licence by hand.', 'beltoft-services' );
				}
			} else {
				$lines[] = __( 'No pay as you go size matches what the disk has; check the licence by hand.', 'beltoft-services' );
			}

			/* translators: %d: resource id. */
			Alerts::notify( sprintf( __( 'Pay as you go disk %d could not be made larger', 'beltoft-services' ), $r->id() ), $lines );
		} catch ( \Throwable $e ) {
			error_log( 'Beltoft Services: the failed disk grow could not be undone: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- runs inside the failure.
		}
	}

	/**
	 * Is this a pay as you go extra disk?
	 *
	 * @param Resource $disk Resource.
	 */
	public static function is_usage( Resource $disk ): bool {
		return DiskDriver::ID === (string) $disk->get( 'driver' ) && 'usage' === (string) ( $disk->license()['source'] ?? '' );
	}

	/**
	 * The size of the disk in GB now: what it has, or what it is being made.
	 *
	 * @param Resource $disk Resource.
	 */
	private static function size( Resource $disk ): int {
		return max( (int) $disk->get( 'size_gb' ), (int) ( ( (array) $disk->get( 'plan' ) )['disk_gb'] ?? 0 ) );
	}

	/**
	 * The next larger pay as you go size of the disk's own product that fits on its server's node, or null.
	 *
	 * @param Resource $disk Resource.
	 * @return \WC_Product_Variation|null
	 */
	public static function next( Resource $disk ): ?\WC_Product_Variation {
		if ( ! self::is_usage( $disk ) ) {
			return null;
		}

		$license = $disk->license();
		$have    = self::size( $disk );
		$server  = Resource::for_license( (int) $disk->get( 'server_license' ) );
		$record  = $server ? VmRecords::for_resource( $server->id() ) : null;
		$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
		$best    = null;
		$best_gb = 0;

		foreach ( DiskField::sizes_for( true ) as $variation ) {
			$gb = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['disk_gb'] ?? 0 );

			if ( $variation->get_parent_id() !== (int) ( $license['product_id'] ?? 0 ) || $gb <= $have || ( null !== $best && $gb >= $best_gb ) ) {
				continue;
			}

			if ( ! $record || ! $cluster ) {
				continue;
			}

			$best    = $variation;
			$best_gb = $gb;
		}

		return $best;
	}

	/**
	 * What the larger size adds at most in a month, for the button: "+€4.40 per month" (the difference between the sizes).
	 *
	 * @param Resource $disk Resource.
	 */
	public static function price_difference( Resource $disk ): string {
		$to   = self::next( $disk );
		$from = wc_get_product( (int) ( $disk->license()['variation_id'] ?? 0 ) );

		return $to && $from instanceof \WC_Product_Variation ? Rates::plus_per_month( max( 0.0, Rates::monthly_difference( $from, $to ) ), $to ) : '';
	}

	/**
	 * Makes the customer's pay as you go disk the next larger size.
	 *
	 * @param Resource $disk    Disk.
	 * @param int      $user_id The customer asking.
	 * @return true|\WP_Error
	 */
	public static function grow( Resource $disk, int $user_id ) {
		$license = $disk->license();
		$fail    = static fn( string $code, string $text, int $status ) => new \WP_Error( $code, $text, array( 'status' => $status ) );

		if ( $user_id < 1 || empty( $license['user_id'] ) || (int) $license['user_id'] !== $user_id || ! self::is_usage( $disk ) || ! $disk->is_live() ) {
			return $fail( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), 403 );
		}

		$done = null;
		$why  = '';

		$stored = $disk->locked(
			static function () use ( $disk, &$done, &$why ): bool {
				if ( 'ready' !== $disk->get( 'state' ) || true === $disk->get( 'linked' ) || null !== $disk->pending_desire() || 'ready' !== $disk->get( 'desired' ) ) {
					$why = __( 'This disk cannot be made larger right now. Please try again when it has finished what it is doing.', 'beltoft-services' );

					return false;
				}

				$to = self::next( $disk );

				if ( null === $to ) {
					$why = __( 'There is no larger size of this disk that can be made now.', 'beltoft-services' );

					return false;
				}

				$done = $to;

				return true;
			}
		);

		if ( null === $stored ) {
			return $fail( 'beltoft_services_grow_busy', __( 'The disk is busy. Please try again in a moment.', 'beltoft-services' ), 409 );
		}

		if ( ! $stored || ! $done instanceof \WC_Product_Variation ) {
			return $fail( 'beltoft_services_grow_refused', '' !== $why ? $why : __( 'The disk could not be made larger now.', 'beltoft-services' ), 422 );
		}

		// Only the licence's variation moves: the plan and the resize follow from it (Lifecycle::apply_plan), the account stays as it is.
		Licenses::update( (int) $license['id'], array( 'variation_id' => $done->get_id() ) );
		Events::add( (int) $license['id'], 'service', 'Customer made the disk larger: pay as you go size ' . (int) ( Product::plan( $done->get_parent_id(), $done->get_id() )['disk_gb'] ?? 0 ) . ' GB' );

		return true;
	}
}
