<?php
/**
 * Settings → Services → Email.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

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
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'add_tab' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'add_section' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'add_fields' ) );
		add_filter( 'beltoft_services_default_retention', array( MailDriver::class, 'default_retention' ), 10, 2 );
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
			'desc'    => __( 'Where receivers send aggregate reports (rua). Empty leaves the report address out of the DMARC record.', 'beltoft-services' ),
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

		return 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host ) ? $host : '';
	}

	/**
	 * The name in the SPF include.
	 */
	public static function spf_include(): string {
		$name = strtolower( rtrim( trim( (string) Settings::get( 'mail_spf_include' ) ), '.' ) );

		return 1 === preg_match( '/^[a-z0-9_](?:[a-z0-9_.-]{0,250}[a-z0-9])?$/D', $name ) ? $name : self::host();
	}

	/**
	 * The DMARC report address, '' when unset.
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
	public static function rate_limit(): ?array {
		$day = (int) Settings::get( 'mail_rl_day' );

		return $day > 0 ? array( $day, 'd' ) : null;
	}

	/**
	 * The sending limit of a whole domain: [ value, 'm' ] (messages per minute), or null for none.
	 *
	 * @return array{0:int,1:string}|null
	 */
	public static function domain_rate_limit(): ?array {
		$minute = (int) Settings::get( 'mail_rl_minute' );

		return $minute > 0 ? array( $minute, 'm' ) : null;
	}
}
