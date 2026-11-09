<?php
/**
 * Extra email domains on one mail plan.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftLicenses\Licenses;
use BeltoftServices\Children;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * An extra domain is a child mail resource on its own `child` license, linked with Children::attach() like Email lite.
 * The limits belong to the plan root and count over all its domains (see MailPlan).
 */
final class MailDomains {

	/** Order line meta: the plan root a bought domain joins. */
	public const ITEM_META = '_beltoft_services_mail_attach';

	/** Resource meta of a domain: set once it joined (or was refused), so a later ready does not try again. */
	public const ATTACH_DONE = 'mail_attach_done';

	/** A plan that was busy is asked again after this many seconds, until the window after the first try is over. */
	public const RETRY_HOOK   = 'beltoft_services_mail_attach_retry';
	public const ATTACH_UNTIL = 'mail_attach_until';
	public const RETRY_AFTER  = 300;
	public const RETRY_WINDOW = 7 * DAY_IN_SECONDS;

	/**
	 * Listens for a registered or transferred domain becoming ready.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'retry' ) );
		add_filter( 'beltoft_services_domain_cart_attach', array( __CLASS__, 'cart_attach' ), 10, 3 );
	}

	/**
	 * Filter beltoft_services_domain_cart_attach: the root id when the customer may add a domain to it.
	 *
	 * @param mixed $attach  Current answer.
	 * @param mixed $mail_for Asked root id.
	 * @param mixed $user_id Customer.
	 */
	public static function cart_attach( $attach, $mail_for, $user_id ) {
		$root = self::attachable( (int) $mail_for, (int) $user_id );

		return $root ? $root->id() : $attach;
	}

	/**
	 * The plan root a user may add a domain to now: theirs, a mail plan root, live, with room. Null otherwise.
	 *
	 * @param int $root_id Resource id the cart or order line names.
	 * @param int $user_id Customer.
	 */
	public static function attachable( int $root_id, int $user_id ): ?Resource {
		$root = self::attach_check( $root_id, $user_id );

		return $root instanceof Resource ? $root : null;
	}

	/**
	 * @return Resource|string The root, or a short reason (English, for an order note).
	 */
	private static function attach_check( int $root_id, int $user_id ) {
		$root = $root_id > 0 && $user_id > 0 ? Resource::find( $root_id ) : null;

		if ( ! $root || MailDriver::ID !== $root->get( 'driver' ) || MailPlan::root( $root )->id() !== $root->id() || (int) ( $root->license()['user_id'] ?? 0 ) !== $user_id ) {
			return 'the email plan is not available to this customer';
		}

		if ( 'ready' !== (string) $root->get( 'state' ) || 'ready' !== (string) $root->get( 'desired' ) ) {
			return 'the email plan is not active';
		}

		if ( count( MailPlan::counted( $root ) ) >= MailPlan::limits( $root )['domains'] ) {
			return 'the email plan has no room for another domain';
		}

		return $root;
	}

	/**
	 * Is this domain already a live member of the plan?
	 *
	 * @param mixed  $root Plan root.
	 * @param string $name Domain.
	 */
	private static function in_plan( $root, string $name ): bool {
		foreach ( $root instanceof Resource ? MailPlan::members( $root ) : array() as $member ) {
			if ( MailGuard::domain( $member ) === strtolower( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Action beltoft_services_state_changed: a domain bought from the email area joins the plan when it is ready.
	 * The root named on the order line is checked again here: owner, live, room. Otherwise an order note, nothing else.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'ready' !== $new || ! $r instanceof Resource || 'domain' !== $r->get( 'driver' ) ) {
			return;
		}

		self::attempt( $r );
	}

	/**
	 * Action beltoft_services_mail_attach_retry: the plan was busy when the domain became ready, so it is asked again.
	 *
	 * @param mixed $id Domain resource id.
	 */
	public static function retry( $id ): void {
		$r = Resource::find( (int) $id );

		if ( $r && 'domain' === $r->get( 'driver' ) && 'ready' === (string) $r->get( 'state' ) ) {
			self::attempt( $r );
		}
	}

	/**
	 * Joins a ready domain bought for a plan to that plan (once; a busy plan is asked again, for a week).
	 *
	 * @param Resource $r Domain resource.
	 */
	private static function attempt( Resource $r ): void {
		// Read fresh: another run of the same event may have finished already.
		$r = Resource::find( $r->id() ) ?? $r;

		if ( $r->get( self::ATTACH_DONE ) ) {
			return;
		}

		$item_id = (int) $r->get( 'order_item_id' );
		$item    = $item_id > 0 ? \WC_Order_Factory::get_order_item( $item_id ) : null;
		$root_id = $item ? (int) $item->get_meta( self::ITEM_META, true ) : 0;

		if ( $root_id <= 0 ) {
			return;
		}

		$name  = (string) $r->get( 'domain' );
		$user  = (int) ( $r->license()['user_id'] ?? 0 );
		$root  = self::attach_check( $root_id, $user );
		$added = $root instanceof Resource ? self::add( $root, $name, array( 'mail_mode_hint' => 'ours' ) ) : null;

		// The domain is already in the plan (another run got there first): joined, nothing to tell.
		$already = is_wp_error( $added ) && 'bad_domain' === $added->get_error_code() && self::in_plan( $root, $name );

		if ( $added instanceof Resource || $already ) {
			$r->set( array( self::ATTACH_DONE => 'joined' ) );

			return;
		}

		$why = is_string( $root ) ? $root : ( is_wp_error( $added ) ? $added->get_error_code() : 'unknown' );

		// A busy plan is asked again a little later (a domain reaches ready once); anything else is final.
		$first = true;

		if ( is_wp_error( $added ) && in_array( $added->get_error_code(), array( 'busy', 'not_ready' ), true ) ) {
			$until = (int) $r->get( self::ATTACH_UNTIL );
			$first = 0 === $until;

			if ( $first ) {
				$until = time() + self::RETRY_WINDOW;
				$r->set( array( self::ATTACH_UNTIL => $until ) );
			}

			if ( time() + self::RETRY_AFTER <= $until && function_exists( 'as_schedule_single_action' ) ) {
				if ( ! ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::RETRY_HOOK, array( $r->id() ), 'beltoft-services' ) ) ) {
					as_schedule_single_action( time() + self::RETRY_AFTER, self::RETRY_HOOK, array( $r->id() ), 'beltoft-services' );
				}
			} else {
				$r->set( array( self::ATTACH_DONE => 'refused' ) );
				$first = true;
			}
		} else {
			$r->set( array( self::ATTACH_DONE => 'refused' ) );
		}

		$order = $first ? wc_get_order( (int) ( $r->license()['order_id'] ?? 0 ) ) : null;

		if ( $order ) {
			$order->add_order_note( sprintf( 'The domain %1$s was not added to the email plan: %2$s.', $name, $why ) );
		}
	}

	/**
	 * Adds a domain to the plan of a root.
	 *
	 * @param Resource             $root   Plan root.
	 * @param string               $domain Typed domain.
	 * @param array<string, mixed> $meta   Extra meta of the new resource.
	 * @return Resource|\WP_Error The child resource, or the refusal.
	 */
	public static function add( Resource $root, string $domain, array $meta = array() ) {
		$root = Resource::find( $root->id() ) ?? $root; // The links are read fresh.

		if ( MailDriver::ID !== $root->get( 'driver' ) || MailPlan::root( $root )->id() !== $root->id() || 'ready' !== (string) $root->get( 'state' ) || 'ready' !== (string) $root->get( 'desired' ) ) {
			return new \WP_Error( 'not_ready', __( 'You can add a domain when the plan is active.', 'beltoft-services' ) );
		}

		$name = MailDomain::normalise( $domain );

		if ( null === $name ) {
			return new \WP_Error( 'bad_domain', (string) MailDomain::refusal( $domain ) );
		}

		$locked = null;
		$ran    = $root->locked(
			static function () use ( $root, $name, $meta, &$locked ): bool {
				$locked = self::add_locked( $root, $name, $meta );

				return true;
			}
		);

		if ( null === $ran || null === $locked ) {
			return new \WP_Error( 'busy', __( 'The plan is busy. Try again in a moment.', 'beltoft-services' ) );
		}

		return $locked;
	}

	/**
	 * The checks and the creation, under the lock of the root.
	 *
	 * @param Resource             $root Plan root.
	 * @param string               $name Normalised domain.
	 * @param array<string, mixed> $meta Extra meta.
	 * @return Resource|\WP_Error
	 */
	private static function add_locked( Resource $root, string $name, array $meta ) {
		$limits = MailPlan::limits( $root );

		foreach ( MailPlan::members( $root ) as $member ) {
			if ( MailGuard::domain( $member ) === $name ) {
				return new \WP_Error( 'bad_domain', __( 'That domain is already in your plan.', 'beltoft-services' ) );
			}
		}

		if ( count( MailPlan::counted( $root ) ) >= $limits['domains'] ) {
			return new \WP_Error( 'no_room', __( 'Your plan has no room for another domain.', 'beltoft-services' ) );
		}

		$why = MailDomain::refusal( $name );

		if ( null !== $why ) {
			return new \WP_Error( 'bad_domain', $why );
		}

		$parent_license = $root->license();
		$slug           = sanitize_title( (string) ( $parent_license['product_slug'] ?? 'mail' ) ) . '-domain';

		// A license without a service product (its slug is not a registered service slug, on purpose): the lifecycle
		// listeners leave it alone, the root decides.
		$license = Licenses::create(
			array(
				'product_slug'    => $slug,
				'email'           => (string) ( $parent_license['email'] ?? '' ),
				'user_id'         => (int) ( $parent_license['user_id'] ?? 0 ),
				'max_activations' => 1,
				'source'          => 'child',
			)
		);

		if ( ! is_array( $license ) ) {
			return new \WP_Error( 'license', __( 'The domain could not be added.', 'beltoft-services' ) );
		}

		$child = Resource::create( $license, Registry::get( MailDriver::ID ), $limits, array_merge( $meta, array( MailDomain::META_DOMAIN => $name ) ) );

		if ( is_wp_error( $child ) ) {
			// No active license without a service.
			Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );

			return $child;
		}

		if ( ! Children::attach( $root, $child ) ) {
			// No half-made child: the service never started, so it is closed and its license revoked, and a retry starts clean.
			$child->set( array( 'state' => 'deleted', 'desired' => 'deleted' ) );
			Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );

			return new \WP_Error( 'link', __( 'The domain could not be linked to your plan.', 'beltoft-services' ) );
		}

		Jobs::enqueue( $child->id() );
		$child = Resource::find( $child->id() ) ?? $child;

		/**
		 * Fires when a domain was added to a mail plan.
		 *
		 * @param Resource $child The new domain.
		 * @param Resource $root  The plan root.
		 */
		do_action( 'beltoft_services_mail_domain_added', $child, $root );

		return $child;
	}

	/**
	 * Removes an extra domain, when it holds no mailboxes and the plan is active.
	 *
	 * @param Resource $child The extra domain.
	 * @return true|\WP_Error
	 */
	public static function remove( Resource $child ) {
		$root = MailPlan::root( $child );

		if ( $root->id() === $child->id() ) {
			return new \WP_Error( 'is_root', __( 'The first domain of the plan cannot be removed.', 'beltoft-services' ) );
		}

		if ( 'ready' !== (string) $root->get( 'state' ) ) {
			return new \WP_Error( 'not_active', __( 'You can remove a domain when the plan is active again.', 'beltoft-services' ) );
		}

		$result = null;
		$ran    = $root->locked(
			static function () use ( $child, &$result ): bool {
				$result = self::remove_locked( $child );

				return true;
			}
		);

		return null === $ran || null === $result ? new \WP_Error( 'busy', __( 'The domain could not be removed. Try again in a moment.', 'beltoft-services' ) ) : $result;
	}

	/**
	 * The checks and the desire to delete, under the lock of the root. Fails closed: what cannot be read is not empty.
	 *
	 * @param Resource $child The extra domain.
	 * @return true|\WP_Error
	 */
	private static function remove_locked( Resource $child ) {
		if ( in_array( 'domain', (array) $child->get( 'steps' ), true ) ) {
			$boxes   = MailPlan::mailboxes_of( $child );
			$aliases = is_wp_error( $boxes ) ? $boxes : Aliases::all( $child );

			if ( is_wp_error( $aliases ) ) {
				return new \WP_Error( 'unavailable', __( 'Try again in a moment.', 'beltoft-services' ) );
			}

			if ( $boxes > 0 || count( $aliases ) > 0 ) {
				return new \WP_Error( 'has_mailboxes', __( 'Delete the mailboxes of this domain first.', 'beltoft-services' ) );
			}
		}

		if ( ! $child->desire( 'deleted', 'The domain was removed from the email plan' ) ) {
			return new \WP_Error( 'busy', __( 'The domain could not be removed. Try again in a moment.', 'beltoft-services' ) );
		}

		Jobs::enqueue( $child->id() );

		return true;
	}
}
