<?php
/**
 * License actions to desired service states.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Events;
use BeltoftLicenses\Import;
use BeltoftLicenses\Licenses;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to beltoft-licenses only. Its license statuses already carry WooCommerce
 * Subscriptions' lifecycle (grace while on hold, pending-cancel running to the end of the paid
 * period, expiry, refunds), so nothing here reads WCS. A license event only records what the
 * service should become (Resource::desire()) and queues the resource: the driver work runs
 * in the reconcile job.
 *
 * - created and active: the service is created and queued for provisioning;
 * - active again, by its status or its expiry: ready (a license with no live service gets a
 *   new one);
 * - suspended, expired, revoked, or active but past its expiry (by its status or its
 *   expiry): paused;
 * - sites, variation or product changed: a new plan snapshot, applied by update_plan; a
 *   product with another driver replaces the service (deleted, then made anew) when it is
 *   not protected and the license is active; otherwise the admin is alerted once.
 *
 * Every listener does nothing while beltoft-licenses imports.
 */
final class Lifecycle {

	/**
	 * Action Scheduler hook that stores a plan change whose resource lock was busy.
	 */
	public const PLAN_HOOK = 'beltoft_services_plan_changed';

	/**
	 * Seconds before a plan change that found the lock busy is tried again.
	 */
	public const PLAN_RETRY = 60;

	/**
	 * License columns that change the plan.
	 */
	public const PLAN_COLUMNS = array( 'max_activations', 'variation_id', 'product_id' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_licenses_license_created', array( __CLASS__, 'on_created' ) );
		add_action( 'beltoft_licenses_status_changed', array( __CLASS__, 'on_status' ), 10, 2 );
		add_action( 'beltoft_licenses_license_updated', array( __CLASS__, 'on_updated' ), 10, 3 );
		add_action( 'beltoft_licenses_expiry_changed', array( __CLASS__, 'on_expiry' ), 10, 2 );
		add_action( self::PLAN_HOOK, array( __CLASS__, 'apply_plan' ) );
	}

	/**
	 * Whether a listener leaves this license alone: an import is running, or it is not a
	 * service license.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private static function skip( array $license ): bool {
		return Import::is_running() || ! Product::is_service_license( $license );
	}

	/**
	 * Is the license usable right now (active and not past its expiry)?
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private static function is_active( array $license ): bool {
		return Licenses::STATUS_ACTIVE === Licenses::effective_status( $license );
	}

	/**
	 * A license was created (paid order, subscription, free plan, admin): an active service
	 * license gets its service.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function on_created( $license ): void {
		if ( ! is_array( $license ) || self::skip( $license ) || ! self::is_active( $license ) ) {
			return;
		}

		self::provide( $license );
	}

	/**
	 * A license's status changed. Active (and not past its expiry): ready. Anything else:
	 * paused. A paused service keeps its data; only retention deletes it.
	 *
	 * @param array<string, mixed> $license License after the change.
	 * @param string               $old     Previous status (unused: the new status decides).
	 */
	public static function on_status( $license, $old ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( ! is_array( $license ) || self::skip( $license ) ) {
			return;
		}

		$resource = Resource::for_license( (int) $license['id'] );

		if ( self::is_active( $license ) ) {
			if ( ! $resource ) {
				// Active again after its service was deleted (or never made): a new one.
				self::provide( $license );
				return;
			}

			self::desire( $resource, 'ready', 'License active' );
			return;
		}

		if ( $resource ) {
			self::desire( $resource, 'paused', sprintf( 'License %s', Licenses::effective_status( $license ) ) );
		}
	}

	/**
	 * A license's expiry changed without a status change (a renewal clears it, on-hold and
	 * pending-cancel set it, an admin moves it). The same decision as on_status(), taken only
	 * when it changes what the service should be.
	 *
	 * @param array<string, mixed> $license License after the change.
	 * @param string|null          $old     Previous expiry (unused: the new one decides).
	 */
	public static function on_expiry( $license, $old ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( ! is_array( $license ) || self::skip( $license ) ) {
			return;
		}

		$resource = Resource::for_license( (int) $license['id'] );
		$active   = self::is_active( $license );

		if ( ! $resource ) {
			if ( $active ) {
				self::provide( $license );
			}
			return;
		}

		$want = $active ? 'ready' : 'paused';

		// Compared with what will apply: a desired state waiting for the lock is newer than the
		// stored one. A deletion is terminal; the same desired state needs nothing.
		$pending = $resource->pending_desire();
		$desired = $pending ? $pending['state'] : $resource->get( 'desired' );

		if ( 'deleted' === $desired || $want === $desired ) {
			return;
		}

		self::desire( $resource, $want, $active ? 'License active' : sprintf( 'License %s', Licenses::effective_status( $license ) ) );
	}

	/**
	 * A license was updated. A change of sites, variation or product takes a new plan.
	 *
	 * @param array<string, mixed> $after   License after the update.
	 * @param array<string, mixed> $before  License before the update.
	 * @param string[]             $changed Changed columns.
	 */
	public static function on_updated( $after, $before, $changed ): void {
		if ( ! is_array( $after ) || ! is_array( $changed ) || self::skip( $after ) ) {
			return;
		}

		if ( ! array_intersect( $changed, self::PLAN_COLUMNS ) ) {
			return;
		}

		self::apply_plan( (int) $after['id'] );
	}

	/**
	 * Stores the license's current plan on its service and queues the update. When the
	 * product's driver is not the service's, the service is replaced instead. The plan is
	 * read when this runs, so a retry always takes the latest one. When the resource lock
	 * stays busy (a job is running), it is tried again a moment later in a job of its own.
	 *
	 * @param int $license_id License id.
	 */
	public static function apply_plan( $license_id ): void {
		$license_id = (int) $license_id;
		$license    = Licenses::find( $license_id );

		if ( ! $license || self::skip( $license ) ) {
			return;
		}

		$resource = Resource::for_license( $license_id );

		if ( ! $resource ) {
			return;
		}

		$product_id   = (int) ( $license['product_id'] ?? 0 );
		$variation_id = (int) ( $license['variation_id'] ?? 0 );

		$driver = Product::driver_for( $product_id );

		// Another driver: the old service is deleted, and once that completes the reconciler
		// creates a new one with the product's driver and plan (replace = true). The old
		// driver never gets the new product's plan.
		if ( $driver && $driver->id() !== $resource->get( 'driver' ) ) {
			// Only an unprotected service of an active license is replaced; anything else (a
			// migrated tenant, a suspended license) is left for an admin to decide.
			if ( ! $resource->get( 'protected' ) && self::is_active( $license ) ) {
				self::desire( $resource, 'deleted', sprintf( 'Product changed to another service driver (%s); the service is replaced', $driver->id() ), true );
			} else {
				self::driver_conflict( $license, $resource, $product_id );
			}
			return;
		}

		// The driver matches (again): a later conflict is alerted again.
		if ( $driver ) {
			delete_option( self::CONFLICT_PREFIX . $resource->id() );
		}

		// A product that is no longer a service keeps the last plan; the sites may still change.
		$plan = $driver ? Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ) : $resource->get( 'plan' );

		$stored = $resource->change_plan( $plan, 'Plan changed' );

		if ( true === $stored ) {
			Jobs::enqueue( $resource->id() );
		} elseif ( null === $stored ) {
			self::retry_plan( $license_id );
		}
		// False: it could not be stored, and the admin was alerted.
	}

	/**
	 * Option prefix remembering which product a driver conflict was alerted for, per resource.
	 */
	public const CONFLICT_PREFIX = 'beltoft_services_driver_conflict_';

	/**
	 * Alert text when a service is not replaced after a switch to another driver.
	 */
	public const CONFLICT_ERROR = 'Product changed to a product with another driver; decide on the license screen.';

	/**
	 * The service keeps its driver and plan; the admin is told once per resource and product.
	 *
	 * @param array<string, mixed> $license    License row.
	 * @param Resource             $r          Resource.
	 * @param int                  $product_id The new product.
	 */
	private static function driver_conflict( array $license, Resource $r, int $product_id ): void {
		$option = self::CONFLICT_PREFIX . $r->id();

		if ( (int) get_option( $option, 0 ) === $product_id ) {
			return;
		}

		update_option( $option, $product_id, false );
		Events::add( (int) $license['id'], 'service', self::CONFLICT_ERROR );
		Alerts::license( $license, self::CONFLICT_ERROR );
	}

	/**
	 * Queues a plan retry, once: only pending actions count, so the running retry can queue
	 * its own follow-up.
	 *
	 * @param int $license_id License id.
	 */
	private static function retry_plan( int $license_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::PLAN_HOOK,
				'args'     => array( $license_id ),
				'group'    => Jobs::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		if ( ! $pending ) {
			as_schedule_single_action( time() + self::PLAN_RETRY, self::PLAN_HOOK, array( $license_id ), Jobs::GROUP );
		}
	}

	/**
	 * Records a desired state and queues the resource. When desire() returns false it has
	 * already done what is needed: queued the change for the lock (and the job), refused it
	 * (a deletion is terminal, written to the history) or alerted that it could not be stored.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $state   ready, paused or deleted.
	 * @param string   $reason  History text.
	 * @param bool     $replace For deleted: a new service follows on an active license.
	 */
	private static function desire( Resource $r, string $state, string $reason, bool $replace = false ): void {
		if ( $r->desire( $state, $reason, $replace ) ) {
			Jobs::enqueue( $r->id() );
		}
	}

	/**
	 * Creates the license's service with the product's plan and queues its provisioning.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function provide( array $license ): void {
		$product_id   = (int) ( $license['product_id'] ?? 0 );
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$driver       = Product::driver_for( $product_id );

		if ( ! $driver ) {
			$id = $product_id > 0 ? get_post_meta( $product_id, Product::META_DRIVER, true ) : '';
			$id = is_scalar( $id ) ? (string) $id : '';

			self::not_created( $license, '' === $id ? 'The product has no service driver' : sprintf( 'The service driver "%s" is not available', $id ) );
			return;
		}

		$resource = Resource::create( $license, $driver, Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ) );

		if ( is_wp_error( $resource ) ) {
			// Another request holds the license lock: it is creating this service now.
			if ( 'locked' !== $resource->get_error_code() ) {
				self::not_created( $license, 'The service could not be created: ' . $resource->get_error_message() );
			}
			return;
		}

		Jobs::enqueue( $resource->id() );
	}

	/**
	 * Writes why a license has no service to its history and alerts the admin.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $error   Reason.
	 */
	private static function not_created( array $license, string $error ): void {
		Events::add( (int) $license['id'], 'service', $error );
		Alerts::license( $license, $error );
	}
}
