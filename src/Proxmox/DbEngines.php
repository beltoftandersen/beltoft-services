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
			PostgresEngine::ID => PostgresEngine::class,
			'mssql'    => MssqlEngine::class,
			ValkeyEngine::ID => ValkeyEngine::class,
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
	 * The name of an engine without a version, for a choice between engines: "PostgreSQL", "Valkey", "SQL Server Express".
	 *
	 * @param string $id Engine id.
	 */
	public static function name( string $id ): string {
		$engine = self::for_plan( array( 'engine' => $id ) );

		return null === $engine ? $id : $engine->label( array() );
	}

	/**
	 * One plain line about an engine for the engine choice. A site or a later engine can set its own.
	 *
	 * @param string $id Engine id.
	 */
	public static function description( string $id ): string {
		$lines = array(
			PostgresEngine::ID => __( 'Relational database', 'beltoft-services' ),
			ValkeyEngine::ID   => __( 'In-memory key-value store, Redis compatible', 'beltoft-services' ),
			'mssql'            => __( 'Microsoft SQL Server, Express edition', 'beltoft-services' ),
		);

		/**
		 * The line under an engine's name on the New database page.
		 *
		 * @param string $line Line ('' for none).
		 * @param string $id   Engine id.
		 */
		return (string) apply_filters( 'beltoft_services_db_engine_description', $lines[ $id ] ?? '', $id );
	}

	/**
	 * The prefixes of the template families of all engines (Clusters::SERVICE_FAMILIES): postgres-, mssql-, valkey.
	 *
	 * @return string[]
	 */
	public static function family_prefixes(): array {
		return Clusters::SERVICE_FAMILIES;
	}
}
