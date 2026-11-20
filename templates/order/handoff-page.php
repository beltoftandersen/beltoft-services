<?php
/**
 * Order received, whole page: used when the theme draws the order received page itself (a page builder's own
 * thank-you element never loads checkout/thankyou.php). It keeps the site's header and footer (wp_head runs, so the
 * purchase event of the analytics plugin is printed as on the normal page) and prints the hand-off screen between them.
 * A theme can override this file as beltoft-services/order/handoff-page.php.
 *
 * @package BeltoftServices
 *
 * @var \WC_Order $order The order (set by BeltoftServices\OrderHandoff::page()).
 */

defined( 'ABSPATH' ) || exit;

$order = BeltoftServices\OrderHandoff::page_order(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the template's own variable, as in WooCommerce's thank-you template.

get_header();
?>
<main id="brx-content" class="bs-handoff-page">
	<div class="woocommerce">
		<?php
		if ( $order instanceof \WC_Order ) {
			include __DIR__ . '/handoff.php';
		}
		?>
	</div>
</main>
<?php
get_footer();
