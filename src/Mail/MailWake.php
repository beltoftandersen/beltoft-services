<?php
/**
 * Steps a mail resource at once when the domain it waits for is ready.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A mail resource that waits for a domain (bought in the same order, or on a name server of ours) retries on its own
 * timer. When the domain resource turns ready the timer is lifted and one job is queued, so the customer does not wait
 * out the rest of the backoff. Jobs::enqueue() keeps one job per resource.
 */
final class MailWake {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 20, 3 );
	}

	/**
	 * Action beltoft_services_state_changed.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'ready' !== $new || ! $r instanceof Resource || ! in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ) {
			return;
		}

		$name = strtolower( (string) $r->get( 'domain' ) );
		$user = (int) ( $r->license()['user_id'] ?? 0 );

		if ( '' === $name || $user <= 0 ) {
			return;
		}

		foreach ( Resource::live_ids( MailDriver::KIND ) as $id ) {
			$mail = Resource::find( $id );

			if ( ! $mail || MailDriver::ID !== $mail->get( 'driver' ) || (int) ( $mail->license()['user_id'] ?? 0 ) !== $user ) {
				continue;
			}

			if ( MailGuard::domain( $mail ) !== $name || ! in_array( (string) $mail->get( 'state' ), array( 'requested', 'provisioning' ), true ) || in_array( 'proof', (array) $mail->get( 'steps' ), true ) ) {
				continue;
			}

			// Only a service waiting on a timer: one that runs now needs nothing.
			if ( (int) $mail->get( 'next_attempt_at' ) > 0 ) {
				$mail->set( array( 'next_attempt_at' => null ) );
			}

			Jobs::enqueue( $mail->id() );
		}
	}
}
