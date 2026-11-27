<?php
/**
 * A DNS-only zone of ours whose name servers were moved away at the registrar.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\Delegation;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
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
		if ( ! DnsSchema::ready() ) {
			return null;
		}

		$row = DnsAssignments::get( $domain );

		if ( null === $row || 'active' !== $row['status'] || (int) $row['resource_id'] <= 0 ) {
			return null;
		}

		$r = Resource::find( (int) $row['resource_id'] );

		return $r && 'dns' === $r->get( 'driver' ) ? $r : null;
	}

	/**
	 * Does the parent zone of the name list other name servers than ours? Read from what the service stored, never from the network.
	 *
	 * @param string $domain  Domain.
	 * @param int    $user_id Customer.
	 */
	public static function away( string $domain, int $user_id ): bool {
		unset( $user_id );

		$service = self::service( $domain );

		return null !== $service && 'other' === (string) $service->get( 'delegation' );
	}

	/**
	 * Reads the delegation of the domain's DNS service again when the days between two checks have passed.
	 *
	 * @param Resource $r Mail resource.
	 */
	public static function watch( Resource $r ): void {
		$service = self::service( (string) $r->get( MailDomain::META_DOMAIN ) );

		if ( null === $service || ! $service->is_live() ) {
			return;
		}

		$days = max( 1, (int) Settings::get( 'dns_delegation_recheck_days' ) );

		if ( time() - (int) $service->get( 'delegation_checked' ) >= $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			Delegation::refresh( $service );
		}
	}
}
