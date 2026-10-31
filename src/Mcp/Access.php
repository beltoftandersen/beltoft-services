<?php
/**
 * The switches of "Use with Claude (MCP)": the owner's setting and the customer's own.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Oauth\Schema;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the connector exists at all (owner setting, and the tables are there) and whether one customer allows it.
 * Both switches are checked when a connection is made and on every call, so turning one off ends the access at once.
 */
final class Access {

	/**
	 * User meta that holds 1 when the customer has turned the connector off.
	 */
	public const OFF_META = 'beltoft_services_mcp_off';

	/**
	 * The redirect addresses accepted when the setting has none.
	 */
	public const DEFAULT_REDIRECTS = "https://claude.ai/api/mcp/auth_callback\nhttps://claude.com/api/mcp/auth_callback";

	/**
	 * Is the connector on for this site?
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'mcp_enabled' ) && Schema::ready();
	}

	/**
	 * The redirect addresses an app may register, exact match.
	 *
	 * @return string[]
	 */
	public static function redirect_allowlist(): array {
		$text = trim( (string) Settings::get( 'mcp_redirect_uris' ) );
		$text = '' === $text ? self::DEFAULT_REDIRECTS : $text;
		$list = array();

		foreach ( preg_split( '/\R/', $text ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' !== $line ) {
				$list[] = $line;
			}
		}

		return $list;
	}

	/**
	 * Does this customer allow the connector?
	 *
	 * @param int $user_id Customer.
	 */
	public static function user_allowed( int $user_id ): bool {
		return $user_id > 0 && '1' !== (string) get_user_meta( $user_id, self::OFF_META, true );
	}

	/**
	 * Sets the customer's switch.
	 *
	 * @param int  $user_id Customer.
	 * @param bool $allowed Allow the connector.
	 */
	public static function set_user( int $user_id, bool $allowed ): void {
		if ( $allowed ) {
			delete_user_meta( $user_id, self::OFF_META );
		} else {
			update_user_meta( $user_id, self::OFF_META, '1' );
		}
	}
}
