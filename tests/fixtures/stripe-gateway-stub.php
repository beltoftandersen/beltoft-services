<?php
/**
 * A stand-in for the Stripe plugin's gateway class, with the shape Card::token_fingerprint() uses:
 * WC_Stripe_Gateway::load()->mode( $order )->paymentMethods->retrieve( $id ).
 *
 * @package BeltoftServices
 */

// phpcs:disable

if ( ! class_exists( 'WC_Stripe_Gateway' ) ) {
	class WC_Stripe_Gateway {

		/** @var array<string, string> Fingerprint by payment method id. */
		public static $cards = array();

		/** @var object */
		public $paymentMethods;

		public static function load() {
			$client                 = new self();
			$client->paymentMethods = new class() {
				public function retrieve( $id ) {
					if ( ! isset( \WC_Stripe_Gateway::$cards[ $id ] ) ) {
						return new \WP_Error( 'resource_missing', 'No such payment method' );
					}

					return (object) array( 'card' => (object) array( 'fingerprint' => \WC_Stripe_Gateway::$cards[ $id ] ) );
				}
			};

			return $client;
		}

		public function mode( $mode ) {
			return $this;
		}
	}
}
