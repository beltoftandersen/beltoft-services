<?php
/**
 * The account at a glance: websites, live CPU and memory (filled by assets/usage-live.js) and storage.
 *
 * Variables: $v: HostingView::overview().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="bsh-overview" aria-label="<?php esc_attr_e( 'Overview', 'beltoft-services' ); ?>">
	<dl class="bsh-overview__facts"<?php echo $v['usage_id'] > 0 ? ' data-usage-resource="' . (int) $v['usage_id'] . '"' : ''; ?>>
		<div><dt><?php esc_html_e( 'Websites', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $v['sites'] ); ?></dd></div>
		<?php if ( $v['ready'] ) : ?>
			<div data-usage-bar="cpu" hidden><dt><?php esc_html_e( 'CPU', 'beltoft-services' ); ?></dt><dd><span class="bs-meter" aria-hidden="true"><span class="bs-meter__fill" data-usage-fill style="width: 0%"></span></span><span class="bsh-overview__small" data-usage-text></span></dd></div>
			<div data-usage-bar="mem" data-mem-state="<?php echo esc_attr( $v['mem_state'] ); ?>" title="<?php esc_attr_e( 'Memory', 'beltoft-services' ); ?>"><dt><?php esc_html_e( 'Memory', 'beltoft-services' ); ?></dt><dd><span class="bs-meter" aria-hidden="true"><span class="bs-meter__fill" data-usage-fill style="width: <?php echo (int) $v['mem_pct']; ?>%"></span></span><span class="bsh-overview__small" data-usage-text><?php echo esc_html( $v['mem_text'] ); ?></span><small class="bsh-overview__small" data-usage-sub></small></dd></div>
		<?php endif; ?>
		<?php if ( null !== $v['storage'] ) : ?>
			<div><dt><?php esc_html_e( 'Storage', 'beltoft-services' ); ?></dt><dd><span class="bs-meter<?php echo $v['storage'] >= 90 ? ' bs-meter--high' : ''; ?>" aria-hidden="true"><span class="bs-meter__fill" style="width: <?php echo (int) $v['storage']; ?>%"></span></span><span class="bsh-overview__small"><?php /* translators: %d: percent of the plan's storage in use. */ echo esc_html( sprintf( __( '%d%% used', 'beltoft-services' ), $v['storage'] ) ); ?></span></dd></div>
		<?php endif; ?>
	</dl>
	<?php if ( $v['jump'] ) : ?>
		<nav class="bsh-jump" aria-label="<?php esc_attr_e( 'On this page', 'beltoft-services' ); ?>">
			<a href="#beltoft-hosting-sites"><?php esc_html_e( 'Websites', 'beltoft-services' ); ?></a>
			<a href="#beltoft-hosting-monitoring"><?php esc_html_e( 'Monitoring', 'beltoft-services' ); ?></a>
			<a href="#beltoft-hosting-files"><?php esc_html_e( 'Files (SFTP)', 'beltoft-services' ); ?></a>
			<?php if ( $v['backups'] ) : ?>
				<a href="#beltoft-hosting-backups"><?php esc_html_e( 'Backups', 'beltoft-services' ); ?></a>
			<?php endif; ?>
			<a href="#beltoft-hosting-email"><?php esc_html_e( 'Email', 'beltoft-services' ); ?></a>
		</nav>
	<?php endif; ?>
</section>
