<?php
/**
 * The result of the last nightly malware scan of a website.
 *
 * Variables: $v: slug, found, value, words (HostingView::scan()).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<details class="bsh-sec" data-sec="scan" id="<?php echo esc_attr( 'beltoft-site-' . $v['slug'] . '-scan' ); ?>"<?php echo $v['found'] ? ' open' : ''; ?>>
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'Malware scan', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php echo esc_html( $v['value'] ); ?></span></summary>
	<div class="bsh-sec__body">
		<p class="<?php echo $v['found'] ? 'beltoft-hosting-warning' : 'beltoft-hosting-line'; ?>" data-scan-state role="status"><?php echo esc_html( $v['words'] ); ?></p>
		<?php if ( $v['found'] ) : ?>
			<p class="beltoft-hosting-help"><?php esc_html_e( 'Your website is still online and nothing was changed. Restore a backup from before this date, or remove the files with the file manager. Contact support if you need help.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<p class="beltoft-hosting-help"><?php esc_html_e( 'Your website is scanned every night for known malware. The scan only reads your files. It does not change or remove anything.', 'beltoft-services' ); ?></p>
	</div>
</details>
