<?php
/**
 * The readable address part of a service's page in My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Account\View;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A service page is `/my-account/<endpoint>/<slug>/` and `/my-account/<endpoint>/<slug>/<tab>/`. The slug comes from what
 * the customer sees as the service's name (the server name, the domain, else the product name), lowercased in the
 * sanitize_title style, and is unique among the customer's services of one type (servers, domains, databases, apps):
 * a clash gets -2, -3. It is stored in the resource's meta (`url_slug`) the first time an address is built, or by
 * migrate() on upgrade, and never changes afterwards: a renamed server keeps its address. The license id keeps working
 * as the address part and is redirected (301) to the slug address by redirect_target().
 *
 * A slug is only ever looked up among the current customer's own services, so another customer's slug is simply not
 * found. A name the tab names or the pages "new" and "add" would make unclear is skipped, and so is one made only of
 * digits (it would read as a license id).
 */
final class Slugs {

	/**
	 * The resource meta key.
	 */
	public const META = 'url_slug';

	/**
	 * The option that holds the version of the last backfill.
	 */
	public const OPTION = 'beltoft_services_url_slugs';

	/**
	 * The backfill version.
	 */
	public const VERSION = 1;

	/**
	 * The longest slug.
	 */
	private const MAX = 100;

	/**
	 * The type of a service for addresses: servers, domains, databases, apps, or '' when the license is none of them.
	 *
	 * @param mixed $license License row.
	 */
	public static function type_of( $license ): string {
		if ( ! is_array( $license ) || empty( $license['id'] ) ) {
			return '';
		}

		if ( Servers::is_server( $license ) ) {
			return 'servers';
		}

		if ( Domains::is_domain( $license ) ) {
			return 'domains';
		}

		foreach ( ServiceArea::all() as $area ) {
			if ( $area->is_member( $license ) ) {
				return $area->key_name();
			}
		}

		return '';
	}

	/**
	 * The slug of a service, made and stored when it has none yet. '' when the license is not a service with a page
	 * (the address then uses the license id).
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function for_license( array $license ): string {
		$type = self::type_of( $license );

		if ( '' === $type ) {
			return '';
		}

		$resource = Resource::for_license( (int) $license['id'] );

		if ( ! $resource ) {
			return '';
		}

		$slug = self::stored( $resource );

		return '' !== $slug ? $slug : self::assign( $resource, $license, $type );
	}

	/**
	 * The slug a resource holds, '' when none.
	 *
	 * @param Resource $resource Resource.
	 */
	public static function stored( Resource $resource ): string {
		$slug = $resource->get( self::META );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Gives a resource its slug, under a lock per customer so two requests never pick the same one.
	 *
	 * @param Resource             $resource Resource.
	 * @param array<string, mixed> $license  License row.
	 * @param string               $type     Type of service (see type_of()).
	 */
	public static function assign( Resource $resource, array $license, string $type ): string {
		global $wpdb;

		$user = (int) ( $license['user_id'] ?? 0 );
		$lock = Locks::name( 'slugs', $user );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
		$held = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, 5 ) );

		try {
			// Another request may have made it while this one waited.
			$fresh = Resource::find( $resource->id() );
			$slug  = $fresh ? self::stored( $fresh ) : '';

			if ( '' !== $slug ) {
				return $slug;
			}

			$slug = self::unique( self::base( $resource, $license, $type ), $type, $user, $resource->id() );

			return $resource->set( array( self::META => $slug ) ) ? $slug : '';
		} finally {
			if ( $held ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}
	}

	/**
	 * The name a slug is made from: what the customer sees as the service's name.
	 *
	 * @param Resource             $resource Resource.
	 * @param array<string, mixed> $license  License row.
	 * @param string               $type     Type of service.
	 */
	public static function name_of( Resource $resource, array $license, string $type ): string {
		if ( 'email' === $type ) {
			$domain = strtolower( trim( (string) $resource->get( 'mail_domain' ) ) );

			if ( '' !== $domain ) {
				return $domain;
			}
		}

		if ( 'domains' === $type ) {
			$domain = strtolower( trim( (string) $resource->get( 'domain' ) ) );

			if ( '' !== $domain ) {
				return $domain;
			}
		}

		if ( 'servers' === $type ) {
			return ServerName::display( $resource, $license );
		}

		return (string) apply_filters( 'beltoft_licenses_account_title', View::product_name( $license ), $license );
	}

	/**
	 * A name as a slug. A domain keeps its dots (a plain host name: letters, digits, dots and hyphens); anything else
	 * goes through sanitize_title().
	 *
	 * @param string $name Name.
	 * @param string $type Type of service.
	 */
	public static function slugify( string $name, string $type ): string {
		$name = strtolower( trim( $name ) );

		if ( in_array( $type, array( 'domains', 'email' ), true ) && 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/D', $name ) ) {
			return substr( $name, 0, self::MAX );
		}

		$slug = sanitize_title( str_replace( '_', '-', $name ) );

		return trim( substr( $slug, 0, self::MAX ), '-' );
	}

	/**
	 * The slug a service would like: its name as a slug, else the word for its type.
	 *
	 * @param Resource             $resource Resource.
	 * @param array<string, mixed> $license  License row.
	 * @param string               $type     Type of service.
	 */
	private static function base( Resource $resource, array $license, string $type ): string {
		$slug = self::slugify( self::name_of( $resource, $license, $type ), $type );

		if ( '' !== $slug ) {
			return $slug;
		}

		$words = array(
			'servers'   => 'server',
			'domains'   => 'domain',
			'databases' => 'database',
			'apps'      => 'app',
			'email'     => 'email',
		);

		return $words[ $type ] ?? 'service';
	}

	/**
	 * The words a slug may not be: the pages "new" and "add", the tabs of the type, and anything made of digits only.
	 *
	 * @param string $slug Candidate.
	 * @param string $type Type of service.
	 */
	public static function reserved( string $slug, string $type ): bool {
		if ( '' === $slug || ctype_digit( $slug ) || in_array( $slug, array( Servers::NEW_VALUE, Domains::ADD_VALUE, Databases::NEW_VALUE ), true ) ) {
			return true;
		}

		return in_array( $slug, self::tabs_of( $type ), true );
	}

	/**
	 * The tab addresses of a type.
	 *
	 * @param string $type Type of service.
	 * @return array<int, string>
	 */
	private static function tabs_of( string $type ): array {
		if ( 'servers' === $type ) {
			return array_merge( Servers::tabs(), array( 'overview' ) );
		}

		if ( 'domains' === $type ) {
			return array_merge( Domains::tabs(), array( 'overview' ) );
		}

		foreach ( ServiceArea::all() as $area ) {
			if ( $area->key_name() === $type ) {
				return array_merge( $area->tabs(), array( 'overview' ) );
			}
		}

		return array( 'overview' );
	}

	/**
	 * The first of base, base-2, base-3 that is not reserved and not held by another service of the customer's of the
	 * same type.
	 *
	 * @param string $base    Wanted slug.
	 * @param string $type    Type of service.
	 * @param int    $user    Customer id.
	 * @param int    $own     This service's activation id (its own slug does not count).
	 */
	private static function unique( string $base, string $type, int $user, int $own ): string {
		$taken = self::taken( $type, $user, $own );

		for ( $n = 1; $n < 10000; $n++ ) {
			$slug = 1 === $n ? $base : substr( $base, 0, self::MAX - strlen( (string) $n ) - 1 ) . '-' . $n;

			if ( ! self::reserved( $slug, $type ) && ! isset( $taken[ $slug ] ) ) {
				return $slug;
			}
		}

		return $base . '-' . wp_generate_password( 6, false, false );
	}

	/**
	 * The slugs held by a customer's services of a type, as keys. A resource folded into another has no page and holds
	 * none.
	 *
	 * @param string $type Type of service.
	 * @param int    $user Customer id.
	 * @param int    $skip Activation id to leave out.
	 * @return array<string, true>
	 */
	private static function taken( string $type, int $user, int $skip ): array {
		$out = array();

		foreach ( self::services( $type, $user ) as $resource ) {
			$slug = self::stored( $resource );

			if ( $resource->id() !== $skip && '' !== $slug ) {
				$out[ $slug ] = true;
			}
		}

		return $out;
	}

	/**
	 * A customer's resources of a type (none that is folded into another).
	 *
	 * @param string $type Type of service.
	 * @param int    $user Customer id.
	 * @return array<int, Resource> By license id.
	 */
	private static function services( string $type, int $user ): array {
		$out = array();

		if ( $user < 1 ) {
			return $out;
		}

		foreach ( Licenses::where( 'user_id', $user ) as $license ) {
			if ( self::type_of( $license ) !== $type ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( $resource && 0 === $resource->folded_into() ) {
				$out[ (int) $license['id'] ] = $resource;
			}
		}

		return $out;
	}

	/**
	 * The license id of the current customer's service of a type that holds a slug, 0 when none does.
	 *
	 * @param string $slug Slug from the address.
	 * @param string $type Type of service.
	 */
	public static function resolve( string $slug, string $type ): int {
		$slug = strtolower( $slug );

		if ( '' === $slug || ! is_user_logged_in() ) {
			return 0;
		}

		foreach ( self::services( $type, get_current_user_id() ) as $license_id => $resource ) {
			if ( self::stored( $resource ) === $slug ) {
				return $license_id;
			}
		}

		return 0;
	}

	/**
	 * What an address after an endpoint says: the first part, the tab part, and the license id it stands for. A number
	 * is the license id (kept for old links); anything else is a slug looked up among the customer's own services.
	 *
	 * @param string $endpoint My Account endpoint.
	 * @param string $type     Type of service.
	 * @return array{first: string, second: string, rest: array<int, string>, id: int, numeric: bool}
	 */
	public static function parse( string $endpoint, string $type ): array {
		global $wp;

		$value = is_object( $wp ) && isset( $wp->query_vars[ $endpoint ] ) ? (string) $wp->query_vars[ $endpoint ] : '';
		$parts = explode( '/', trim( sanitize_text_field( $value ), '/' ) );
		$first = strtolower( $parts[0] );

		$numeric = ctype_digit( $first );
		$id      = $numeric ? (int) $first : 0;

		if ( ! $numeric && '' !== $first && ! in_array( $first, array( Servers::NEW_VALUE, Domains::ADD_VALUE ), true ) ) {
			$id = self::resolve( $first, $type );
		}

		return array(
			'first'   => $first,
			'second'  => isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '',
			'rest'    => self::segments( array_slice( $parts, 1 ) ),
			'id'      => $id,
			'numeric' => $numeric,
		);
	}

	/**
	 * The parts of an address after the service, cleaned: lowercase letters, digits, dots, underscores and hyphens, no empty part.
	 * A plugin that draws pages below a service page (see ServiceArea::page_body hook) reads them.
	 *
	 * @param array<int, string> $parts Raw parts.
	 * @return array<int, string>
	 */
	public static function segments( array $parts ): array {
		$out = array();

		foreach ( $parts as $part ) {
			$part = (string) preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) $part ) );

			if ( '' !== $part && '.' !== $part && '..' !== $part ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Is the address a name that stands for no service of the customer's (and not the New or Add page)?
	 *
	 * @param array{first: string, id: int} $asked Result of parse().
	 */
	public static function unknown( array $asked ): bool {
		return '' !== $asked['first'] && 0 === $asked['id'] && ! in_array( $asked['first'], array( Servers::NEW_VALUE, Domains::ADD_VALUE ), true );
	}

	/**
	 * The address part for a license: its slug and the tab, or its license id when it has no slug.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $tab     Tab, or ''.
	 */
	public static function value( array $license, string $tab = '' ): string {
		$slug = self::for_license( $license );

		return ( '' !== $slug ? $slug : (string) (int) $license['id'] ) . ( '' !== $tab ? '/' . $tab : '' );
	}

	/**
	 * Where a request for a license id should go: the slug address of the customer's own service, with the tab and the
	 * query arguments kept. '' when the address is not a license id, or not the customer's own service of the type.
	 *
	 * @param string   $endpoint My Account endpoint.
	 * @param string   $type     Type of service.
	 * @param callable $url      Builds an address: fn( array $license, string $tab, array $rest ): string (rest: every part after the service).
	 */
	public static function redirect_target( string $endpoint, string $type, callable $url ): string {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( $endpoint ) || ! is_user_logged_in() ) {
			return '';
		}

		$asked = self::parse( $endpoint, $type );

		if ( ! $asked['numeric'] || $asked['id'] < 1 ) {
			return '';
		}

		$license = Licenses::find( $asked['id'] );

		if ( ! $license || (int) ( $license['user_id'] ?? 0 ) !== get_current_user_id() || self::type_of( $license ) !== $type || '' === self::for_license( $license ) ) {
			return '';
		}

		$target = (string) $url( $license, $asked['second'], $asked['rest'] );
		$query  = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['QUERY_STRING'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only copied to the new address.

		if ( '' !== $query ) {
			wp_parse_str( $query, $args );
			$target = add_query_arg( array_map( 'rawurlencode', array_filter( $args, 'is_scalar' ) ), $target );
		}

		return $target;
	}

	/**
	 * Gives every live service of a kind that holds no slug one. Safe to run again.
	 *
	 * @return int How many slugs were made.
	 */
	public static function migrate(): int {
		$made = 0;

		foreach ( array( 'vm', 'domain', 'dns', 'db', 'http', 'mail' ) as $kind ) {
			$made += self::migrate_kind( $kind );
		}

		update_option( self::OPTION, self::VERSION, true );

		return $made;
	}

	/**
	 * The backfill of one kind.
	 *
	 * @param string $kind Activation kind.
	 */
	private static function migrate_kind( string $kind ): int {
		$made = 0;

		foreach ( Resource::live_ids( $kind ) as $id ) {
			$resource = Resource::find( $id );
			$license  = $resource ? $resource->license() : null;

			if ( ! $resource || ! $license || '' !== self::stored( $resource ) || 0 !== $resource->folded_into() ) {
				continue;
			}

			$type = self::type_of( $license );

			if ( '' !== $type && '' !== self::assign( $resource, $license, $type ) ) {
				++$made;
			}
		}

		return $made;
	}

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 20 );
	}

	/**
	 * Runs the backfill once per version, on the first request after an upgrade.
	 */
	public static function maybe_migrate(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::VERSION ) {
			self::migrate();
		}
	}
}
