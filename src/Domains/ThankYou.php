<?php
/**
 * Next steps on the order received page for DNS hosting of a domain registered elsewhere.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * A transfer order says what happens now (approval at the current registrar, how long, the email when it is done).
 * A DNS hosting order changes nothing for the domain until the customer moves its name servers, so
 * the thank-you page says what to do next: check the records, remove an old DS record, change the
 * name servers. It is printed inside the order details, or on the classic
 * thank-you hook, once. BeltoftServices\ThankYou owns the hooks and prints these blocks inside its "What happens next" area.
 */
final class ThankYou {

	/**
	 * Orders already printed in this request.
	 *
	 * @var array<int, bool>
	 */
	private static array $printed = array();

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
	 * The transfer-in domains of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, bool> Domain => our DNS already serves it for this customer.
	 */
	public static function transfers( \WC_Order $order ): array {
		$domains = array();

		foreach ( $order->get_items() as $item ) {
			$data = $item->get_meta( '_beltoft_services_domain', true );

			if ( is_array( $data ) && 'transfer' === ( $data['mode'] ?? '' ) && ! empty( $data['domain'] ) ) {
				$domains[ (string) $data['domain'] ] = Zone::served_for( strtolower( (string) $data['domain'] ), (int) $order->get_customer_id() );
			}
		}

		return $domains;
	}

	/**
	 * Days a transfer may take, as promised on the order received page.
	 */
	public static function transfer_days(): int {
		/**
		 * How many days the order received page says a domain transfer can take, at most.
		 *
		 * @param int $days Days, 5 by default (the usual time a losing registrar has to respond).
		 */
		return max( 1, (int) apply_filters( 'beltoft_services_transfer_days', 5 ) );
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

		$domains   = self::domains( $order );
		$transfers = self::transfers( $order );

		if ( ! $domains && ! $transfers ) {
			return;
		}

		self::$printed[ $order_id ] = true;

		// One domain on the order: its own page. Several: the list.
		$account_url = \BeltoftServices\Account\Domains::url_for_order( $order_id, array_merge( array_keys( $domains ), array_keys( $transfers ) ) );

		if ( $transfers ) {
			wc_get_template(
				'order/transfer-next-steps.php',
				array(
					'domains'     => $transfers,
					'days'        => self::transfer_days(),
					'account_url' => $account_url,
				),
				'beltoft-services/',
				BELTOFT_SERVICES_PATH . 'templates/'
			);
		}

		if ( ! $domains ) {
			return;
		}

		wc_get_template(
			'order/dns-next-steps.php',
			array(
				'domains'     => $domains,
				'nameservers' => Zone::nameservers(),
				'account_url' => $account_url,
			),
			'beltoft-services/',
			BELTOFT_SERVICES_PATH . 'templates/'
		);
	}
}
