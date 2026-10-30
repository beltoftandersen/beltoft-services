<?php
/**
 * Subject and heading of the WooCommerce Subscriptions renewal emails for a usage invoice.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * A usage charge is a renewal of the usage account, so WooCommerce Subscriptions sends its renewal emails.
 * Their words do not fit metered usage: for an order that holds usage lines (Renewal::META_LINES) the subject
 * and heading change, and the body stays WooCommerce's order table. Other renewals are left alone.
 */
final class Emails {

	/**
	 * Hooks. The admin email and the two customer receipts use the filters every WooCommerce email has
	 * (woocommerce_email_subject_{id}, woocommerce_email_heading_{id}); the customer invoice builds its subject and
	 * heading itself, so it has its own filters in WooCommerce Subscriptions.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_subject_new_renewal_order', array( __CLASS__, 'admin_subject' ), 20, 2 );
		add_filter( 'woocommerce_email_heading_new_renewal_order', array( __CLASS__, 'admin_heading' ), 20, 2 );
		add_filter( 'woocommerce_subscriptions_email_subject_customer_processing_renewal_order', array( __CLASS__, 'customer_subject' ), 20, 2 );
		add_filter( 'woocommerce_subscriptions_email_subject_customer_completed_renewal_order', array( __CLASS__, 'customer_subject' ), 20, 2 );
		add_filter( 'woocommerce_subscriptions_email_subject_new_renewal_order', array( __CLASS__, 'customer_subject' ), 20, 2 );
		// One heading filter for the customer's processing, completed and invoice emails.
		add_filter( 'woocommerce_email_heading_customer_renewal_order', array( __CLASS__, 'customer_heading' ), 20, 2 );
	}

	/**
	 * Whether an order is a usage invoice.
	 *
	 * @param mixed $order Order.
	 */
	public static function is_usage( $order ): bool {
		return $order instanceof \WC_Order && '' !== (string) $order->get_meta( Renewal::META_LINES );
	}

	/**
	 * The month the invoice is for, as "October 2026": the latest month among its lines.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function month( \WC_Order $order ): string {
		$start = (int) $order->get_meta( Renewal::META_PERIOD );

		if ( $start <= 0 ) {
			$start = (int) $order->get_date_created()?->getTimestamp();
		}

		return wp_date( 'F Y', $start > 0 ? $start : time(), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * "Usage invoice" or "Usage invoice (early)".
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function name( \WC_Order $order ): string {
		return '' !== (string) $order->get_meta( Renewal::META_EARLY )
			? __( 'Usage invoice (early)', 'beltoft-services' )
			: __( 'Usage invoice', 'beltoft-services' );
	}

	/**
	 * Admin subject.
	 *
	 * @param mixed $subject Subject.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public static function admin_subject( $subject, $order ) {
		if ( ! self::is_usage( $order ) ) {
			return $subject;
		}

		/* translators: 1: "Usage invoice" or "Usage invoice (early)", 2: order number, 3: month and year. */
		return sprintf( __( '%1$s #%2$s for %3$s', 'beltoft-services' ), self::name( $order ), $order->get_order_number(), self::month( $order ) );
	}

	/**
	 * Admin heading.
	 *
	 * @param mixed $heading Heading.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public static function admin_heading( $heading, $order ) {
		return self::is_usage( $order ) ? self::name( $order ) : $heading;
	}

	/**
	 * Customer subject: the invoice to pay or the paid one.
	 *
	 * @param mixed $subject Subject.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public static function customer_subject( $subject, $order ) {
		if ( ! self::is_usage( $order ) ) {
			return $subject;
		}

		$name  = self::name( $order );
		$month = self::month( $order );

		if ( $order->needs_payment() ) {
			/* translators: 1: "Usage invoice" or "Usage invoice (early)", 2: month and year. */
			return sprintf( __( 'Your %1$s for %2$s needs payment', 'beltoft-services' ), lcfirst( $name ), $month );
		}

		/* translators: 1: "Usage invoice" or "Usage invoice (early)", 2: month and year. */
		return sprintf( __( 'Your %1$s for %2$s', 'beltoft-services' ), lcfirst( $name ), $month );
	}

	/**
	 * Customer heading.
	 *
	 * @param mixed $heading Heading.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public static function customer_heading( $heading, $order ) {
		if ( ! self::is_usage( $order ) ) {
			return $heading;
		}

		if ( $order->needs_payment() ) {
			return __( 'Usage invoice needs payment', 'beltoft-services' );
		}

		/* translators: 1: "Usage invoice" or "Usage invoice (early)", 2: month and year. */
		return sprintf( __( '%1$s for %2$s', 'beltoft-services' ), self::name( $order ), self::month( $order ) );
	}
}
