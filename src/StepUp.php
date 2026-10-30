<?php
/**
 * A second check before something sensitive is shown, such as a domain's transfer code: the account password by
 * default. A site that has its own second factor (an authenticator app, say) asks for that through the two filters.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

final class StepUp {

	/**
	 * What the customer is asked for.
	 *
	 * @param int $user_id Customer.
	 * @return array{kind: string, label: string, autocomplete: string} kind password or code, the field label, and the browser's autocomplete hint.
	 */
	public static function ask( int $user_id ): array {
		$default = array(
			'kind'         => 'password',
			'label'        => __( 'Your account password', 'beltoft-services' ),
			'autocomplete' => 'current-password',
		);

		/**
		 * What a customer confirms with before a sensitive value is shown.
		 *
		 * @param array<string, string> $ask     kind (password or code), label, autocomplete.
		 * @param int                   $user_id Customer.
		 */
		$ask = apply_filters( 'beltoft_services_step_up_ask', $default, $user_id );

		return is_array( $ask ) && in_array( $ask['kind'] ?? '', array( 'password', 'code' ), true ) && '' !== (string) ( $ask['label'] ?? '' )
			? array(
				'kind'         => (string) $ask['kind'],
				'label'        => (string) $ask['label'],
				'autocomplete' => 'code' === $ask['kind'] ? 'one-time-code' : 'current-password',
			)
			: $default;
	}

	/**
	 * Is the secret right for this customer?
	 *
	 * @param int    $user_id Customer.
	 * @param string $secret  What was typed.
	 */
	public static function verify( int $user_id, string $secret ): bool {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || '' === $secret || strlen( $secret ) > 200 ) {
			return false;
		}

		/**
		 * A site's own check. Return true or false to decide, null to let the account password decide.
		 *
		 * @param bool|null $ok      Decision so far.
		 * @param int       $user_id Customer.
		 * @param string    $secret  What was typed.
		 * @param string    $kind    What was asked for (see beltoft_services_step_up_ask).
		 */
		$ok = apply_filters( 'beltoft_services_step_up_verify', null, $user_id, $secret, self::ask( $user_id )['kind'] );

		if ( null !== $ok ) {
			return true === $ok;
		}

		return 'password' === self::ask( $user_id )['kind'] && wp_check_password( $secret, $user->user_pass, $user->ID );
	}
}
