<?php
/**
 * My Account → Overview: what needs the customer now (work in progress, a failed payment).
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/attention.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array{tone: string, state: string, name: string, text: string, link: string, label: string}> $items Items.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-ov__attention" role="status">
	<?php foreach ( $items as $beltoft_services_item ) : ?>
		<div>
			<span class="beltoft-services-state is-<?php echo esc_attr( $beltoft_services_item['tone'] ); ?>"><?php echo esc_html( $beltoft_services_item['state'] ); ?></span>
			<span><b><?php echo esc_html( $beltoft_services_item['name'] ); ?></b> <?php echo esc_html( $beltoft_services_item['text'] ); ?></span>
			<a href="<?php echo esc_url( $beltoft_services_item['link'] ); ?>"><?php echo esc_html( $beltoft_services_item['label'] ); ?></a>
		</div>
	<?php endforeach; ?>
</div>
