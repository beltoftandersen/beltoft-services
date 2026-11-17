<?php
/**
 * The Add a website panel: a title, what to start with and the PHP version.
 *
 * Variables: $v: php_versions.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-hosting-panel" id="beltoft-hosting-add" data-hosting-scope>
	<header class="beltoft-hosting-panel__head"><h3 class="beltoft-hosting-panel__title"><?php esc_html_e( 'Add a website', 'beltoft-services' ); ?></h3></header>
	<div class="beltoft-hosting-formrow">
		<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Website title', 'beltoft-services' ); ?></span><input type="text" data-hosting-field="label" maxlength="100" autocomplete="off"></label>
		<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Start with', 'beltoft-services' ); ?></span>
			<select data-hosting-field="install">
				<option value="wordpress"><?php esc_html_e( 'WordPress', 'beltoft-services' ); ?></option>
				<option value="empty"><?php esc_html_e( 'No WordPress', 'beltoft-services' ); ?></option>
			</select>
		</label>
		<label class="beltoft-hosting-field"><span><?php esc_html_e( 'PHP version', 'beltoft-services' ); ?></span>
			<select data-hosting-field="php">
				<?php foreach ( $v['php_versions'] as $beltoft_services_version ) : ?>
					<option value="<?php echo esc_attr( $beltoft_services_version ); ?>"><?php echo esc_html( 'PHP ' . $beltoft_services_version ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="add_site"><?php esc_html_e( 'Add website', 'beltoft-services' ); ?></button>
	</div>
</section>
