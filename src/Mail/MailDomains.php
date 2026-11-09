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

		if ( count( MailPlan::members( $root ) ) >= $limits['domains'] ) {
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
