<?php
/**
 * Shared helpers for the test suite.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Settings;

/**
 * Base test case: settings, Action Scheduler runner and an HTTP recorder.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Requests seen by the recorder.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	protected $http_calls = array();

	/**
	 * Answers requests; null means a plain 200 with an empty body.
	 *
	 * @var callable|null
	 */
	protected $http_responder = null;

	public function set_up(): void {
		parent::set_up();

		$this->http_calls     = array();
		$this->http_responder = null;

		add_filter( 'pre_http_request', array( $this, 'record_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'record_http' ), 10 );

		parent::tear_down();
	}

	/**
	 * The pre_http_request recorder.
	 *
	 * @param mixed                $preempt Pre-empted response.
	 * @param array<string, mixed> $args    Request arguments.
	 * @param string               $url     URL.
	 * @return mixed
	 */
	public function record_http( $preempt, $args, $url ) {
		$this->http_calls[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( null !== $this->http_responder ) {
			return call_user_func( $this->http_responder, $url, $args );
		}

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Every HTTP request made so far.
	 *
	 * @return array<int, array{url: string, args: array<string, mixed>}>
	 */
	protected function http_calls(): array {
		return $this->http_calls;
	}

	/**
	 * Sets what the recorder answers. The callable gets the URL and the arguments and
	 * returns an HTTP response array or a WP_Error.
	 *
	 * @param callable $responder Responder.
	 */
	protected function respond_with( callable $responder ): void {
		$this->http_responder = $responder;
	}

	/**
	 * Merges settings over the saved ones.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	protected function settings( array $values ): void {
		$current = get_option( Settings::OPTION, array() );
		update_option( Settings::OPTION, array_merge( is_array( $current ) ? $current : array(), $values ) );
	}

	/**
	 * Runs the pending Action Scheduler actions of group beltoft-services that are due,
	 * repeatedly, until none is due.
	 */
	protected function run_jobs(): void {
		for ( $round = 0; $round < 100; $round++ ) {
			$ids = as_get_scheduled_actions(
				array(
					'group'        => 'beltoft-services',
					'status'       => \ActionScheduler_Store::STATUS_PENDING,
					'date'         => time(),
					'date_compare' => '<=',
					'per_page'     => -1,
					'orderby'      => 'date',
					'order'        => 'ASC',
				),
				'ids'
			);

			if ( empty( $ids ) ) {
				return;
			}

			foreach ( $ids as $id ) {
				\ActionScheduler::runner()->process_action( $id, 'Tests' );
			}
		}
	}
}
