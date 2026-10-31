<?php
/**
 * Authorization codes, access tokens and refresh tokens.
 *
 * @package BeltoftServices\Oauth
 */

namespace BeltoftServices\Oauth;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Every secret is stored as a keyed hash (HMAC-SHA256 with a WordPress salt), never in clear: a copy of the database
 * does not give anyone a working token, and a hash cannot be guessed back without the salt. All grants of one
 * connection share a family id.
 *
 * - a code lives 60 seconds, works once, and is bound to its client, redirect address and PKCE challenge;
 * - an access token lives an hour;
 * - a refresh token lives 30 days and is replaced on every use; showing an old one again ends the whole family,
 *   because it means two parties hold it;
 * - using a code twice also ends the family it started.
 */
final class Grants {

	public const CODE_TTL    = 60;
	public const ACCESS_TTL  = HOUR_IN_SECONDS;
	public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Seconds in which the same refresh token may be sent again (a lost answer) without ending the connection.
	 */
	public const RETRY_WINDOW = 10;

	/**
	 * Seconds between two "last used" writes of one connection.
	 */
	private const TOUCH_EVERY = 60;

	/**
	 * The keyed hash a secret is stored and looked up by.
	 *
	 * @param string $secret Code or token.
	 */
	public static function hash( string $secret ): string {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) . 'beltoft-oauth' );
	}

	/**
	 * Issues an authorization code.
	 *
	 * @param string $client_id Client.
	 * @param int    $user_id   Customer who agreed.
	 * @param string $scope     read or actions.
	 * @param string $redirect  The redirect address of this request.
	 * @param string $challenge PKCE S256 challenge.
	 * @return string The code, shown once.
	 */
	public static function issue_code( string $client_id, int $user_id, string $scope, string $redirect, string $challenge ): string {
		$code = 'bso_c_' . self::random();
		$now  = time();

		self::purge();
		self::insert( 'code', $code, bin2hex( random_bytes( 16 ) ), $client_id, $user_id, $scope, $now, $now + self::CODE_TTL, $redirect, $challenge );

		return $code;
	}

	/**
	 * Trades a code for tokens.
	 *
	 * @param string $code     Code.
	 * @param string $client_id Client.
	 * @param string $redirect The redirect address sent again.
	 * @param string $verifier PKCE verifier.
	 * @return array<string, mixed>|\WP_Error Token response, or an error with an OAuth code.
	 */
	public static function exchange_code( string $code, string $client_id, string $redirect, string $verifier ) {
		$row = self::find( 'code', $code );

		if ( null === $row || ! hash_equals( (string) $row['client_id'], $client_id ) ) {
			return new \WP_Error( 'invalid_grant', 'The code is not valid.' );
		}

		// The first use takes the row; any later use is a replay and ends what the first one made.
		if ( ! self::take( (int) $row['id'] ) ) {
			self::revoke_family( (string) $row['family'] );

			return new \WP_Error( 'invalid_grant', 'The code is not valid.' );
		}

		if ( (int) $row['expires_at'] < time() || ! hash_equals( (string) $row['redirect_uri'], $redirect ) || ! self::verifies( $verifier, (string) $row['challenge'] ) ) {
			return new \WP_Error( 'invalid_grant', 'The code is not valid.' );
		}

		if ( ! self::allowed( (int) $row['user_id'] ) ) {
			return new \WP_Error( 'access_denied', 'This account does not allow this connection.' );
		}

		return self::issue_tokens( (string) $row['family'], $client_id, (int) $row['user_id'], (string) $row['scope'], (int) $row['connected_at'] );
	}

	/**
	 * Trades a refresh token for a new pair.
	 *
	 * @param string $token     Refresh token.
	 * @param string $client_id Client.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function refresh( string $token, string $client_id ) {
		$row = self::find( 'refresh', $token );

		if ( null === $row || ! hash_equals( (string) $row['client_id'], $client_id ) ) {
			return new \WP_Error( 'invalid_grant', 'The refresh token is not valid.' );
		}

		$retry = false;

		if ( ! self::take( (int) $row['id'] ) ) {
			// The same token again within seconds is a client that did not get our answer: it gets a fresh pair, and
			// the pair made for the first try is dropped. Later, it means two parties hold the token.
			$again = self::find( 'refresh', $token );
			$used  = null === $again ? 0 : (int) $again['used_at'];

			if ( null === $again || $used <= 0 || time() - $used > self::RETRY_WINDOW ) {
				self::revoke_family( (string) $row['family'] );

				return new \WP_Error( 'invalid_grant', 'The refresh token is not valid.' );
			}

			$retry = true;
		}

		if ( (int) $row['expires_at'] < time() ) {
			return new \WP_Error( 'invalid_grant', 'The refresh token is not valid.' );
		}

		if ( ! self::allowed( (int) $row['user_id'] ) ) {
			self::revoke_family( (string) $row['family'] );

			return new \WP_Error( 'invalid_grant', 'The refresh token is not valid.' );
		}

		self::drop_live( (string) $row['family'], $retry );

		return self::issue_tokens( (string) $row['family'], $client_id, (int) $row['user_id'], (string) $row['scope'], (int) $row['connected_at'], (int) $row['last_used'] );
	}

	/**
	 * Who an access token belongs to.
	 *
	 * @param string $token Access token.
	 * @return array{user: int, scope: string, family: string, client_id: string}|null
	 */
	public static function authenticate( string $token ): ?array {
		global $wpdb;

		$row = self::find( 'access', $token );

		if ( null === $row || (int) $row['expires_at'] < time() || ! self::allowed( (int) $row['user_id'] ) ) {
			return null;
		}

		if ( time() - (int) $row['last_used'] >= self::TOUCH_EVERY ) {
			$t = Schema::tables()['grants'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_used = %d WHERE family = %s AND type IN (%s, %s) AND used_at IS NULL', $t, time(), $row['family'], 'access', 'refresh' ) );
		}

		return array(
			'user'      => (int) $row['user_id'],
			'scope'     => Tokens::ACTIONS === $row['scope'] ? Tokens::ACTIONS : Tokens::READ,
			'family'    => (string) $row['family'],
			'client_id' => (string) $row['client_id'],
		);
	}

	/**
	 * Revocation (RFC 7009): a token of this client, access or refresh, ends its whole connection. Unknown tokens are ignored.
	 *
	 * @param string $token     Token.
	 * @param string $client_id Client.
	 */
	public static function revoke_token( string $token, string $client_id ): void {
		foreach ( array( 'refresh', 'access' ) as $type ) {
			$row = self::find( $type, $token );

			if ( null !== $row && hash_equals( (string) $row['client_id'], $client_id ) ) {
				self::revoke_family( (string) $row['family'] );

				return;
			}
		}
	}

	/**
	 * Ends one family.
	 *
	 * @param string $family Family id.
	 */
	public static function revoke_family( string $family ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->delete( Schema::tables()['grants'], array( 'family' => $family ) );
	}

	/**
	 * Ends everything a customer has connected (a new password, a deleted account).
	 *
	 * @param int $user_id Customer.
	 */
	public static function revoke_user( int $user_id ): void {
		global $wpdb;

		if ( $user_id > 0 && Schema::ready() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$wpdb->delete( Schema::tables()['grants'], array( 'user_id' => $user_id ) );
		}
	}

	/**
	 * Listener of after_password_reset and wp_set_password.
	 *
	 * @param mixed $first  User (after_password_reset) or the password (wp_set_password).
	 * @param mixed $second Nothing, or the user id.
	 */
	public static function password_changed( $first, $second = null ): void {
		self::revoke_user( $first instanceof \WP_User ? (int) $first->ID : (int) $second );
	}

	/**
	 * Listener of profile_update: ends the connections when the password hash changed.
	 *
	 * @param mixed $user_id  User id.
	 * @param mixed $old_user The user before the update.
	 */
	public static function profile_updated( $user_id, $old_user = null ): void {
		$now = get_userdata( (int) $user_id );

		if ( $now && $old_user instanceof \WP_User && $old_user->user_pass !== $now->user_pass ) {
			self::revoke_user( (int) $user_id );
		}
	}

	/**
	 * The apps a customer has connected: one entry per live connection.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array{family: string, name: string, scope: string, connected: int, last_used: int}>
	 */
	public static function connections( int $user_id ): array {
		global $wpdb;

		$t = Schema::tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own tables.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT g.family, g.scope, g.connected_at, g.last_used, c.name FROM %i g LEFT JOIN %i c ON c.client_id = g.client_id WHERE g.user_id = %d AND g.type = %s AND g.used_at IS NULL AND g.expires_at >= %d ORDER BY g.connected_at DESC', $t['grants'], $t['clients'], $user_id, 'refresh', time() ), ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'family'    => (string) $row['family'],
				'name'      => '' !== (string) $row['name'] ? (string) $row['name'] : 'App',
				'scope'     => (string) $row['scope'],
				'connected' => (int) $row['connected_at'],
				'last_used' => (int) $row['last_used'],
			);
		}

		return $out;
	}

	/**
	 * Disconnects one of the customer's apps.
	 *
	 * @param int    $user_id Customer.
	 * @param string $family  Family id.
	 */
	public static function disconnect( int $user_id, string $family ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->delete( Schema::tables()['grants'], array( 'family' => $family, 'user_id' => $user_id ) ) > 0;
	}

	/**
	 * Removes grants that expired over a day ago (used refresh tokens stay until then, to catch a replay). Runs at random.
	 */
	public static function purge(): void {
		global $wpdb;

		if ( 1 !== wp_rand( 1, 20 ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d', Schema::tables()['grants'], time() - DAY_IN_SECONDS ) );
	}

	/**
	 * Drops the access tokens of a family, and with $all the refresh tokens not used yet, before a new pair is made.
	 *
	 * @param string $family Family.
	 * @param bool   $all    Also the unused refresh tokens.
	 */
	private static function drop_live( string $family, bool $all ): void {
		global $wpdb;

		$t = Schema::tables()['grants'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->delete( $t, array( 'family' => $family, 'type' => 'access' ) );

		if ( $all ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE family = %s AND type = %s AND used_at IS NULL', $t, $family, 'refresh' ) );
		}
	}

	/**
	 * The new access and refresh token of a family.
	 *
	 * @param string $family    Family.
	 * @param string $client_id Client.
	 * @param int    $user_id   Customer.
	 * @param string $scope     Scope.
	 * @param int    $connected When the family began.
	 * @param int    $last_used Last use carried over.
	 * @return array<string, mixed>
	 */
	private static function issue_tokens( string $family, string $client_id, int $user_id, string $scope, int $connected, int $last_used = 0 ): array {
		$access  = 'bso_a_' . self::random();
		$refresh = 'bso_r_' . self::random();
		$now     = time();

		self::insert( 'access', $access, $family, $client_id, $user_id, $scope, $connected, $now + self::ACCESS_TTL, null, null, $last_used );
		self::insert( 'refresh', $refresh, $family, $client_id, $user_id, $scope, $connected, $now + self::REFRESH_TTL, null, null, $last_used );

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		);
	}

	/**
	 * Both switches, and a customer who still exists.
	 *
	 * @param int $user_id Customer.
	 */
	private static function allowed( int $user_id ): bool {
		return Access::enabled() && Access::user_allowed( $user_id ) && false !== get_userdata( $user_id );
	}

	/**
	 * PKCE S256: the verifier hashes to the challenge.
	 *
	 * @param string $verifier  Verifier.
	 * @param string $challenge Challenge.
	 */
	private static function verifies( string $verifier, string $challenge ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $verifier ) ) {
			return false;
		}

		return hash_equals( $challenge, rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
	}

	/**
	 * A random secret, URL safe.
	 */
	private static function random(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Stores a grant by the hash of its secret.
	 *
	 * @param string      $type      code, access or refresh.
	 * @param string      $secret    The secret.
	 * @param string      $family    Family.
	 * @param string      $client_id Client.
	 * @param int         $user_id   Customer.
	 * @param string      $scope     Scope.
	 * @param int         $connected Start of the family.
	 * @param int         $expires   Expiry.
	 * @param string|null $redirect  Code only.
	 * @param string|null $challenge Code only.
	 * @param int         $last_used Last use.
	 */
	private static function insert( string $type, string $secret, string $family, string $client_id, int $user_id, string $scope, int $connected, int $expires, ?string $redirect = null, ?string $challenge = null, int $last_used = 0 ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->insert(
			Schema::tables()['grants'],
			array(
				'type'         => $type,
				'hash'         => self::hash( $secret ),
				'family'       => $family,
				'client_id'    => $client_id,
				'user_id'      => $user_id,
				'scope'        => Tokens::ACTIONS === $scope ? Tokens::ACTIONS : Tokens::READ,
				'redirect_uri' => $redirect,
				'challenge'    => $challenge,
				'connected_at' => $connected,
				'expires_at'   => $expires,
				'last_used'    => $last_used,
			)
		);
	}

	/**
	 * The grant a secret belongs to.
	 *
	 * @param string $type   code, access or refresh.
	 * @param string $secret The secret presented.
	 * @return array<string, mixed>|null
	 */
	private static function find( string $type, string $secret ): ?array {
		global $wpdb;

		if ( '' === $secret || strlen( $secret ) > 200 ) {
			return null;
		}

		$hash = self::hash( $secret );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE hash = %s AND type = %s', Schema::tables()['grants'], $hash, $type ), ARRAY_A );

		return is_array( $row ) && hash_equals( (string) $row['hash'], $hash ) ? $row : null;
	}

	/**
	 * Marks a code or refresh token used. True for the first caller only.
	 *
	 * @param int $id Row id.
	 */
	private static function take( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_at = %d WHERE id = %d AND used_at IS NULL', Schema::tables()['grants'], time(), $id ) );
	}
}
