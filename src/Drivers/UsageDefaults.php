<?php
/**
 * Defaults of a driver that is billed by the usage engine as a plain service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

trait UsageDefaults {

	/**
	 * A service of its own.
	 */
	public function usage_is_addon(): bool {
		return false;
	}

	/**
	 * No Billing choice.
	 */
	public function usage_billing_choice(): bool {
		return false;
	}

	/**
	 * Nothing in the cart breakdown.
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_breakdown(): ?array {
		return null;
	}

	/**
	 * Not deletable here.
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_delete(): ?array {
		return null;
	}

	/**
	 * The default label.
	 *
	 * @param Resource $r Resource.
	 */
	public function usage_label( Resource $r ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- default.
		return null;
	}

	/**
	 * The default reference.
	 *
	 * @param Resource $r Resource.
	 */
	public function usage_reference( Resource $r ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- default.
		return null;
	}
}
