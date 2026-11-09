<?php
/**
 * Email lite: the mail domain that comes with a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftLicenses\Licenses;
use BeltoftServices\Children;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 9.1. Setup is optional: the hosting is ready without it. Enabling makes a **child mail resource** with the
 * existing mail driver, with the limits of the hosting plan (mailboxes, size, aliases) and one domain, and its
 * sending limit per minute from the plan (resource meta `mail_rl`, which wins over the global setting for that
 * domain only). The child is a resource with a license of its own (a resource needs one) and no subscription; the
 * parent's license governs it through the Children cascade (pause, resume, delete follow the hosting account).
 * The mail driver does the rest: proof of the domain, the domain on the mail server, DKIM, DNS records.
 *
 * Not in this version: the hidden system mailbox for website mail and the SMTP file in the container (plan 9.2),
 * and changing the domain while no mailbox exists.
 */
final class LiteMail {

	/**
	 * The account's own domain: the oldest name that is not a temporary preview address, in whatever state. The main
	 * domain is anchored on that name; whether it is connected yet is a separate question (see ready_domain()).
	 *
	 * @param Resource $parent Hosting resource.
	 */
	public static function own_domain( Resource $parent ): ?string {
		foreach ( SiteDomains::for_resource( $parent->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] ) {
				return MailDomain::normalise( $row['host'] );
			}
		}

		return null;
	}

	/**
	 * The own domain once it is connected (active), else null: Email lite is offered and set up only then.
	 *
	 * @param Resource $parent Hosting resource.
	 */
	public static function ready_domain( Resource $parent ): ?string {
		$own = self::own_domain( $parent );
		$row = null === $own ? null : SiteDomains::find( $own );

		return null !== $row && 'active' === $row['state'] ? $own : null;
	}

	/**
	 * Makes the Email lite domain of an account.
	 *
	 * @param Resource $parent Hosting resource.
	 * @param string   $domain The mail domain: the account's own domain (empty means it).
	 * @return Resource|\WP_Error The child resource, or the refusal.
	 */
	public static function enable( Resource $parent, string $domain ) {
		$parent = Resource::find( $parent->id() ) ?? $parent; // The links are read fresh.

		if ( HostingDriver::ID !== $parent->get( 'driver' ) || ! $parent->is_live() || null === Records::for_resource( $parent->id() ) ) {
			return new \WP_Error( 'not_hosting', __( 'This is not a live hosting account.', 'beltoft-services' ) );
		}

		foreach ( Children::of( $parent ) as $child ) {
			if ( MailDriver::ID === $child->get( 'driver' ) && 'deleted' !== $child->get( 'state' ) ) {
				return new \WP_Error( 'exists', __( 'This account has Email lite already.', 'beltoft-services' ) );
			}
		}

		$own = self::ready_domain( $parent );

		if ( null === $own ) {
			return new \WP_Error( 'no_domain', __( 'Connect your own domain to a website first. Email lite uses that domain.', 'beltoft-services' ) );
		}

		$name = '' === trim( $domain ) ? $own : MailDomain::normalise( $domain );

		if ( null === $name ) {
			return new \WP_Error( 'bad_domain', __( 'The domain is not valid.', 'beltoft-services' ) );
		}

		// One mail domain, the account's own: never the temporary preview address, never another name.
		if ( $name !== $own ) {
			return new \WP_Error( 'not_own_domain', __( 'Email lite uses the domain connected to your hosting account.', 'beltoft-services' ) );
		}

		$plan = Plan::typed( (array) $parent->get( 'plan' ) );

		if ( (int) $plan['mail_mailboxes'] < 1 ) {
			return new \WP_Error( 'no_mail', __( 'The plan has no mailboxes.', 'beltoft-services' ) );
		}

		$parent_license = $parent->license();
		$slug           = sanitize_title( (string) ( $parent_license['product_slug'] ?? 'hosting' ) ) . '-mail';

		// A license without a service product (its slug is not a registered service slug, on purpose): the lifecycle
		// listeners leave it alone, the parent decides.
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
			return new \WP_Error( 'license', __( 'The Email lite license could not be made.', 'beltoft-services' ) );
		}

		$child = Resource::create(
			$license,
			Registry::get( MailDriver::ID ),
			array(
				'mailboxes' => (int) $plan['mail_mailboxes'],
				'quota_mb'  => (int) $plan['mail_quota_mb'],
				'aliases'   => max( 1, (int) $plan['mail_aliases'] ),
				'domains'   => 1,
			),
			array(
				MailDomain::META_DOMAIN => $name,
				'mail_rl'               => array( 'minute' => (int) $plan['mail_per_minute'] ),
			)
		);

		if ( is_wp_error( $child ) ) {
			return $child;
		}

		if ( ! Children::attach( $parent, $child ) ) {
			// No half-made child: the service never started, so it is closed and its license revoked, and a retry starts clean.
			$child->set( array( 'state' => 'deleted', 'desired' => 'deleted' ) );
			Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );

			return new \WP_Error( 'link', __( 'The Email lite service could not be linked to the account.', 'beltoft-services' ) );
		}

		Jobs::enqueue( $child->id() );

		return Resource::find( $child->id() ) ?? $child;
	}
}
