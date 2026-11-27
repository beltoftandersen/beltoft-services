<?php
/**
 * Takes the email records we made out of the customer's zone when the email domain is deleted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Only for a zone of ours, only the records this service wrote (mail_records, mark written_by_us), only a record that still holds
 * exactly the value we wrote, and only through MailDns::unpublish() (the zone writer). A domain at another provider has no zone
 * of ours: nothing is written anywhere. Behind the setting mail_dns_cleanup_on_delete. Run again after a retry it finds nothing
 * more to remove.
 */
final class MailCleanup {

	/**
	 * Removes what we wrote.
	 *
	 * @param Resource $r Mail resource that is being deleted.
	 * @return true|\WP_Error True when done or not due; an error when the zone could not be changed (the delete tries again).
	 */
	public static function run( Resource $r ) {
		if ( ! MailSettings::dns_cleanup_on_delete() ) {
			return true;
		}

		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$user   = (int) ( $r->license()['user_id'] ?? 0 );
		$rows   = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['written_by_us'] ) && isset( $row['name'], $row['type'], $row['value'], $row['key'] ) ) {
				$rows[] = $row;
			}
		}

		// Never for DNS elsewhere: the zone must be the customer's own on our name servers right now.
		if ( '' === $domain || array() === $rows || 'ours' !== (string) $r->get( 'mail_mode' ) || ! MailDomain::on_our_servers( $domain, $user ) ) {
			return true;
		}

		foreach ( $rows as $row ) {
			$done = MailDns::unpublish( $user, $domain, $row );

			if ( is_wp_error( $done ) && 'not_ours' !== $done->get_error_code() ) {
				return $done;
			}
		}

		// What was removed is not ours to remove again.
		$kept = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			if ( is_array( $row ) ) {
				unset( $row['written_by_us'] );
			}

			$kept[] = $row;
		}

		$r->set( array( 'mail_records' => $kept, 'mail_dns_cleaned' => time() ) );

		return true;
	}
}
