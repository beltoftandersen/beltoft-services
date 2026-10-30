<?php
/**
 * My Account → Overview: the next payment.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/next-payment.php.
 *
 * @package BeltoftServices
 *
 * @var array{date: string, time: string, total: float, amount: string, covers: string, card: string, label: string, url: string}|null $payment Values; amount is price markup from WooCommerce. Null with only usage to show.
 * @var bool   $due   Whether $payment is an amount to pay.
 * @var string $usage "Usage so far: EUR X (estimate)" for a pay-as-you-go customer, else ''.
 * @var int    $next  Unix time of the usage account's next payment (0 when none).
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__section beltoft-services-ov__billing">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Billing', 'beltoft-services' ); ?></h2>
		<?php if ( $payment && '' !== $payment['url'] ) : ?><a href="<?php echo esc_url( $payment['url'] ); ?>"><?php esc_html_e( 'Go to Billing', 'beltoft-services' ); ?></a><?php endif; ?>
	</div>
	<?php if ( $due ) : ?>
	<div class="beltoft-services-ov__money">
	<div class="beltoft-services-ov__moneyhead">
		<span class="beltoft-services-ov__label"><?php esc_html_e( 'Next payment', 'beltoft-services' ); ?></span>
		<span class="beltoft-services-ov__big"><?php echo wp_kses_post( $payment['amount'] ); ?></span>
	</div>
	<dl>
		<dt><?php esc_html_e( 'Due', 'beltoft-services' ); ?></dt>
		<dd><time datetime="<?php echo esc_attr( $payment['time'] ); ?>"><?php echo esc_html( $payment['date'] ); ?></time></dd>
		<?php if ( '' !== $payment['covers'] ) : ?>
			<dt><?php esc_html_e( 'Covers', 'beltoft-services' ); ?></dt>
			<dd><?php echo esc_html( $payment['covers'] ); ?></dd>
		<?php endif; ?>
		<?php if ( '' !== $payment['card'] ) : ?>
			<dt><?php echo esc_html( $payment['label'] ?? __( 'Payment method', 'beltoft-services' ) ); ?></dt>
			<dd><?php echo esc_html( $payment['card'] ); ?></dd>
		<?php endif; ?>
	</dl>
	</div>
	<?php endif; ?>
	<?php if ( '' !== $usage ) : ?>
	<div class="beltoft-services-ov__money beltoft-services-ov__usage">
		<p><?php echo esc_html( $usage ); ?></p>
		<?php if ( ! $due && $next > 0 ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Next invoice %s', 'beltoft-services' ), wp_date( (string) get_option( 'date_format' ), $next ) ) ); ?></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</section>
