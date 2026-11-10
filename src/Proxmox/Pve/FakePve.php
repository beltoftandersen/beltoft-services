<?php
/**
 * The scaffold shared by the fake Proxmox clients.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

defined( 'ABSPATH' ) || exit;

/**
 * What the VM fake (FakeClient) and the container fake (Hosting\FakeNode) have in common: the state, the
 * recorded calls, scripted failures and task outcomes, and the option that keeps the state on staging.
 * The subclass supplies the option name and the empty state; the routes stay in the subclass.
 */
abstract class FakePve implements Client {

	use Tasks;

	/**
	 * Persist in the option (staging) instead of in memory.
	 *
	 * @var bool
	 */
	protected $persist;

	/**
	 * The state: nodes, pools, tasks, seq and what the subclass adds.
	 *
	 * @var array<string, mixed>
	 */
	protected $state;

	/**
	 * Recorded calls.
	 *
	 * @var array<int, array{method: string, path: string, params: array<string, mixed>}>
	 */
	protected $calls = array();

	/**
	 * Scripted failures: method, regex, result.
	 *
	 * @var array<int, array{0: string, 1: string, 2: \WP_Error|array}>
	 */
	protected $failures = array();

	/**
	 * Scripted task outcomes, used by the next tasks created.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $outcomes = array();

	/**
	 * The name of the option that keeps the persisted state (autoload off).
	 *
	 * @return string
	 */
	abstract protected function option_name(): string;

	/**
	 * The empty state.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected static function blank(): array;

	/**
	 * @param bool $persist Keep the state in the option the subclass names (autoload off).
	 */
	public function __construct( bool $persist = false ) {
		$this->persist = $persist;
		$this->state   = static::blank();
		$this->load();
	}

	/**
	 * Adds a pool.
	 *
	 * @param string $poolid Pool id.
	 */
	public function add_pool( string $poolid ): void {
		$this->load();
		$this->state['pools'][ $poolid ] = array( 'members' => array() );
		$this->save();
	}

	/**
	 * Makes the next call matching the method and the path pattern fail. Consumed once, in the order
	 * added. An array is an error spec: code (default http_500), message, status (default 500). It
	 * applies after the parameters were validated.
	 *
	 * @param string          $method     Method.
	 * @param string          $path_regex Regex with delimiters, matched against the path.
	 * @param \WP_Error|array $result     Error.
	 */
	public function fail_next( string $method, string $path_regex, $result ): void {
		$this->failures[] = array( strtoupper( $method ), $path_regex, $result );
	}

	/**
	 * Scripts the next task created: polls (status running that many times, default 0), exitstatus
	 * (default OK) and effect (apply the change; default: only when OK).
	 *
	 * @param array<string, mixed> $outcome Outcome.
	 */
	public function next_task( array $outcome ): void {
		$this->outcomes[] = $outcome;
	}

	/**
	 * Every call made so far.
	 *
	 * @return array<int, array{method: string, path: string, params: array<string, mixed>}>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * The calls whose path matches a regex, optionally of one method.
	 *
	 * @param string $path_regex Regex with delimiters.
	 * @param string $method     Method, or '' for any.
	 * @return array<int, array{method: string, path: string, params: array<string, mixed>}>
	 */
	public function calls_to( string $path_regex, string $method = '' ): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn( $c ) => 1 === preg_match( $path_regex, $c['path'] ) && ( '' === $method || strtoupper( $method ) === $c['method'] )
			)
		);
	}

	/**
	 * The whole state (secrets are never in it).
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$this->load();

		return $this->state;
	}

	/**
	 * Reloads the persisted state.
	 */
	protected function load(): void {
		if ( ! $this->persist ) {
			return;
		}

		$stored = get_option( $this->option_name(), null );

		if ( is_array( $stored ) ) {
			$this->state = array_merge( static::blank(), $stored );
		}
	}

	/**
	 * Saves the state when persisting (autoload off).
	 */
	protected function save(): void {
		if ( $this->persist ) {
			update_option( $this->option_name(), $this->state, false );
		}
	}
}
