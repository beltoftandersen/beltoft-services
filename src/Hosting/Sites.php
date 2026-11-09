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
		return 1 === preg_match( self::SLUG_PATTERN, $slug ) && ! self::reserved( $slug );
	}

	/**
	 * Names that are never a website's temporary address: the names an operator's own services answer to inside the
	 * preview zone (mail, DNS, the panel). The list is the default plus the filter.
	 *
	 * @param string $slug Slug.
	 */
	public static function reserved( string $slug ): bool {
		$default = array( 'www', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'webmail', 'autoconfig', 'autodiscover', 'ns', 'ns1', 'ns2', 'ns3', 'ns4', 'admin', 'administrator', 'api', 'cpanel', 'ftp', 'sftp', 'ssh', 'edge', 'node', 'db', 'mysql', 'dns', 'mx', 'mx1', 'mx2', 'root', 'postmaster', 'hostmaster', 'abuse', 'localhost' );

		/**
		 * Preview names no website may take.
		 *
		 * @param string[] $names Names (lowercase).
		 */
		$names = (array) apply_filters( 'beltoft_services_hosting_reserved_slugs', $default );

		return in_array( strtolower( $slug ), array_map( 'strtolower', array_map( 'strval', $names ) ), true );
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

		$made = null !== self::find( $resource_id, $slug );

		if ( $made ) {
			self::release_hold( $slug );
		}

		return $made;
	}

	/**
	 * Is a slug used by any account? A slug names the preview address, so it is unique over all accounts.
	 *
	 * @param string $slug Slug.
	 */
	public static function taken( string $slug ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE slug = %s', Schema::tables()['hosting_sites'], $slug ) ) > 0;
	}

	/** How long a name chosen at checkout is held for the customer who chose it. */
	public const RESERVE_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Holds a name for the person at the checkout, so two people do not pay for the same name. The hold is an option made
	 * with add_option (the database refuses a second one), lasts two hours, and is released when the site is made.
	 *
	 * @param string $slug  Slug.
	 * @param string $owner Who holds it: the customer's id, or the cart's session id for a guest.
	 * @param bool   $guest_yields A signed-in customer (owner u<id>) takes a name a guest holds. The cart's session id
	 *                             changes when a guest's account is made at the checkout, so the guest hold may be the
	 *                             same person's own.
	 * @return bool True when the name is the owner's now.
	 */
	public static function reserve( string $slug, string $owner, bool $guest_yields = false ): bool {
		$name = 'beltoft_services_slug_hold_' . substr( md5( $slug ), 0, 20 );
		$now  = time();

		if ( add_option( $name, array( 'o' => $owner, 't' => $now + self::RESERVE_TTL ), '', false ) ) {
			return true;
		}

		wp_cache_delete( $name, 'options' );
		$held = get_option( $name );

		if ( is_array( $held ) && (string) ( $held['o'] ?? '' ) !== $owner && (int) ( $held['t'] ?? 0 ) > $now ) {
			$yields = $guest_yields && 0 === strpos( $owner, 'u' ) && 0 === strpos( (string) ( $held['o'] ?? '' ), 'g' );

			if ( ! $yields ) {
				return false;
			}
		}

		update_option( $name, array( 'o' => $owner, 't' => $now + self::RESERVE_TTL ), false );

		return true;
	}

	/**
	 * Is the name held by somebody else at the checkout?
	 *
	 * @param string $slug  Slug.
	 * @param string $owner The asking person.
	 */
	public static function held_by_other( string $slug, string $owner ): bool {
		$name = 'beltoft_services_slug_hold_' . substr( md5( $slug ), 0, 20 );
		wp_cache_delete( $name, 'options' );
		$held = get_option( $name );

		return is_array( $held ) && (string) ( $held['o'] ?? '' ) !== $owner && (int) ( $held['t'] ?? 0 ) > time();
	}

	/**
	 * Lets go of a hold (the site was made, or the order failed).
	 *
	 * @param string $slug Slug.
	 */
	public static function release_hold( string $slug ): void {
		delete_option( 'beltoft_services_slug_hold_' . substr( md5( $slug ), 0, 20 ) );
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
	 * Changes the PHP version, the workers and the flags of a site; a key that is not given stays.
	 *
	 * @param int                  $resource_id Activation id.
	 * @param string               $slug        Slug.
	 * @param array<string, mixed> $changes     php (string), workers (int), flags (array, merged over the old ones).
	 */
	public static function update( int $resource_id, string $slug, array $changes ): bool {
		global $wpdb;

		$site = self::find( $resource_id, $slug );

		if ( null === $site ) {
			return false;
		}

		$row = array();

		if ( isset( $changes['php'] ) ) {
			$row['php'] = mb_substr( (string) $changes['php'], 0, 8 );
		}

		if ( isset( $changes['workers'] ) ) {
			$row['workers'] = max( 0, (int) $changes['workers'] );
		}

		if ( isset( $changes['flags'] ) && is_array( $changes['flags'] ) ) {
			$row['flags'] = (string) wp_json_encode( array_merge( $site['flags'], $changes['flags'] ) );
		}

		if ( array() === $row ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->update( Schema::tables()['hosting_sites'], $row, array( 'resource_id' => $resource_id, 'slug' => $slug ) );
	}

	/**
	 * Removes one site record.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $slug        Slug.
	 */
	public static function remove( int $resource_id, string $slug ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->delete( Schema::tables()['hosting_sites'], array( 'resource_id' => $resource_id, 'slug' => $slug ), array( '%d', '%s' ) );
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
