<?php
/**
 * The public setup guide for mail programs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [beltoft_services_mail_setup]: a plain guide to adding a mailbox to the common mail programs. The server
 * values come from the settings (mail host, webmail address, the ports of the Connect tab). The markup is the template
 * mail-setup.php (override it in yourtheme/beltoft-services/mail-setup.php). The stylesheet loads only on a page that
 * uses the shortcode. Without a mail host the shortcode prints an HTML comment.
 */
final class MailSetup {

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'beltoft_services_mail_setup';

	/**
	 * Stylesheet handle.
	 */
	public const HANDLE = 'beltoft-services-mail-setup';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Handler of the shortcode.
	 *
	 * @param mixed $atts Attributes, none.
	 */
	public static function shortcode( $atts = array() ): string {
		unset( $atts );

		return self::html();
	}

	/**
	 * The address of the guide page, empty when the setting has none or is not an https or http address.
	 */
	public static function page_url(): string {
		return esc_url_raw( trim( (string) \BeltoftServices\Settings::get( 'mail_guide_url' ) ), array( 'https', 'http' ) );
	}

	/**
	 * The guide as HTML.
	 */
	public static function html(): string {
		$host = MailSettings::host();

		if ( '' === $host ) {
			return '<!-- beltoft-services: the mail host name is not set -->';
		}

		/**
		 * Whether to load the mail setup stylesheet.
		 *
		 * @param bool $load Load it, default true.
		 */
		if ( apply_filters( 'beltoft_services_mail_setup_css', true ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/mail-setup.css', BELTOFT_SERVICES_FILE ), array(), Assets::version( 'assets/mail-setup.css' ) );
		}

		$webmail = esc_url_raw( trim( (string) \BeltoftServices\Settings::get( 'mail_webmail_url' ) ), array( 'https' ) );

		ob_start();
		wc_get_template(
			'mail-setup.php',
			array(
				'host'    => $host,
				'webmail' => $webmail,
				'imap'    => MailView::IMAP_PORT,
				'pop'     => MailView::POP_PORT,
				'smtp'    => array( MailView::SMTP_SSL, MailView::SMTP_TLS ),
			),
			'beltoft-services/',
			BELTOFT_SERVICES_PATH . 'templates/'
		);

		return (string) ob_get_clean();
	}
}
