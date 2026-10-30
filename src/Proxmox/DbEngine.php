<?php
/**
 * What differs between the engines of a managed database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One class per engine, chosen by the plan field `engine` (DbEngines). The build, the guest protocol (job.json,
 * status.json, credentials.json), the private network, the public IP add-on, the allow-list, the backups and the card
 * are shared; only what is listed here is the engine's own.
 */
interface DbEngine {

	/**
	 * The value of the plan field `engine`.
	 */
	public function id(): string;

	/**
	 * The name shown to the customer, with the version when the plan names one: "PostgreSQL 18".
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public function label( array $plan ): string;

	/**
	 * The TCP port of the server (the guest listens on it, the VM firewall opens it for the allow-list).
	 */
	public function port(): int;

	/**
	 * The name of the customer's database and of the customer's login.
	 */
	public function database(): string;

	public function login(): string;

	/**
	 * The template family a server of this plan is built from, for example postgres-18.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public function family( array $plan ): string;

	/**
	 * The engine's part of the plan check (version, smallest size): the text of the refusal, or null. It needs no
	 * Proxmox call.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public function plan_error( array $plan ): ?string;

	/**
	 * The engine's own fields of the init job (the shared ones are written by DbBuild).
	 *
	 * @return array<string, mixed>
	 */
	public function init_fields( Resource $r ): array;

	/**
	 * Connection strings for an address, with PASSWORD where the customer's password goes: `verify` checks the server
	 * certificate against the CA the customer downloads, `require` only encrypts.
	 *
	 * @return array{verify:string,require:string}
	 */
	public function connection( string $host ): array;

	/**
	 * A sentence on the limits of the engine's edition for the card, or an empty string.
	 */
	public function limits(): string;

	/**
	 * A sentence on how to trust the CA certificate with this engine's clients.
	 */
	public function ca_hint(): string;
}
