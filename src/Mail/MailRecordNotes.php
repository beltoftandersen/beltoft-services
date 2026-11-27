<?php
/**
 * Tells the DNS records editor which records an email domain uses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Listener of beltoft_services_dns_record_notes (Domains\RecordRoutes and the domain page ask): for a zone that holds a live
 * email domain of the same customer on our name servers, the three records mail stops without (MX, SPF, DKIM) with the words the
 * editor shows before the customer deletes one. A note names the record by name, type and a part of its value, so another TXT or
 * MX at the same name is not warned about. The recommended records (DMARC, autoconfig, autodiscover) are left out.
 */
final class MailRecordNotes {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_dns_record_notes', array( __CLASS__, 'notes' ), 10, 3 );
	}

	/**
	 * The notes.
	 *
	 * @param mixed $notes   Notes so far: arrays of name, type, match, text.
	 * @param mixed $zone    Zone (the domain).
	 * @param mixed $user_id The customer.
	 * @return mixed
	 */
	public static function notes( $notes, $zone = '', $user_id = 0 ) {
		if ( ! is_array( $notes ) || ! is_string( $zone ) || '' === $zone || (int) $user_id <= 0 ) {
			return $notes;
		}

		foreach ( Resource::find_by_meta( MailDriver::KIND, MailDomain::META_DOMAIN, strtolower( $zone ) ) as $id ) {
			$r = Resource::find( (int) $id );

			if ( ! $r || MailDriver::ID !== $r->get( 'driver' ) || ! $r->is_live() || in_array( (string) $r->get( 'state' ), array( 'deleted', 'deleting', 'failed' ), true ) || (int) ( $r->license()['user_id'] ?? 0 ) !== (int) $user_id || 'ours' !== (string) $r->get( 'mail_mode' ) ) {
				continue;
			}

			foreach ( (array) $r->get( 'mail_records' ) as $row ) {
				if ( ! is_array( $row ) || ! MailDns::required( (string) ( $row['key'] ?? '' ) ) || ! isset( $row['name'], $row['type'], $row['value'] ) ) {
					continue;
				}

				$notes[] = array(
					'name'  => (string) $row['name'],
					'type'  => (string) $row['type'],
					'match' => 'MX' === $row['type'] ? (string) $row['value'] : substr( (string) $row['value'], 0, strpos( (string) $row['value'], 'v=DKIM1' ) === 0 ? 7 : 6 ),
					'text'  => __( 'This record is used by your email. Mail stops if you delete it.', 'beltoft-services' ),
				);
			}

			break;
		}

		return $notes;
	}
}
