<?php
/**
 * My Account → Servers: extra disks and snapshot packs whose server is not on the list.
 *
 * Override by copying to yourtheme/beltoft-services/account/addons.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array{name: string, state: string, body: string}> $addons Name, status text and the driver's own part (already escaped HTML).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-servers beltoft-services-servers--addons">
	<?php foreach ( $addons as $beltoft_services_addon ) : ?>
		<div class="beltoft-services-srv beltoft-services-srv--addon">
			<span class="beltoft-services-srv__id">
				<span class="beltoft-services-srv__name"><?php echo esc_html( $beltoft_services_addon['name'] ); ?></span>
				<span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_addon['state'] ); ?></span>
			</span>
			<?php if ( '' !== $beltoft_services_addon['body'] ) : ?>
				<div class="beltoft-services-srv__body"><?php echo $beltoft_services_addon['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Printed and escaped by the driver. ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
