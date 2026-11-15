<?php
/**
 * The Files and database section of a website: each tool with what it does, and SFTP for a program on your computer.
 *
 * Variables: $v: HostingView::site().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug = $v['slug'];
?>
<details class="bsh-sec" data-sec="tools" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-tools' ); ?>">
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'Files and database', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php esc_html_e( 'File manager, phpMyAdmin, SFTP', 'beltoft-services' ); ?></span></summary>
	<div class="bsh-sec__body">
		<?php if ( $v['ready'] ) : ?>
			<div class="bs-action">
				<p class="bs-action__text"><?php esc_html_e( 'Shows the files of this website in a new tab, to upload, edit and delete them. The tab ends after 30 minutes without use.', 'beltoft-services' ); ?></p>
				<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="tool_files" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-done="<?php esc_attr_e( 'The file manager opened in a new tab.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Open file manager', 'beltoft-services' ); ?></button></div>
			</div>
			<div class="bs-action">
				<p class="bs-action__text"><?php esc_html_e( 'Opens phpMyAdmin for the database of this website in a new tab. No password is needed. The tab ends after 30 minutes without use.', 'beltoft-services' ); ?></p>
				<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="tool_database" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-done="<?php esc_attr_e( 'The database tool opened in a new tab.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Open database', 'beltoft-services' ); ?></button></div>
			</div>
		<?php endif; ?>
		<div class="bs-action">
			<p class="bs-action__text"><?php esc_html_e( 'To work with an FTP program on your computer, connect with SFTP.', 'beltoft-services' ); ?></p>
			<div class="bs-action__do"><a class="beltoft-hosting-btn" href="<?php echo esc_url( $v['sftp_link'] ); ?>"><?php esc_html_e( 'SFTP details', 'beltoft-services' ); ?></a></div>
		</div>
	</div>
</details>
