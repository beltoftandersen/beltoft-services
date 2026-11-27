<?php
/**
 * My Account → Overview: priority support, active or offered.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/support.php.
 *
 * @package BeltoftServices
 *
 * @var bool   $active  Whether the customer has priority support.
 * @var string $promise What it promises, in one sentence.
 * @var string $price   The price as WooCommerce prints it, markup ('' when active).
 * @var string $url     The link that puts it in the checkout ('' when active).
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__section beltoft-services-ov__support<?php echo $active ? '' : ' is-empty'; ?>">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Priority support', 'beltoft-services' ); ?></h2>
	</div>
	<p class="beltoft-licenses__empty"><?php if ( $active ) : ?><span class="beltoft-services-state is-ok"><?php esc_html_e( 'Active', 'beltoft-services' ); ?></span><?php endif; ?><?php echo esc_html( $promise ); ?></p>
	<?php if ( ! $active ) : ?>
		<?php if ( '' !== $price ) : ?><p class="beltoft-licenses__empty"><?php echo wp_kses_post( $price ); ?></p><?php endif; ?>
		<?php \BeltoftServices\Account\ServiceArea::actions( array( array( 'label' => __( 'Buy priority support', 'beltoft-services' ), 'url' => $url ) ), true ); ?>
	<?php endif; ?>
</section>
