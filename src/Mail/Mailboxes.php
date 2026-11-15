<?php
/**
 * The mailboxes of one email domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plain PHP operations on the mailboxes of a mail resource (no screen yet). A password is generated and stored for
 * one-time reveal (MailAccess) before it reaches the mail server, and is never returned: the caller reveals it with
 * MailAccess::take(). Every operation checks the resource (MailGuard) and stays inside its domain.
 */
final class Mailboxes {

	/** The shared customer actions of the mail driver (internal actions: queued by MailRoutes, run by MailDriver::act). */
	public const CREATE    = 'mailbox_create';
	public const PASSWORD  = 'mailbox_password';
	public const DNS_CHECK = 'mail_dns_check';
	public const ACTIONS   = array( self::CREATE, self::PASSWORD, self::DNS_CHECK );

	/**
	 * Hooks: a lost job of these actions is queued again (each can be repeated), and a mailbox that its job could not finish is taken back.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_action_resumable', array( __CLASS__, 'resumable' ), 10, 3 );
		add_action( 'beltoft_services_action_failed', array( __CLASS__, 'failed' ), 10, 4 );
	}

	/**
	 * Filter beltoft_services_action_resumable.
	 *
	 * @param bool   $yes    Answer so far.
	 * @param string $driver Driver id.
	 * @param string $action Action name.
	 */
	public static function resumable( $yes, $driver, $action ): bool {
		return (bool) $yes || ( MailDriver::ID === $driver && in_array( $action, self::ACTIONS, true ) );
	}

	/**
	 * Action beltoft_services_action_failed: a new mailbox whose job ended for good is removed again and its password forgotten.
	 *
	 * @param mixed  $r      Resource.
	 * @param string $action Action name.
	 * @param mixed  $args   Arguments of the job.
	 * @param string $why    failed, stopped or lapsed.
	 */
	public static function failed( $r, $action, $args, $why ): void {
		if ( self::CREATE !== $action || ! $r instanceof Resource || ! in_array( $why, array( 'failed', 'stopped' ), true ) || ! is_array( $args ) ) {
			return;
		}

		$email = MailGuard::inside( $r, (string) ( $args['email'] ?? '' ) );

		if ( null !== $email && 'pending' === MailAccess::state( $r, $email ) ) {
			self::undo( $r, $email );
		}
	}

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
	 * The request side of a new mailbox: checks what needs no call to the mail server and says what the job will make. The mailbox
	 * itself is made by the shared action `mailbox_create` (run_create), queued by the route with reserve() as its preparation.
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $local Local part.
	 * @param string   $name  Display name; the local part when empty.
	 * @param int      $size  Size in MB, up to the plan's; 0 for the plan's size.
	 * @return array{email:string,name:string,quota:int}|\WP_Error
	 */
	public static function plan_create( Resource $r, $local, string $name = '', int $size = 0 ) {
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

		// A password already kept for this address (one in flight, or one not yet shown) means the mailbox exists: it is never overwritten.
		if ( MailAccess::has( $r, $email ) ) {
			return new \WP_Error( 'exists', __( 'That address is already in use.', 'beltoft-services' ) );
		}

		return array( 'email' => $email, 'name' => '' !== trim( $name ) ? sanitize_text_field( $name ) : (string) strstr( $email, '@', true ), 'quota' => $size > 0 ? $size : $limits['quota_mb'] );
	}

	/**
	 * Generates the password of an address and keeps it encrypted and pending (MailAccess::begin) until the job has set it on the mail
	 * server. The password is in no job argument. Used as the preparation of the queued action.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $email Mailbox address.
	 * @return true|\WP_Error
	 */
	public static function reserve( Resource $r, string $email ) {
		$error = null;
		$ran   = $r->locked(
			static function () use ( $r, $email, &$error ): bool {
				try {
					MailAccess::begin( $r, $email );
				} catch ( \Throwable $e ) {
					$error = new \WP_Error( 'unavailable', __( 'The password could not be stored. Try again later.', 'beltoft-services' ) );
				}

				return null === $error;
			}
		);

		if ( null === $ran ) {
			return new \WP_Error( 'busy', __( 'Another change is running. Try again in a moment.', 'beltoft-services' ) );
		}

		return $error ?? true;
	}

	/**
	 * What the customer is told when the job ends with a sentence of its own.
	 *
	 * @param string               $text Sentence.
	 * @param array<string, mixed> $meta More meta.
	 * @return array<string, mixed>
	 */
	private static function said( string $text, array $meta = array() ): array {
		return array_merge( $meta, array( Jobs::TEXT => $text ) );
	}

	/**
	 * The job `mailbox_create` (MailDriver::act). It makes the mailbox with the password kept by reserve(), sets the send limit and the
	 * hold, and only then lets the password be revealed. Safe to run again: a mailbox that is there and was asked for by an earlier run
	 * is taken as made (never "already in use"); one that was there before is refused and never touched; a passing mail server error
	 * is tried again on the usual backoff, and a mailbox that can never be finished is removed again and the removal is checked.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args email, name, quota.
	 */
	public static function run_create( Resource $r, array $args ): StepResult {
		$email = MailGuard::inside( $r, (string) ( $args['email'] ?? '' ) );

		if ( null === $email ) {
			return StepResult::fail( 'Not a mailbox of this domain.', self::said( __( 'That mailbox is not part of this domain.', 'beltoft-services' ) ) );
		}

		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return StepResult::fail( 'The email service is not ready.', self::said( $bad->get_error_message() ) );
		}

		$state = MailAccess::state( $r, $email );

		if ( 'ready' === $state ) {
			// An earlier run finished; its reply was lost.
			return StepResult::done( self::said( self::created_text() ) );
		}

		if ( null === $state ) {
			return StepResult::fail( 'The password was not kept.', self::said( __( 'The password could not be stored. Try again later.', 'beltoft-services' ) ) );
		}

		$outcome = null;
		$ran     = MailPlan::locked(
			$r,
			static function () use ( $r, $email, $args, &$outcome ): bool {
				$outcome = self::create_locked( $r, $email, $args );

				return true;
			}
		);

		return null === $ran ? StepResult::retry( 30, 'Another change is running.' ) : $outcome;
	}

	/**
	 * The steps of run_create under the plan's lock.
	 *
	 * @param Resource             $r     Resource.
	 * @param string               $email Address.
	 * @param array<string, mixed> $args  Arguments of the job.
	 */
	private static function create_locked( Resource $r, string $email, array $args ): StepResult {
		$client = MailClients::for();
		$box    = $client->get_mailbox( $email );

		if ( is_wp_error( $box ) ) {
			return self::from_error( $r, $email, $box, false );
		}

		if ( null === $box ) {
			// The limit is the plan's: the mailboxes of the root and of every extra domain count together.
			$used = MailPlan::measure_mailboxes( MailPlan::root( $r ) );

			if ( is_wp_error( $used ) ) {
				return self::from_error( $r, $email, $used, false );
			}

			if ( $used >= MailPlan::limits( $r )['mailboxes'] ) {
				MailAccess::abort( $r, $email );

				return StepResult::fail( 'The plan has no room.', self::said( __( 'Your plan has no room for more mailboxes.', 'beltoft-services' ) ) );
			}

			$password = MailAccess::pending_password( $r, $email );

			if ( null === $password || ! MailAccess::mark_sent( $r, $email ) ) {
				return StepResult::retry( 60, 'The password could not be read.' );
			}

			$added = $client->add_mailbox(
				array(
					'local_part'      => (string) strstr( $email, '@', true ),
					'domain'          => MailGuard::domain( $r ),
					'name'            => (string) ( $args['name'] ?? '' ),
					'quota'           => (string) (int) ( $args['quota'] ?? 0 ),
					'password'        => $password,
					'password2'       => $password,
					'active'          => '1',
					'force_pw_update' => '0',
				)
			);

			$password = '';

			if ( is_wp_error( $added ) ) {
				return self::from_error( $r, $email, $added, true );
			}
		} elseif ( ! MailAccess::sent( $r, $email ) ) {
			// There before this request: never touched, never given a password it does not have.
			MailAccess::abort( $r, $email );

			return StepResult::fail( 'The mailbox existed before.', self::said( __( 'That address is already in use.', 'beltoft-services' ) ) );
		}

		// The send limit is not optional on a mail server shared by everyone: without it the mailbox is removed again.
		$limit = MailSettings::rate_limit( $r );

		if ( null !== $limit ) {
			$set = $client->set_mailbox_rate_limit( $email, $limit[0], $limit[1] );

			if ( is_wp_error( $set ) ) {
				return self::from_error( $r, $email, $set, true );
			}
		}

		// A domain whose SPF and DKIM are not found yet may receive, not send: the new mailbox is held like the others.
		$held = MailHold::on_new_mailbox( $r, $client, $email );

		if ( is_wp_error( $held ) ) {
			return self::from_error( $r, $email, $held, true );
		}

		if ( ! MailAccess::finish( $r, $email ) ) {
			return StepResult::retry( 30, 'The password could not be released.' );
		}

		$seen = MailPlan::mailboxes_of( $r );
		MailView::remember( $r, is_wp_error( $seen ) ? (int) $r->get( 'mail_boxes' ) + 1 : $seen );

		return StepResult::done( self::said( self::created_text() ) );
	}

	/**
	 * The sentence of a made mailbox.
	 */
	private static function created_text(): string {
		return __( 'The mailbox is ready. Show the password once to set up your mail program.', 'beltoft-services' );
	}

	/**
	 * A mail server error as the result of a step. A passing one (the server could not be reached) is tried again; a refusal the customer
	 * can read (taken name, full plan) ends the action with its sentence; anything else ends it for staff. When the mailbox is already ours
	 * and the job ends, undo() removes it again.
	 *
	 * @param Resource  $r     Resource.
	 * @param string    $email Address.
	 * @param \WP_Error $e     The client's error.
	 * @param bool      $ours  Whether the mailbox was asked for by this job (so it is removed when the job ends).
	 */
	private static function from_error( Resource $r, string $email, \WP_Error $e, bool $ours ): StepResult {
		$mapped = MailGuard::failed( $e, __( 'create the mailbox', 'beltoft-services' ) );
		$code   = $mapped->get_error_code();

		if ( 'unavailable' === $code ) {
			return StepResult::retry( 60, 'The mail server could not be reached.' );
		}

		if ( $ours ) {
			self::undo( $r, $email );
		} else {
			MailAccess::abort( $r, $email );
		}

		return in_array( $code, array( 'exists', 'limit' ), true )
			? StepResult::fail( 'Refused by the mail server: ' . $code, self::said( $mapped->get_error_message() ) )
			: StepResult::fail( 'The mail server did not accept the mailbox: ' . $e->get_error_code() );
	}

	/**
	 * Takes back a mailbox this job asked for and could not finish: deleted on the mail server (the answer is checked, staff are told when it
	 * fails, so a mailbox without a send limit is never left unseen) and its password forgotten.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $email Address.
	 */
	public static function undo( Resource $r, string $email ): void {
		if ( ! MailAccess::sent( $r, $email ) ) {
			// Nothing was asked of the mail server for this address: the mailbox, if there is one, is not ours to remove.
			MailAccess::abort( $r, $email );
			return;
		}

		$gone = MailClients::for()->delete_mailbox( $email );

		if ( is_wp_error( $gone ) ) {
			Alerts::admin( $r, sprintf( 'A mailbox that could not be finished was not removed again (%s): %s', $email, $gone->get_error_code() ) );
		}

		MailAccess::abort( $r, $email );
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
	 * The request side of a new password: checks what needs no call to the mail server. The password is made by the shared action
	 * `mailbox_password` (run_password), queued by the route with reserve() as its preparation.
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $email Address.
	 * @return string|\WP_Error The address.
	 */
	public static function plan_password( Resource $r, $email ) {
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

		return $email;
	}

	/**
	 * The job `mailbox_password` (MailDriver::act): sets the password kept by reserve() on the mailbox and lets it be revealed. The password
	 * is kept encrypted until then, so a lost answer or a replay sets the same password again and it is never unknown.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args email.
	 */
	public static function run_password( Resource $r, array $args ): StepResult {
		$email = MailGuard::inside( $r, (string) ( $args['email'] ?? '' ) );

		if ( null === $email ) {
			return StepResult::fail( 'Not a mailbox of this domain.', self::said( __( 'That mailbox is not part of this domain.', 'beltoft-services' ) ) );
		}

		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return StepResult::fail( 'The email service is not ready.', self::said( $bad->get_error_message() ) );
		}

		if ( 'ready' === MailAccess::state( $r, $email ) ) {
			return StepResult::done( self::said( self::password_text() ) );
		}

		$password = MailAccess::pending_password( $r, $email );

		if ( null === $password ) {
			return StepResult::fail( 'The password was not kept.', self::said( __( 'The password could not be stored. Try again later.', 'beltoft-services' ) ) );
		}

		$client = MailClients::for();
		$box    = $client->get_mailbox( $email );

		if ( is_wp_error( $box ) ) {
			return StepResult::retry( 60, 'The mail server could not be reached.' );
		}

		if ( null === $box ) {
			MailAccess::abort( $r, $email );

			return StepResult::fail( 'The mailbox does not exist.', self::said( __( 'That mailbox does not exist.', 'beltoft-services' ) ) );
		}

		$set      = $client->edit_mailbox( $email, array( 'password' => $password, 'password2' => $password, 'force_pw_update' => '0' ) );
		$password = '';

		if ( is_wp_error( $set ) ) {
			$mapped = MailGuard::failed( $set, __( 'reset the password', 'beltoft-services' ) );

			if ( 'unavailable' === $mapped->get_error_code() ) {
				return StepResult::retry( 60, 'The mail server could not be reached.' );
			}

			MailAccess::abort( $r, $email );

			return StepResult::fail( 'The password was not accepted: ' . $set->get_error_code() );
		}

		if ( ! MailAccess::finish( $r, $email ) ) {
			return StepResult::retry( 30, 'The password could not be released.' );
		}

		return StepResult::done( self::said( self::password_text() ) );
	}

	/**
	 * The sentence of a new password.
	 */
	private static function password_text(): string {
		return __( 'The old password no longer works. Show the new password once.', 'beltoft-services' );
	}
}
