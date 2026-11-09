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

		$system = MailGuard::system_address( $r );

		foreach ( $rows as $row ) {
			$email = strtolower( (string) ( $row['username'] ?? '' ) );

			if ( '' !== $system && $email === $system ) {
				continue; // The system mailbox is not the customer's.
			}

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

		$quota   = $size > 0 ? $size : $limits['quota_mb'];
		$client  = MailClients::for();
		$outcome = null;
		$count   = 0;

		// State, count, password and add are one step under the resource lock: parallel requests cannot pass the plan's limit together.
		$ran = MailPlan::locked(
			$r,
			static function () use ( $r, $client, $email, $name, $quota, &$outcome, &$count ): bool {
				$bad = MailGuard::check( $r );

				if ( $bad ) {
					$outcome = $bad;
					return false;
				}

				// The limit is the plan's: the mailboxes of the root and of every extra domain count together.
				$used = MailPlan::measure_mailboxes( MailPlan::root( $r ) );

				if ( is_wp_error( $used ) ) {
					$outcome = MailGuard::failed( $used, __( 'create the mailbox', 'beltoft-services' ) );
					return false;
				}

				if ( $used >= MailPlan::limits( $r )['mailboxes'] ) {
					$outcome = new \WP_Error( 'limit', __( 'Your plan has no room for more mailboxes.', 'beltoft-services' ) );
					return false;
				}

				try {
					$password = MailAccess::begin( $r, $email );
				} catch ( \Throwable $e ) {
					$outcome = new \WP_Error( 'unavailable', __( 'The password could not be stored. Try again later.', 'beltoft-services' ) );
					return false;
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
					$outcome = MailGuard::failed( $added, __( 'create the mailbox', 'beltoft-services' ) );
					return false;
				}

				// The send limit is not optional on a mail server shared by everyone: without it the mailbox is removed again.
				$limit = MailSettings::rate_limit( $r );

				if ( null !== $limit ) {
					$set = $client->set_mailbox_rate_limit( $email, $limit[0], $limit[1] );

					if ( is_wp_error( $set ) ) {
						$client->delete_mailbox( $email );
						MailAccess::abort( $r, $email );
						$outcome = MailGuard::failed( $set, __( 'create the mailbox', 'beltoft-services' ) );
						return false;
					}
				}

				MailAccess::finish( $r, $email );
				$seen    = MailPlan::mailboxes_of( $r );
				$count   = is_wp_error( $seen ) ? (int) $r->get( 'mail_boxes' ) + 1 : $seen;
				$outcome = true;

				return true;
			}
		);

		if ( null === $ran ) {
			return new \WP_Error( 'busy', __( 'Another change is running. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( true !== $outcome ) {
			return $outcome;
		}

		MailView::remember( $r, $count );

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

		if ( '' !== MailGuard::system_address( $r ) && $email === MailGuard::system_address( $r ) ) {
			return new \WP_Error( 'invalid', __( 'That mailbox is used by your websites and cannot be deleted here.', 'beltoft-services' ) );
		}

		$gone = MailClients::for()->delete_mailbox( $email );

		if ( is_wp_error( $gone ) ) {
			return MailGuard::failed( $gone, __( 'delete the mailbox', 'beltoft-services' ) );
		}

		MailAccess::purge( $r, $email );
		$left = Mailboxes::all( $r );

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

		if ( '' !== MailGuard::system_address( $r ) && $email === MailGuard::system_address( $r ) ) {
			return new \WP_Error( 'invalid', __( 'That mailbox is used by your websites and its password cannot be changed here.', 'beltoft-services' ) );
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
}
