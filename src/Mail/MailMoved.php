<?php
/**
 * A DNS-only zone of ours whose name servers were moved away at the registrar.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\DnsWatch;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The work is the shared DNS watch's (Domains\DnsWatch); these are email's names for it.
 * Whether a name counts as ours is a database answer (ZoneAccess). For a DNS hosting service the parent zone can say otherwise:
 * the customer pointed the name servers somewhere else. The delegation of the service is read again every few days (the days
 * between two checks are a setting of the domains tab); once it says other, the name stops counting as ours for email, and the
 * look at the domain reads public DNS like for any domain hosted elsewhere.
 */
final class MailMoved {

	/**
	 * The DNS hosting service that holds a name for its customer.
	 *
	 * @param string $domain Domain.
	 */
	public static function service( string $domain ): ?Resource {
		return DnsWatch::service( $domain );
	}

	/**
	 * Does the parent zone of the name list other name servers than ours? Read from what the service stored, never from the network.
	 *
	 * @param string $domain  Domain.
	 * @param int    $user_id Customer.
	 */
	public static function away( string $domain, int $user_id ): bool {
		unset( $user_id );

		return DnsWatch::away( $domain );
	}

	/**
	 * Reads the delegation of the domain's DNS service again when the days between two checks have passed.
	 *
	 * @param Resource $r Mail resource.
	 */
	public static function watch( Resource $r ): void {
		DnsWatch::watch_delegation( (string) $r->get( MailDomain::META_DOMAIN ) );
	}
}
