<?php
/**
 * The business email values of the service emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the mail_* values, the email domain and the link to the Email page to the service emails
 * (beltoft_services_email_values). Every key is always set, with its empty default.
 */
final class EmailValues {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_email_values', array( __CLASS__, 'values' ), 10, 3 );
	}

	/**
	 * The values.
	 *
	 * @param mixed $values Values so far.
	 * @param mixed $r      Resource.
	 * @return mixed
	 */
	public static function values( $values, $r ) {
		if ( ! is_array( $values ) || ! $r instanceof Resource ) {
			return $values;
		}

		$is_mail = MailDriver::ID === $r->get( 'driver' );
		$mail    = $is_mail ? ( Resource::find( $r->id() ) ?? $r ) : null;
		$license = $r->license();

		if ( $mail ) {
			$values['domain_name'] = HostName::display( (string) $mail->get( 'mail_domain' ) );

			if ( $license && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
				$values['account_url'] = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Email::class )->url( $license );
			}
		}

		$values['mail_domain']    = $mail ? HostName::display( (string) $mail->get( 'mail_domain' ) ) : '';
		$values['mail_external']  = $mail && 'external' === (string) $mail->get( 'mail_mode' );
		$values['mail_records']   = $mail ? MailDns::missing_rows( $mail ) : array();
		$values['mail_records_needed'] = $mail ? MailDns::missing_rows( $mail, true ) : array();
		$values['mail_host']      = $mail ? MailSettings::host() : '';
		$values['mail_imap']      = MailView::IMAP_PORT;
		$values['mail_smtp']      = MailView::SMTP_SSL;
		$values['mail_guide_url'] = MailSetup::page_url();

		return $values;
	}
}
