<?php
/**
 * One database engine of the managed database driver: what differs between PostgreSQL and Valkey.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The seam between the shared driver (build, firewall, access, card, backups, monitoring) and an engine. The plan
 * field `engine` selects it; a plan without the field, or a stored resource from before engines, is PostgreSQL.
 * Everything else in the driver is the same for every engine.
 */
abstract class DbEngine {

	/**
	 * The engine of a plan. An unknown name gets no engine (null), so the build refuses the plan.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function for_plan( array $plan ): ?DbEngine {
		$id = $plan['engine'] ?? PostgresEngine::ID;

		if ( PostgresEngine::ID === $id ) {
			return new PostgresEngine();
		}

		return ValkeyEngine::ID === $id ? new ValkeyEngine() : null;
	}

	/**
	 * The engine of a resource (PostgreSQL when the plan names none or an engine that is not known).
	 */
	public static function for_resource( Resource $r ): DbEngine {
		return self::for_plan( (array) $r->get( 'plan' ) ) ?? new PostgresEngine();
	}

	/**
	 * Engine id, as in the plan field `engine`.
	 */
	abstract public function id(): string;

	/**
	 * Name with the version when the plan names one: "PostgreSQL 18", "Valkey".
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	abstract public function label( array $plan ): string;

	/**
	 * The port the engine listens on (the allow-list rules open it on the public NIC).
	 */
	abstract public function port(): int;

	/**
	 * The database name shown on the card and sent to the guest; empty when the engine has none.
	 */
	abstract public function database(): string;

	/**
	 * The customer's login name.
	 */
	abstract public function role(): string;

	/**
	 * The template family a plan is built from (the cluster lists a template under it).
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	abstract public function family( array $plan ): string;

	/**
	 * What is wrong with the engine's part of a plan, or null. The error reaches only the admin.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	abstract public function plan_error( array $plan ): ?string;

	/**
	 * The init job.
	 *
	 * @param Resource          $r       Resource.
	 * @param string            $request Request id.
	 * @param array<int,string> $listen  Addresses to listen on (the private one first).
	 * @param string            $cidr    The customer's private network.
	 * @return array<string, mixed>
	 */
	abstract public function init_job( Resource $r, string $request, array $listen, string $cidr ): array;

	/**
	 * The connection strings of the card, each with PASSWORD where the password goes.
	 *
	 * @param string $host Address.
	 * @return array<int, array{label:string,value:string}>
	 */
	abstract public function connections( string $host ): array;

	/**
	 * A sentence under the connection strings for the engine (empty for none), plain text.
	 */
	public function connection_note(): string {
		return '';
	}
}
