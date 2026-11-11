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
		// The subject we build replaces the one WooCommerce Subscriptions got through woocommerce_email_subject_{id}, so it goes through that
		// filter again: a language plugin that translates emails by id sees the text the customer will get.
		foreach ( array( 'customer_processing_renewal_order', 'customer_completed_renewal_order' ) as $id ) {
			add_filter(
				'woocommerce_subscriptions_email_subject_' . $id,
				static fn( $subject, $order = null ) => self::filtered( 'subject', $id, self::customer_subject( $subject, $order ), $order ),
				20,
				2
			);
		}
		// The customer invoice email (to pay, or its paid variant) builds its subject through this hook.
		add_filter(
			'woocommerce_subscriptions_email_subject_new_renewal_order',
			static fn( $subject, $order = null ) => self::filtered( 'subject', 'customer_renewal_invoice', self::customer_subject( $subject, $order ), $order ),
			20,
			2
		);
		// One heading filter for the customer's processing, completed and invoice emails.
		add_filter( 'woocommerce_email_heading_customer_renewal_order', array( __CLASS__, 'customer_heading' ), 20, 2 );
	}

	/**
	 * Passes a subject or heading of a usage invoice email through the filter every WooCommerce email has, woocommerce_email_subject_{id} or
	 * woocommerce_email_heading_{id}, with the email object, when the order is a usage invoice (the text was changed by this class).
	 *
	 * @param string $kind  'subject' or 'heading'.
	 * @param string $id    Email id.
	 * @param mixed  $text  Text.
	 * @param mixed  $order Order.
	 * @return mixed
	 */
	private static function filtered( string $kind, string $id, $text, $order ) {
		if ( ! self::is_usage( $order ) || ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return $text;
		}

		$email = null;

		foreach ( WC()->mailer()->get_emails() as $candidate ) {
			if ( $candidate->id === $id ) {
				$email = $candidate;
				break;
			}
		}

		return apply_filters( 'woocommerce_email_' . $kind . '_' . $id, $text, $order, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
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
	public static function name( \WC_Order $order ): string {
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

		// Which email this is, for its own filter: the invoice to pay, or the receipt of a processing or completed order.
		$id = $order->needs_payment() ? 'customer_renewal_invoice' : ( $order->has_status( 'completed' ) ? 'customer_completed_renewal_order' : 'customer_processing_renewal_order' );

		if ( $order->needs_payment() ) {
			return self::filtered( 'heading', $id, __( 'Usage invoice needs payment', 'beltoft-services' ), $order );
		}

		/* translators: 1: "Usage invoice" or "Usage invoice (early)", 2: month and year. */
		return self::filtered( 'heading', $id, sprintf( __( '%1$s for %2$s', 'beltoft-services' ), self::name( $order ), self::month( $order ) ), $order );
	}
}
