<?php
/**
 * My Account → Servers: one row per server.
 *
 * Override by copying to yourtheme/beltoft-services/account/servers-list.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $rows Rows from BeltoftServices\Account\Servers::rows(): id, url, name, details, state, tone (ok, busy, off, bad), renews, bars (label, percent).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-servers">
	<?php foreach ( $rows as $beltoft_services_row ) : ?>
		<a class="beltoft-services-srv" id="beltoft-service-<?php echo (int) $beltoft_services_row['id']; ?>" href="<?php echo esc_url( $beltoft_services_row['url'] ); ?>">
			<span class="beltoft-services-srv__id">
				<span class="beltoft-services-srv__name"><?php echo esc_html( $beltoft_services_row['name'] ); ?></span>
				<?php if ( '' !== $beltoft_services_row['details'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['details'] ); ?></span><?php endif; ?>
			</span>
			<span class="beltoft-services-srv__state">
				<span class="beltoft-services-state is-<?php echo esc_attr( $beltoft_services_row['tone'] ); ?>"><?php echo esc_html( $beltoft_services_row['state'] ); ?></span>
				<?php if ( '' !== $beltoft_services_row['renews'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['renews'] ); ?></span><?php endif; ?>
			</span>
			<span class="beltoft-services-srv__bars">
				<?php foreach ( $beltoft_services_row['bars'] as $beltoft_services_bar ) : ?>
					<span class="beltoft-services-bar">
						<span><?php echo esc_html( $beltoft_services_bar['label'] ); ?></span>
						<span class="beltoft-services-bar__track"><i style="width:<?php echo (int) $beltoft_services_bar['percent']; ?>%"></i></span>
						<span><?php echo (int) $beltoft_services_bar['percent']; ?>%</span>
					</span>
				<?php endforeach; ?>
			</span>
			<span class="beltoft-services-srv__go" aria-hidden="true">&rsaquo;</span>
		</a>
	<?php endforeach; ?>
</div>
