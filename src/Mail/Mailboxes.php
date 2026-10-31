<?php
/**
 * The mailboxes of one email domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plain PHP operations on the mailboxes of a mail resource (no screen yet). A password is generated and stored for
 * one-time reveal (MailAccess) before it reaches the mail server, and is never returned: the caller reveals it with
 * MailAccess::take(). Every operation checks the resource (MailGuard) and stays inside its domain.
 */
final class Mailboxes {

	/**
	 * The mailboxes with their usage.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{email:string,name:string,quota_mb:int,used_mb:int,active:bool,reveal:bool}>|\WP_Error
	 */
	public static function all( Resource $r ) {
		$rows = MailClients::for()->list_mailboxes( MailGuard::domain( $r ) );

		if ( is_wp_error( $rows ) ) {
			return MailGuard::failed( $rows, __( 'read the mailboxes', 'beltoft-services' ) );
		}

		$out = array();

		foreach ( $rows as $row ) {
			$email = strtolower( (string) ( $row['username'] ?? '' ) );

			$out[] = array(
				'email'    => $email,
				'name'     => (string) ( $row['name'] ?? '' ),
				'quota_mb' => (int) round( (int) ( $row['quota'] ?? 0 ) / 1048576 ),
				'used_mb'  => (int) round( (int) ( $row['quota_used'] ?? 0 ) / 1048576 ),
				'active'   => '0' !== (string) ( $row['active'] ?? '1' ),
				'reveal'   => MailAccess::revealable( $r, $email ),
			);
		}

		return $out;
	}

	/**
	 * Creates a mailbox with the plan's quota, a generated password (for one-time reveal) and the send limit.
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $local Local part.
	 * @param string   $name  Display name; the local part when empty.
	 * @param int      $size  Size in MB, up to the plan's; 0 for the plan's size.
	 * @return array{email:string,quota_mb:int}|\WP_Error
	 */
	public static function create( Resource $r, $local, string $name = '', int $size = 0 ) {
		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return $bad;
		}

		$email = MailGuard::address( $r, $local );

		if ( null === $email ) {
			return new \WP_Error( 'invalid', __( 'Use letters, digits, dots, hyphens and underscores for the name before the @.', 'beltoft-services' ) );
		}

		$limits = MailGuard::limits( $r );

		if ( $size < 0 || $size > $limits['quota_mb'] ) {
			return new \WP_Error( 'limit', __( 'That size is outside what your plan allows.', 'beltoft-services' ) );
		}

		$quota  = $size > 0 ? $size : $limits['quota_mb'];
		$client = MailClients::for();
		$have   = $client->list_mailboxes( MailGuard::domain( $r ) );

		if ( is_wp_error( $have ) ) {
			return MailGuard::failed( $have, __( 'create the mailbox', 'beltoft-services' ) );
		}

		if ( count( $have ) >= $limits['mailboxes'] ) {
			return new \WP_Error( 'limit', __( 'Your plan has no room for more mailboxes.', 'beltoft-services' ) );
		}

		$password = '';
		$error    = null;
		$ran      = $r->locked(
			static function () use ( $r, $email, &$password, &$error ): bool {
				try {
					$password = MailAccess::begin( $r, $email );
				} catch ( \Throwable $e ) {
					$error = new \WP_Error( 'unavailable', __( 'The password could not be stored. Try again later.', 'beltoft-services' ) );
				}

				return null === $error;
			}
		);

		if ( null === $ran ) {
			return new \WP_Error( 'busy', __( 'Another change is running. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( $error ) {
			return $error;
		}

		$added = $client->add_mailbox(
			array(
				'local_part'      => (string) strstr( $email, '@', true ),
				'domain'          => MailGuard::domain( $r ),
				'name'            => '' !== trim( $name ) ? sanitize_text_field( $name ) : (string) strstr( $email, '@', true ),
				'quota'           => (string) $quota,
				'password'        => $password,
				'password2'       => $password,
				'active'          => '1',
				'force_pw_update' => '0',
			)
		);

		$password = '';

		if ( is_wp_error( $added ) ) {
			MailAccess::abort( $r, $email );

			return MailGuard::failed( $added, __( 'create the mailbox', 'beltoft-services' ) );
		}

		// The send limit is not optional on a mail server shared by everyone: without it the mailbox is removed again.
		$limit = MailSettings::rate_limit();

		if ( null !== $limit ) {
			$set = $client->set_mailbox_rate_limit( $email, $limit[0], $limit[1] );

			if ( is_wp_error( $set ) ) {
				$client->delete_mailbox( $email );
				MailAccess::abort( $r, $email );

				return MailGuard::failed( $set, __( 'create the mailbox', 'beltoft-services' ) );
			}
		}

		MailAccess::finish( $r, $email );
		MailView::remember( $r, count( $have ) + 1 );

		return array( 'email' => $email, 'quota_mb' => $quota );
	}

	/**
	 * Deletes a mailbox and forgets its password. A mailbox that is gone already is not an error.
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $email Address.
	 * @return true|\WP_Error
	 */
	public static function delete( Resource $r, $email ) {
		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return $bad;
		}

		$email = MailGuard::inside( $r, $email );

		if ( null === $email ) {
			return new \WP_Error( 'invalid', __( 'That mailbox is not part of this domain.', 'beltoft-services' ) );
		}

		$gone = MailClients::for()->delete_mailbox( $email );

		if ( is_wp_error( $gone ) ) {
			return MailGuard::failed( $gone, __( 'delete the mailbox', 'beltoft-services' ) );
		}

		MailAccess::purge( $r, $email );
		$left = MailClients::for()->list_mailboxes( MailGuard::domain( $r ) );

		if ( ! is_wp_error( $left ) ) {
			MailView::remember( $r, count( $left ) );
		}

		return true;
	}

	/**
	 * Sets a new generated password for one-time reveal. The old password stops working at once.
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $email Address.
	 * @return true|\WP_Error
	 */
	public static function reset_password( Resource $r, $email ) {
		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return $bad;
		}

		$email = MailGuard::inside( $r, $email );

		if ( null === $email ) {
			return new \WP_Error( 'invalid', __( 'That mailbox is not part of this domain.', 'beltoft-services' ) );
		}

		$client = MailClients::for();
		$box    = $client->get_mailbox( $email );

		if ( is_wp_error( $box ) ) {
			return MailGuard::failed( $box, __( 'reset the password', 'beltoft-services' ) );
		}

		if ( null === $box ) {
			return new \WP_Error( 'not_found', __( 'That mailbox does not exist.', 'beltoft-services' ) );
		}

		$password = '';
		$error    = null;
		$ran      = $r->locked(
			static function () use ( $r, $email, &$password, &$error ): bool {
				try {
					$password = MailAccess::begin( $r, $email );
				} catch ( \Throwable $e ) {
					$error = new \WP_Error( 'unavailable', __( 'The password could not be stored. Try again later.', 'beltoft-services' ) );
				}

				return null === $error;
			}
		);

		if ( null === $ran ) {
			return new \WP_Error( 'busy', __( 'Another change is running. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( $error ) {
			return $error;
		}

		$set      = $client->edit_mailbox( $email, array( 'password' => $password, 'password2' => $password, 'force_pw_update' => '0' ) );
		$password = '';

		if ( is_wp_error( $set ) ) {
			MailAccess::abort( $r, $email );

			return MailGuard::failed( $set, __( 'reset the password', 'beltoft-services' ) );
		}

		MailAccess::finish( $r, $email );

		return true;
	}

	/**
	 * Sets the quota of one mailbox, up to the plan's.
	 *
	 * @param Resource $r        Resource.
	 * @param mixed    $email    Address.
	 * @param int      $quota_mb New quota in MB.
	 * @return true|\WP_Error
	 */
	public static function set_quota( Resource $r, $email, int $quota_mb ) {
		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return $bad;
		}

		$email = MailGuard::inside( $r, $email );

		if ( null === $email ) {
			return new \WP_Error( 'invalid', __( 'That mailbox is not part of this domain.', 'beltoft-services' ) );
		}

		if ( $quota_mb < 1 || $quota_mb > MailGuard::limits( $r )['quota_mb'] ) {
			return new \WP_Error( 'limit', __( 'That size is outside what your plan allows.', 'beltoft-services' ) );
		}

		$set = MailClients::for()->edit_mailbox( $email, array( 'quota' => (string) $quota_mb ) );

		return is_wp_error( $set ) ? MailGuard::failed( $set, __( 'change the size', 'beltoft-services' ) ) : true;
	}

	/**
	 * Switches one mailbox on or off (the abuse pause uses it).
	 *
	 * @param Resource $r      Resource.
	 * @param mixed    $email  Address.
	 * @param bool     $active On or off.
	 * @return true|\WP_Error
	 */
	public static function set_active( Resource $r, $email, bool $active ) {
		$email = MailGuard::inside( $r, $email );

		if ( null === $email ) {
			return new \WP_Error( 'invalid', __( 'That mailbox is not part of this domain.', 'beltoft-services' ) );
		}

		$set = MailClients::for()->edit_mailbox( $email, array( 'active' => $active ? '1' : '0' ) );

		return is_wp_error( $set ) ? MailGuard::failed( $set, __( 'change the mailbox', 'beltoft-services' ) ) : true;
	}
}
