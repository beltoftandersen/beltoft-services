<?php
/**
 * Order received: what happens next. A theme can override this file as beltoft-services/order/next.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $rows          The order's services (BeltoftServices\ThankYou::row()).
 * @var string                           $domain_blocks The DNS hosting and transfer blocks, already printed HTML.
 * @var string                           $poll_url      The order progress route with the order key.
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
					<li class="beltoft-service is-<?php echo esc_attr( $beltoft_row['group'] ); ?>" data-service="<?php echo esc_attr( (string) $beltoft_row['id'] ); ?>">
						<p class="beltoft-service__head">
							<span class="beltoft-service__name"><?php echo esc_html( $beltoft_row['name'] ); ?></span>
							<span class="beltoft-service__state" data-service-state><?php echo esc_html( $beltoft_row['label'] ); ?></span>
						</p>
						<?php BeltoftServices\Progress::render( $beltoft_row['progress'] ); ?>
						<p class="beltoft-service__message" data-service-message<?php echo '' === $beltoft_row['message'] ? ' hidden' : ''; ?>><?php echo esc_html( $beltoft_row['message'] ); ?></p>
						<p class="beltoft-service__open" data-service-open<?php echo $beltoft_row['ready'] ? '' : ' hidden'; ?>>
							<a href="<?php echo esc_url( $beltoft_row['url'] ); ?>"><?php echo esc_html( BeltoftServices\ThankYou::open_label( $beltoft_row['kind'] ) ); ?></a>
						</p>
						<?php if ( ! empty( $beltoft_row['disk'] ) && 'failed' !== $beltoft_row['group'] ) : ?>
							<p class="beltoft-service__note"><?php esc_html_e( 'Find your disk in My account > VPS > your server > Disks.', 'beltoft-services' ); ?></p>
							<p class="beltoft-service__links">
								<?php if ( '' !== $beltoft_row['disk']['server_url'] ) : ?>
									<a href="<?php echo esc_url( $beltoft_row['disk']['server_url'] ); ?>"><?php esc_html_e( 'Open your server', 'beltoft-services' ); ?></a>
								<?php endif; ?>
								<a href="<?php echo esc_url( $beltoft_row['disk']['guide_url'] ); ?>"><?php esc_html_e( 'Disk setup guide', 'beltoft-services' ); ?></a>
							</p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
	<?php echo $domain_blocks; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printed by the plugin's own, escaped templates. ?>
</section>
