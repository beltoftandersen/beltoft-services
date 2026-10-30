<?php
/**
 * The Security section of Account details: personal API tokens and the API guide.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Api\Routes;
use BeltoftServices\Api\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * A plain form with a nonce under the Account details form. A new token is shown in the reply to the post that
 * made it, once: nothing keeps the secret, and a reload shows only the list.
 */
final class ApiTokens {

	/**
	 * Name of the posted field that says what to do (create or revoke).
	 */
	public const FIELD = 'beltoft_api_token';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'beltoft_services_api_token';

	/**
	 * What the last post did: ok, message and a new password shown once.
	 *
	 * @var array{ok: bool, message: string, password: string}|null
	 */
	private static $result = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
		add_action( 'woocommerce_after_edit_account_form', array( __CLASS__, 'render' ) );
	}

	/**
	 * Listener of template_redirect: a posted token form.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in process().
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::FIELD ] ) || ! is_user_logged_in() ) {
			return;
		}

		$post = array(
			'action' => sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ),
			'name'   => isset( $_POST['token_name'] ) ? sanitize_text_field( wp_unslash( $_POST['token_name'] ) ) : '',
			'scope'  => isset( $_POST['token_scope'] ) ? sanitize_key( wp_unslash( $_POST['token_scope'] ) ) : '',
			'uuid'   => isset( $_POST['token_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['token_uuid'] ) ) : '',
			'nonce'  => isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// The reply may carry a secret.
		nocache_headers();
		self::$result = self::process( get_current_user_id(), $post );
	}

	/**
	 * Does one posted action for a customer.
	 *
	 * @param int                  $user_id Customer.
	 * @param array<string, mixed> $post    action (create, revoke), name, scope, uuid, nonce.
	 * @return array{ok: bool, message: string, password: string}
	 */
	public static function process( int $user_id, array $post ): array {
		if ( ! wp_verify_nonce( (string) ( $post['nonce'] ?? '' ), self::NONCE ) ) {
			return array( 'ok' => false, 'message' => __( 'This page expired. Reload it and try again.', 'beltoft-services' ), 'password' => '' );
		}

		if ( 'revoke' === ( $post['action'] ?? '' ) ) {
			return Tokens::revoke( $user_id, (string) ( $post['uuid'] ?? '' ) )
				? array( 'ok' => true, 'message' => __( 'The token was revoked.', 'beltoft-services' ), 'password' => '' )
				: array( 'ok' => false, 'message' => __( 'That token was not found.', 'beltoft-services' ), 'password' => '' );
		}

		if ( 'create' !== ( $post['action'] ?? '' ) ) {
			return array( 'ok' => false, 'message' => '', 'password' => '' );
		}

		$made = Tokens::create( $user_id, (string) ( $post['name'] ?? '' ), (string) ( $post['scope'] ?? '' ) );

		if ( is_wp_error( $made ) ) {
			return array( 'ok' => false, 'message' => $made->get_error_message(), 'password' => '' );
		}

		return array( 'ok' => true, 'message' => __( 'Your token is ready. Copy it now: it is not shown again.', 'beltoft-services' ), 'password' => $made['password'] );
	}

	/**
	 * Prints the section under the Account details form.
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		wc_get_template(
			'api-tokens.php',
			array(
				'tokens'   => Tokens::all( $user->ID ),
				'scopes'   => Tokens::scopes(),
				'result'   => self::$result,
				'nonce'    => wp_create_nonce( self::NONCE ),
				'field'    => self::FIELD,
				'base'     => rest_url( Routes::NAMESPACE . '/' ),
				'username' => $user->user_login,
				'max'      => Tokens::MAX,
			),
			'beltoft-services/account/',
			BELTOFT_SERVICES_PATH . 'templates/account/'
		);
	}
}
