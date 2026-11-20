<?php
/**
 * Order received: what happens next. A theme can override this file as beltoft-services/order/next.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $rows          The order's services (BeltoftServices\ThankYou::row()).
 * @var string                           $domain_blocks The DNS hosting and transfer blocks, already printed HTML.
 * @var string                           $poll_url      The order progress route with the order key.
 * @var string                           $dashboard_url Where "Go to your dashboard" leads, '' for no button.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-next" aria-labelledby="beltoft-next-title">
	<h2 class="beltoft-next__title" id="beltoft-next-title"><?php esc_html_e( 'What happens next', 'beltoft-services' ); ?></h2>
	<?php if ( $rows ) : ?>
		<div class="beltoft-next__block beltoft-next__services" data-beltoft-services-order data-progress-url="<?php echo esc_url( $poll_url ); ?>">
			<h3><?php esc_html_e( 'Your services', 'beltoft-services' ); ?></h3>
			<ul class="beltoft-service-list">
				<?php foreach ( $rows as $beltoft_row ) : ?>
					<?php wc_get_template( 'order/service-row.php', array( 'beltoft_row' => $beltoft_row, 'flow' => false ), 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' ); ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $dashboard_url ) ) : ?>
				<p class="beltoft-next__dashboard"><a href="<?php echo esc_url( $dashboard_url ); ?>"><?php esc_html_e( 'Go to your dashboard', 'beltoft-services' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php echo $domain_blocks; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printed by the plugin's own, escaped templates. ?>
</section>
