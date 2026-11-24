<?php
/**
 * The "Service" column of WooCommerce → Licenses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Admin;

use BeltoftLicenses\Admin\Screen as LicenceScreen;
use BeltoftServices\Apps\Apps;
use BeltoftServices\ManualServer\ManualServerDriver;
use BeltoftServices\Proxmox\Db\DbEngines;
use BeltoftServices\Proxmox\Vm\Provision;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Names what each license on the license list is for, through the list hooks of beltoft-licenses
 * (`beltoft_licenses_list_columns`, `beltoft_licenses_list_page_rows`, `beltoft_licenses_list_column_service`; on an older
 * beltoft-licenses nothing fires and the column does not appear). The page's licenses are read once: their live
 * services, the newest closed ones (dimmed, with the date), the servers that apps and disks sit on, and the website names of
 * the hosting accounts. Every name links to the service panel on the license screen.
 */
final class LicenceServiceColumn {

	public const COLUMN = 'service';

	/**
	 * Closed services shown per license.
	 */
	public const CLOSED_SHOWN = 3;

	/**
	 * License id => live resources, for the page.
	 *
	 * @var array<int, Resource[]>
	 */
	private static array $live = array();

	/**
	 * License id => closed resources, for the page.
	 *
	 * @var array<int, Resource[]>
	 */
	private static array $closed = array();

	/**
	 * Activation id => website names, for the page.
	 *
	 * @var array<int, string[]>
	 */
	private static array $hosts = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_licenses_list_columns', array( __CLASS__, 'columns' ) );
		add_action( 'beltoft_licenses_list_page_rows', array( __CLASS__, 'prime' ) );
		add_filter( 'beltoft_licenses_list_column_' . self::COLUMN, array( __CLASS__, 'cell' ), 10, 2 );
	}

	/**
	 * Adds the column after the product.
	 *
	 * @param mixed $columns Column labels by name.
	 * @return mixed
	 */
	public static function columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$out = array();

		foreach ( $columns as $name => $label ) {
			$out[ $name ] = $label;

			if ( 'product' === $name ) {
				$out[ self::COLUMN ] = __( 'Service', 'beltoft-services' );
			}
		}

		return isset( $out[ self::COLUMN ] ) ? $out : $columns + array( self::COLUMN => __( 'Service', 'beltoft-services' ) );
	}

	/**
	 * Reads what the page's licenses need, once.
	 *
	 * @param mixed $rows License rows of the page.
	 */
	public static function prime( $rows ): void {
		self::$live   = array();
		self::$closed = array();
		self::$hosts  = array();

		if ( ! is_array( $rows ) ) {
			return;
		}

		$ids = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}

		self::load( $ids );

		// The servers that apps and disks sit on.
		$servers = array();

		foreach ( array( self::$live, self::$closed ) as $groups ) {
			foreach ( $groups as $list ) {
				foreach ( $list as $r ) {
					$on = (int) $r->get( 'server_license' );

					if ( $on > 0 && ! isset( self::$live[ $on ] ) && ! isset( self::$closed[ $on ] ) ) {
						$servers[ $on ] = $on;
					}
				}
			}
		}

		if ( $servers ) {
			self::load( array_values( $servers ) );
		}

		$resources = array();

		foreach ( self::$live as $list ) {
			foreach ( $list as $r ) {
				$resources[] = $r;
			}
		}

		// A feature that names its services from its own tables answers here: resource id => names.
		$names = apply_filters( 'beltoft_services_licence_column_names', array(), $resources );

		if ( is_array( $names ) ) {
			self::$hosts = $names;
		}
	}

	/**
	 * Reads the live and closed resources of licenses not read yet.
	 *
	 * @param int[] $license_ids License ids.
	 */
	private static function load( array $license_ids ): void {
		foreach ( Resource::live_for_licenses( $license_ids ) as $id => $list ) {
			self::$live[ $id ] = $list;
		}

		foreach ( Resource::closed_for_licenses( $license_ids, self::CLOSED_SHOWN ) as $id => $list ) {
			self::$closed[ $id ] = $list;
		}
	}

	/**
	 * The cell of one license: its services, live first, then the ended ones dimmed.
	 *
	 * @param mixed $content What an earlier filter returned.
	 * @param mixed $license  License row.
	 * @return mixed
	 */
	public static function cell( $content, $license = null ) {
		if ( ! is_array( $license ) || empty( $license['id'] ) ) {
			return $content;
		}

		$id = (int) $license['id'];

		// Called outside a list page: read this one license.
		if ( ! isset( self::$live[ $id ] ) && ! isset( self::$closed[ $id ] ) ) {
			self::prime( array( $license ) );
		}

		$entries = array();
		$seen    = array();

		foreach ( self::$live[ $id ] ?? array() as $r ) {
			foreach ( self::names( $r ) as $name ) {
				$seen[ $name ] = true;
				$entries[]     = array( $name, self::link( $id, $r ) );
			}
		}

		foreach ( self::$closed[ $id ] ?? array() as $r ) {
			foreach ( self::names( $r ) as $name ) {
				if ( isset( $seen[ $name ] ) ) {
					continue;
				}

				$seen[ $name ] = true;
				$when          = '' !== $r->ended_at() ? mysql2date( get_option( 'date_format' ), get_date_from_gmt( $r->ended_at() ) ) : '';
				/* translators: 1: the name of the service, 2: the date it was deleted. */
				$entries[]     = array( '' !== $when ? sprintf( __( '%1$s, deleted %2$s', 'beltoft-services' ), $name, $when ) : $name, self::link( $id, null ), true );
			}
		}

		return $entries ? $entries : $content;
	}

	/**
	 * The service panel of a license: its anchor for a live service, the screen for an ended one.
	 *
	 * @param int           $license_id License id.
	 * @param Resource|null $r          Live resource.
	 */
	private static function link( int $license_id, ?Resource $r ): string {
		return LicenceScreen::url( array( 'license' => $license_id ) ) . ( $r ? '#beltoft-service-' . $r->id() : '' );
	}

	/**
	 * What a resource is called: the name or names it holds, by kind.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	public static function names( Resource $r ): array {
		$kind = self::kind_of( $r );
		$meta = static fn( string $key ): string => is_scalar( $r->get( $key ) ) ? trim( (string) $r->get( $key ) ) : '';
		$out  = array();

		switch ( $kind ) {
			case 'domain':
				$out[] = $meta( 'domain' );
				break;
			case 'dns':
				/* translators: %s: domain name. */
				$out[] = '' !== $meta( 'domain' ) ? sprintf( __( '%s (DNS)', 'beltoft-services' ), $meta( 'domain' ) ) : '';
				break;
			case 'vm':
				$name = Provision::vm_name( $r );
				$ip   = $meta( 'ip' );
				$out[] = '' !== $ip ? $name . ' (' . $ip . ')' : $name;
				break;
			case 'db':
				$label = DbEngines::for_resource( $r )->label( (array) $r->get( 'plan' ) );
				$ip    = $meta( 'ip' );
				/* translators: %s: engine and version, such as "PostgreSQL 18". */
				$name  = '' !== $label ? sprintf( __( '%s database', 'beltoft-services' ), $label ) : __( 'Database', 'beltoft-services' );
				$out[] = '' !== $ip ? $name . ' (' . $ip . ')' : $name;
				break;
			case 'mail':
				$out[] = $meta( 'mail_domain' );
				break;
			case 'ssl':
				$out[] = $meta( 'ssl_name' );
				break;
			case 'applic':
				$out[] = self::app_on_server( $r );
				break;
			case 'lickey':
				$parts = array_filter( array( $meta( 'lic_title' ), $meta( 'lic_ip' ) ), 'strlen' );
				$out[] = implode( ' / ', $parts );
				break;
			case ManualServerDriver::KIND:
				$out[] = $meta( 'server_name' );
				break;
			case 'disk':
				$size = (int) $r->get( 'size_gb' );
				$on   = self::server_name( (int) $r->get( 'server_license' ) );
				/* translators: 1: size in GB, 2: server name. */
				$out[] = $size > 0 ? ( '' !== $on ? sprintf( __( 'Extra disk %1$d GB on %2$s', 'beltoft-services' ), $size, $on ) : sprintf( __( 'Extra disk %d GB', 'beltoft-services' ), $size ) ) : '';
				break;
			case 'ipv4':
				$address = $meta( 'ipv4_address' );
				$on      = self::server_name( (int) $r->get( 'server_license' ) );
				/* translators: 1: IPv4 address, 2: server name. */
				$out[]   = '' !== $address ? ( '' !== $on ? sprintf( __( 'IPv4 address %1$s on %2$s', 'beltoft-services' ), $address, $on ) : sprintf( __( 'IPv4 address %s', 'beltoft-services' ), $address ) ) : '';
				break;
			case 'storage':
				$out[] = $meta( 'storage_name' );
				break;
			default:
				$out = self::$hosts[ $r->id() ] ?? array();
				break;
		}

		return array_values( array_filter( array_map( 'strval', $out ), 'strlen' ) );
	}

	/**
	 * The kind of a resource (its driver's kind).
	 *
	 * @param Resource $r Resource.
	 */
	private static function kind_of( Resource $r ): string {
		return $r->kind();
	}

	/**
	 * "Plesk on web-01".
	 *
	 * @param Resource $r App licence resource.
	 */
	private static function app_on_server( Resource $r ): string {
		$app        = (string) ( ( (array) $r->get( 'plan' ) )['app'] ?? '' );
		$definition = '' !== $app ? Apps::definition( $app ) : null;
		$label      = is_array( $definition ) && ! empty( $definition['name'] ) ? (string) $definition['name'] : ucfirst( $app );
		$on         = self::server_name( (int) $r->get( 'server_license' ) );

		if ( '' === $label ) {
			return '';
		}

		/* translators: 1: app or licence name, 2: server name. */
		return '' !== $on ? sprintf( __( '%1$s on %2$s', 'beltoft-services' ), $label, $on ) : $label;
	}

	/**
	 * The name of the server of a license: its live server, else the newest closed one.
	 *
	 * @param int $license_id License id of the server.
	 */
	private static function server_name( int $license_id ): string {
		if ( $license_id < 1 ) {
			return '';
		}

		foreach ( array_merge( self::$live[ $license_id ] ?? array(), self::$closed[ $license_id ] ?? array() ) as $r ) {
			if ( 'vm' === self::kind_of( $r ) ) {
				return Provision::vm_name( $r );
			}
		}

		return '';
	}
}
