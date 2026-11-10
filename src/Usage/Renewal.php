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
	 * Set by Threshold while it builds an early bill (a carried one is then dropped, see Minimum).
	 *
	 * @var bool
	 */
	public static $early = false;

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
		if ( \BeltoftServices\Apps\AppLicenceDriver::ID === $resource->get( 'driver' ) ) {
			$server = \BeltoftServices\Apps\AppLicence::server_of( $resource );
			$name   = $server ? (string) $server->get( 'server_name' ) : '';
			$label  = $product ? $product->get_name() : '#' . $resource_id;

			return '' !== $name ? sprintf( '%1$s (%2$s)', $name, $label ) : $label;
		}

		// An extra disk is shown with its server ("Extra disk for web1"): its own product name says nothing to the customer.
		if ( \BeltoftServices\Proxmox\Disk\DiskDriver::ID === $resource->get( 'driver' ) ) {
			$server = Resource::for_license( (int) $resource->get( 'server_license' ) );
			$name   = $server ? (string) $server->get( 'server_name' ) : '';
			$parent = $server ? wc_get_product( (int) ( $server->license()['product_id'] ?? 0 ) ) : null;

			return '' !== $name ? $name : ( $parent ? $parent->get_name() : ( $product ? $product->get_name() : '#' . $resource_id ) );
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
			'database'  => __( 'database', 'beltoft-services' ),
			'backups'   => __( 'backups', 'beltoft-services' ),
			'disk'      => __( 'extra disk', 'beltoft-services' ),
			'snapshots' => __( 'snapshot slots', 'beltoft-services' ),
			'app'       => __( 'app licence', 'beltoft-services' ),
		);

		return $labels[ $item ] ?? $item;
	}

	/**
	 * A billed quantity for the customer: up to four decimals, no trailing zeros, in the site's number format.
	 *
	 * @param float $quantity Quantity.
	 */
	private static function quantity_text( float $quantity ): string {
		$plain    = rtrim( rtrim( number_format( $quantity, 4, '.', '' ), '0' ), '.' );
		$position = strpos( $plain, '.' );

		return number_format_i18n( $quantity, false === $position ? 0 : strlen( $plain ) - $position - 1 );
	}

	/**
	 * "1 hour", "600 hours": the count in the site's number format, the word by plural form.
	 *
	 * @param int $hours Hours.
	 */
	private static function hours_text( int $hours ): string {
		/* translators: %s: number of hours. */
		return sprintf( _n( '%s hour', '%s hours', $hours, 'beltoft-services' ), number_format_i18n( $hours ) );
	}

	/**
	 * The name of a billing unit, translated, for "per %s and hour".
	 *
	 * @param string $unit Unit as stored (hour, GB, pack).
	 */
	private static function unit_name( string $unit ): string {
		switch ( $unit ) {
			case 'hour':
				return __( 'hour', 'beltoft-services' );
			case 'GB':
				return _x( 'GB', 'unit symbol for gigabyte', 'beltoft-services' );
			case 'pack':
				return _x( 'pack', 'unit name, singular, as in "per pack and hour"', 'beltoft-services' );
		}

		return $unit;
	}

	/**
	 * The quantity of a line as a note: " (2.5 GB)", " (2 packs)", empty for hours.
	 *
	 * @param float  $quantity Quantity.
	 * @param string $unit     Unit as stored.
	 */
	private static function quantity_note( float $quantity, string $unit ): string {
		if ( 'hour' === $unit ) {
			return '';
		}

		$text = self::quantity_text( $quantity );

		if ( 'pack' === $unit ) {
			$count = floor( $quantity ) === $quantity ? (int) $quantity : 2;

			/* translators: %s: number of snapshot packs. */
			return ' (' . sprintf( _n( '%s pack', '%s packs', $count, 'beltoft-services' ), $text ) . ')';
		}

		return ' (' . sprintf( /* translators: 1: quantity, 2: unit symbol (GB). */ _x( '%1$s %2$s', 'quantity with unit', 'beltoft-services' ), $text, self::unit_name( $unit ) ) . ')';
	}

	/**
	 * What a usage fee line is about, in words for the customer: "Server web1", "Backups for web1".
	 *
	 * @param string $item  Item.
	 * @param string $label Server or app name (label()).
	 */
	public static function line_subject( string $item, string $label ): string {
		if ( 'server' === $item ) {
			/* translators: %s: server name. */
			return sprintf( __( 'Server %s', 'beltoft-services' ), $label );
		}

		if ( 'database' === $item ) {
			/* translators: %s: database name. */
			return sprintf( __( 'Database %s', 'beltoft-services' ), $label );
		}

		$words = array(
			'backups'   => __( 'Backups', 'beltoft-services' ),
			'disk'      => __( 'Extra disk', 'beltoft-services' ),
			'snapshots' => __( 'Snapshot slots', 'beltoft-services' ),
			'app'       => __( 'App licence', 'beltoft-services' ),
		);

		/* translators: 1: item (Backups, Extra disk), 2: server name. */
		return sprintf( __( '%1$s for %2$s', 'beltoft-services' ), $words[ $item ] ?? $item, $label );
	}

	/**
	 * The renewal order of a usage account gets its usage lines.
	 *
	 * @param mixed $order        Renewal order.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public static function filter( $order, $subscription ) {
		if ( ! $order instanceof \WC_Order || ! Account::is_account( $subscription ) || '' !== (string) $order->get_meta( self::META_LINES ) || '' !== (string) $order->get_meta( Minimum::META_ORDER ) ) {
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
			$per   = 'hour' === $line['unit'] ? self::unit_name( 'hour' ) : sprintf( /* translators: %s: unit (GB, pack). */ __( '%s and hour', 'beltoft-services' ), self::unit_name( (string) $line['unit'] ) );
			$qty   = self::quantity_note( (float) $line['quantity'], (string) $line['unit'] );
			$hours = self::hours_text( (int) $line['hours'] );
			$subject = self::line_subject( (string) $line['item'], self::label( $line['resource_id'] ) );
			$month   = wp_date( 'F Y', (int) $line['month'], new \DateTimeZone( 'UTC' ) );
			$rate    = Rates::money( (float) $line['rate'], 4, $currency );
			$fee->set_name(
				sprintf(
					/* translators: 1: what is billed ("Server web1"), 2: month and year, 3: number of hours with the word ("600 hours"), 4: quantity note (" (2.5 GB)" or empty), 5: rate with currency, 6: unit, 7: cap note. */
					__( '%1$s, %2$s: %3$s%4$s at %5$s per %6$s (excl. VAT)%7$s', 'beltoft-services' ),
					$subject,
					$month,
					$hours,
					$qty,
					$rate,
					$per,
					$line['capped'] ? sprintf( /* translators: %s: amount with currency. */ __( ', capped at %s a month', 'beltoft-services' ), Rates::money( (float) $line['cap'], 2, $currency ) ) : ''
				)
			);
			// What the invoice views show as name and detail (InvoiceView), so they need not take the long name apart.
			$fee->add_meta_data( InvoiceView::META_NAME, $subject, true );
			$fee->add_meta_data(
				InvoiceView::META_DETAIL,
				sprintf(
					/* translators: 1: month and year, 2: number of hours with the word ("600 hours"), 3: quantity note (" (2.5 GB)" or empty), 4: rate with currency, 5: unit, 6: cap note. */
					__( '%1$s: %2$s%3$s at %4$s per %5$s%6$s', 'beltoft-services' ),
					$month,
					$hours,
					$qty,
					$rate,
					$per,
					$line['capped'] ? sprintf( /* translators: %s: amount with currency. */ __( ', capped at %s', 'beltoft-services' ), Rates::money( (float) $line['cap'], 2, $currency ) ) : ''
				),
				true
			);
			$fee->set_total( (string) $line['charge'] );
			$fee->set_tax_status( 'taxable' );
			$fee->set_tax_class( '' );
			$fee->add_meta_data( '_beltoft_services_usage_resource', $line['resource_id'], true );
			$fee->add_meta_data( '_beltoft_services_usage_item', $line['item'], true );
			$fee->add_meta_data( '_beltoft_services_usage_hours', $line['hours'], true );
			$order->add_item( $fee );
		}

		$order->calculate_totals( true );

		// A month whose card amount is under the minimum is carried: the lines go, the rows wait for the next renewal.
		if ( Minimum::carries( \BeltoftServices\Credit\Balance::cents( $order->get_total() ), (int) $subscription->get_customer_id() ) ) {
			Minimum::carry( $order, $subscription, self::$early );
			$order->save();

			return $order;
		}

		Minimum::clear( $subscription );
		$order->update_meta_data( self::META_LINES, 'yes' );
		$order->update_meta_data( self::META_BILLED, (string) $statement['total'] );
		// The latest month among the lines (Unix time of its first hour), for the emails.
		$order->update_meta_data( self::META_PERIOD, (string) max( array_column( $statement['lines'], 'month' ) ) );
		// Credit pays after the usage lines and taxes are final.
		\BeltoftServices\Credit\Invoice::apply( $order, (int) $subscription->get_customer_id() );
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
