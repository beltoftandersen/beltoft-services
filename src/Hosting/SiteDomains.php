<?php
/**
 * The names of the websites of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Records of the host names that reach a site: the preview name, a domain whose DNS zone we serve for the
 * customer (`ours`), and a domain the customer points at us themselves (`external`). A host name belongs to one
 * site over all accounts. State: pending, waiting (external, not pointing at us yet), active, or removed.
 */
final class SiteDomains {

	public const KINDS  = array( 'preview', 'ours', 'external' );
	public const STATES = array( 'pending', 'waiting', 'active' );

	/**
	 * A host name in the form the plugin accepts: lowercase labels, at least two of them.
	 *
	 * @param string $host Host name.
	 */
	public static function valid_host( string $host ): bool {
		return 1 === preg_match( '/^(?=.{3,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host );
	}

	/**
	 * Adds a name; the same name of the same resource is kept as it is.
	 *
	 * @param int    $resource_id Activation id.
	 * @param int    $site_id     Site id.
	 * @param string $host        Host name.
	 * @param string $kind        preview, ours or external.
	 * @return bool True when the name belongs to this resource afterwards.
	 */
	public static function add( int $resource_id, int $site_id, string $host, string $kind ): bool {
		global $wpdb;

		if ( ! self::valid_host( $host ) || ! in_array( $kind, self::KINDS, true ) ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the unique key on the host is the backstop.
		$wpdb->insert(
			Schema::tables()['hosting_domains'],
			array(
				'resource_id' => $resource_id,
				'site_id'     => $site_id,
				'host'        => $host,
				'kind'        => $kind,
				'state'       => 'external' === $kind ? 'waiting' : 'pending',
				'created'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		$row = self::find( $host );

		return null !== $row && $row['resource_id'] === $resource_id;
	}

	/**
	 * One name.
	 *
	 * @param string $host Host name.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $host ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE host = %s', Schema::tables()['hosting_domains'], $host ), ARRAY_A );

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	/**
	 * Every external name of every account.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function external(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE kind = %s ORDER BY id', Schema::tables()['hosting_domains'], 'external' ), ARRAY_A );

		return array_map( array( __CLASS__, 'typed' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The names of an account, oldest first.
	 *
	 * @param int $resource_id Activation id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_resource( int $resource_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d ORDER BY id', Schema::tables()['hosting_domains'], $resource_id ), ARRAY_A );

		return array_map( array( __CLASS__, 'typed' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Sets the state of a name and the time it was looked at.
	 *
	 * @param string $host  Host name.
	 * @param string $state pending, waiting or active.
	 */
	public static function set_state( string $host, string $state ): bool {
		global $wpdb;

		if ( ! in_array( $state, self::STATES, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->update( Schema::tables()['hosting_domains'], array( 'state' => $state, 'checked' => gmdate( 'Y-m-d H:i:s' ) ), array( 'host' => $host ), array( '%s', '%s' ), array( '%s' ) );
	}

	/**
	 * Removes one name.
	 *
	 * @param string $host Host name.
	 */
	public static function remove( string $host ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->delete( Schema::tables()['hosting_domains'], array( 'host' => $host ), array( '%s' ) );
	}

	/**
	 * Removes every name of a resource.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function remove_all( int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->delete( Schema::tables()['hosting_domains'], array( 'resource_id' => $resource_id ), array( '%d' ) );
	}

	/**
	 * A row with typed values.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'resource_id' => (int) $row['resource_id'],
			'site_id'     => (int) $row['site_id'],
			'host'        => (string) $row['host'],
			'kind'        => (string) $row['kind'],
			'state'       => (string) $row['state'],
			'cert_state'  => (string) $row['cert_state'],
			'checked'     => null === $row['checked'] ? null : (string) $row['checked'],
		);
	}
}
