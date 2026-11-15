<?php
/**
 * The Staging section of a website: what the copy is, its state while a job runs, and the buttons that go with the state.
 *
 * Variables: $v: HostingView::staging().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug  = $v['slug'];
$beltoft_services_about = __( 'A staging copy is a copy of this website on an address of its own, to try changes in before they go live. It sends no email, is not shown to search engines and does not count as a website.', 'beltoft-services' );
$beltoft_services_shown = false;
?>
<details class="bsh-sec" data-sec="staging" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-staging' ); ?>"<?php echo $v['busy'] ? ' open' : ''; ?>>
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'Staging', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php echo esc_html( $v['value'] ); ?></span></summary>
	<div class="bsh-sec__body">
		<?php if ( $v['busy'] ) : ?>
			<p class="beltoft-hosting-line" data-staging-busy data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" role="status"><?php echo esc_html( $v['busy_text'] ); ?></p>
		<?php else : ?>
			<?php if ( $v['exists'] ) : ?>
				<dl class="bsh-kv" data-staging-state>
					<div><dt><?php esc_html_e( 'Address', 'beltoft-services' ); ?></dt><dd><a class="beltoft-hosting-link" href="<?php echo esc_url( 'https://' . $v['host'] . '/' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $v['host_show'] ); ?></a></dd></div>
					<?php if ( '' !== $v['made'] ) : ?>
						<div><dt><?php esc_html_e( 'Made', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $v['made'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== $v['refreshed'] ) : ?>
						<div><dt><?php esc_html_e( 'Refreshed from live', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $v['refreshed'] ); ?></dd></div>
					<?php endif; ?>
				</dl>
				<?php if ( $v['ready'] ) : ?>
					<div class="bs-action">
						<p class="bs-action__text"><?php esc_html_e( 'Signs you in to the WordPress of the staging copy, in a new tab. The address above shows the copy as visitors see it.', 'beltoft-services' ); ?></p>
						<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_open" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Open staging', 'beltoft-services' ); ?></button></div>
					</div>
					<div class="bs-action">
						<p class="bs-action__text"><?php esc_html_e( 'Puts the staging copy on the live website. You choose files only, or files and database. A safety point is made first, and you can undo the push for 7 days.', 'beltoft-services' ); ?></p>
						<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_edit" aria-expanded="false" aria-controls="<?php echo esc_attr( $v['id'] ); ?>"><?php esc_html_e( 'Push to live', 'beltoft-services' ); ?></button></div>
					</div>
					<div class="beltoft-hosting-secure" id="<?php echo esc_attr( $v['id'] ); ?>" data-staging-form hidden>
						<fieldset class="beltoft-hosting-fieldset"><legend><?php esc_html_e( 'What to push', 'beltoft-services' ); ?></legend>
							<label class="beltoft-hosting-radio"><input type="radio" name="<?php echo esc_attr( $v['id'] ); ?>-what" value="full" data-confirm="<?php echo esc_attr( $v['confirm_full'] ); ?>" checked> <span><?php esc_html_e( 'Files and database', 'beltoft-services' ); ?></span></label>
							<label class="beltoft-hosting-radio"><input type="radio" name="<?php echo esc_attr( $v['id'] ); ?>-what" value="files" data-confirm="<?php echo esc_attr( $v['confirm_files'] ); ?>"> <span><?php esc_html_e( 'Files only', 'beltoft-services' ); ?></span></label>
							<p class="beltoft-hosting-warning"><?php esc_html_e( 'Files and database: orders, customers, comments and other changes made on the live website since the staging copy was made are replaced.', 'beltoft-services' ); ?></p>
							<p class="beltoft-hosting-help"><?php esc_html_e( 'Files only: themes, plugins and other files are pushed. The database and the media library of the live website stay as they are.', 'beltoft-services' ); ?></p>
						</fieldset>
						<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_push" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Push to live', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="staging_cancel"><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button></div>
					</div>
					<div class="bs-action">
						<p class="bs-action__text"><?php esc_html_e( 'Copies the live website over the staging copy again. Changes made only in the staging copy are lost.', 'beltoft-services' ); ?></p>
						<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_refresh" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-confirm="<?php esc_attr_e( 'Refresh the staging copy from the live website? Changes made only in the staging copy are lost.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Refresh from live', 'beltoft-services' ); ?></button></div>
					</div>
				<?php endif; ?>
			<?php elseif ( $v['taken'] ) : ?>
				<p class="beltoft-hosting-line"><?php esc_html_e( 'Your plan includes one staging copy, and another website has it.', 'beltoft-services' ); ?></p>
			<?php elseif ( $v['can_create'] ) : ?>
				<?php $beltoft_services_shown = true; ?>
				<div class="bs-action">
					<p class="bs-action__text"><?php echo esc_html( $beltoft_services_about ); ?></p>
					<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_create" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Create staging copy', 'beltoft-services' ); ?></button></div>
				</div>
			<?php endif; ?>

			<?php if ( null !== $v['undo'] ) : ?>
				<div class="bs-action">
					<p class="bs-action__text"><?php echo esc_html( $v['undo']['text'] ); ?></p>
					<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_undo" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-confirm="<?php echo esc_attr( $v['undo']['confirm'] ); ?>"><?php esc_html_e( 'Undo last push', 'beltoft-services' ); ?></button></div>
				</div>
			<?php endif; ?>

			<?php if ( $v['ready'] && $v['exists'] ) : ?>
				<div class="bs-action">
					<p class="bs-action__text"><?php esc_html_e( 'Deletes the staging copy, its files and its database. The live website is not changed.', 'beltoft-services' ); ?></p>
					<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn bs-btn--danger" data-hosting-action="staging_delete" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-confirm="<?php esc_attr_e( 'Delete the staging copy? Its files and its database are deleted. The live website is not changed.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Delete staging copy', 'beltoft-services' ); ?></button></div>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ! $beltoft_services_shown ) : ?>
			<p class="beltoft-hosting-help"><?php echo esc_html( $beltoft_services_about ); ?></p>
		<?php endif; ?>
	</div>
</details>
