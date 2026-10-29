<?php
/**
 * My Account → Overview: the next payment.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/next-payment.php.
 *
 * @package BeltoftServices
 *
 * @var array{date: string, time: string, amount: string, covers: string, card: string, url: string} $payment Values; amount is price markup from WooCommerce.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__money">
	<span class="beltoft-services-ov__label"><?php esc_html_e( 'Next payment', 'beltoft-services' ); ?></span>
	<span class="beltoft-services-ov__big"><?php echo wp_kses_post( $payment['amount'] ); ?></span>
	<dl>
		<dt><?php esc_html_e( 'Due', 'beltoft-services' ); ?></dt>
		<dd><time datetime="<?php echo esc_attr( $payment['time'] ); ?>"><?php echo esc_html( $payment['date'] ); ?></time></dd>
		<?php if ( '' !== $payment['covers'] ) : ?>
			<dt><?php esc_html_e( 'Covers', 'beltoft-services' ); ?></dt>
			<dd><?php echo esc_html( $payment['covers'] ); ?></dd>
		<?php endif; ?>
		<?php if ( '' !== $payment['card'] ) : ?>
			<dt><?php esc_html_e( 'Card', 'beltoft-services' ); ?></dt>
			<dd><?php echo esc_html( $payment['card'] ); ?></dd>
		<?php endif; ?>
	</dl>
	<?php if ( '' !== $payment['url'] ) : ?><a href="<?php echo esc_url( $payment['url'] ); ?>"><?php esc_html_e( 'Go to Billing', 'beltoft-services' ); ?></a><?php endif; ?>
</section>
