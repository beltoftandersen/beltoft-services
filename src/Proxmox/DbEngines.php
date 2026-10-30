<?php
/**
 * The engines a managed database can be.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The map from the plan field `engine` to its class. A plan without the field is PostgreSQL, so a database made
 * before the second engine keeps working.
 */
final class DbEngines {

	public const DEFAULT_ID = 'postgres';

	/**
	 * Engine id => class.
	 *
	 * @return array<string, class-string<DbEngine>>
	 */
	private static function map(): array {
		return array(
			'postgres' => PostgresEngine::class,
			'mssql'    => MssqlEngine::class,
		);
	}

	/**
	 * The engine of a plan, or null when the plan names one that does not exist.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function for_plan( array $plan ): ?DbEngine {
		$id    = $plan['engine'] ?? self::DEFAULT_ID;
		$class = is_string( $id ) ? ( self::map()[ $id ] ?? null ) : null;

		return null === $class ? null : new $class();
	}

	/**
	 * The engine of a database. An unknown engine reads as PostgreSQL here (the build refuses such a plan before it
	 * gets this far, see DbBuild::plan_error()).
	 */
	public static function for_resource( Resource $r ): DbEngine {
		return self::for_plan( (array) $r->get( 'plan' ) ) ?? new PostgresEngine();
	}

	/**
	 * The prefixes of the template families of all engines, for example postgres-.
	 *
	 * @return string[]
	 */
	public static function family_prefixes(): array {
		return array_map( static fn( string $id ): string => $id . '-', array_keys( self::map() ) );
	}
}
