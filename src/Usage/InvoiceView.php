<?php
/**
 * How a usage invoice reads in emails and order views.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Credit\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * Display only: the stored fees, taxes and totals are never touched. A usage invoice is the renewal order of the
 * usage account with one fee line per resource and item (Renewal). WooCommerce prints fee lines among the totals,
 * so here they are shown as item rows instead (name, detail, amount), and the totals are put in the order the
 * customer reads them: subtotal, VAT, credit, what was charged. The intros of the WooCommerce Subscriptions emails
 * for such an order say it is a usage invoice. A usage invoice is recognised by the usage meta of its fee lines.
 */
final class InvoiceView {

	public const META_NAME   = '_beltoft_services_usage_name';
	public const META_DETAIL = '_beltoft_services_usage_detail';

	/**
	 * Whether the email items table being built is plain text (set by woocommerce_email_order_items_args).
	 *
	 * @var bool
	 */
	private static bool $plain = false;

	/**
	 * Templates being rendered, innermost last: template name => order (a usage invoice) or null.
	 *
	 * @var array<int, array{0: string, 1: ?\WC_Order}>
	 */
	private static array $stack = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'totals' ), 20, 2 );
		add_filter( 'woocommerce_email_order_items_args', array( __CLASS__, 'items_args' ), 20 );
		add_filter( 'woocommerce_email_order_items_table', array( __CLASS__, 'email_items' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table_items', array( __CLASS__, 'screen_items' ), 20 );
		add_action( 'woocommerce_before_template_part', array( __CLASS__, 'enter' ), 10, 4 );
		add_action( 'woocommerce_after_template_part', array( __CLASS__, 'leave' ), 10, 1 );
		add_filter( 'gettext', array( __CLASS__, 'intro' ), 20, 3 );
		add_filter( 'gettext_with_context', array( __CLASS__, 'intro_with_context' ), 20, 4 );
	}

	/**
	 * The usage fee lines of an order.
	 *
	 * @param mixed $order Order.
	 * @return array<int, \WC_Order_Item_Fee>
	 */
	public static function fees( $order ): array {
		$fees = array();

		if ( ! $order instanceof \WC_Order ) {
			return $fees;
		}

		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( $fee instanceof \WC_Order_Item_Fee && '' !== (string) $fee->get_meta( '_beltoft_services_usage_item' ) ) {
				$fees[] = $fee;
			}
		}

		return $fees;
	}

	/**
	 * Whether an order is a usage invoice.
	 *
	 * @param mixed $order Order.
	 */
	public static function is_usage( $order ): bool {
		return (bool) self::fees( $order );
	}

	/**
	 * Name and detail of a usage fee line: the meta Renewal stores, else (older orders) the long fee name taken apart.
	 *
	 * @param \WC_Order_Item_Fee $fee Fee line.
	 * @return array{name: string, detail: string}
	 */
	public static function parts( \WC_Order_Item_Fee $fee ): array {
		$name = (string) $fee->get_meta( self::META_NAME );

		if ( '' !== $name ) {
			return array(
				'name'   => $name,
				'detail' => (string) $fee->get_meta( self::META_DETAIL ),
			);
		}

		$full = (string) $fee->get_name();

		// "Server web1, September 2026: 200 hours at €0.0616 per hour (excl. VAT), capped at €55.00 a month".
		if ( preg_match( '/^(.+?), ([^,:]+ \d{4}): (.+?) \(excl\. VAT\)(?:, capped at (.+?) a month)?$/u', $full, $m ) ) {
			$detail = $m[2] . ': ' . $m[3];

			if ( ! empty( $m[4] ) ) {
				/* translators: %s: amount with currency. */
				$detail .= sprintf( __( ', capped at %s', 'beltoft-services' ), $m[4] );
			}

			return array(
				'name'   => $m[1],
				'detail' => $detail,
			);
		}

		return array(
			'name'   => $full,
			'detail' => '',
		);
	}

	/**
	 * The usage rows of an invoice for display.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array{name: string, detail: string, amount: string}>
	 */
	private static function rows( \WC_Order $order ): array {
		$rows = array();

		foreach ( self::fees( $order ) as $fee ) {
			$parts  = self::parts( $fee );
			$rows[] = array(
				'name'   => $parts['name'],
				'detail' => $parts['detail'],
				'amount' => wc_price( (float) $fee->get_total(), array( 'currency' => $order->get_currency() ) ),
			);
		}

		return $rows;
	}

	/**
	 * The credit fee line of an invoice, if credit paid part of it.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function credit_fee( \WC_Order $order ): ?\WC_Order_Item_Fee {
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( $fee instanceof \WC_Order_Item_Fee && 'yes' === $fee->get_meta( Invoice::META_FEE ) ) {
				return $fee;
			}
		}

		return null;
	}

	/**
	 * Totals in reading order: usage subtotal, VAT, credit, then what was charged. The fee lines are not repeated.
	 *
	 * @param mixed $rows  Total rows.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function totals( $rows, $order = null ) {
		if ( ! is_array( $rows ) || ! self::is_usage( $order ) ) {
			return $rows;
		}

		$currency = $order->get_currency();
		$usage    = 0.0;

		foreach ( self::fees( $order ) as $fee ) {
			$usage += (float) $fee->get_total();
		}

		$credit  = self::credit_fee( $order );
		$total   = (float) $order->get_total();
		$subtot  = array(
			'cart_subtotal' => array(
				'type'  => 'subtotal',
				'label' => __( 'Subtotal (excl. VAT):', 'beltoft-services' ),
				'value' => wc_price( $usage, array( 'currency' => $currency ) ),
			),
		);
		$middle  = array();
		$method  = array();
		$final   = null;
		$credit_row = null;

		foreach ( $rows as $key => $row ) {
			$type = (string) ( $row['type'] ?? '' );

			if ( 'subtotal' === $type ) {
				continue;
			}

			if ( 'fee' === $type ) {
				// The usage fees are item rows now; the credit fee is placed after VAT below.
				if ( $credit && 'fee_' . $credit->get_id() === $key ) {
					$credit_row = array( $key, $row );
				} else {
					$item = $order->get_item( (int) substr( (string) $key, 4 ) );

					if ( ! $item instanceof \WC_Order_Item_Fee || '' === (string) $item->get_meta( '_beltoft_services_usage_item' ) ) {
						$middle[ $key ] = $row;
					}
				}
				continue;
			}

			if ( 'total' === $type ) {
				$final = array( $key, $row );
				continue;
			}

			if ( 'payment_method' === $type ) {
				$method[ $key ] = $row;
				continue;
			}

			$middle[ $key ] = $row;
		}

		$out = $subtot + $middle;

		if ( $final ) {
			if ( $total <= 0 && $credit_row ) {
				// Credit paid it all: its row is the last line.
				$row          = $credit_row[1];
				$row['label'] = __( 'Paid from account credit:', 'beltoft-services' );
				$out[ $credit_row[0] ] = $row;
			} else {
				if ( $credit_row ) {
					$out[ $credit_row[0] ] = $credit_row[1];
				}

				$row = $final[1];

				if ( $order->is_paid() && $total > 0 ) {
					$row['label'] = __( 'Charged to your card:', 'beltoft-services' );
				}

				$out[ $final[0] ] = $row;
			}
		} elseif ( $credit_row ) {
			$out[ $credit_row[0] ] = $credit_row[1];
		}

		return $out + $method;
	}

	/**
	 * Remembers whether the email items table is plain text.
	 *
	 * @param mixed $args Template args.
	 * @return mixed
	 */
	public static function items_args( $args ) {
		self::$plain = is_array( $args ) && ! empty( $args['plain_text'] );

		return $args;
	}

	/**
	 * The usage rows after the items of an email.
	 *
	 * @param mixed $html  Items markup (or plain text).
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function email_items( $html, $order = null ) {
		if ( ! is_string( $html ) || ! self::is_usage( $order ) ) {
			return $html;
		}

		$out = '';

		foreach ( self::rows( $order ) as $row ) {
			if ( self::$plain ) {
				$out .= str_pad( $row['name'], 40 ) . ' ' . str_pad( wp_strip_all_tags( $row['amount'] ), 20, ' ', STR_PAD_LEFT ) . "\n";
				$out .= '' !== $row['detail'] ? '  ' . $row['detail'] . "\n" : '';
				continue;
			}

			$out .= '<tr class="order_item"><td class="td font-family text-align-left" colspan="2" style="vertical-align:middle;word-wrap:break-word;">'
				. '<div>' . esc_html( $row['name'] ) . '</div>'
				. ( '' !== $row['detail'] ? '<div style="font-size:13px;line-height:1.4;color:#757575;margin-top:2px;">' . esc_html( $row['detail'] ) . '</div>' : '' )
				. '</td><td class="td font-family text-align-right" style="vertical-align:middle;white-space:nowrap;">' . wp_kses_post( $row['amount'] ) . '</td></tr>';
		}

		return $html . $out;
	}

	/**
	 * Rows under the items of the thank-you page and My Account order view: table rows in the WooCommerce template,
	 * item-style blocks where a Bricks element prints the hook.
	 *
	 * @param mixed $order Order.
	 */
	public static function screen_items( $order ): void {
		if ( ! self::is_usage( $order ) ) {
			return;
		}

		$in_table = self::inside( 'order/order-details.php' );

		foreach ( self::rows( $order ) as $row ) {
			if ( $in_table ) {
				echo '<tr class="woocommerce-table__line-item order_item"><td class="woocommerce-table__product-name product-name">'
					. '<span class="beltoft-services-usage-name">' . esc_html( $row['name'] ) . '</span>'
					. ( '' !== $row['detail'] ? '<br><small class="beltoft-services-usage-detail" style="opacity:.7">' . esc_html( $row['detail'] ) . '</small>' : '' )
					. '</td><td class="woocommerce-table__product-total product-total">' . wp_kses_post( $row['amount'] ) . '</td></tr>';
				continue;
			}

			echo '<div class="beltoft-services-usage-row" style="display:flex;align-self:stretch;justify-content:space-between;align-items:flex-start;column-gap:1.5rem;padding:1rem 0;">'
				. '<div style="display:flex;flex-direction:column;flex:1 1 0;"><span>' . esc_html( $row['name'] ) . '</span>'
				. ( '' !== $row['detail'] ? '<small style="opacity:.7;font-size:1.1rem">' . esc_html( $row['detail'] ) . '</small>' : '' )
				. '</div><div style="font-weight:600;text-align:right;white-space:nowrap;">' . wp_kses_post( $row['amount'] ) . '</div></div>';
		}
	}

	/**
	 * A template starts: remember it and its order.
	 *
	 * @param mixed $name    Template name.
	 * @param mixed $path    Template path.
	 * @param mixed $located Located file.
	 * @param mixed $args    Template args.
	 */
	public static function enter( $name = '', $path = '', $located = '', $args = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$order = is_array( $args ) && isset( $args['order'] ) && self::is_usage( $args['order'] ) ? $args['order'] : null;

		self::$stack[] = array( (string) $name, $order );
	}

	/**
	 * A template ends.
	 *
	 * @param mixed $name Template name.
	 */
	public static function leave( $name = '' ): void {
		for ( $i = count( self::$stack ) - 1; $i >= 0; --$i ) {
			if ( self::$stack[ $i ][0] === (string) $name ) {
				array_splice( self::$stack, $i );
				return;
			}
		}
	}

	/**
	 * Is a template being rendered?
	 *
	 * @param string $name Template name.
	 */
	private static function inside( string $name ): bool {
		foreach ( self::$stack as $entry ) {
			if ( $entry[0] === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The usage invoice an email template is being rendered for, if any.
	 */
	private static function email_order(): ?\WC_Order {
		foreach ( array_reverse( self::$stack ) as $entry ) {
			if ( $entry[1] && 0 === strpos( $entry[0], 'emails/' ) ) {
				return $entry[1];
			}
		}

		return null;
	}

	/**
	 * Intro of the WooCommerce Subscriptions emails (no filter in their templates, so the sentences are swapped).
	 *
	 * @param mixed $text    Translated text.
	 * @param mixed $orig    Original text.
	 * @param mixed $domain  Text domain.
	 * @return mixed
	 */
	public static function intro( $text, $orig = '', $domain = '' ) {
		if ( 'woocommerce-subscriptions' !== $domain || ! self::$stack ) {
			return $text;
		}

		$order = self::email_order();

		if ( ! $order ) {
			return $text;
		}

		$month = Emails::month( $order );

		if ( 'We have finished processing your subscription renewal order.' === $orig ) {
			/* translators: %s: month and year. */
			return sprintf( __( 'Here is your usage invoice for %s.', 'beltoft-services' ), $month );
		}

		if ( 'Just to let you know &mdash; we\'ve received your subscription renewal order #%s, and it is now being processed:' === $orig ) {
			/* translators: %s: month and year. */
			return str_replace( '%', '%%', sprintf( __( 'Here is your usage invoice for %s.', 'beltoft-services' ), $month ) );
		}

		return $text;
	}

	/**
	 * Intro sentences that have a context.
	 *
	 * @param mixed $text    Translated text.
	 * @param mixed $orig    Original text.
	 * @param mixed $context Context.
	 * @param mixed $domain  Text domain.
	 * @return mixed
	 */
	public static function intro_with_context( $text, $orig = '', $context = '', $domain = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( 'woocommerce-subscriptions' !== $domain || ! self::$stack ) {
			return $text;
		}

		$order = self::email_order();

		if ( ! $order ) {
			return $text;
		}

		$month = Emails::month( $order );

		if ( 'You have received a subscription renewal order from %1$s. Their order is as follows:' === $orig ) {
			$name = Emails::name( $order );
			$n    = (string) $order->get_order_number();

			if ( $order->has_status( 'failed' ) ) {
				/* translators: 1: "Usage invoice", 2: order number. %1$s after them is the customer name, kept for printf. */
				$tpl = __( '%1$s #%2$s for %%1$s could not be charged.', 'beltoft-services' );
			} elseif ( $order->is_paid() ) {
				$tpl = __( '%1$s #%2$s for %%1$s was paid.', 'beltoft-services' );
			} else {
				$tpl = __( '%1$s #%2$s for %%1$s was created.', 'beltoft-services' );
			}

			return sprintf( $tpl, $name, $n );
		}

		if ( 'An order has been created for you to renew your subscription on %1$s. To pay for this invoice please use the following link: %2$s' === $orig
			|| 'The automatic payment to renew your subscription with %1$s has failed. To reactivate the subscription, please log in and pay for the renewal from your account page: %2$s' === $orig ) {
			/* translators: %s: month and year. %2$s after it is the pay link, kept for printf. */
			return sprintf( __( 'Your usage invoice for %s could not be charged. Pay it here: %%2$s', 'beltoft-services' ), $month );
		}

		return $text;
	}
}
