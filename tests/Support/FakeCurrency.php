<?php
/**
 * A second shop currency for tests, with no add-on behind it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

/**
 * Simulates what a multi-currency site does, through the plugin's own filters and WooCommerce's:
 * the current currency, stored prices per currency (empty when missing), a currency runner, the currency
 * of a customer and the amount settings of a currency. Call fake_currency_down() in tear_down().
 */
trait FakeCurrency {

	/** @var string Current fake currency, '' for none. */
	private string $fake_current = '';

	/** @var array<int, array{0: string, 1: callable, 2: int}> Filters this trait added. */
	private array $fake_filters = array();

	/**
	 * Starts the fake: the shop currency filter follows fake_set_currency() and prices read per currency.
	 *
	 * @param string $current Currency code to start in.
	 */
	protected function fake_currency_up( string $current ): void {
		$this->fake_current = $current;

		$this->fake_filter(
			'woocommerce_currency',
			function ( $code ) {
				return '' !== $this->fake_current ? $this->fake_current : $code;
			},
			1
		);

		$price = function ( $value, $product ) {
			$base = (string) get_option( 'woocommerce_currency' );

			if ( '' === $this->fake_current || $this->fake_current === $base ) {
				return $value;
			}

			return (string) get_post_meta( $product->get_id(), '_test_price_' . $this->fake_current, true );
		};

		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_variation_get_regular_price' ) as $hook ) {
			$this->fake_filter( $hook, $price, 10, 2 );
		}
	}

	/**
	 * Sets the current currency.
	 *
	 * @param string $code Code.
	 */
	protected function fake_set_currency( string $code ): void {
		$this->fake_current = $code;
	}

	/**
	 * Stores a price of a product in a currency.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $code       Currency code.
	 * @param string $price      Price.
	 */
	protected function fake_price( int $product_id, string $code, string $price ): void {
		update_post_meta( $product_id, '_test_price_' . $code, $price );
	}

	/**
	 * Adds a currency runner that forces the code for the callback and restores it.
	 *
	 * @param bool $honour False: a runner that quietly forces nothing (a code that is not configured).
	 */
	protected function fake_runner( bool $honour = true ): void {
		$this->fake_filter(
			'beltoft_services_currency_runner',
			function () use ( $honour ) {
				return function ( string $code, callable $fn ) use ( $honour ) {
					$before = $this->fake_current;

					if ( $honour ) {
						$this->fake_current = $code;
					}

					try {
						return $fn();
					} finally {
						$this->fake_current = $before;
					}
				};
			}
		);
	}

	/**
	 * Answers the currency of customers.
	 *
	 * @param array<int, string> $map User id => code.
	 */
	protected function fake_user_currencies( array $map ): void {
		$this->fake_filter(
			'beltoft_services_user_currency',
			function ( $code, $user_id ) use ( $map ) {
				return $map[ (int) $user_id ] ?? $code;
			},
			10,
			2
		);
	}

	/**
	 * Answers the amount settings of currencies.
	 *
	 * @param array<string, array<string, mixed>> $map Currency => key => amount.
	 */
	protected function fake_amounts( array $map ): void {
		$this->fake_filter(
			'beltoft_services_amount_setting',
			function ( $value, $key, $currency ) use ( $map ) {
				return $map[ $currency ][ $key ] ?? $value;
			},
			10,
			3
		);
	}

	/** Removes every filter this trait added. */
	protected function fake_currency_down(): void {
		foreach ( $this->fake_filters as $f ) {
			remove_filter( $f[0], $f[1], $f[2] );
		}

		$this->fake_filters = array();
		$this->fake_current = '';
	}

	/**
	 * Adds and remembers a filter.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Argument count.
	 */
	private function fake_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->fake_filters[] = array( $hook, $callback, $priority );
	}
}
