<?php
/**
 * The Files (SFTP) panel: where to connect, a password (shown once), and public SSH keys. Setting either needs the
 * emailed code, like the other credentials that stay valid (API tokens, the domain transfer code).
 *
 * Variables: $v: HostingView::files().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_sm = 'beltoft-hosting-btn bsh-btn--sm';
?>
<section class="beltoft-hosting-panel" id="beltoft-hosting-files" data-hosting-scope>
	<header class="beltoft-hosting-panel__head"><h3 class="beltoft-hosting-panel__title"><?php esc_html_e( 'Files (SFTP)', 'beltoft-services' ); ?></h3></header>
	<?php // Where to connect: one row for each value, with its copy button at the right edge. ?>
	<ul class="bs-list bsh-conn">
		<?php foreach ( $v['conn'] as $beltoft_services_label => $beltoft_services_value ) : ?>
			<li class="bs-item"><span class="bsh-conn__label"><?php echo esc_html( (string) $beltoft_services_label ); ?></span><code class="bsh-conn__value"><?php echo esc_html( $beltoft_services_value ); ?></code><button type="button" class="<?php echo esc_attr( $beltoft_services_sm ); ?>" data-hosting-action="copy" data-copy="<?php echo esc_attr( $beltoft_services_value ); ?>"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></li>
		<?php endforeach; ?>
	</ul>
	<p class="bsh-note"><?php echo esc_html( $v['note'] ); ?></p>

	<?php // The password: what is set, and the button that opens its form. ?>
	<div class="bs-action">
		<p class="bs-action__text"><strong class="bsh-action__title"><?php esc_html_e( 'Password', 'beltoft-services' ); ?></strong><?php echo esc_html( $v['password'] ); ?></p>
		<div class="bs-action__do"><?php if ( $v['ready'] ) : ?><button type="button" class="beltoft-hosting-btn" data-hosting-action="files_open" data-target="password" aria-expanded="false"><?php esc_html_e( 'Set a password', 'beltoft-services' ); ?></button><?php endif; ?></div>
	</div>
	<?php if ( $v['ready'] ) : ?>
		<div class="beltoft-hosting-secure" data-files-form="password" hidden>
			<?php \BeltoftServices\Hosting\HostingView::render( 'code-field' ); ?>
			<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Your own password, 16 characters or more (optional)', 'beltoft-services' ); ?></span><input type="text" data-hosting-field="sftp_password" autocomplete="off" autocapitalize="off" spellcheck="false"></label>
			<p class="beltoft-hosting-help"><?php esc_html_e( 'Leave it empty to get a generated password. It is shown once and is not stored here.', 'beltoft-services' ); ?></p>
			<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="files_password"><?php esc_html_e( 'Set password', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="files_close"><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button></div>
		</div>
		<div class="beltoft-hosting-reveal" data-files-reveal hidden><p class="beltoft-hosting-line"><?php esc_html_e( 'Your password. Copy it now: it is shown once and is not stored.', 'beltoft-services' ); ?></p><code data-files-secret></code> <button type="button" class="<?php echo esc_attr( $beltoft_services_sm ); ?>" data-hosting-action="copy_secret"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>
	<?php endif; ?>

	<?php // SSH keys: each key a row with Remove, then the button that opens the form. ?>
	<div class="bs-action">
		<p class="bs-action__text"><strong class="bsh-action__title"><?php esc_html_e( 'SSH keys', 'beltoft-services' ); ?></strong><?php echo array() === $v['keys'] ? esc_html__( 'No keys added.', 'beltoft-services' ) : ''; ?></p>
		<div class="bs-action__do"><?php if ( $v['ready'] ) : ?><button type="button" class="beltoft-hosting-btn" data-hosting-action="files_open" data-target="key" aria-expanded="false"><?php esc_html_e( 'Add an SSH key', 'beltoft-services' ); ?></button><?php endif; ?></div>
	</div>
	<?php if ( array() !== $v['keys'] ) : ?>
		<ul class="bs-list bsh-names">
			<?php foreach ( $v['keys'] as $beltoft_services_key ) : ?>
				<li class="bs-item"><span class="beltoft-hosting-name"><?php echo esc_html( $beltoft_services_key['name'] ); ?></span><span class="bsh-names__meta"><?php echo esc_html( $beltoft_services_key['fingerprint'] ); ?></span>
					<?php if ( $v['ready'] ) : ?>
						<button type="button" class="<?php echo esc_attr( $beltoft_services_sm ); ?>" data-hosting-action="remove_key" data-fingerprint="<?php echo esc_attr( $beltoft_services_key['fingerprint'] ); ?>"><?php esc_html_e( 'Remove', 'beltoft-services' ); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( $v['ready'] ) : ?>
		<div class="beltoft-hosting-secure" data-files-form="key" hidden>
			<?php \BeltoftServices\Hosting\HostingView::render( 'code-field' ); ?>
			<label class="beltoft-hosting-field beltoft-hosting-field--wide"><span><?php esc_html_e( 'Public key', 'beltoft-services' ); ?></span><textarea rows="3" data-hosting-field="ssh_key" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="ssh-ed25519 AAAA..."></textarea></label>
			<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="files_key"><?php esc_html_e( 'Add key', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="files_close"><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button></div>
		</div>
	<?php endif; ?>
</section>
