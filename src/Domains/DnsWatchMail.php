<?php
/**
 * The customer emails of the DNS watch: one generic set for every feature.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Four emails (ids beltoft_services_dns_reminder, _lost, _back, _timeout), one template each. What differs per feature (what the missing
 * records stop, where the customer acts, the link) comes from DnsWatchFeature::copy() through the filter beltoft_services_email_values,
 * so the text is the same shape everywhere and is read in one place before a release.
 *
 * The four ids that email used before (beltoft_services_mail_dns_*) were replaced on 2026-11-27 (owner's answer): a text the owner had
 * saved for one of them is copied to the new id once (migrate_saved_texts()), and the old ids are gone.
 */
final class DnsWatchMail {

	public const EVENTS = array( 'reminder', 'lost', 'back', 'timeout' );

	/** Option that remembers the saved texts were moved. */
	public const MIGRATED = 'beltoft_services_dns_watch_emails_migrated';

	/** The email ids that were replaced, by event. */
	private const OLD_IDS = array(
		'reminder' => 'beltoft_services_mail_dns_reminder',
		'lost'     => 'beltoft_services_mail_dns_lost',
		'back'     => 'beltoft_services_mail_dns_back',
		'timeout'  => 'beltoft_services_mail_dns_timeout',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_email_values', array( __CLASS__, 'values' ), 20, 4 );
		add_filter( 'beltoft_services_email_value_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 19 );
	}

	/**
	 * The id of the email of an event.
	 *
	 * @param string $event reminder, lost, back or timeout.
	 */
	public static function id( string $event ): string {
		return 'beltoft_services_dns_' . $event;
	}

	/**
	 * Sends the email of an event about a resource. Never throws (Emails::send).
	 *
	 * @param string          $event reminder, lost, back or timeout.
	 * @param DnsWatchFeature $f     Feature.
	 * @param Resource        $r     Resource.
	 */
	public static function send( string $event, DnsWatchFeature $f, Resource $r ): bool {
		if ( ! $f->mails( $event, $r ) ) {
			return false;
		}

		return Emails::send( self::id( $event ), $r, array( 'dns_feature' => $f->id() ) );
	}

	/**
	 * The empty values, so a template renders with the filter removed.
	 *
	 * @param mixed $defaults Defaults so far.
	 * @return mixed
	 */
	public static function defaults( $defaults ) {
		return is_array( $defaults ) ? $defaults + self::empty_values() : $defaults;
	}

	/**
	 * Every key with its empty default.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_values(): array {
		return array(
			'dns_name'       => '',
			'dns_subject'    => '',
			'dns_effect'     => '',
			'dns_where'      => '',
			'dns_link'       => '',
			'dns_open_text'  => '',
			'dns_guide_url'  => '',
			'dns_guide_text' => '',
			'dns_days'       => 0,
			'dns_moved'      => false,
			'dns_lost_names' => '',
			'dns_missing'    => array(),
			'dns_lost_rows'  => array(),
		);
	}

	/**
	 * The template values of the DNS watch emails (beltoft_services_email_values).
	 *
	 * @param mixed $values Values so far.
	 * @param mixed $r      Resource.
	 * @param mixed $data   Trigger data.
	 * @param mixed $email  The email.
	 * @return mixed
	 */
	public static function values( $values, $r, $data = array(), $email = null ) {
		if ( ! is_array( $values ) || ! $r instanceof Resource ) {
			return $values;
		}

		$values += self::empty_values();
		$id      = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
		$event   = array_search( $id, array_map( array( __CLASS__, 'id' ), self::EVENTS ), true );

		if ( false === $event ) {
			return $values;
		}

		$event   = self::EVENTS[ $event ];
		$wanted  = is_array( $data ) ? (string) ( $data['dns_feature'] ?? '' ) : '';
		$feature = '' !== $wanted ? DnsWatch::feature( $wanted ) : DnsWatch::feature_of( $r );
		$fresh   = Resource::find( $r->id() ) ?? $r;

		/**
		 * The values of a DNS watch email about one subject of a resource, for a feature that watches many names of one service (hosting:
		 * each outside domain of an account). The trigger data carries dns_feature and the feature's own subject. Return the dns_* values
		 * (the keys of empty_values()), or null to let the one-subject path above answer.
		 *
		 * @param array<string, mixed>|null $subject Null.
		 * @param string                    $event   reminder, lost, back or timeout.
		 * @param array<string, mixed>      $data    Trigger data.
		 * @param Resource                  $fresh   The resource, read again.
		 */
		$subject = '' === $wanted || ! is_array( $data ) ? null : apply_filters( 'beltoft_services_dns_watch_subject_values', null, $event, $data, $fresh );

		if ( is_array( $subject ) ) {
			return array_merge( $values, $subject );
		}

		if ( null === $feature ) {
			return $values;
		}

		$lost   = DnsWatchState::lost_keys( $feature, $fresh );
		$domain = HostName::display( $feature->domain( $fresh ) );
		$copy   = $feature->copy( $event, $fresh );

		$values['domain_name']    = $domain;
		$values['dns_name']       = $domain;
		$values['dns_days']       = DnsWatchSettings::wait_days( $feature->id() );
		$values['dns_moved']      = (int) $fresh->get( $feature->prefix() . '_moved' ) > 0;
		$values['dns_lost_names'] = DnsWatchState::names( $lost );
		$values['dns_missing']    = $feature->missing_rows( $fresh, true );
		$values['dns_lost_rows']  = array_values( array_filter( $values['dns_missing'], static fn( $row ) => in_array( (string) $row['key'], $lost, true ) ) );

		foreach ( array( 'dns_subject', 'dns_effect', 'dns_where', 'dns_link', 'dns_open_text', 'dns_guide_url', 'dns_guide_text' ) as $key ) {
			$values[ $key ] = isset( $copy[ $key ] ) ? (string) $copy[ $key ] : '';
		}

		return $values;
	}

	/**
	 * Copies the texts the owner saved for one of the replaced email ids to the new id, once, and removes the old settings. A new id that
	 * already has saved settings is never overwritten.
	 */
	public static function maybe_migrate(): void {
		if ( '' !== (string) get_option( self::MIGRATED, '' ) ) {
			return;
		}

		self::migrate_saved_texts();
		update_option( self::MIGRATED, (string) time(), false );
	}

	/**
	 * The copy itself.
	 *
	 * @return int How many saved settings were copied.
	 */
	public static function migrate_saved_texts(): int {
		$copied = 0;

		foreach ( self::OLD_IDS as $event => $old ) {
			$saved = get_option( 'woocommerce_' . $old . '_settings', null );

			if ( null === $saved ) {
				continue;
			}

			$new = 'woocommerce_' . self::id( $event ) . '_settings';

			if ( is_array( $saved ) && null === get_option( $new, null ) ) {
				update_option( $new, $saved );
				++$copied;
			}

			delete_option( 'woocommerce_' . $old . '_settings' );
		}

		return $copied;
	}
}
