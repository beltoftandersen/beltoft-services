<?php
/**
 * The driver contract.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A driver turns a resource's lifecycle into calls to an external service. Operations
 * report a StepResult and must be safe to run again.
 */
interface Driver {

	/**
	 * Driver id, for example 'http' or 'proxmox'.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Activation kind of its resources: /^[a-z0-9_-]{1,20}$/ and not 'site'.
	 *
	 * @return string
	 */
	public function kind(): string;

	/**
	 * Usage line, for example "2 of 3 dashboards".
	 *
	 * @param int $used Used.
	 * @param int $max  Allowed.
	 * @return string
	 */
	public function unit_label( int $used, int $max ): string;

	/**
	 * Service tab fields: key => [type, label, desc, default, variation(bool)].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function plan_fields(): array;

	/**
	 * Create the remote service.
	 *
	 * @param Resource $r Resource.
	 * @return StepResult
	 */
	public function provision( Resource $r ): StepResult;

	/**
	 * Suspend the remote service.
	 *
	 * @param Resource $r Resource.
	 * @return StepResult
	 */
	public function pause( Resource $r ): StepResult;

	/**
	 * Bring a suspended service back.
	 *
	 * @param Resource $r Resource.
	 * @return StepResult
	 */
	public function resume( Resource $r ): StepResult;

	/**
	 * Apply a changed plan.
	 *
	 * @param Resource $r Resource.
	 * @return StepResult
	 */
	public function update_plan( Resource $r ): StepResult;

	/**
	 * Remove the remote service.
	 *
	 * @param Resource $r Resource.
	 * @return StepResult
	 */
	public function delete( Resource $r ): StepResult;

	/**
	 * Read-only remote status, or null if unknown.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public function status( Resource $r ): ?array;

	/**
	 * Actions a customer may run now: name => label. May depend on state.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	public function customer_actions( Resource $r ): array;

	/**
	 * Run a customer action.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments.
	 * @return StepResult
	 */
	public function act( Resource $r, string $action, array $args ): StepResult;

	/**
	 * Print the driver's part of the My Account panel.
	 *
	 * @param Resource $r Resource.
	 */
	public function render_account( Resource $r ): void;

	/**
	 * Print the driver's part of the admin panel.
	 *
	 * @param Resource $r Resource.
	 */
	public function render_admin( Resource $r ): void;
}
