<?php
/**
 * The only door from a license history text to a sentence a customer reads.
 *
 * @package BeltoftServices\Account
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one history text (written by the plugin for the admin) into a fixed, translatable customer sentence, or into nothing.
 *
 * The rule of this class: a history text reaches a customer only through an entry here. Raw text is never printed and a text
 * that is not listed is not shown. A value taken from a text (a snapshot name, a backup date) is only ever put into a fixed
 * sentence and is escaped where it is printed. Texts that describe the same thing from the inside (a deletion that starts,
 * runs and ends) give the same sentence, which is marked as a single fact (`once`) so the feed shows it one time.
 *
 * Three kinds of text exist: what the customer did, what happened that the customer should know (ready, paused, restored,
 * deleted, an alert email sent), and the internal rest (retries, steps, errors, addresses, prices, node names), which is
 * never listed.
 */
final class ActivityMap {

	/**
	 * The one sentence of a pause, by the state its subscription is in: held for a failed payment, or held.
	 */
	public const HELD   = 'held';
	public const FAILED = 'failed';

	/**
	 * Is the text a pause? Whether it is shown depends on the state of the service's subscription, which the caller reads.
	 *
	 * @param string $message History text.
	 */
	public static function is_pause( string $message ): bool {
		return 'Service paused' === $message || 'Hosting paused' === $message;
	}

	/**
	 * The sentence of a history text.
	 *
	 * @param string $message History text.
	 * @param string $pause   For a pause text: '' when the subscription is not held, self::HELD, or self::FAILED (a failed renewal came first).
	 * @return array{text: string, once: bool}|null Null when the text is not for customers.
	 */
	public static function sentence( string $message, string $pause = '' ): ?array {
		if ( '' === $message || strlen( $message ) > 400 ) {
			return null;
		}

		$text = null;

		if ( self::is_pause( $message ) ) {
			if ( self::FAILED === $pause ) {
				$text = __( 'Paused after a failed payment', 'beltoft-services' );
			} elseif ( self::HELD === $pause ) {
				$text = __( 'Paused', 'beltoft-services' );
			}
		} else {
			$text = self::fixed( $message ) ?? self::matched( $message );
		}

		if ( null === $text || '' === $text ) {
			return null;
		}

		return array(
			'text' => $text,
			'once' => $text === self::deleted(),
		);
	}

	/**
	 * The sentence of a deletion, the one fact several texts stand for.
	 */
	private static function deleted(): string {
		return __( 'Deleted', 'beltoft-services' );
	}

	/**
	 * Texts that must match completely.
	 *
	 * @param string $message History text.
	 */
	private static function fixed( string $message ): ?string {
		switch ( $message ) {
			case 'Service provisioned':
				return __( 'Set up and ready', 'beltoft-services' );
			case 'Service resumed':
			case 'Hosting resumed':
				return __( 'Resumed', 'beltoft-services' );
			case 'Service plan updated':
			case 'Hosting plan applied':
			case 'Email plan updated':
			case 'Plan changed':
				return __( 'Plan updated', 'beltoft-services' );
			case 'Reinstall started by the customer':
				return __( 'Reinstall started', 'beltoft-services' );
			case 'Customer started the server':
				return __( 'Server started', 'beltoft-services' );
			case 'Customer shut the server down':
				return __( 'Server shut down', 'beltoft-services' );
			case 'Customer restarted the server':
				return __( 'Server restarted', 'beltoft-services' );
			case 'Customer renamed the server; the hostname changes at the next boot':
				return __( 'Server renamed', 'beltoft-services' );
			case 'Customer reset the server password':
			case 'Customer reset the server password; it applies when the server next runs cloud-init':
				return __( 'Server password reset', 'beltoft-services' );
			case 'Customer changed the SSH key':
			case 'Customer changed the SSH key; it applies when the server next starts, and the old key keeps working until the server is reinstalled':
				return __( 'SSH key changed', 'beltoft-services' );
			case 'Console opened':
				return __( 'Console opened', 'beltoft-services' );
			case 'Private network attached':
				return __( 'Private network attached', 'beltoft-services' );
			case 'Private network detached':
				return __( 'Private network detached', 'beltoft-services' );
			case 'Customer turned usage emails on':
				return __( 'Usage emails turned on', 'beltoft-services' );
			case 'Customer turned usage emails off':
				return __( 'Usage emails turned off', 'beltoft-services' );
			case 'Customer made a new database password':
				return __( 'New database password made', 'beltoft-services' );
			case 'Customer took the private key of the SSL certificate':
			case 'Customer took the private key of the S/MIME certificate as a PKCS#12 file':
				return __( 'Private key downloaded', 'beltoft-services' );
			case 'Public IP address added to the database':
				return __( 'Public IP address added', 'beltoft-services' );
			case 'Public IP address removed from the database':
				return __( 'Public IP address removed', 'beltoft-services' );
			case 'Data disk made larger':
				return __( 'Data disk made larger', 'beltoft-services' );
			case 'Extra disk size updated':
				return __( 'Disk size changed', 'beltoft-services' );
			case 'Extra disk removed':
				return __( 'Disk removed', 'beltoft-services' );
			case 'Database restored from a backup':
			case 'Server restored from a backup':
				return __( 'Restored from a backup', 'beltoft-services' );
			case 'Restore finished':
				return __( 'Restore finished', 'beltoft-services' );
			case 'Backup finished':
				return __( 'Backup taken', 'beltoft-services' );
			case 'The app install was tried again':
				return __( 'App install tried again', 'beltoft-services' );
			case 'Email domain ready':
				return __( 'Email domain ready', 'beltoft-services' );
			case 'Domain active':
				return __( 'Domain active', 'beltoft-services' );
			case 'DNS zone ready':
				return __( 'DNS zone ready', 'beltoft-services' );
			case 'Prepaid transfer year renewed':
				return __( 'Domain renewed', 'beltoft-services' );
			case 'SSL certificate issued':
				return __( 'Certificate issued', 'beltoft-services' );
			case 'SSL certificate reissue requested':
				return __( 'Certificate reissue requested', 'beltoft-services' );
			case 'SSL approval email sent again':
				return __( 'Approval email sent again', 'beltoft-services' );
			case 'SSL certificate cancelled by the customer':
				return __( 'Certificate cancelled', 'beltoft-services' );
			case 'Network quarantine applied: outgoing traffic blocked':
				return __( 'Outgoing traffic blocked', 'beltoft-services' );
			case 'Network quarantine lifted: outgoing traffic allowed again':
				return __( 'Outgoing traffic allowed again', 'beltoft-services' );
			case 'Monitoring email: server down':
				return __( 'Alert email sent: server down', 'beltoft-services' );
			case 'Monitoring email: disk almost completely full':
				return __( 'Alert email sent: disk almost completely full', 'beltoft-services' );
			case 'Monitoring email: disk nearly full':
			case 'Monitoring email: database disk nearly full':
				return __( 'Alert email sent: disk nearly full', 'beltoft-services' );
			case 'Monitoring email: memory high':
				return __( 'Alert email sent: memory high', 'beltoft-services' );
			case 'Monitoring email: CPU high':
				return __( 'Alert email sent: CPU high', 'beltoft-services' );
			case 'Monitoring email: database not answering':
				return __( 'Alert email sent: database not answering', 'beltoft-services' );
			// One deletion has several texts (the request, the start, the machine going, the license ending): one sentence.
			case 'Customer deleted the service':
			case 'Service deleted':
			case 'The virtual machine was deleted':
			case 'Hosting deleted':
			case 'Email domain deleted':
				return self::deleted();
		}

		return null;
	}

	/**
	 * Texts that carry a value or an action id.
	 *
	 * @param string $message History text.
	 */
	private static function matched( string $message ): ?string {
		if ( 1 === preg_match( '/^Customer took the snapshot "(.*)"$/sD', $message, $m ) ) {
			/* translators: %s: snapshot name */
			return sprintf( __( 'Snapshot "%s" taken', 'beltoft-services' ), $m[1] );
		}

		if ( 1 === preg_match( '/^Snapshot "(.*)" deleted$/sD', $message, $m ) ) {
			/* translators: %s: snapshot name */
			return sprintf( __( 'Snapshot "%s" deleted', 'beltoft-services' ), $m[1] );
		}

		if ( 1 === preg_match( '/^Server rolled back to the snapshot "(.*)" of \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC$/sD', $message, $m ) ) {
			/* translators: %s: snapshot name */
			return sprintf( __( 'Went back to snapshot "%s"', 'beltoft-services' ), $m[1] );
		}

		if ( 1 === preg_match( '/^Server restored from the backup of (\d{4}-\d{2}-\d{2} \d{2}:\d{2}) UTC$/D', $message, $m ) ) {
			/* translators: %s: date and time of the backup */
			return sprintf( __( 'Restored from the backup of %s', 'beltoft-services' ), self::stamp( $m[1] ) );
		}

		if ( 1 === preg_match( '/^Backup of (\d{4}-\d{2}-\d{2} \d{2}:\d{2}) UTC deleted at the customer\'s request$/D', $message, $m ) ) {
			/* translators: %s: date and time of the backup */
			return sprintf( __( 'Backup of %s deleted', 'beltoft-services' ), self::stamp( $m[1] ) );
		}

		if ( 1 === preg_match( '/^Deletion warning: the service will be deleted on (\d{4}-\d{2}-\d{2} \d{2}:\d{2}) UTC\.$/D', $message, $m ) ) {
			/* translators: %s: date and time the service will be deleted */
			return sprintf( __( 'Warned that it will be deleted on %s', 'beltoft-services' ), self::stamp( $m[1] ) );
		}

		if ( 1 === preg_match( '/^Customer made the disk larger: pay as you go size (\d{1,6}) GB$/D', $message, $m ) ) {
			/* translators: %d: disk size in GB */
			return sprintf( __( 'Disk made larger, now %d GB', 'beltoft-services' ), (int) $m[1] );
		}

		if ( 1 === preg_match( '/^Domain renewed after paid subscription order/', $message ) ) {
			return __( 'Domain renewed', 'beltoft-services' );
		}

		if ( 1 === preg_match( '/^Customer action "([a-z_]+)" done$/D', $message, $m ) ) {
			return self::customer_action( $m[1] );
		}

		/**
		 * Filters the sentence of an activity message a feature words itself (Hosting: a hosting change). Return null when the
		 * message is not the feature's.
		 *
		 * @param string|null $sentence Sentence so far.
		 * @param string      $message  The message the service logged.
		 */
		$sentence = apply_filters( 'beltoft_services_activity_sentence', null, $message );

		return is_string( $sentence ) ? $sentence : null;
	}

	/**
	 * A UTC "Y-m-d H:i" as the site's date and time, in the site's time zone.
	 *
	 * @param string $utc Date and time in UTC.
	 */
	private static function stamp( string $utc ): string {
		$time = strtotime( $utc . ' UTC' );

		if ( false === $time ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * The sentence of a customer action that left no text of its own, by its action id.
	 *
	 * @param string $action Action id.
	 */
	private static function customer_action( string $action ): ?string {
		switch ( $action ) {
			case 'backup_now':
				return __( 'Backup taken', 'beltoft-services' );
			case 'start':
				return __( 'Started', 'beltoft-services' );
			case 'shutdown':
				return __( 'Shut down', 'beltoft-services' );
			case 'reboot':
				return __( 'Restarted', 'beltoft-services' );
			case 'mailbox_create':
				return __( 'Mailbox made', 'beltoft-services' );
			case 'mailbox_password':
				return __( 'Mailbox password changed', 'beltoft-services' );
			case 'mail_dns_check':
				return __( 'DNS records checked', 'beltoft-services' );
			case 'fw_apply':
				return __( 'Firewall changed', 'beltoft-services' );
			case 'fw_reset':
				return __( 'Firewall reset', 'beltoft-services' );
			case 'db_set_policy':
				return __( 'Eviction policy changed', 'beltoft-services' );
			case 'set_nameservers':
				return __( 'Name servers changed', 'beltoft-services' );
			case 'lock':
			case 'unlock':
				return __( 'Transfer lock changed', 'beltoft-services' );
			case 'set_records':
				return __( 'DNS records changed', 'beltoft-services' );
			case 'dnssec_enable':
				return __( 'DNSSEC turned on', 'beltoft-services' );
			case 'dnssec_disable':
				return __( 'DNSSEC turned off', 'beltoft-services' );
			case 'dnssec_key_add':
			case 'dnssec_key_remove':
				return __( 'DNSSEC keys changed', 'beltoft-services' );
			case 'whois_privacy':
				return __( 'WHOIS privacy changed', 'beltoft-services' );
			case 'adopt_switch':
				return __( 'Switched to our DNS', 'beltoft-services' );
		}

		return null;
	}
}
