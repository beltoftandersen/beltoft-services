<?php
/**
 * The Security section of My Account: personal API tokens and the API guide.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Api\Routes;
use BeltoftServices\Mcp\Access;
use BeltoftServices\Mcp\Endpoint;
use BeltoftServices\Oauth\Grants;
use BeltoftServices\Api\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * A plain form with a nonce, printed on the theme's Security tab through the beltoft_account_security_sections
 * action, or under the Account details form when the theme says it has no such tab (filter
 * beltoft_services_api_tokens_on_security_tab). A new token is shown in the reply to the post that
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
		add_action( 'woocommerce_after_edit_account_form', array( __CLASS__, 'render_under_form' ) );
		add_action( 'beltoft_account_security_sections', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Whether the theme prints the section on its Security tab, so not under the Account details form.
	 */
	public static function on_security_tab(): bool {
		/**
		 * Whether the theme has a Security tab that prints the API tokens (action beltoft_account_security_sections).
		 *
		 * @param bool $on Default false: the section goes under the Account details form.
		 */
		return (bool) apply_filters( 'beltoft_services_api_tokens_on_security_tab', false );
	}

	/**
	 * Listener of woocommerce_after_edit_account_form: the section, unless the Security tab has it.
	 */
	public static function render_under_form(): void {
		if ( ! self::on_security_tab() ) {
			self::render();
		}
	}

	/**
	 * The layout stylesheet and the copy script, on the pages that can show the section.
	 */
	public static function assets(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'is_wc_endpoint_url' ) || ! ( is_wc_endpoint_url( 'edit-account' ) || is_wc_endpoint_url( 'security' ) ) ) {
			return;
		}

		Panel::style();
		wp_enqueue_script( 'beltoft-services-api-tokens', plugins_url( 'assets/api-tokens.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/api-tokens.js' ), true );
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
			'family' => isset( $_POST['app_family'] ) ? sanitize_key( wp_unslash( $_POST['app_family'] ) ) : '',
			'allow'  => isset( $_POST['mcp_allow'] ) ? sanitize_key( wp_unslash( $_POST['mcp_allow'] ) ) : '',
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

		if ( 'disconnect' === ( $post['action'] ?? '' ) ) {
			return Grants::disconnect( $user_id, (string) ( $post['family'] ?? '' ) )
				? array( 'ok' => true, 'message' => __( 'The app was disconnected.', 'beltoft-services' ), 'password' => '' )
				: array( 'ok' => false, 'message' => __( 'That app was not found.', 'beltoft-services' ), 'password' => '' );
		}

		if ( 'mcp_switch' === ( $post['action'] ?? '' ) ) {
			$allow = '1' === (string) ( $post['allow'] ?? '' );
			Access::set_user( $user_id, $allow );

			return array( 'ok' => true, 'message' => $allow ? __( 'Apps may connect to your account.', 'beltoft-services' ) : __( 'Apps can no longer use your account.', 'beltoft-services' ), 'password' => '' );
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
	 * Prints the section.
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
				'levels'   => Tokens::levels(),
				'result'   => self::$result,
				'nonce'    => wp_create_nonce( self::NONCE ),
				'field'    => self::FIELD,
				'base'     => rest_url( Routes::NAMESPACE . '/' ),
				'username' => $user->user_login,
				'max'      => Tokens::MAX,
				'mcp'      => array(
					'on'      => Access::enabled(),
					'allowed' => Access::user_allowed( $user->ID ),
					'url'     => Endpoint::url(),
					'apps'    => Access::enabled() ? Grants::connections( $user->ID ) : array(),
				),
			),
			'beltoft-services/account/',
			BELTOFT_SERVICES_PATH . 'templates/account/'
		);
	}
}
