<?php
/**
 * The signal of a hosting change that waits for the agent in a background job.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Drivers\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Base::call() in job mode when the agent's job is not finished (or the agent did not answer): the job run
 * ends and Jobs::act() queues the same action again. Nothing waits.
 */
final class TaskPending extends \RuntimeException {

	/**
	 * What the job run answers.
	 *
	 * @var StepResult
	 */
	public $result;

	/**
	 * @param StepResult $result Poll or retry.
	 */
	public function __construct( StepResult $result ) {
		parent::__construct( 'pending' );
		$this->result = $result;
	}
}
