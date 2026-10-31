<?php
/**
 * The customer's page of an email domain (the driver's part of the service panel).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Account\Email;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Prints templates/account/mail.php with MailView. A password is never part of the page: a button asks for it once.
 */
final class MailCard {

	public const HANDLE = 'beltoft-services-mail';

	/**
	 * The script and style, loaded when the page is printed (no other page loads them).
	 */
	public static function assets(): void {
		static $done = false;

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/mail.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/mail.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/mail.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Account\Panel::HANDLE ), \BeltoftServices\Assets::version( 'assets/mail.js' ), true );

		if ( $done && false !== wp_scripts()->get_data( self::HANDLE, 'data' ) ) {
			return;
		}

		$done = true;
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesMail',
			array(
				'strings' => array(
					'failed'    => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'limit'     => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
					'gone'      => __( 'The password has already been shown or is no longer available.', 'beltoft-services' ),
					'once'      => __( 'The password is shown one time only. Copy it now and keep it safe.', 'beltoft-services' ),
					/* translators: %s: mailbox address. */
					'created'   => __( 'Mailbox %s is ready.', 'beltoft-services' ),
					/* translators: %s: mailbox address. */
					'reset'     => __( 'New password for %s.', 'beltoft-services' ),
					'copied'    => __( 'Copied', 'beltoft-services' ),
					'confirm'   => __( 'Delete this alias?', 'beltoft-services' ),
					'checking'  => __( 'Looking at your DNS. This can take a few seconds.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * Listener of beltoft_services_account_assets: the script and style are in the page head and footer on the Email
	 * endpoint, not only when the card is printed.
	 *
	 * @param mixed $parent   Handle of the panel script.
	 * @param mixed $endpoint The My Account endpoint being shown.
	 */
	public static function early( $parent = null, $endpoint = null ): void {
		if ( is_string( $endpoint ) && ServiceArea::get( Email::class )->slug() === $endpoint && is_user_logged_in() ) {
			self::assets();
		}
	}

	/**
	 * Prints the page.
	 *
	 * @param Resource $r Resource.
	 */
	public static function render( Resource $r ): void {
		self::assets();

		$page = ServiceArea::page();
		$area = $page && ( $page['area'] ?? null ) instanceof Email ? $page['area'] : null;
		$nav  = $area ? $area->nav() : array();
		$tab  = $area && '' !== (string) $page['tab'] ? (string) $page['tab'] : 'overview';
		$want = array( 'overview' => array( 'mailboxes' ), 'aliases' => array( 'aliases' ) );
		$view = MailView::data( $r, $want[ $tab ] ?? array() );
		$link = static fn( string $slug ): string => $area && $page ? $area->url( $page['license'], 'overview' === $slug ? '' : $slug ) : '';

		( static function ( array $view, array $nav, string $active, callable $link ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/mail.php';
		} )( $view, $nav, $tab, $link );
	}
}
