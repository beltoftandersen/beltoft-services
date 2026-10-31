<?php
/**
 * The usage account's renewal order: one line per resource and item for the closed period.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions creates the renewal order of the usage account (wcs_create_renewal_order()) and
 * runs the filter `wcs_renewal_order_created`. This class adds one fee line per resource, item and month to
 * the order, next to the account's 0 line (name, hours x rate, capped at the monthly cap), recalculates taxes and
 * totals and claims the ledger rows for the order. WooCommerce Subscriptions then charges get_total()
 * through the gateway. With no unbilled rows the 0 renewal stays as it is. Rows an order holds are never
 * billed again; a cancelled order gives them back.
 */
final class Renewal {

	public const META_LINES  = '_beltoft_services_usage_lines';
	public const META_BILLED = '_beltoft_services_usage_billed';
	public const META_PERIOD = '_beltoft_services_usage_period';
	public const META_EARLY  = '_beltoft_services_usage_early';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wcs_renewal_order_created', array( __CLASS__, 'filter' ), 20, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancelled' ) );
	}

	/**
	 * The statement of ledger rows: one entry per resource, item and UTC month. The cap is the most one
	 * resource's item costs in a month (the largest hourly amount x 730), less what earlier orders took
	 * in that month.
	 *
	 * @param array<int, array<string, mixed>> $rows   Ledger rows.
	 * @param int                              $except An order whose rows do not count as billed before.
	 * @return array{lines: array<int, array<string, mixed>>, total: float}
	 */
	public static function statement( array $rows, int $except = 0 ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			$start = (int) strtotime( gmdate( 'Y-m-01 00:00:00', (int) strtotime( $row['hour_bucket'] . ' UTC' ) ) . ' UTC' );
			$key   = $row['resource_id'] . '|' . $row['item'] . '|' . $start;

			$groups[ $key ]['resource_id'] = (int) $row['resource_id'];
			$groups[ $key ]['item']        = (string) $row['item'];
			$groups[ $key ]['unit']        = (string) $row['unit'];
			$groups[ $key ]['quantity']    = (float) $row['quantity'];
			$groups[ $key ]['rate']        = (float) $row['rate'];
			$groups[ $key ]['month']       = $start;
			$groups[ $key ]['hours']       = ( $groups[ $key ]['hours'] ?? 0 ) + 1;
			$groups[ $key ]['raw']         = ( $groups[ $key ]['raw'] ?? 0.0 ) + (float) $row['quantity'] * (float) $row['rate'];
			$groups[ $key ]['peak']        = max( $groups[ $key ]['peak'] ?? 0.0, (float) $row['quantity'] * (float) $row['rate'] );
		}

		$lines = array();
		$total = 0.0;

		foreach ( $groups as $group ) {
			$end    = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $group['month'] + 32 * DAY_IN_SECONDS ) . ' UTC' );
			$cap    = round( $group['peak'] * Rates::HOURS, 2 );
			$prior  = min( $cap, Ledger::billed( $group['resource_id'], $group['item'], $group['month'], $end, $except ) );
			$room   = max( 0.0, $cap - $prior );
			$raw    = round( $group['raw'], 4 );
			$charge = round( min( $raw, $room ), 2 );

			$group['cap']    = $cap;
			$group['raw']    = $raw;
			$group['charge'] = $charge;
			$group['capped'] = $raw > $room;
			$lines[]         = $group;
			$total          += $charge;
		}

		return array(
			'lines' => $lines,
			'total' => round( $total, 2 ),
		);
	}

	/**
	 * What a resource is called on an invoice: the customer's server name, else the product name.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function label( int $resource_id ): string {
		$resource = Resource::find( $resource_id );

		if ( ! $resource ) {
			return '#' . $resource_id;
		}

		$name    = (string) $resource->get( 'server_name' );
		$product = wc_get_product( (int) ( $resource->license()['product_id'] ?? 0 ) );

		// The licence of an app is shown with its server: "web1 (Plesk)".
		if ( \BeltoftServices\Proxmox\AppLicenceDriver::ID === $resource->get( 'driver' ) ) {
			$server = \BeltoftServices\Proxmox\AppLicence::server_of( $resource );
			$name   = $server ? (string) $server->get( 'server_name' ) : '';
			$label  = $product ? $product->get_name() : '#' . $resource_id;

			return '' !== $name ? sprintf( '%1$s (%2$s)', $name, $label ) : $label;
		}

		if ( '' !== $name ) {
			return $name;
		}

		return $product ? $product->get_name() : '#' . $resource_id;
	}

	/**
	 * The words for an item on an invoice.
	 *
	 * @param string $item Item.
	 */
	public static function item_label( string $item ): string {
		$labels = array(
			'server'    => __( 'server', 'beltoft-services' ),
			'backups'   => __( 'backups', 'beltoft-services' ),
			'disk'      => __( 'extra disk', 'beltoft-services' ),
			'snapshots' => __( 'snapshot slots', 'beltoft-services' ),
			'app'       => __( 'app licence', 'beltoft-services' ),
		);

		return $labels[ $item ] ?? $item;
	}

	/**
	 * The renewal order of a usage account gets its usage lines.
	 *
	 * @param mixed $order        Renewal order.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public static function filter( $order, $subscription ) {
		if ( ! $order instanceof \WC_Order || ! Account::is_account( $subscription ) || '' !== (string) $order->get_meta( self::META_LINES ) ) {
			return $order;
		}

		$rows = Ledger::unbilled( (int) $subscription->get_customer_id(), Ledger::hour( time() ) );

		if ( ! $rows ) {
			return $order;
		}

		$claimed = Ledger::claim( array_column( $rows, 'id' ), (int) $order->get_id() );

		if ( ! $claimed ) {
			return $order;
		}

		$statement  = self::statement( $claimed, (int) $order->get_id() );
		// The account's 0 product line stays: WooCommerce Subscriptions builds the order-pay cart of a renewal from
		// the order's product lines, so without it a failed usage invoice cannot be paid ("Your checkout is empty").
		// The fee lines below travel into that cart with it (WCS_Cart_Renewal::maybe_add_fees()).
		$currency = $order->get_currency();

		foreach ( $statement['lines'] as $line ) {
			$fee = new \WC_Order_Item_Fee();
			$per = 'hour' === $line['unit'] ? __( 'hour', 'beltoft-services' ) : sprintf( /* translators: %s: unit (GB, pack). */ __( '%s and hour', 'beltoft-services' ), $line['unit'] );
			$qty = 'hour' === $line['unit'] ? '' : sprintf( ' (%s %s)', rtrim( rtrim( number_format( $line['quantity'], 4, '.', '' ), '0' ), '.' ), $line['unit'] );
			$fee->set_name(
				sprintf(
					/* translators: 1: server name, 2: item, 3: month (2026-10), 4: hours, 5: quantity note, 6: rate with currency, 7: unit, 8: cap note. */
					__( '%1$s, %2$s, %3$s: %4$s hours%5$s at %6$s per %7$s%8$s', 'beltoft-services' ),
					self::label( $line['resource_id'] ),
					self::item_label( $line['item'] ),
					gmdate( 'Y-m', $line['month'] ),
					(string) $line['hours'],
					$qty,
					$currency . ' ' . rtrim( rtrim( number_format( $line['rate'], 8, '.', '' ), '0' ), '.' ),
					$per,
					$line['capped'] ? sprintf( /* translators: %s: amount with currency. */ __( ', capped at %s a month', 'beltoft-services' ), $currency . ' ' . number_format( $line['cap'], 2, '.', '' ) ) : ''
				)
			);
			$fee->set_total( (string) $line['charge'] );
			$fee->set_tax_status( 'taxable' );
			$fee->set_tax_class( '' );
			$fee->add_meta_data( '_beltoft_services_usage_resource', $line['resource_id'], true );
			$fee->add_meta_data( '_beltoft_services_usage_item', $line['item'], true );
			$fee->add_meta_data( '_beltoft_services_usage_hours', $line['hours'], true );
			$order->add_item( $fee );
		}

		$order->update_meta_data( self::META_LINES, 'yes' );
		$order->update_meta_data( self::META_BILLED, (string) $statement['total'] );
		// The latest month among the lines (Unix time of its first hour), for the emails.
		$order->update_meta_data( self::META_PERIOD, (string) max( array_column( $statement['lines'], 'month' ) ) );
		$order->calculate_totals( true );
		$order->save();

		return $order;
	}

	/**
	 * A cancelled renewal order gives its usage rows back.
	 *
	 * @param int $order_id Order.
	 */
	public static function cancelled( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof \WC_Order && '' !== (string) $order->get_meta( self::META_LINES ) ) {
			Ledger::release( (int) $order_id );
			$order->delete_meta_data( self::META_LINES );
			$order->save();
		}
	}
}
