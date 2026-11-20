<?php
/**
 * My Account, Overview: the services of a new order with their process lines.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/new-order.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $views One per order: id, number, view_url, rows, state, failed, closing, blocks, poll_url, nonce.
 */

defined( 'ABSPATH' ) || exit;

foreach ( $views as $beltoft_view ) :
	$beltoft_done = 'running' !== $beltoft_view['state'];
	?>
<section class="beltoft-services-ov__order bs-order beltoft-next is-<?php echo esc_attr( $beltoft_view['state'] ); ?>" aria-labelledby="bs-order-<?php echo esc_attr( (string) $beltoft_view['id'] ); ?>" data-bs-order data-state="<?php echo esc_attr( $beltoft_view['state'] ); ?>">
	<div class="bs-order__head">
		<h2 class="bs-order__title" id="bs-order-<?php echo esc_attr( (string) $beltoft_view['id'] ); ?>">
			<?php
			/* translators: %s: order number. */
			echo esc_html( sprintf( __( 'Your new order #%s', 'beltoft-services' ), $beltoft_view['number'] ) );
			?>
		</h2>
		<a class="bs-order__see" href="<?php echo esc_url( $beltoft_view['view_url'] ); ?>"><?php esc_html_e( 'See the order', 'beltoft-services' ); ?></a>
	</div>
	<div class="bs-order__closing" role="status" aria-live="polite" data-order-closing<?php echo $beltoft_done ? '' : ' hidden'; ?>
		data-text-ready="<?php echo esc_attr( BeltoftServices\Account\OverviewOrder::closing( 'ready', 0 ) ); ?>"
		data-text-mixed-one="<?php echo esc_attr( BeltoftServices\Account\OverviewOrder::closing( 'mixed', 1 ) ); ?>"
		data-text-mixed-many="<?php echo esc_attr( BeltoftServices\Account\OverviewOrder::closing( 'mixed', 2 ) ); ?>"
		data-text-failed="<?php echo esc_attr( BeltoftServices\Account\OverviewOrder::closing( 'failed', 1 ) ); ?>">
		<p class="bs-order__closing-text" data-order-closing-text><?php echo esc_html( (string) $beltoft_view['closing'] ); ?></p>
		<ul class="bs-order__closing-list" data-order-closing-list>
			<?php foreach ( $beltoft_done ? $beltoft_view['rows'] : array() as $beltoft_row ) : ?>
				<?php if ( 'failed' === $beltoft_row['group'] ) : ?>
					<li><a href="<?php echo esc_url( $beltoft_row['url'] ); ?>"><?php echo esc_html( $beltoft_row['name'] ); ?></a></li>
				<?php elseif ( $beltoft_row['ready'] ) : ?>
					<li><a href="<?php echo esc_url( $beltoft_row['url'] ); ?>"><?php echo esc_html( BeltoftServices\ThankYou::open_label( $beltoft_row['kind'] ) ); ?></a></li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	</div>
	<div class="beltoft-next__block beltoft-next__services" data-beltoft-services-order data-bs-flow data-progress-url="<?php echo esc_url( $beltoft_view['poll_url'] ); ?>" data-nonce="<?php echo esc_attr( $beltoft_view['nonce'] ); ?>" data-bar-text="<?php esc_attr_e( 'Setting up your order', 'beltoft-services' ); ?>" data-order="<?php echo esc_attr( (string) $beltoft_view['id'] ); ?>" data-known="<?php echo esc_attr( (string) count( $beltoft_view['rows'] ) ); ?>">
		<?php if ( ! $beltoft_view['rows'] ) : ?>
			<div class="bs-loader is-busy bs-order__waiting" role="status"><span class="bs-loader__text"><?php esc_html_e( 'Setting up your order', 'beltoft-services' ); ?></span><span class="bs-loader__bar"><i></i></span></div>
		<?php endif; ?>
		<ul class="beltoft-service-list bs-flow-list">
			<?php foreach ( $beltoft_view['rows'] as $beltoft_row ) : ?>
				<?php wc_get_template( 'order/service-row.php', array( 'beltoft_row' => $beltoft_row, 'flow' => true ), 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' ); ?>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php echo $beltoft_view['blocks']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printed by the plugin's own, escaped templates. ?>
</section>
<?php endforeach; ?>
