<?php
/**
 * Order received: the hand-off screen. A theme can override this file as beltoft-services/order/handoff.php.
 *
 * Used in place of checkout/thankyou.php for a paid order that has a place to go (BeltoftServices\OrderHandoff).
 * assets/handoff.js waits for the purchase event and then moves the browser to the address in data-next. Without
 * JavaScript the normal "What happens next" area and a link are shown.
 *
 * @package BeltoftServices
 *
 * @var \WC_Order $order The order (a variable of WooCommerce's thank-you template).
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_next = BeltoftServices\OrderHandoff::target( $order );

ob_start();
BeltoftServices\ThankYou::render( $order->get_id() );
$beltoft_services_area = (string) ob_get_clean();

// The swap replaced WooCommerce's thank-you template, which fires the two hooks below (the payment gateway's and the general one:
// tag managers and gateways listen to them). Fire them here, inside a hidden wrapper, so they still run. Not when the shortcode
// placed this screen: WooCommerce's own content then stays on the page and fires them itself.
$beltoft_services_hooks = '';

if ( ! BeltoftServices\OrderHandoff::placed() ) {
	ob_start();
	do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
	do_action( 'woocommerce_thankyou', $order->get_id() );
	$beltoft_services_hooks = (string) ob_get_clean();
}
?>
<section class="bs-handoff" data-bs-handoff data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-next="<?php echo esc_url( $beltoft_services_next ); ?>" data-track="<?php echo BeltoftServices\OrderHandoff::tracking() ? '1' : '0'; ?>" aria-live="polite">
	<h2 class="bs-handoff__title"><?php esc_html_e( 'Setting up your order', 'beltoft-services' ); ?></h2>
	<p class="bs-handoff__text"><?php esc_html_e( 'This takes a moment. We take you to your dashboard.', 'beltoft-services' ); ?></p>
	<div class="bs-loader is-busy bs-handoff__loader" role="status">
		<span class="bs-loader__text"><?php esc_html_e( 'Setting up your order', 'beltoft-services' ); ?></span>
		<span class="bs-loader__bar"><i></i></span>
	</div>
	<p class="bs-handoff__skip"><a href="<?php echo esc_url( $beltoft_services_next ); ?>" data-bs-handoff-skip><?php esc_html_e( 'Go to your dashboard now', 'beltoft-services' ); ?></a></p>
	<div hidden data-bs-handoff-hooks><?php echo $beltoft_services_hooks; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the output of WooCommerce's own thank-you hooks, kept hidden. ?></div>
	<noscript>
		<?php echo $beltoft_services_area; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printed by the plugin's own, escaped templates. ?>
	</noscript>
</section>
