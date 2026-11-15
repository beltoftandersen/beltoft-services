<?php
/**
 * The signal of a hosting change that reached its first agent call in a web request.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Base::call() in request mode: every check of the change and its local work before the first agent call
 * passed, and the agent work belongs to a background job. Base::queue() catches it and answers with what it carries.
 */
final class Deferred extends \RuntimeException {

	/**
	 * The name of the agent call that was reached.
	 *
	 * @var string
	 */
	public $call = '';

	/**
	 * The answer: the queued task, or the refusal of a second change.
	 *
	 * @var array<string, mixed>|\WP_Error
	 */
	public $answer;

	/**
	 * @param string                         $call   Name of the agent call that was reached.
	 * @param array<string, mixed>|\WP_Error $answer The answer.
	 */
	public function __construct( string $call, $answer ) {
		parent::__construct( 'deferred' );
		$this->call   = $call;
		$this->answer = $answer;
	}
}
