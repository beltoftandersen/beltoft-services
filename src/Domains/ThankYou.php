<?php
/**
 * Next steps on the order received page for DNS hosting of a domain registered elsewhere.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * A DNS hosting order changes nothing for the domain until the customer moves its name servers, so
 * the thank-you page says what to do next: check the records, remove an old DS record, change the
 * name servers. It is printed inside the order details, or on the classic
 * thank-you hook, once.
 */
final class ThankYou {

	/**
	 * Orders already printed in this request.
	 *
	 * @var array<int, bool>
	 */
	private static array $printed = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// Inside the order details, under the thank-you heading (also where page builders print it).
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'render_details' ), 5 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render' ), 5 );
	}

	/**
	 * The DNS hosting domains of an order: domain => parent DS present (true, false or null when unknown).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, bool|null>
	 */
	public static function domains( \WC_Order $order ): array {
		$domains = array();

		foreach ( $order->get_items() as $item ) {
			$data = $item->get_meta( '_beltoft_services_domain', true );

			if ( ! is_array( $data ) || 'dns' !== ( $data['mode'] ?? '' ) || empty( $data['domain'] ) ) {
				continue;
			}

			$claim = ! empty( $data['claim_id'] ) ? DnsClaims::get( (int) $data['claim_id'] ) : null;
			$ds    = is_array( $claim ) && null !== ( $claim['ds_present'] ?? null ) ? (bool) $claim['ds_present'] : null;

			$domains[ (string) $data['domain'] ] = $ds;
		}

		return $domains;
	}

	/**
	 * The order details hook: only on the order received page, not when an order is viewed later.
	 *
	 * @param mixed $order Order.
	 */
	public static function render_details( $order ): void {
		if ( $order instanceof \WC_Order && function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			self::render( $order->get_id() );
		}
	}

	/**
	 * Prints the next steps for an order with DNS hosting.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function render( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || isset( self::$printed[ $order_id ] ) || $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			return;
		}

		$domains = self::domains( $order );

		if ( ! $domains ) {
			return;
		}

		self::$printed[ $order_id ] = true;

		wc_get_template(
			'order/dns-next-steps.php',
			array(
				'domains'     => $domains,
				'nameservers' => Zone::nameservers(),
				'account_url' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'domains' ) : '',
			),
			'beltoft-services/',
			BELTOFT_SERVICES_PATH . 'templates/'
		);
	}
}
