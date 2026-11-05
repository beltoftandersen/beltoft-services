<?php
/**
 * The websites of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Records of the websites in a container. A slug is `[a-z0-9-]{1,32}` and unique over all accounts, because
 * it names the preview address.
 */
final class Sites {

	public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/D';

	/**
	 * Is this a valid slug?
	 *
	 * @param string $slug Slug.
	 */
	public static function valid_slug( string $slug ): bool {
		return 1 === preg_match( self::SLUG_PATTERN, $slug );
	}

	/**
	 * Adds a site; an existing site of the same resource and slug is kept as it is.
	 *
	 * @param int                  $resource_id Activation id.
	 * @param string               $slug        Slug.
	 * @param string               $label       Label.
	 * @param string               $php         PHP version, for example 8.3.
	 * @param int                  $workers     PHP workers of the site's pool.
	 * @param array<string, mixed> $flags       Free flags.
	 * @return bool True when the site exists for this resource afterwards.
	 */
	public static function add( int $resource_id, string $slug, string $label, string $php, int $workers, array $flags = array() ): bool {
		global $wpdb;

		if ( ! self::valid_slug( $slug ) ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the unique keys are the backstop.
		$wpdb->insert(
			Schema::tables()['hosting_sites'],
			array(
				'resource_id' => $resource_id,
				'slug'        => $slug,
				'label'       => mb_substr( $label, 0, 100 ),
				'php'         => mb_substr( $php, 0, 8 ),
				'workers'     => max( 0, $workers ),
				'flags'       => (string) wp_json_encode( $flags ),
				'created'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		return null !== self::find( $resource_id, $slug );
	}

	/**
	 * One site of a resource.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $slug        Slug.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $resource_id, string $slug ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d AND slug = %s', Schema::tables()['hosting_sites'], $resource_id, $slug ), ARRAY_A );

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	/**
	 * Every site of a resource, oldest first.
	 *
	 * @param int $resource_id Activation id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_resource( int $resource_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d ORDER BY id', Schema::tables()['hosting_sites'], $resource_id ), ARRAY_A );

		return array_map( array( __CLASS__, 'typed' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Removes every site record of a resource.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function remove_all( int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->delete( Schema::tables()['hosting_sites'], array( 'resource_id' => $resource_id ), array( '%d' ) );
	}

	/**
	 * A row with typed values.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		$flags = json_decode( (string) ( $row['flags'] ?? '' ), true );

		return array(
			'id'          => (int) $row['id'],
			'resource_id' => (int) $row['resource_id'],
			'slug'        => (string) $row['slug'],
			'label'       => (string) $row['label'],
			'php'         => (string) $row['php'],
			'workers'     => (int) $row['workers'],
			'flags'       => is_array( $flags ) ? $flags : array(),
		);
	}
}
