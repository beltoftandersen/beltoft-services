<?php
/**
 * The outcome of one driver operation.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Done, retry later, or failed. Its meta is merged into the resource meta in every case,
 * which is how a driver records the steps it has completed.
 */
final class StepResult {

	const DONE   = 'done';
	const RETRY  = 'retry';
	const FAILED = 'failed';

	/**
	 * Outcome.
	 *
	 * @var string
	 */
	private $outcome;

	/**
	 * Seconds until the next try (retry only).
	 *
	 * @var int
	 */
	private $delay;

	/**
	 * Error message (retry and failed).
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Resource meta to merge.
	 *
	 * @var array<string, mixed>
	 */
	private $meta;

	/**
	 * History line to record (done only).
	 *
	 * @var string
	 */
	private $event;

	/**
	 * Whether a retry follows real progress (retry only).
	 *
	 * @var bool
	 */
	private $progress = false;

	/** Deadline for an explicitly bounded external wait; zero uses normal retry limits. */
	private int $wait_until = 0;

	/**
	 * Use the named constructors.
	 *
	 * @param string               $outcome Outcome.
	 * @param int                  $delay   Delay in seconds.
	 * @param string               $error   Error message.
	 * @param array<string, mixed> $meta    Meta.
	 * @param string               $event   Event text.
	 */
	private function __construct( string $outcome, int $delay, string $error, array $meta, string $event ) {
		$this->outcome = $outcome;
		$this->delay   = $delay;
		$this->error   = $error;
		$this->meta    = $meta;
		$this->event   = $event;
	}

	/**
	 * The operation is complete.
	 *
	 * @param array<string, mixed> $meta  Meta to merge into the resource.
	 * @param string               $event Optional history text.
	 * @return self
	 */
	public static function done( array $meta = array(), string $event = '' ): self {
		return new self( self::DONE, 0, '', $meta, $event );
	}

	/**
	 * Not finished or failed for now: try again later.
	 *
	 * @param int                  $delay Seconds until the next try.
	 * @param string               $error What went wrong.
	 * @param array<string, mixed> $meta  Meta to merge into the resource.
	 * @param bool                 $progress True when this run got further (a step completed, a remote task ended): the Reconciler then counts the retry as a first one, so a long operation made of many short steps is not cut off by the 5-retry budget. Waiting for something that is still running is not progress.
	 * @return self
	 */
	public static function retry( int $delay, string $error, array $meta = array(), bool $progress = false ): self {
		$result           = new self( self::RETRY, max( 0, $delay ), $error, $meta, '' );
		$result->progress = $progress;

		return $result;
	}

	/** Wait for an external authority until a fixed deadline without consuming the short retry budget. */
	public static function wait( int $delay, string $error, int $until, array $meta = array() ): self {
		$result = self::retry( $delay, $error, $meta, true );
		$result->wait_until = $until;
		return $result;
	}

	public function wait_until(): int {
		return $this->wait_until;
	}

	/**
	 * This result with the progress flag set (a retry only; other results are returned as they are).
	 *
	 * @return self
	 */
	public function with_progress(): self {
		if ( ! $this->is_retry() ) {
			return $this;
		}

		$result = self::retry( $this->delay, $this->error, $this->meta, true );
		$result->wait_until = $this->wait_until;
		return $result;
	}

	/**
	 * Does a retry follow progress?
	 *
	 * @return bool
	 */
	public function has_progress(): bool {
		return $this->progress;
	}

	/**
	 * Failed for good.
	 *
	 * @param string               $error What went wrong.
	 * @param array<string, mixed> $meta  Meta to merge into the resource.
	 * @return self
	 */
	public static function fail( string $error, array $meta = array() ): self {
		return new self( self::FAILED, 0, $error, $meta, '' );
	}

	/**
	 * Is the operation complete?
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return self::DONE === $this->outcome;
	}

	/**
	 * Should it be tried again?
	 *
	 * @return bool
	 */
	public function is_retry(): bool {
		return self::RETRY === $this->outcome;
	}

	/**
	 * Did it fail for good?
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return self::FAILED === $this->outcome;
	}

	/**
	 * Seconds until the next try; 0 unless a retry.
	 *
	 * @return int
	 */
	public function delay(): int {
		return $this->delay;
	}

	/**
	 * The error message; empty when done.
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Meta to merge into the resource.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * History text; empty if none.
	 *
	 * @return string
	 */
	public function event(): string {
		return $this->event;
	}
}
