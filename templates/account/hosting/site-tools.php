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
		<?php $beltoft_services_db = $v['database']; ?>
		<?php if ( '' !== $beltoft_services_db['name'] ) : ?>
			<dl class="bsh-kv" data-db-facts>
				<div><dt><?php esc_html_e( 'Database name', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( $beltoft_services_db['name'] ); ?></code></dd></div>
				<div><dt><?php esc_html_e( 'Database user', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( $beltoft_services_db['user'] ); ?></code></dd></div>
				<div><dt><?php esc_html_e( 'Database host', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( $beltoft_services_db['host'] ); ?></code></dd></div>
			</dl>
			<p class="beltoft-hosting-help"><?php esc_html_e( 'The user and the host are the same for every website of your hosting. The database can be reached from your websites only.', 'beltoft-services' ); ?></p>
			<?php if ( $v['ready'] ) : ?>
				<div class="bs-action">
					<p class="bs-action__text"><?php esc_html_e( 'Shows the database password once. It needs a code that is sent to your email.', 'beltoft-services' ); ?></p>
					<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="files_open" data-target="database" aria-expanded="false"><?php esc_html_e( 'Show database password', 'beltoft-services' ); ?></button></div>
				</div>
				<div class="beltoft-hosting-secure" data-files-form="database" hidden>
					<?php \BeltoftServices\Hosting\HostingView::render( 'code-field', array( 'purpose' => 'hosting_database' ) ); ?>
					<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="db_password"><?php esc_html_e( 'Show password', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="files_close"><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button></div>
				</div>
				<div class="beltoft-hosting-reveal" data-db-reveal hidden><p class="beltoft-hosting-line"><?php esc_html_e( 'Your database password. Copy it now: it is not shown again.', 'beltoft-services' ); ?></p><code data-db-secret></code> <button type="button" class="beltoft-hosting-btn bsh-btn--sm" data-hosting-action="copy_db_secret"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</details>
