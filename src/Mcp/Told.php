<?php
/**
 * Tells the customer by e-mail what a connected app did in their name, and says so in the log when the mail was not sent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The customer's way to learn that something was done: after every confirmed tool that cannot be undone, and after every
 * secret that was shown. It uses the notice e-mail (whatever the shop's switch for it says), to the licence's address or,
 * when that is empty, the customer's own. A call is refused before it is confirmed when there is no address at all, and a
 * mail that could not be sent leaves "notice e-mail not sent" in the history or the customer's log, so it never fails
 * silently. A mail never holds a secret.
 */
final class Told {

	/**
	 * What each tool did, as the mail says it ("it reinstalled the server").
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function phrase( string $name, array $args ): string {
		switch ( $name ) {
			case 'delete_snapshot':
				return __( 'deleted a snapshot', 'beltoft-services' );

			case 'rollback_snapshot':
				return __( 'rolled the server back to a snapshot', 'beltoft-services' );

			case 'restore_backup':
				return __( 'restored it from a backup', 'beltoft-services' );

			case 'reinstall_server':
				return __( 'reinstalled the server, which erases everything on it', 'beltoft-services' );

			case 'replace_ssh_key':
				return __( 'replaced the SSH key of the server', 'beltoft-services' );

			case 'reset_firewall':
				return __( 'reset the firewall to its default rules', 'beltoft-services' );

			case 'reset_server_password':
				return __( 'made a new password for the server', 'beltoft-services' );

			case 'delete_mailbox':
				/* translators: %s: mailbox address. */
				return sprintf( __( 'deleted the mailbox %s and every message in it', 'beltoft-services' ), sanitize_email( (string) ( $args['email'] ?? '' ) ) );

			case 'set_database_eviction_policy':
				return __( 'changed what the database does when its memory is full', 'beltoft-services' );

			case 'cancel_subscription':
				return __( 'cancelled the subscription', 'beltoft-services' );

			case 'remove_addon':
				return __( 'removed an add-on', 'beltoft-services' );

			case 'set_default_payment_method':
				return __( 'made a saved card your default payment method', 'beltoft-services' );

			case 'set_firewall':
				return false === ( $args['on'] ?? null ) ? __( 'turned the firewall off', 'beltoft-services' ) : __( 'changed the firewall so that many ports are open to everyone', 'beltoft-services' );

			case 'set_database_access':
				return __( 'opened the database to everyone', 'beltoft-services' );

			case 'domain_settings':
				return __( 'moved the domain to other name servers', 'beltoft-services' );
		}

		return str_replace( '_', ' ', $name );
	}

	/**
	 * The address the customer is told at, or ''.
	 *
	 * @param int           $user_id Customer.
	 * @param Resource|null $r       The service the call is about, if any.
	 */
	public static function recipient( int $user_id, ?Resource $r ): string {
		$license = $r ? $r->license() : array();
		$email   = trim( (string) ( $license['email'] ?? '' ) );

		if ( '' === $email ) {
			$user  = get_userdata( $user_id );
			$email = $user ? trim( (string) $user->user_email ) : '';
		}

		return is_email( $email ) ? $email : '';
	}

	/**
	 * The service a call is about, for the mail: the one named by id, or the first one a subscription pays for.
	 *
	 * @param int                  $user_id Customer.
	 * @param array<string, mixed> $args    Arguments.
	 */
	public static function service_of( int $user_id, array $args ): ?Resource {
		if ( isset( $args['id'] ) && is_int( $args['id'] ) ) {
			$r = Resource::find( $args['id'] );

			return $r && (int) ( $r->license()['user_id'] ?? 0 ) === $user_id ? $r : null;
		}

		if ( isset( $args['subscription_id'] ) && is_int( $args['subscription_id'] ) ) {
			foreach ( \BeltoftLicenses\Licenses::where( 'subscription_id', $args['subscription_id'] ) as $license ) {
				$r = (int) ( $license['user_id'] ?? 0 ) === $user_id ? Resource::for_license( (int) $license['id'] ) : null;

				if ( $r ) {
					return $r;
				}
			}
		}

		return null;
	}

	/**
	 * Is there an address to tell the customer at? Without one the call is not confirmed at all.
	 *
	 * @param int                  $user_id Customer.
	 * @param array<string, mixed> $args    Arguments.
	 */
	public static function can_tell( int $user_id, array $args ): bool {
		return '' !== self::recipient( $user_id, self::service_of( $user_id, $args ) );
	}

	/**
	 * Tells the customer what a confirmed tool did, and notes it when the mail did not go.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 */
	public static function done( string $name, array $args, int $user_id ): void {
		self::send( $user_id, self::service_of( $user_id, $args ), 'app_action', self::phrase( $name, $args ), $args );
	}

	/**
	 * Sends the notice e-mail, or for something that belongs to no service a plain note through the shop's mailer, and
	 * writes a line to the trail when it did not go.
	 *
	 * @param int                  $user_id Customer.
	 * @param Resource|null        $r       The service, if any.
	 * @param string               $kind    Notice kind.
	 * @param string               $what    What the app did, for kind app_action.
	 * @param array<string, mixed> $args    Arguments, for the trail.
	 */
	public static function send( int $user_id, ?Resource $r, string $kind, string $what, array $args = array() ): void {
		$sent = false;

		try {
			if ( $r ) {
				$sent = Emails::send( 'beltoft_services_notice', $r, array( 'notice_kind' => $kind, 'notice_app' => Acts::app_name(), 'notice_what' => $what ) );
			} elseif ( '' !== self::recipient( $user_id, null ) ) {
				$app    = Acts::app_name();
				$mailer = WC()->mailer();
				$head   = __( 'A connected app changed your account', 'beltoft-services' );
				$body   = '<p>' . esc_html( '' !== $app
					/* translators: 1: name of the connected app, 2: what it did, for example made a saved card your default payment method. */
					? sprintf( __( 'An app you connected to your account (%1$s) changed your account at your request: it %2$s.', 'beltoft-services' ), $app, $what )
					/* translators: %s: what it did, for example made a saved card your default payment method. */
					: sprintf( __( 'An app you connected to your account changed your account at your request: it %s.', 'beltoft-services' ), $what ) ) . '</p>'
					. '<p>' . esc_html__( 'If this was not you, disconnect the app under Account details, Security, and change your account password.', 'beltoft-services' ) . '</p>';
				$sent   = (bool) $mailer->send( self::recipient( $user_id, null ), $head, $mailer->wrap_message( $head, $body ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		if ( ! $sent ) {
			$line = 'notice e-mail not sent';

			if ( $r ) {
				Tools::log( $r->id(), $line, true );
			} else {
				Audit::add( $user_id, $line );
			}
		}

		unset( $args );
	}
}
