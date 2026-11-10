<?php
/**
 * A second check before something sensitive is done or shown, such as making a Full access API token or showing a
 * domain's transfer code. The default check is a six digit code emailed to the account address, so it works for
 * customers who sign in with Google and have no password they know. A site that has its own second factor (an
 * authenticator app, say) plugs it in through the two filters.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftServices\Emails\StepUpCode;

defined( 'ABSPATH' ) || exit;

final class StepUp {

	/**
	 * Minutes a code works, wrong tries allowed per code, and the send limit (codes per window, window in seconds).
	 */
	public const TTL_MINUTES = 10;
	public const MAX_TRIES   = 5;
	public const SEND_LIMIT  = 3;
	public const SEND_WINDOW = 600;

	/**
	 * User meta that holds the hash of the code of a purpose.
	 */
	private const META = 'beltoft_services_stepup_';

	/**
	 * The purposes a code can be sent and checked for. Each feature that needs the step registers its own on the filter
	 * beltoft_services_stepup_purposes (the API tokens and the domain transfer code do); anything else is refused.
	 *
	 * @return string[]
	 */
	public static function purposes(): array {
		/**
		 * The purposes of the step-up check, one string each.
		 *
		 * @param string[] $purposes Purposes registered so far.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'beltoft_services_stepup_purposes', array() ) ) ) );
	}

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The route behind the "Send code" button of the transfer code form.
	 */
	public static function routes(): void {
		register_rest_route(
			'beltoft-services/v1',
			'/step-up/send',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_send' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'purpose' => array( 'type' => 'string', 'required' => true, 'enum' => self::purposes() ),
				),
			)
		);
	}

	/**
	 * Sends a code for the route.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest_send( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$sent    = self::send( $user_id, (string) $request->get_param( 'purpose' ) );

		$response = is_wp_error( $sent )
			? rest_convert_error_to_response( $sent )
			: new \WP_REST_Response( array( 'sent' => true, 'email' => self::masked_email( $user_id ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * What the customer is asked for.
	 *
	 * @param int    $user_id Customer.
	 * @param string $purpose One of purposes(). Without one, the API token step (the first feature that used it).
	 * @return array{kind: string, label: string, autocomplete: string, email: string, purpose: string} kind emailed (the default) or code
	 *         (a site's own factor, nothing is sent), the field label, the autocomplete hint, the masked account email.
	 */
	public static function ask( int $user_id, string $purpose = 'api_token' ): array {
		$default = array(
			'kind'         => 'emailed',
			'label'        => __( 'Code from the email', 'beltoft-services' ),
			'autocomplete' => 'one-time-code',
			'email'        => self::masked_email( $user_id ),
			'purpose'      => $purpose,
		);

		/**
		 * What a customer confirms with before a sensitive value is shown.
		 *
		 * @param array<string, string> $ask     kind (emailed or code), label.
		 * @param int                   $user_id Customer.
		 * @param string                $purpose api_token or transfer_code.
		 */
		$ask = apply_filters( 'beltoft_services_step_up_ask', $default, $user_id, $purpose );

		return is_array( $ask ) && in_array( $ask['kind'] ?? '', array( 'emailed', 'code' ), true ) && '' !== (string) ( $ask['label'] ?? '' )
			? array_merge( $default, array( 'kind' => (string) $ask['kind'], 'label' => (string) $ask['label'] ) )
			: $default;
	}

	/**
	 * The account email with most of the name hidden, for example c***@example.org.
	 *
	 * @param int $user_id Customer.
	 */
	public static function masked_email( int $user_id ): string {
		$user  = get_userdata( $user_id );
		$email = $user ? (string) $user->user_email : '';
		$at    = strrpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return '';
		}

		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Emails a new code to the account address. A new code replaces the old one.
	 *
	 * @param int    $user_id Customer.
	 * @param string $purpose One of purposes().
	 * @return true|\WP_Error
	 */
	public static function send( int $user_id, string $purpose ) {
		$user = get_userdata( $user_id );

		if ( ! $user || ! in_array( $purpose, self::purposes(), true ) || '' === (string) $user->user_email ) {
			return new \WP_Error( 'step_up_send', __( 'We could not send the code. Try again in a few minutes.', 'beltoft-services' ), array( 'status' => 400 ) );
		}

		if ( 'emailed' !== self::ask( $user_id, $purpose )['kind'] ) {
			return new \WP_Error( 'step_up_send', __( 'No code is sent for this check.', 'beltoft-services' ), array( 'status' => 400 ) );
		}

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:stepup-send:' . $purpose, self::SEND_LIMIT, self::SEND_WINDOW ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many codes were sent. Wait a few minutes and try again.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		update_user_meta(
			$user_id,
			self::META . $purpose,
			array(
				'hash'    => self::hash( $user_id, $purpose, $code ),
				'expires' => time() + self::TTL_MINUTES * MINUTE_IN_SECONDS,
				'tries'   => 0,
			)
		);

		if ( ! self::mail( $user, $purpose, $code ) ) {
			delete_user_meta( $user_id, self::META . $purpose );

			return new \WP_Error( 'step_up_send', __( 'We could not send the code. Try again in a few minutes.', 'beltoft-services' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Is the secret right for this customer and purpose? A site's filter decides first; without one the emailed code does.
	 * A code works once, for the purpose it was sent for, for ten minutes and for five wrong tries.
	 *
	 * @param int    $user_id Customer.
	 * @param string $secret  What was typed.
	 * @param string $purpose One of purposes().
	 */
	public static function verify( int $user_id, string $secret, string $purpose = 'api_token' ): bool {
		$secret = preg_replace( '/\s+/', '', $secret ) ?? '';

		if ( ! get_userdata( $user_id ) || '' === $secret || strlen( $secret ) > 200 || ! in_array( $purpose, self::purposes(), true ) ) {
			return false;
		}

		$ask = self::ask( $user_id, $purpose );

		/**
		 * A site's own check. Return true or false to decide, null to let the emailed code decide.
		 *
		 * @param bool|null $ok      Decision so far.
		 * @param int       $user_id Customer.
		 * @param string    $secret  What was typed.
		 * @param string    $kind    What was asked for (see beltoft_services_step_up_ask).
		 * @param string    $purpose api_token or transfer_code.
		 */
		$ok = apply_filters( 'beltoft_services_step_up_verify', null, $user_id, $secret, $ask['kind'], $purpose );

		if ( null !== $ok ) {
			return true === $ok;
		}

		if ( 'emailed' !== $ask['kind'] ) {
			return false;
		}

		$meta = get_user_meta( $user_id, self::META . $purpose, true );

		if ( ! is_array( $meta ) || empty( $meta['hash'] ) ) {
			return false;
		}

		if ( (int) ( $meta['expires'] ?? 0 ) < time() || (int) ( $meta['tries'] ?? 0 ) >= self::MAX_TRIES ) {
			delete_user_meta( $user_id, self::META . $purpose );

			return false;
		}

		if ( ! hash_equals( (string) $meta['hash'], self::hash( $user_id, $purpose, $secret ) ) ) {
			$meta['tries'] = (int) ( $meta['tries'] ?? 0 ) + 1;

			if ( $meta['tries'] >= self::MAX_TRIES ) {
				delete_user_meta( $user_id, self::META . $purpose );
			} else {
				update_user_meta( $user_id, self::META . $purpose, $meta );
			}

			return false;
		}

		// Single use.
		delete_user_meta( $user_id, self::META . $purpose );

		return true;
	}

	/**
	 * Keyed hash of a code: tied to the customer and the purpose, so one code cannot serve another.
	 *
	 * @param int    $user_id Customer.
	 * @param string $purpose Purpose.
	 * @param string $code    Code.
	 */
	private static function hash( int $user_id, string $purpose, string $code ): string {
		return hash_hmac( 'sha256', $user_id . '|' . $purpose . '|' . $code, wp_salt( 'auth' ) );
	}

	/**
	 * Sends the email through WooCommerce's mailer.
	 *
	 * @param \WP_User $user    Customer.
	 * @param string   $purpose Purpose.
	 * @param string   $code    The code. Never logged.
	 */
	private static function mail( \WP_User $user, string $purpose, string $code ): bool {
		try {
			foreach ( WC()->mailer()->get_emails() as $email ) {
				if ( $email instanceof StepUpCode ) {
					return $email->trigger( $user, $purpose, $code );
				}
			}
		} catch ( \Throwable $e ) {
			// The message of the exception stays out of the log: the code travels in the mail body.
			error_log( 'Beltoft Services: step-up email failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only record of a failed send, no code in it.
		}

		return false;
	}
}
