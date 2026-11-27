<?php
/**
 * The customer's page of an email domain (the driver's part of the service panel).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Mail\Email;
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
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/mail.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Account\Panel::HANDLE, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/mail.js' ), true );

		if ( $done && false !== wp_scripts()->get_data( self::HANDLE, 'data' ) ) {
			return;
		}

		$done = true;
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesMail',
			array(
				'passwordMin' => MailPassword::min(),
				'passwordMax' => MailPassword::MAX,
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
					'noCode'    => __( 'No password was shown. Use Show password once when you have the code we email you.', 'beltoft-services' ),
					/* translators: %s: the mailbox address. */
					'chosenNew' => __( 'The mailbox %s is ready. Sign in with the password you chose.', 'beltoft-services' ),
					/* translators: %s: the mailbox address. */
					'chosenSet' => __( 'The new password of %s is set. The old one no longer works.', 'beltoft-services' ),
					/* translators: 1: shortest length, 2: longest length. */
					'pwLength'  => __( 'The password must be %1$d to %2$d characters long.', 'beltoft-services' ),
					'pwControl' => __( 'The password cannot contain control characters.', 'beltoft-services' ),
					'pwSpace'   => __( 'The password cannot start or end with a space.', 'beltoft-services' ),
					'pwMix'     => __( 'The password needs at least one letter and one digit.', 'beltoft-services' ),
					'pwAddress' => __( 'The password cannot contain the mailbox address.', 'beltoft-services' ),
					'pwLocal'   => __( 'The password cannot contain the name before the @ of the mailbox.', 'beltoft-services' ),
					'pwSame'    => __( 'The two passwords are not the same.', 'beltoft-services' ),
					'confirm'   => __( 'Delete this alias?', 'beltoft-services' ),
					'revealing' => __( 'Showing the password…', 'beltoft-services' ),
					'resetting' => __( 'Setting the new password…', 'beltoft-services' ),
					'creating'  => __( 'Making the mailbox…', 'beltoft-services' ),
					'deleting'  => __( 'Deleting…', 'beltoft-services' ),
					'aliasing'  => __( 'Making the alias…', 'beltoft-services' ),
					'adding'    => __( 'Adding the domain…', 'beltoft-services' ),
					'removing'  => __( 'Removing the domain…', 'beltoft-services' ),
					'removeAsk' => MailSettings::dns_cleanup_on_delete()
						? __( 'Remove this domain from your plan? We also remove the email records we made in your DNS on our name servers.', 'beltoft-services' )
						: __( 'Remove this domain from your plan?', 'beltoft-services' ),
					'checking'  => __( 'Looking at your DNS. This can take a few seconds.', 'beltoft-services' ),
					'proofNot'  => __( 'Checked. The proof record is not found yet. Add it on the DNS tab, then check again.', 'beltoft-services' ),
					'checked'   => __( 'Checked.', 'beltoft-services' ),
					'retrying'  => __( 'We are setting up your email domain again. This can take a minute.', 'beltoft-services' ),
					/* translators: %d: seconds */
					'again'     => __( 'Check again in %d s', 'beltoft-services' ),
					'slow'      => __( 'This is taking longer than usual. It goes on in the background: reload the page in a minute to see the result.', 'beltoft-services' ),
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
		$want = array( 'overview' => array( 'mailboxes' ), 'aliases' => array( 'aliases', 'mailboxes' ) ); // The alias form offers the mailboxes to choose.
		$view = MailView::data( $r, $want[ $tab ] ?? array() );
		$link = static fn( string $slug ): string => $area && $page ? $area->url( $page['license'], 'overview' === $slug ? '' : $slug ) : '';

		// The address of the page of another domain of the plan.
		$other_url = static function ( int $resource ) use ( $area ): string {
			$other = Resource::find( $resource );

			return $area && $other ? $area->url( $other->license() ) : '';
		};

		( static function ( array $view, array $nav, string $active, callable $link, callable $other_url ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/mail.php';
		} )( $view, $nav, $tab, $link, $other_url );
	}
}
