<?php
/**
 * A scriptable driver for tests.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

/**
 * Answers each operation with the StepResult a test scripted (done by default) and records
 * every call.
 */
class FakeDriver implements Driver {

	/**
	 * Scripted results: operation => StepResult[] (a queue) or a single StepResult.
	 *
	 * @var array<string, mixed>
	 */
	private $script = array();

	/**
	 * Recorded calls.
	 *
	 * @var array<int, array{op: string, resource: Resource, args: array<int, mixed>}>
	 */
	private $calls = array();

	/**
	 * Status answer.
	 *
	 * @var array<string, mixed>|null
	 */
	public $status = null;

	/**
	 * Customer actions answer.
	 *
	 * @var array<string, string>
	 */
	public $customer_actions = array();

	/**
	 * Scripts the result of an operation. Several calls queue results in order; the last one
	 * repeats.
	 *
	 * @param string     $op     Operation name, e.g. 'provision'.
	 * @param StepResult $result Result.
	 * @return $this
	 */
	public function will( string $op, StepResult $result ): self {
		$this->script[ $op ][] = $result;

		return $this;
	}

	/**
	 * Recorded calls.
	 *
	 * @param string|null $op Only this operation, or all when null.
	 * @return array<int, array{op: string, resource: Resource, args: array<int, mixed>}>
	 */
	public function calls( ?string $op = null ): array {
		if ( null === $op ) {
			return $this->calls;
		}

		return array_values(
			array_filter(
				$this->calls,
				static function ( $call ) use ( $op ) {
					return $call['op'] === $op;
				}
			)
		);
	}

	/**
	 * Records a call and returns its scripted result.
	 *
	 * @param string   $op   Operation.
	 * @param Resource $r    Resource.
	 * @param array    $args Extra arguments.
	 * @return StepResult
	 */
	private function step( string $op, Resource $r, array $args = array() ): StepResult {
		$this->calls[] = array(
			'op'       => $op,
			'resource' => $r,
			'args'     => $args,
		);

		if ( empty( $this->script[ $op ] ) ) {
			return StepResult::done();
		}

		return count( $this->script[ $op ] ) > 1 ? array_shift( $this->script[ $op ] ) : $this->script[ $op ][0];
	}

	public function id(): string {
		return 'fake';
	}

	public function label(): string {
		return 'Fake';
	}

	public function kind(): string {
		return 'fake';
	}

	public function unit_label( int $used, int $max ): string {
		return sprintf( '%d of %d units', $used, $max );
	}

	public function plan_fields(): array {
		return array();
	}

	public function provision( Resource $r ): StepResult {
		return $this->step( 'provision', $r );
	}

	public function pause( Resource $r ): StepResult {
		return $this->step( 'pause', $r );
	}

	public function resume( Resource $r ): StepResult {
		return $this->step( 'resume', $r );
	}

	public function update_plan( Resource $r ): StepResult {
		return $this->step( 'update_plan', $r );
	}

	public function delete( Resource $r ): StepResult {
		return $this->step( 'delete', $r );
	}

	public function status( Resource $r ): ?array {
		$this->calls[] = array(
			'op'       => 'status',
			'resource' => $r,
			'args'     => array(),
		);

		return $this->status;
	}

	public function customer_actions( Resource $r ): array {
		return $this->customer_actions;
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return $this->step( 'act', $r, array( $action, $args ) );
	}

	public function render_account( Resource $r ): void {
		echo 'fake-account';
	}

	public function render_admin( Resource $r ): void {
		echo 'fake-admin';
	}
}
