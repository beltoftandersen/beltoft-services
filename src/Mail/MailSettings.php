<?php
/**
 * Settings → Services → Email.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Crypto;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every site-specific mail value: the mailcow address and key, the mail host customers point their DNS at, the webmail
 * address, the SPF include, the DMARC report address, the domains customers may never add, and the send limits. The
 * fields are added to the generic settings page through its filters.
 */
final class MailSettings {

	public const TAB = 'mail';

	/**
	 * Hooks the tab and its fields.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 4 );
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'add_tab' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'add_section' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'add_fields' ) );
		add_filter( 'beltoft_services_default_retention', array( MailDriver::class, 'default_retention' ), 10, 2 );
	}

	/**
	 * Adds the mail driver to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( ! is_array( $drivers ) ) {
			return $drivers;
		}

		if ( ! isset( $drivers[ 'mail' ] ) ) {
			$drivers[ 'mail' ] = new MailDriver();
		}

		return $drivers;
	}

	/**
	 * @param mixed $tabs Tabs.
	 * @return mixed
	 */
	public static function add_tab( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ] = __( 'Email', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function add_section( $sections ) {
		if ( is_array( $sections ) ) {
			$sections['mail']        = __( 'Mail server', 'beltoft-services' );
			$sections['mail_dns']    = __( 'DNS records customers get', 'beltoft-services' );
			$sections['mail_watch']  = __( 'Watching the DNS records', 'beltoft-services' );
			$sections['mail_limits'] = __( 'Limits', 'beltoft-services' );
		}

		return $sections;
	}

	/**
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function add_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$server = array( 'tab' => self::TAB, 'section' => 'mail' );
		$dns    = array( 'tab' => self::TAB, 'section' => 'mail_dns' );
		$limits = array( 'tab' => self::TAB, 'section' => 'mail_limits' );
		$watch  = array( 'tab' => self::TAB, 'section' => 'mail_watch' );

		$fields['mail_client']  = $server + array(
			'type'    => 'select',
			'default' => 'fake',
			'options' => array(
				'fake' => __( 'Fake: nothing leaves this site', 'beltoft-services' ),
				'live' => __( 'Live: call the mail server (also needs BELTOFT_SERVICES_MAIL_LIVE in wp-config.php)', 'beltoft-services' ),
			),
			'label'   => __( 'Mail server mode', 'beltoft-services' ),
		);
		$fields['mail_api_url'] = $server + array(
			'type'    => 'url',
			'default' => '',
			'label'   => __( 'Mail server API address', 'beltoft-services' ),
			'desc'    => __( 'The https address of the mailcow web interface, without a path.', 'beltoft-services' ),
		);
		$fields['mail_api_key'] = $server + array(
			'type'    => 'secret',
			'default' => '',
			'label'   => __( 'Mail server API key', 'beltoft-services' ),
			'desc'    => __( 'A read-write mailcow API key that is allowed from this server only. It is stored encrypted and never shown again.', 'beltoft-services' ),
		);
		$fields['mail_host']    = $server + array(
			'type'    => 'text',
			'default' => '',
			'label'   => __( 'Mail host name', 'beltoft-services' ),
			'desc'    => __( 'The name customers point their MX records and their mail programs at, for example mail.example.com. Nothing is provisioned until it is set.', 'beltoft-services' ),
		);
		$fields['mail_webmail_url'] = $server + array(
			'type'    => 'url',
			'default' => '',
			'label'   => __( 'Webmail address', 'beltoft-services' ),
		);
		$fields['mail_order_url'] = $server + array(
			'type'    => 'text',
			'default' => '',
			'label'   => __( 'Email order page', 'beltoft-services' ),
			'desc'    => __( 'Where the Create an email domain button in the account goes, for example /mail/#pricing. Empty uses the product page.', 'beltoft-services' ),
		);
		$fields['mail_guide_url'] = $server + array(
			'type'    => 'url',
			'default' => '',
			'label'   => __( 'Setup guide URL', 'beltoft-services' ),
			'desc'    => __( 'Address of a public page that holds the shortcode [beltoft_services_mail_setup]. When set, the Connect tab links to it. Empty means no link.', 'beltoft-services' ),
		);
		$fields['mail_dav_url'] = $server + array(
			'type'    => 'url',
			'default' => '',
			'label'   => __( 'Calendar and contacts address', 'beltoft-services' ),
			'desc'    => __( 'The CalDAV and CardDAV address of the mail server, for example https://mail.example.com/dav/. When set, the setup guide and the Connect tab explain how to add the calendar and the contacts. Empty hides them.', 'beltoft-services' ),
		);
		$fields['mail_reserved_domains'] = $server + array(
			'type'    => 'textarea',
			'default' => '',
			'label'   => __( 'Domains customers may not add', 'beltoft-services' ),
			'desc'    => __( 'One domain per line. A domain here, and any name under it, is refused: use it for the mail domains the mail server carries for you.', 'beltoft-services' ),
		);
		$fields['mail_allow_external_forward'] = $server + array(
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Let aliases forward to other domains', 'beltoft-services' ),
		);

		$fields['mail_restart_sogo'] = $server + array(
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Restart the calendar and contacts service after a new domain is added', 'beltoft-services' ),
			'desc'    => __( 'Sends restart_sogo with each new domain, as the mail server\'s own Add domain and restart SOGo does. Without it, calendar and contacts of a new domain work only after the next SOGo restart.', 'beltoft-services' ),
		);

		$fields['mail_spf_include'] = $dns + array(
			'type'    => 'text',
			'default' => '',
			'label'   => __( 'SPF include name', 'beltoft-services' ),
			'desc'    => __( 'The name in include:<name> of the SPF record, a TXT record of your own that lists the mail server addresses. Empty uses the mail host name.', 'beltoft-services' ),
		);
		$fields['mail_dmarc_rua'] = $dns + array(
			'type'    => 'email',
			'default' => '',
			'label'   => __( 'DMARC report address', 'beltoft-services' ),
			'desc'    => __( 'Where DMARC reports for customer domains go. Empty: the WordPress admin email.', 'beltoft-services' ),
		);

		$fields['mail_dns_confirm_minutes'] = $watch + array(
			'type'    => 'number',
			'default' => 10,
			'min'     => 0,
			'max'     => 1440,
			'label'   => __( 'Minutes to wait before a lost record is confirmed', 'beltoft-services' ),
			'desc'    => __( 'When a needed DNS record is not found, the record is read once more after this many minutes. Only if it is still gone is the customer told. 0 confirms at once.', 'beltoft-services' ),
		);
		$fields['mail_dns_remind_days'] = $watch + array(
			'type'    => 'text',
			'default' => '3,10',
			'label'   => __( 'Days after a loss when the customer is reminded', 'beltoft-services' ),
			'desc'    => __( 'Day numbers separated by commas. The first email goes out when the loss is confirmed; each day here adds one reminder while the records are still missing. Empty sends the first email only.', 'beltoft-services' ),
		);
		$fields['mail_dns_recheck_per_day'] = $watch + array(
			'type'    => 'select',
			'default' => '2',
			'options' => array(
				'1' => __( 'Once a day', 'beltoft-services' ),
				'2' => __( 'Twice a day', 'beltoft-services' ),
				'4' => __( 'Four times a day', 'beltoft-services' ),
			),
			'label'   => __( 'How often a domain with all records is looked at again', 'beltoft-services' ),
		);
		$fields['mail_dns_weekly_days'] = $watch + array(
			'type'    => 'number',
			'default' => 7,
			'min'     => 1,
			'max'     => 60,
			'label'   => __( 'Days between looks after the first 14 days', 'beltoft-services' ),
			'desc'    => __( 'After the ten minute look has stopped, a domain that still lacks records is looked at this often.', 'beltoft-services' ),
		);
		$fields['mail_dns_stop_days'] = $watch + array(
			'type'    => 'number',
			'default' => 90,
			'min'     => 15,
			'max'     => 3650,
			'label'   => __( 'Days after which the look stops for good', 'beltoft-services' ),
		);
		$fields['mail_dns_admin_days'] = $watch + array(
			'type'    => 'number',
			'default' => 60,
			'min'     => 1,
			'max'     => 3650,
			'label'   => __( 'Days without email DNS before you get one alert', 'beltoft-services' ),
			'desc'    => __( 'You get one email. Nothing is paused or deleted because of it.', 'beltoft-services' ),
		);
		$fields['mail_dns_admin_throttle_days'] = $watch + array(
			'type'    => 'number',
			'default' => 7,
			'min'     => 1,
			'max'     => 365,
			'label'   => __( 'Days between two alerts about the same domain losing records', 'beltoft-services' ),
		);
		$fields['mail_dns_check_gap'] = $watch + array(
			'type'    => 'number',
			'default' => 60,
			'min'     => 0,
			'max'     => 3600,
			'label'   => __( 'Seconds between two Check now presses of a customer', 'beltoft-services' ),
		);
		$fields['mail_dns_check_per_hour'] = $watch + array(
			'type'    => 'number',
			'default' => 10,
			'min'     => 1,
			'max'     => 1000,
			'label'   => __( 'Check now presses of a customer in an hour', 'beltoft-services' ),
		);
		$fields['mail_dns_lost_red_days'] = $watch + array(
			'type'    => 'number',
			'default' => 3,
			'min'     => 0,
			'max'     => 365,
			'label'   => __( 'Days after a loss when the status turns red', 'beltoft-services' ),
		);
		$fields['mail_dns_mx_strict'] = $watch + array(
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Count a second MX record with the same or a better priority as a problem', 'beltoft-services' ),
			'desc'    => __( 'Off: it is only shown as a note and the domain still counts as set up.', 'beltoft-services' ),
		);
		$fields['mail_dns_cleanup_on_delete'] = $watch + array(
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Remove our email records from the customer zone when the email domain is deleted', 'beltoft-services' ),
			'desc'    => __( 'Only for zones on our own name servers, and only records that still hold exactly the value we wrote. Records at other DNS providers are never touched.', 'beltoft-services' ),
		);
		$fields['mail_retry_days'] = $watch + array(
			'type'    => 'number',
			'default' => 30,
			'min'     => 0,
			'max'     => 365,
			'label'   => __( 'Days a customer may press Try again after the domain proof was not found', 'beltoft-services' ),
			'desc'    => __( 'The name stays reserved for that customer for this time. After it the name is free for a new order. 0 turns Try again off.', 'beltoft-services' ),
		);

		$fields['mail_rl_minute'] = $limits + array(
			'type'    => 'number',
			'default' => 30,
			'min'     => 0,
			'max'     => 100000,
			'label'   => __( 'Messages per domain per minute', 'beltoft-services' ),
			'desc'    => __( 'The limit for all mailboxes of one email domain together, set on the mail server when the domain is made and when its plan changes. 0 turns it off.', 'beltoft-services' ),
		);
		$fields['mail_rl_day'] = $limits + array(
			'type'    => 'number',
			'default' => 500,
			'min'     => 0,
			'max'     => 1000000,
			'label'   => __( 'Messages per mailbox per day', 'beltoft-services' ),
			'desc'    => __( 'The limit of each mailbox, set when the mailbox is made. 0 turns it off. The mail server blocks sending at the limit; nothing is paused by this plugin.', 'beltoft-services' ),
		);
		$fields['mail_quota_alert_percent'] = $limits + array(
			'type'    => 'number',
			'default' => 90,
			'min'     => 1,
			'max'     => 100,
			'label'   => __( 'Alert when a mailbox is this full, in percent', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * Settings are read through here so the stored value of a secret never leaves this class.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return Settings::get( $key );
	}

	/**
	 * The mailcow base address, '' when unset.
	 */
	public static function api_url(): string {
		return trim( (string) Settings::get( 'mail_api_url' ) );
	}

	/**
	 * The decrypted API key, '' when unset or not readable. Keep it out of logs, events and mail.
	 */
	public static function api_key(): string {
		$stored = (string) Settings::get( 'mail_api_key' );

		return '' === $stored ? '' : (string) Crypto::decrypt( $stored );
	}

	/**
	 * The mail host name as a lower case host, '' when unset or not a host name.
	 */
	public static function host(): string {
		$host = strtolower( rtrim( trim( (string) Settings::get( 'mail_host' ) ), '.' ) );

		return HostName::is_host( $host, 0, true ) ? $host : '';
	}

	/**
	 * The name in the SPF include.
	 */
	public static function spf_include(): string {
		$name = strtolower( rtrim( trim( (string) Settings::get( 'mail_spf_include' ) ), '.' ) );

		return 1 === preg_match( '/^[a-z0-9_](?:[a-z0-9_.-]{0,250}[a-z0-9])?$/D', $name ) ? $name : self::host();
	}

	/**
	 * The DMARC report address as set, '' when unset (the DNS records then use the admin email).
	 */
	public static function dmarc_rua(): string {
		$email = sanitize_email( (string) Settings::get( 'mail_dmarc_rua' ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * The reserved domains, lower case.
	 *
	 * @return string[]
	 */
	public static function reserved(): array {
		$list = preg_split( '/[\s,]+/', strtolower( (string) Settings::get( 'mail_reserved_domains' ) ), -1, PREG_SPLIT_NO_EMPTY );
		$list = array_map( static fn( $d ) => rtrim( $d, '.' ), is_array( $list ) ? $list : array() );

		/**
		 * Filters the domains customers may not use for email.
		 *
		 * @param string[] $list Lower case domains.
		 */
		$filtered = apply_filters( 'beltoft_services_mail_reserved_domains', $list );

		return is_array( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : $list;
	}

	/**
	 * The sending limit of every mailbox: [ value, 'd' ] (messages per day), or null for none.
	 *
	 * @return array{0:int,1:string}|null
	 */
	public static function rate_limit( ?\BeltoftServices\Resource $r = null ): ?array {
		$day = self::override( $r, 'day', (int) Settings::get( 'mail_rl_day' ) );

		return $day > 0 ? array( $day, 'd' ) : null;
	}

	/**
	 * A per-resource sending limit (the resource meta `mail_rl`, keys minute and day), which wins over the global
	 * setting for that resource's domain and mailboxes only. Hosting sets it on the Email lite domain of a plan.
	 * A key that is not set leaves the global value; a set key, 0 included, replaces it.
	 *
	 * @param \BeltoftServices\Resource|null $r       Resource.
	 * @param string                          $key     minute or day.
	 * @param int                             $default The global value.
	 */
	public static function override( ?\BeltoftServices\Resource $r, string $key, int $default ): int {
		$set = null === $r ? null : $r->get( MailMeta::RATE_LIMIT );

		return is_array( $set ) && isset( $set[ $key ] ) && is_numeric( $set[ $key ] ) ? max( 0, (int) $set[ $key ] ) : $default;
	}

	/**
	 * The sending limit of a whole domain: [ value, 'm' ] (messages per minute), or null for none.
	 *
	 * @return array{0:int,1:string}|null
	 */
	public static function domain_rate_limit( ?\BeltoftServices\Resource $r = null ): ?array {
		$minute = self::override( $r, 'minute', (int) Settings::get( 'mail_rl_minute' ) );

		return $minute > 0 ? array( $minute, 'm' ) : null;
	}

	// -- Watching the DNS records -----------------------------------------------------------------

	/** Seconds to wait before a lost record is confirmed. */
	public static function dns_confirm_seconds(): int {
		return max( 0, (int) Settings::get( 'mail_dns_confirm_minutes' ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * The days after a loss when a reminder goes out, ascending.
	 *
	 * @return int[]
	 */
	public static function dns_remind_days(): array {
		$days = array();

		foreach ( preg_split( '/[\s,;]+/', (string) Settings::get( 'mail_dns_remind_days' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $day ) {
			if ( ctype_digit( $day ) && (int) $day > 0 ) {
				$days[] = (int) $day;
			}
		}

		$days = array_values( array_unique( $days ) );
		sort( $days );

		return $days;
	}

	/** Looks per day at a domain with all records. 1, 2 or 4. */
	public static function dns_recheck_per_day(): int {
		$n = (int) Settings::get( 'mail_dns_recheck_per_day' );

		return in_array( $n, array( 1, 2, 4 ), true ) ? $n : 2;
	}

	/** The cron string of the sweep that queues those looks. */
	public static function dns_recheck_cron(): string {
		$hours = array( 1 => '3', 2 => '3,15', 4 => '3,9,15,21' );

		return '40 ' . $hours[ self::dns_recheck_per_day() ] . ' * * *';
	}

	/** Seconds a domain is left alone after a look: a little under the gap between two sweeps. */
	public static function dns_recheck_guard(): int {
		return ( intdiv( 24, self::dns_recheck_per_day() ) - 2 ) * HOUR_IN_SECONDS;
	}

	/** Seconds over which one sweep spreads its looks. */
	public static function dns_recheck_spread(): int {
		return ( intdiv( 24, self::dns_recheck_per_day() ) - 1 ) * HOUR_IN_SECONDS;
	}

	/** Days between the slow looks after the ten minute look has stopped. */
	public static function dns_weekly_days(): int {
		return max( 1, (int) Settings::get( 'mail_dns_weekly_days' ) );
	}

	/** Days after which no look is made at all. */
	public static function dns_stop_days(): int {
		return max( 15, (int) Settings::get( 'mail_dns_stop_days' ) );
	}

	/** Days without email DNS before the one admin alert. */
	public static function dns_admin_days(): int {
		return max( 1, (int) Settings::get( 'mail_dns_admin_days' ) );
	}

	/** Seconds between two admin alerts about the same domain losing records. */
	public static function dns_admin_throttle(): int {
		return max( 1, (int) Settings::get( 'mail_dns_admin_throttle_days' ) ) * DAY_IN_SECONDS;
	}

	/** Seconds between two Check now presses. */
	public static function dns_check_gap(): int {
		return max( 0, (int) Settings::get( 'mail_dns_check_gap' ) );
	}

	/** Check now presses in a rolling hour. */
	public static function dns_check_per_hour(): int {
		return max( 1, (int) Settings::get( 'mail_dns_check_per_hour' ) );
	}

	/** Seconds after a loss when the status turns red. */
	public static function dns_red_after(): int {
		return max( 0, (int) Settings::get( 'mail_dns_lost_red_days' ) ) * DAY_IN_SECONDS;
	}

	/** Is a second MX with the same or a better priority a problem? */
	public static function dns_mx_strict(): bool {
		return (bool) Settings::get( 'mail_dns_mx_strict' );
	}

	/** Are our records removed from the customer zone when the domain is deleted? */
	public static function dns_cleanup_on_delete(): bool {
		return (bool) Settings::get( 'mail_dns_cleanup_on_delete' );
	}

	/** Seconds a customer may press Try again after the proof was not found. 0: never. */
	public static function retry_seconds(): int {
		return max( 0, (int) Settings::get( 'mail_retry_days' ) ) * DAY_IN_SECONDS;
	}
}
