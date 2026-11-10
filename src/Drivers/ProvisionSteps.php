<?php
/**
 * Optional driver contract: the steps of a build, for the customer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * A driver that provisions in named steps says what they are called while a customer waits. Progress
 * asks the driver of a resource for this list (key => label, in order); a driver that does not implement
 * it has no step list, only a title.
 */
interface ProvisionSteps {

	/**
	 * The steps of a build in order, each with the words the customer sees.
	 *
	 * @return array<string, string> Step key => label.
	 */
	public function step_labels(): array;
}
