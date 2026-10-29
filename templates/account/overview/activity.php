<?php
/**
 * My Account → Overview: recent activity, newest first.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/activity.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array{label: string, time: string, text: string}> $items Items.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__section beltoft-services-ov__activity">
	<div class="beltoft-services-ov__head"><h2><?php esc_html_e( 'Recent activity', 'beltoft-services' ); ?></h2></div>
	<div class="beltoft-services-ov__feed">
		<ul>
			<?php foreach ( $items as $beltoft_services_item ) : ?>
				<li><time datetime="<?php echo esc_attr( $beltoft_services_item['time'] ); ?>"><?php echo esc_html( $beltoft_services_item['label'] ); ?></time><span><?php echo esc_html( $beltoft_services_item['text'] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
