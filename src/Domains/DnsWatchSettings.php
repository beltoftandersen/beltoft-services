<?php
/**
 * The numbers of the DNS watch: shared settings in the Domains tab.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every number of the watch is a setting (tab Domains, section "DNS watch"). A feature may later get its own value without a code
 * change: get() reads `<feature>_dns_watch_<key>` first and falls back to the shared `dns_watch_<key>`; no feature field is declared
 * until the owner asks for one.
 *
 * The twelve values the owner may have changed under Email (mail_dns_*) are copied here once, on the first request after the
 * upgrade (migrate()); mail keeps only the MX rule and the retry days.
 */
final class DnsWatchSettings {

	public const SECTION = 'dns_watch';

	/** Option that remembers the copy was made. */
	public const MIGRATED = 'beltoft_services_dns_watch_settings_migrated';

	/** Old key (tab Email) => new key. */
	public const MOVED = array(
		'mail_dns_confirm_minutes'     => 'dns_watch_confirm_minutes',
		'mail_dns_remind_days'         => 'dns_watch_remind_days',
		'mail_dns_recheck_per_day'     => 'dns_watch_recheck_per_day',
		'mail_dns_weekly_days'         => 'dns_watch_weekly_days',
		'mail_dns_stop_days'           => 'dns_watch_stop_days',
		'mail_dns_admin_days'          => 'dns_watch_admin_days',
		'mail_dns_admin_throttle_days' => 'dns_watch_admin_throttle_days',
		'mail_dns_lost_mail_gap_hours' => 'dns_watch_lost_mail_gap_hours',
		'mail_dns_check_gap'           => 'dns_watch_check_gap',
		'mail_dns_check_per_hour'      => 'dns_watch_check_per_hour',
		'mail_dns_lost_red_days'       => 'dns_watch_lost_red_days',
		'mail_dns_cleanup_on_delete'   => 'dns_watch_cleanup_on_delete',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'sections' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 19 );
	}

	/**
	 * One setting: the feature's own value when one is stored, else the shared one.
	 *
	 * @param string $key     Key without the prefix, for example confirm_minutes.
	 * @param string $feature Feature id, '' for the shared value.
	 * @return mixed
	 */
	public static function get( string $key, string $feature = '' ) {
		if ( '' !== $feature ) {
			$stored = get_option( Settings::OPTION, array() );
			$own    = $feature . '_dns_watch_' . $key;

			if ( is_array( $stored ) && array_key_exists( $own, $stored ) ) {
				return $stored[ $own ];
			}
		}

		return Settings::get( 'dns_watch_' . $key );
	}

	/** Seconds to wait before a lost record is confirmed. */
	public static function confirm_seconds( string $feature = '' ): int {
		return max( 0, (int) self::get( 'confirm_minutes', $feature ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * The days after a loss when a reminder goes out, ascending.
	 *
	 * @return int[]
	 */
	public static function remind_days( string $feature = '' ): array {
		$days = array();

		foreach ( preg_split( '/[\s,;]+/', (string) self::get( 'remind_days', $feature ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $day ) {
			if ( ctype_digit( $day ) && (int) $day > 0 ) {
				$days[] = (int) $day;
			}
		}

		$days = array_values( array_unique( $days ) );
		sort( $days );

		return $days;
	}

	/** Seconds after setup before a customer is reminded once of records still missing. */
	public static function first_remind_seconds( string $feature = '' ): int {
		return max( 1, (int) self::get( 'first_remind_hours', $feature ) ) * HOUR_IN_SECONDS;
	}

	/** Looks per day at a service whose records were found. 1, 2 or 4. */
	public static function recheck_per_day( string $feature = '' ): int {
		$n = (int) self::get( 'recheck_per_day', $feature );

		return in_array( $n, array( 1, 2, 4 ), true ) ? $n : 2;
	}

	/** The cron string of the sweep that queues those looks. */
	public static function recheck_cron( string $feature = '' ): string {
		$hours = array( 1 => '3', 2 => '3,15', 4 => '3,9,15,21' );

		return '40 ' . $hours[ self::recheck_per_day( $feature ) ] . ' * * *';
	}

	/** Seconds a service is left alone after a look: a little under the gap between two sweeps. */
	public static function recheck_guard( string $feature = '' ): int {
		return ( intdiv( 24, self::recheck_per_day( $feature ) ) - 2 ) * HOUR_IN_SECONDS;
	}

	/** Seconds over which one sweep spreads its looks. */
	public static function recheck_spread( string $feature = '' ): int {
		return ( intdiv( 24, self::recheck_per_day( $feature ) ) - 1 ) * HOUR_IN_SECONDS;
	}

	/** Days between the slow looks after the first look has stopped. */
	public static function weekly_days( string $feature = '' ): int {
		return max( 1, (int) self::get( 'weekly_days', $feature ) );
	}

	/** Days after which no look is made at all. */
	public static function stop_days( string $feature = '' ): int {
		return max( 15, (int) self::get( 'stop_days', $feature ) );
	}

	/** Days without the records before the one staff alert. */
	public static function admin_days( string $feature = '' ): int {
		return max( 1, (int) self::get( 'admin_days', $feature ) );
	}

	/** Seconds between two staff alerts about the same service losing records. */
	public static function admin_throttle( string $feature = '' ): int {
		return max( 1, (int) self::get( 'admin_throttle_days', $feature ) ) * DAY_IN_SECONDS;
	}

	/** Seconds that must pass after the last lost or found mail before a new lost mail goes out. */
	public static function mail_gap( string $feature = '' ): int {
		return max( 0, (int) self::get( 'lost_mail_gap_hours', $feature ) ) * HOUR_IN_SECONDS;
	}

	/** Seconds between two Check now presses. */
	public static function check_gap( string $feature = '' ): int {
		return max( 0, (int) self::get( 'check_gap', $feature ) );
	}

	/** Check now presses in a rolling hour. */
	public static function check_per_hour( string $feature = '' ): int {
		return max( 1, (int) self::get( 'check_per_hour', $feature ) );
	}

	/** Seconds after a loss when the status turns red. */
	public static function red_after( string $feature = '' ): int {
		return max( 0, (int) self::get( 'lost_red_days', $feature ) ) * DAY_IN_SECONDS;
	}

	/** Are our records removed from the customer zone when the service is deleted? */
	public static function cleanup_on_delete( string $feature = '' ): bool {
		return (bool) self::get( 'cleanup_on_delete', $feature );
	}

	/** Days the records are looked for every few minutes. */
	public static function wait_days( string $feature = '' ): int {
		$days = (int) self::get( 'wait_days', $feature );

		/**
		 * The days of the wait of one feature, for a feature whose wait is bound to something else (a certificate order is cancelled
		 * after its own number of days, and the watch must end with it).
		 *
		 * @param int    $days    Days from the setting.
		 * @param string $feature Feature id ('' for the shared number).
		 */
		return max( 1, (int) apply_filters( 'beltoft_services_dns_watch_wait_days', $days, $feature ) );
	}

	/** How many times a lost record whose lookup keeps failing is looked at again before the staff are told. */
	public static function unknown_retries( string $feature = '' ): int {
		return max( 1, (int) self::get( 'unknown_retries', $feature ) );
	}

	/** Percent of the lookups of one sweep that may fail before the sweep starts no loss. */
	public static function sweep_fail_percent( string $feature = '' ): int {
		return min( 100, max( 1, (int) self::get( 'sweep_fail_percent', $feature ) ) );
	}

	/** Seconds between two reads of public DNS while the records are awaited. */
	public static function look_seconds( string $feature = '' ): int {
		return max( 5, (int) self::get( 'look_minutes', $feature ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function sections( $sections ) {
		if ( is_array( $sections ) ) {
			$sections[ self::SECTION ] = __( 'DNS watch', 'beltoft-services' );
		}

		return $sections;
	}

	/**
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$w = array( 'tab' => DomainSettings::TAB, 'section' => self::SECTION );

		$fields['dns_watch_wait_days'] = $w + array(
			'type'    => 'number',
			'default' => 14,
			'min'     => 1,
			'max'     => 365,
			'label'   => __( 'Days the DNS records are looked for every few minutes', 'beltoft-services' ),
			'desc'    => __( 'After a service is set up, its DNS records are looked for this long. Then the look slows down (see the days between looks below).', 'beltoft-services' ),
		);
		$fields['dns_watch_look_minutes'] = $w + array(
			'type'    => 'number',
			'default' => 10,
			'min'     => 5,
			'max'     => 1440,
			'label'   => __( 'Minutes between two looks while the records are awaited', 'beltoft-services' ),
		);
		$fields['dns_watch_unknown_retries'] = $w + array(
			'type'    => 'number',
			'default' => 6,
			'min'     => 1,
			'max'     => 48,
			'label'   => __( 'Times a lost record is looked at again when the lookup itself fails', 'beltoft-services' ),
			'desc'    => __( 'A lookup that fails (no answer from the resolver) never confirms a loss and sends no email. The record is looked at again after the confirm minutes; after this many failed tries you get one alert and the loss stays unconfirmed.', 'beltoft-services' ),
		);
		$fields['dns_watch_sweep_fail_percent'] = $w + array(
			'type'    => 'number',
			'default' => 50,
			'min'     => 1,
			'max'     => 100,
			'label'   => __( 'Percent of failed lookups in one daily sweep that stops it from starting any loss', 'beltoft-services' ),
			'desc'    => __( 'When more than this share of the lookups of a sweep fail, the resolver is the problem: no service is marked as losing records in that sweep, and you get one alert a day.', 'beltoft-services' ),
		);
		$fields['dns_watch_first_remind_hours'] = $w + array(
			'type'    => 'number',
			'default' => 24,
			'min'     => 1,
			'max'     => 720,
			'label'   => __( 'Hours after setup before the customer is reminded of missing records', 'beltoft-services' ),
			'desc'    => __( 'One email, only for a domain hosted at another DNS provider and only while needed records are missing.', 'beltoft-services' ),
		);
		$fields['dns_watch_confirm_minutes'] = $w + array(
			'type'    => 'number',
			'default' => 10,
			'min'     => 0,
			'max'     => 1440,
			'label'   => __( 'Minutes to wait before a lost record is confirmed', 'beltoft-services' ),
			'desc'    => __( 'When a needed DNS record is not found, the record is read once more after this many minutes. Only if it is still gone is the customer told. 0 confirms at once.', 'beltoft-services' ),
		);
		$fields['dns_watch_remind_days'] = $w + array(
			'type'    => 'text',
			'default' => '3,10',
			'label'   => __( 'Days after a loss when the customer is reminded', 'beltoft-services' ),
			'desc'    => __( 'Day numbers separated by commas. The first email goes out when the loss is confirmed; each day here adds one reminder while the records are still missing. Empty sends the first email only.', 'beltoft-services' ),
		);
		$fields['dns_watch_recheck_per_day'] = $w + array(
			'type'    => 'select',
			'default' => '2',
			'options' => array(
				'1' => __( 'Once a day', 'beltoft-services' ),
				'2' => __( 'Twice a day', 'beltoft-services' ),
				'4' => __( 'Four times a day', 'beltoft-services' ),
			),
			'label'   => __( 'How often a service with all records is looked at again', 'beltoft-services' ),
		);
		$fields['dns_watch_weekly_days'] = $w + array(
			'type'    => 'number',
			'default' => 7,
			'min'     => 1,
			'max'     => 60,
			'label'   => __( 'Days between looks after the first 14 days', 'beltoft-services' ),
			'desc'    => __( 'After the first look has stopped, a service that still lacks records is looked at this often.', 'beltoft-services' ),
		);
		$fields['dns_watch_stop_days'] = $w + array(
			'type'    => 'number',
			'default' => 90,
			'min'     => 15,
			'max'     => 3650,
			'label'   => __( 'Days after which the look stops for good', 'beltoft-services' ),
		);
		$fields['dns_watch_admin_days'] = $w + array(
			'type'    => 'number',
			'default' => 60,
			'min'     => 1,
			'max'     => 3650,
			'label'   => __( 'Days without the records before you get one alert', 'beltoft-services' ),
			'desc'    => __( 'You get one email. Nothing is paused or deleted because of it.', 'beltoft-services' ),
		);
		$fields['dns_watch_admin_throttle_days'] = $w + array(
			'type'    => 'number',
			'default' => 7,
			'min'     => 1,
			'max'     => 365,
			'label'   => __( 'Days between two alerts about the same domain losing records', 'beltoft-services' ),
		);
		$fields['dns_watch_lost_mail_gap_hours'] = $w + array(
			'type'    => 'number',
			'default' => 24,
			'min'     => 0,
			'max'     => 720,
			'label'   => __( 'Hours between a lost mail and the next one for the same domain', 'beltoft-services' ),
			'desc'    => __( 'A record that goes missing and comes back again and again does not mail the customer each time: a loss confirmed within this many hours after the last lost or found mail sends no new lost mail, and a found mail is not sent again within this time. The page and anything held always follow the real state. 0 turns this off.', 'beltoft-services' ),
		);
		$fields['dns_watch_check_gap'] = $w + array(
			'type'    => 'number',
			'default' => 60,
			'min'     => 0,
			'max'     => 3600,
			'label'   => __( 'Seconds between two Check now presses of a customer', 'beltoft-services' ),
		);
		$fields['dns_watch_check_per_hour'] = $w + array(
			'type'    => 'number',
			'default' => 10,
			'min'     => 1,
			'max'     => 1000,
			'label'   => __( 'Check now presses of a customer in an hour', 'beltoft-services' ),
		);
		$fields['dns_watch_lost_red_days'] = $w + array(
			'type'    => 'number',
			'default' => 3,
			'min'     => 0,
			'max'     => 365,
			'label'   => __( 'Days after a loss when the status turns red', 'beltoft-services' ),
		);
		$fields['dns_watch_cleanup_on_delete'] = $w + array(
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Remove our DNS records from the customer zone when the service is deleted', 'beltoft-services' ),
			'desc'    => __( 'Only for zones on our own name servers, and only records that still hold exactly the value we wrote. Records at other DNS providers are never touched.', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * Copies the values the owner saved under Email to the shared keys, once, and removes the old keys. A shared key that is already
	 * saved is never overwritten.
	 */
	public static function maybe_migrate(): void {
		if ( '' !== (string) get_option( self::MIGRATED, '' ) ) {
			return;
		}

		self::migrate();
		update_option( self::MIGRATED, (string) time(), false );
	}

	/**
	 * The copy itself.
	 *
	 * @return int How many values were copied.
	 */
	public static function migrate(): int {
		$stored = get_option( Settings::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return 0;
		}

		$copied  = 0;
		$changed = false;

		foreach ( self::MOVED as $old => $new ) {
			if ( ! array_key_exists( $old, $stored ) ) {
				continue;
			}

			$changed = true;

			if ( ! array_key_exists( $new, $stored ) ) {
				$stored[ $new ] = $stored[ $old ];
				++$copied;
			}

			unset( $stored[ $old ] );
		}

		if ( $changed ) {
			update_option( Settings::OPTION, $stored );
		}

		return $copied;
	}
}
