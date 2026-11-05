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
	 * Makes the Email lite domain of an account.
	 *
	 * @param Resource $parent Hosting resource.
	 * @param string   $domain The mail domain, normally the main site domain.
	 * @return Resource|\WP_Error The child resource, or the refusal.
	 */
	public static function enable( Resource $parent, string $domain ) {
		$parent = Resource::find( $parent->id() ) ?? $parent; // The links are read fresh.

		if ( HostingDriver::ID !== $parent->get( 'driver' ) || ! $parent->is_live() || null === Records::for_resource( $parent->id() ) ) {
			return new \WP_Error( 'not_hosting', 'This is not a live hosting account.' );
		}

		foreach ( Children::of( $parent ) as $child ) {
			if ( MailDriver::ID === $child->get( 'driver' ) && 'deleted' !== $child->get( 'state' ) ) {
				return new \WP_Error( 'exists', 'This account has Email lite already.' );
			}
		}

		$name = MailDomain::normalise( $domain );

		if ( null === $name ) {
			return new \WP_Error( 'bad_domain', 'The domain is not valid.' );
		}

		$plan = Plan::typed( (array) $parent->get( 'plan' ) );

		if ( (int) $plan['mail_mailboxes'] < 1 ) {
			return new \WP_Error( 'no_mail', 'The plan has no mailboxes.' );
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
			return new \WP_Error( 'license', 'The Email lite license could not be made.' );
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

			return new \WP_Error( 'link', 'The Email lite service could not be linked to the account.' );
		}

		Jobs::enqueue( $child->id() );

		return Resource::find( $child->id() ) ?? $child;
	}
}
