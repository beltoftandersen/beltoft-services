<?php
/**
 * The OAuth server's tables: registered clients, grants (codes, access tokens, refresh tokens) and the confirmations of the MCP tools.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Oauth;

defined( 'ABSPATH' ) || exit;

/**
 * Own tables, because a grant is looked up by the hash of what the client presents, a refresh family is revoked in one
 * statement and the list of connected apps is a query; an option would hold all of it in one growing row.
 * Created with dbDelta the way the other schemas of the plugin are; the feature stays off until ready().
 */
final class Schema {

	public const DB_VERSION        = '2';
	public const OPTION_DB_VERSION = 'beltoft_services_oauth_db_version';

	/**
	 * Table names.
	 *
	 * @return array<string, string>
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'clients' => $wpdb->prefix . 'beltoft_services_oauth_clients',
			'grants'  => $wpdb->prefix . 'beltoft_services_oauth_grants',
			'confirms' => $wpdb->prefix . 'beltoft_services_mcp_confirms',
		);
	}

	/**
	 * Whether the tables are in place.
	 */
	public static function ready(): bool {
		return self::DB_VERSION === get_option( self::OPTION_DB_VERSION );
	}

	/**
	 * Creates or updates the tables when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( ! self::ready() ) {
			self::create_tables();
		}
	}

	/**
	 * Creates the tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();
		$quiet   = $wpdb->suppress_errors( true );

		dbDelta(
			"CREATE TABLE {$t['clients']} (
				client_id varchar(40) CHARACTER SET ascii NOT NULL,
				name varchar(100) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL,
				created_at int(10) unsigned NOT NULL,
				PRIMARY KEY  (client_id),
				KEY created_at (created_at)
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['grants']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(10) CHARACTER SET ascii NOT NULL,
				hash char(64) CHARACTER SET ascii NOT NULL,
				family char(32) CHARACTER SET ascii NOT NULL,
				client_id varchar(40) CHARACTER SET ascii NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				scope varchar(20) CHARACTER SET ascii NOT NULL,
				redirect_uri text NULL,
				challenge varchar(64) CHARACTER SET ascii NULL DEFAULT NULL,
				connected_at int(10) unsigned NOT NULL,
				expires_at int(10) unsigned NOT NULL,
				used_at int(10) unsigned NULL DEFAULT NULL,
				last_used int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash),
				KEY family (family),
				KEY user_type (user_id,type),
				KEY expires_at (expires_at)
			) $collate;"
		);

		// One row per confirmation token the MCP tools hand out (see Mcp\Confirmations): only the hash of the token is kept.
		dbDelta(
			"CREATE TABLE {$t['confirms']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) CHARACTER SET ascii NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				grant_id varchar(64) CHARACTER SET ascii NOT NULL,
				tool varchar(40) CHARACTER SET ascii NOT NULL,
				args_hash char(64) CHARACTER SET ascii NOT NULL,
				state_hash char(64) CHARACTER SET ascii NOT NULL,
				created_at int(10) unsigned NOT NULL,
				expires_at int(10) unsigned NOT NULL,
				used_at int(10) unsigned NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY user_open (user_id,used_at,expires_at),
				KEY expires_at (expires_at)
			) $collate;"
		);

		$wpdb->suppress_errors( $quiet );

		$exists = static function ( string $table ) use ( $wpdb ): bool {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		};

		if ( $exists( $t['clients'] ) && $exists( $t['grants'] ) && $exists( $t['confirms'] ) ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
		}
	}
}
