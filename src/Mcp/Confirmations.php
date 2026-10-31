<?php
/**
 * The two-step confirmation of the MCP tools that cannot be undone, show a secret or move money.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Oauth\Schema;
use BeltoftServices\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * A call to such a tool does nothing but describe itself: it returns a summary for the customer and a token. The tool
 * runs only when it is called again with the same arguments and that token, which the model is told to send only after
 * the customer said yes in the chat. The server cannot know whether the model asked; what it does enforce is that
 * nothing runs without a token it issued, that the token belongs to this customer, this connection, this tool and these
 * exact arguments, that it is used once and that it is used within minutes.
 *
 * Storage is a table of its own (Oauth\Schema), not options or transients: the single use must be atomic, and with a
 * persistent object cache a transient read and delete are not. Only the SHA-256 of a token is stored, so a copy of the
 * database does not hold a token that can still be used. A token is burned by the UPDATE that accepts it, before the
 * tool runs, so a tool that fails afterwards does not leave a second try: the customer asks again.
 */
final class Confirmations {

	/**
	 * Seconds a token lives.
	 */
	public const TTL = 300;

	/**
	 * Seconds a token for a secret lives.
	 */
	public const SECRET_TTL = 120;

	/**
	 * Seconds that must pass between the summary and the confirmation: a model that confirms in the same breath, without
	 * asking, is refused. Filter beltoft_services_mcp_confirm_delay.
	 */
	public const MIN_DELAY = 5;

	/**
	 * Tokens one connection (grant) may hold open at the same time.
	 */
	public const MAX_OPEN = 10;

	/**
	 * Summaries a customer may ask for per minute.
	 */
	public const RATE_LIMIT = 20;

	/**
	 * Seconds a used or expired row is kept, so a refusal can say which of the two it was.
	 */
	private const KEEP = HOUR_IN_SECONDS;

	/**
	 * Prefix of a token, so it is recognisable as one.
	 */
	public const PREFIX = 'bsc_';

	/**
	 * Makes a token for a described call.
	 *
	 * @param string               $tool  Tool.
	 * @param array<string, mixed> $args  Arguments without the token, as validated.
	 * @param string               $state Whatever the summary depended on; a change of it voids the token.
	 * @param bool                 $secret Whether the tool shows a secret (shorter life).
	 * @return array{token: string, expires_in: int}|\WP_Error
	 */
	public static function issue( string $tool, array $args, string $state, bool $secret = false ) {
		global $wpdb;

		$token   = Tokens::current();
		$user_id = get_current_user_id();

		if ( null === $token || $user_id <= 0 || (int) $token['user'] !== $user_id ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
		}

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:mcp-confirm', self::RATE_LIMIT, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Too many requests. Please wait a minute.', 'beltoft-services' ) );
		}

		$now = time();
		$t   = Schema::tables()['confirms'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d', $t, $now - self::KEEP ) );

		$ttl   = $secret ? self::SECRET_TTL : self::TTL;
		$given = self::PREFIX . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A random token, not obfuscation.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$stored = $wpdb->insert(
			$t,
			array(
				'token_hash' => hash( 'sha256', $given ),
				'user_id'    => $user_id,
				'grant_id'   => (string) $token['uuid'],
				'tool'       => $tool,
				'args_hash'  => self::args_hash( $args ),
				'state_hash' => hash( 'sha256', $state ),
				'created_at' => $now,
				'expires_at' => $now + $ttl,
			)
		);

		if ( 1 !== $stored ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This did not work. Try again in a moment.', 'beltoft-services' ) );
		}

		// Insert first, then count: two calls at once cannot both slip in under the limit, the one that goes over takes its row back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grant_id = %s AND used_at IS NULL AND expires_at >= %d', $t, (string) $token['uuid'], $now ) );

		if ( $open > self::MAX_OPEN ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$wpdb->delete( $t, array( 'token_hash' => hash( 'sha256', $given ) ) );

			return new \WP_Error( 'beltoft_services_tool', __( 'There are too many confirmations waiting. Ask the customer to answer them, or wait a few minutes.', 'beltoft-services' ) );
		}

		return array( 'token' => $given, 'expires_in' => $ttl );
	}

	/**
	 * Accepts a token for a call, once.
	 *
	 * @param string               $given Token as sent.
	 * @param string               $tool  Tool.
	 * @param array<string, mixed> $args  Arguments without the token, as validated.
	 * @param string               $state What the summary depends on now.
	 * @return true|string True when the call may run (the token is burned); else why not: unknown, used, expired,
	 *                     other (another customer or connection), changed (another tool or other arguments), state.
	 */
	public static function consume( string $given, string $tool, array $args, string $state ) {
		global $wpdb;

		$token = Tokens::current();
		$t     = Schema::tables()['confirms'];
		$hash  = hash( 'sha256', $given );

		if ( null === $token || ! str_starts_with( $given, self::PREFIX ) || strlen( $given ) > 100 ) {
			return 'unknown';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', $t, $hash ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return 'unknown';
		}

		if ( null !== $row['used_at'] ) {
			return 'used';
		}

		if ( (int) $row['expires_at'] < time() ) {
			return 'expired';
		}

		// Another customer or another connection never learns more than "unknown", and does not burn the token.
		if ( (int) $row['user_id'] !== get_current_user_id() || ! hash_equals( (string) $row['grant_id'], (string) $token['uuid'] ) ) {
			return 'other';
		}

		if ( $row['tool'] !== $tool || ! hash_equals( (string) $row['args_hash'], self::args_hash( $args ) ) ) {
			return 'changed';
		}

		/**
		 * Seconds a confirmation must wait after its summary (0 turns the wait off).
		 *
		 * @param int $seconds Seconds.
		 */
		$delay = (int) apply_filters( 'beltoft_services_mcp_confirm_delay', self::MIN_DELAY );

		if ( time() - (int) $row['created_at'] < $delay ) {
			return 'soon';
		}

		// The one atomic step: whoever changes the row from unused to used runs the tool, the other caller does not.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$won = 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_at = %d WHERE id = %d AND used_at IS NULL AND expires_at >= %d', $t, time(), (int) $row['id'], time() ) );

		if ( ! $won ) {
			return 'used';
		}

		// What the summary said is no longer true: the token is spent, the customer is shown the new situation.
		return hash_equals( (string) $row['state_hash'], hash( 'sha256', $state ) ) ? true : 'state';
	}

	/**
	 * Words for the model for a refusal code of consume().
	 *
	 * @param string $why Code.
	 */
	public static function refusal( string $why ): string {
		switch ( $why ) {
			case 'expired':
				return __( 'The confirmation has expired. Call the tool again without the confirm argument to get a new summary, and show it to the customer.', 'beltoft-services' );

			case 'used':
				return __( 'This confirmation was used already. Nothing was done again. Call the tool again without the confirm argument if the customer wants it once more.', 'beltoft-services' );

			case 'changed':
				return __( 'The confirmation is for other arguments or another tool. Call the tool again without the confirm argument to get a summary of what you want to do now, and show it to the customer.', 'beltoft-services' );

			case 'soon':
				return __( 'Too soon. The customer has not had time to read the summary. Show the summary to the customer, ask whether to go ahead, and call the tool again with the confirm value only after they answer yes. The confirm value stays valid for a few minutes.', 'beltoft-services' );

			case 'state':
				return __( 'Things changed since the summary was made, so nothing was done. Call the tool again without the confirm argument to get a new summary, and show it to the customer.', 'beltoft-services' );

			default:
				return __( 'The confirmation is not valid. Call the tool again without the confirm argument to get a summary, and show it to the customer.', 'beltoft-services' );
		}
	}

	/**
	 * The hash of arguments: the same arguments in any key order give the same hash, any other argument a different one.
	 *
	 * @param array<string, mixed> $args Arguments without the token.
	 */
	public static function args_hash( array $args ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $args ) ) );
	}

	/**
	 * Keys sorted at every level (lists keep their order, it can matter), strings trimmed, whole numbers as integers.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( is_array( $value ) ) {
			$out = array_map( array( __CLASS__, 'canonical' ), $value );

			if ( ! array_is_list( $out ) ) {
				ksort( $out );
			}

			return $out;
		}

		if ( is_string( $value ) ) {
			return trim( $value );
		}

		return is_float( $value ) && floor( $value ) === $value ? (int) $value : $value;
	}
}
