<?php
/**
 * My Account → Servers: extra disks and snapshot packs whose server is not on the list.
 *
 * Override by copying to yourtheme/beltoft-services/account/addons.php.
 *
 * @package BeltoftServices
 *
 * @var bool $joined True when the server list is printed just above: the rows continue it.
 * @var array<int, array{name: string, state: string, body: string}> $addons Name, status text and the driver's own part (already escaped HTML).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-servers beltoft-services-servers--addons<?php echo ! empty( $joined ) ? ' is-joined' : ''; ?>">
	<?php foreach ( $addons as $beltoft_services_addon ) : ?>
		<div class="beltoft-services-srv beltoft-services-srv--addon">
			<span class="beltoft-services-srv__id">
				<span class="beltoft-services-srv__name"><?php echo esc_html( $beltoft_services_addon['name'] ); ?></span>
				<?php if ( '' !== $beltoft_services_addon['body'] ) : ?>
					<div class="beltoft-services-srv__body"><?php echo $beltoft_services_addon['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Printed and escaped by the driver. ?></div>
				<?php endif; ?>
			</span>
			<span class="beltoft-services-srv__state"><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_addon['state'] ); ?></span></span>
		</div>
	<?php endforeach; ?>
</div>
