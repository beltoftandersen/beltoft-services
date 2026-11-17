<?php
/**
 * My Account → Servers: one row per server.
 *
 * Override by copying to yourtheme/beltoft-services/account/servers-list.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $rows Rows from BeltoftServices\Account\Servers::rows(): id, url, name, details, state, tone (ok, busy, off, bad), renews, bars (label, percent; the memory bar also has key, title, state, text, sub).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-servers">
	<?php foreach ( $rows as $beltoft_services_row ) : ?>
		<a class="beltoft-services-srv" id="beltoft-service-<?php echo (int) $beltoft_services_row['id']; ?>" href="<?php echo esc_url( $beltoft_services_row['url'] ); ?>">
			<span class="beltoft-services-srv__id">
				<span class="beltoft-services-state is-<?php echo esc_attr( $beltoft_services_row['tone'] ); ?>"><?php echo esc_html( $beltoft_services_row['state'] ); ?></span>
				<span class="beltoft-services-srv__name"><?php echo esc_html( $beltoft_services_row['name'] ); ?></span>
				<?php if ( '' !== $beltoft_services_row['details'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['details'] ); ?></span><?php endif; ?>
			</span>
			<span class="beltoft-services-srv__state">
				<?php if ( ! empty( $beltoft_services_row['note'] ) ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['note'] ); ?></span><?php endif; ?>
			</span>
			<span class="beltoft-services-srv__bars" data-usage-resource="<?php echo (int) $beltoft_services_row['id']; ?>">
				<?php foreach ( $beltoft_services_row['bars'] as $beltoft_services_bar ) : ?>
					<?php $beltoft_services_known = null !== $beltoft_services_bar['percent']; ?>
					<?php $beltoft_services_text = isset( $beltoft_services_bar['text'] ) && '' !== $beltoft_services_bar['text'] ? $beltoft_services_bar['text'] : (int) $beltoft_services_bar['percent'] . '%'; ?>
					<span class="beltoft-services-bar"<?php echo isset( $beltoft_services_bar['key'] ) ? ' data-usage-bar="' . esc_attr( $beltoft_services_bar['key'] ) . '"' : ''; ?><?php echo isset( $beltoft_services_bar['state'] ) ? ' data-mem-state="' . esc_attr( $beltoft_services_bar['state'] ) . '"' : ''; ?><?php echo ! empty( $beltoft_services_bar['title'] ) ? ' title="' . esc_attr( $beltoft_services_bar['title'] ) . '"' : ''; ?><?php echo $beltoft_services_known ? '' : ' hidden'; ?>>
						<span><?php echo esc_html( $beltoft_services_bar['label'] ); ?></span>
						<span class="beltoft-services-bar__track"><i data-usage-fill style="width:<?php echo (int) $beltoft_services_bar['percent']; ?>%"></i></span>
						<span data-usage-text><?php echo esc_html( $beltoft_services_text ); ?></span>
						<?php if ( isset( $beltoft_services_bar['sub'] ) ) : ?><small class="beltoft-services-bar__sub" data-usage-sub><?php echo esc_html( $beltoft_services_bar['sub'] ); ?></small><?php endif; ?>
					</span>
				<?php endforeach; ?>
			</span>
			<span class="beltoft-services-srv__go" aria-hidden="true">&rsaquo;</span>
		</a>
	<?php endforeach; ?>
</div>
