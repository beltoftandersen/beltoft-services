<?php
/**
 * Shared helpers for the test suite.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Product;
use BeltoftServices\Settings;
use BeltoftServices\Tests\Support\FakeDriver;

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

	/**
	 * Seconds the Proxmox status re-reads would have slept.
	 *
	 * @var int[]
	 */
	protected $pauses = array();

	public function set_up(): void {
		parent::set_up();
		// The licenses activation table is outside WordPress' per-test rollback. A domain resource
		// from an earlier test would be picked up by the daily sync during unrelated alert tests.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'beltoft_licenses_activations WHERE kind = %s', 'domain' ) );

		$this->http_calls     = array();
		$this->http_responder = null;

		add_filter( 'pre_http_request', array( $this, 'record_http' ), 10, 3 );

		// Re-reads of a lagging Proxmox status never sleep in tests; the pauses are counted.
		$this->pauses = array();
		add_filter(
			'beltoft_services_proxmox_pause',
			fn() => function ( int $seconds ): void {
				$this->pauses[] = $seconds;
			}
		);
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
	 * Creates a FakeDriver and registers it through the beltoft_services_drivers filter for
	 * the current test (WP_UnitTestCase restores the hooks afterwards).
	 *
	 * @param string $id Driver id (and kind); 'fake' by default.
	 * @return FakeDriver
	 */
	protected function fake_driver( string $id = 'fake' ): FakeDriver {
		$fake = new FakeDriver( $id );

		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) use ( $fake ) {
				$drivers[ $fake->id() ] = $fake;
				return $drivers;
			}
		);

		return $fake;
	}

	/**
	 * Creates a saved, licensed service product.
	 *
	 * $extra keys: slug, retention, trial_requires_payment ('yes'|'no'), connection, max
	 * (sites per license), variations (a list of plan overrides, each optionally with 'max';
	 * makes a variable product).
	 *
	 * @param string               $driver Driver id.
	 * @param array<string, mixed> $plan   Plan values by field key.
	 * @param array<string, mixed> $extra  Other settings.
	 * @return \WC_Product
	 */
	protected function service_product( string $driver = 'fake', array $plan = array(), array $extra = array() ): \WC_Product {
		$variations = isset( $extra['variations'] ) ? (array) $extra['variations'] : array();
		$product    = $variations ? new \WC_Product_Variable() : new \WC_Product_Simple();

		$product->set_name( 'Service ' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );

		if ( $variations ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( 'Tier' );
			$attribute->set_options( array_map( 'strval', array_keys( array_values( $variations ) ) ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$product->set_attributes( array( $attribute ) );
		} else {
			$product->set_regular_price( '10' );
		}

		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, (string) ( $extra['slug'] ?? 'test-service' ) );
		$product->update_meta_data( Product::META_DRIVER, $driver );
		$product->update_meta_data( Product::META_CONNECTION, (string) ( $extra['connection'] ?? '' ) );
		$product->update_meta_data( Product::META_RETENTION, isset( $extra['retention'] ) ? (string) $extra['retention'] : '' );
		$product->update_meta_data( Product::META_TRIAL_PAID, (string) ( $extra['trial_requires_payment'] ?? 'yes' ) );

		if ( isset( $extra['max'] ) ) {
			$product->update_meta_data( \BeltoftLicenses\Product::META_MAX, (string) $extra['max'] );
		}

		foreach ( $plan as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value );
		}

		$product->save();

		if ( '' !== $driver ) {
			Product::remember_slug( (string) ( $extra['slug'] ?? 'test-service' ) );
		}

		foreach ( array_values( $variations ) as $index => $overrides ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'tier' => (string) $index ) );
			$variation->set_regular_price( '10' );
			$variation->set_status( 'publish' );

			foreach ( (array) $overrides as $key => $value ) {
				if ( 'max' === $key ) {
					$variation->update_meta_data( \BeltoftLicenses\Product::META_MAX, (string) $value );
				} else {
					$variation->update_meta_data( Product::META_PLAN . $key, is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value );
				}
			}

			$variation->save();
		}

		if ( $variations ) {
			\WC_Product_Variable::sync( $product->get_id() );
		}

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Creates a license for a product. With $data['fire'] = false the creation listeners
	 * (beltoft_licenses_license_created) are detached while it is created; the license is the
	 * same normal license.
	 *
	 * @param \WC_Product          $product Product (simple or variable parent).
	 * @param array<string, mixed> $data    Columns for Licenses::create(), plus 'fire'.
	 * @return array<string, mixed>
	 */
	protected function service_license( \WC_Product $product, array $data = array() ): array {
		$fire = ! array_key_exists( 'fire', $data ) || $data['fire'];
		unset( $data['fire'] );

		$data = array_merge(
			array(
				'product_id'      => $product->get_id(),
				'product_slug'    => (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'email'           => 'customer@example.com',
				'user_id'         => self::factory()->user->create(),
				'max_activations' => \BeltoftLicenses\Product::max_activations( $product ),
				'source'          => 'order',
			),
			$data
		);

		global $wp_filter;
		$saved = $wp_filter['beltoft_licenses_license_created'] ?? null;

		if ( ! $fire ) {
			unset( $wp_filter['beltoft_licenses_license_created'] );
		}

		$license = \BeltoftLicenses\Licenses::create( $data );

		if ( ! $fire && null !== $saved ) {
			$wp_filter['beltoft_licenses_license_created'] = $saved;
		}

		$this->assertIsArray( $license, 'The license was created.' );

		return $license;
	}

	/**
	 * Seeds a cluster's capacity cache: every node online, 64 GiB, nothing committed.
	 *
	 * @param string   $cluster Cluster id.
	 * @param string[] $nodes   Node names.
	 */
	protected function seed_capacity( string $cluster = 'lab', array $nodes = array( 'node-a' ) ): void {
		$rows = array();

		foreach ( $nodes as $node ) {
			$rows[] = array(
				'node'   => $node,
				'status' => 'online',
				'maxmem' => 68719476736,
				'mem'    => 0,
			);
		}

		set_transient( 'beltoft_services_proxmox_capacity_' . $cluster, $rows, HOUR_IN_SECONDS );
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
	 * repeatedly, until none is due. Fails the test when an action ends as failed (Action
	 * Scheduler swallows exceptions) or when actions are still due after $max_rounds.
	 *
	 * @param int $max_rounds Rounds before giving up.
	 */
	protected function run_jobs( int $max_rounds = 100 ): void {
		for ( $round = 0; $round < $max_rounds; $round++ ) {
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

				if ( \ActionScheduler_Store::STATUS_FAILED === \ActionScheduler::store()->get_status( $id ) ) {
					$messages = array();
					foreach ( \ActionScheduler::logger()->get_logs( $id ) as $entry ) {
						$messages[] = $entry->get_message();
					}

					$this->fail( sprintf( 'Action %d (%s) failed: %s', $id, \ActionScheduler::store()->fetch_action( $id )->get_hook(), implode( ' | ', $messages ) ) );
				}
			}
		}

		$this->fail( sprintf( 'Jobs were still due after %d rounds.', $max_rounds ) );
	}
}
