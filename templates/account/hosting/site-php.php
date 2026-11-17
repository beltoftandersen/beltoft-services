<?php
/**
 * The PHP section of a website: the version and the way to change it on one row.
 *
 * Variables: $v: HostingView::site().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug = $v['slug'];
$beltoft_services_php  = $v['php'];
?>
<details class="bsh-sec" data-sec="php" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-php' ); ?>">
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'PHP', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php echo esc_html( 'PHP ' . $beltoft_services_php['version'] ); ?></span></summary>
	<div class="bsh-sec__body">
		<?php if ( $v['ready'] ) : ?>
			<div class="bs-action">
				<p class="bs-action__text"><?php /* translators: %s: PHP version. */ echo esc_html( sprintf( __( 'This website runs on PHP %s. Choose another version if your software needs it.', 'beltoft-services' ), $beltoft_services_php['version'] ) ); ?></p>
				<div class="bs-action__do">
					<span class="beltoft-hosting-inline" data-php-form>
						<label class="screen-reader-text" for="<?php echo esc_attr( 'beltoft-php-' . $beltoft_services_slug ); ?>"><?php esc_html_e( 'PHP version', 'beltoft-services' ); ?></label>
						<select id="<?php echo esc_attr( 'beltoft-php-' . $beltoft_services_slug ); ?>" data-hosting-field="php_version">
							<?php foreach ( $beltoft_services_php['versions'] as $beltoft_services_version ) : ?>
								<option value="<?php echo esc_attr( $beltoft_services_version ); ?>"<?php selected( $beltoft_services_version, $beltoft_services_php['version'] ); ?>><?php echo esc_html( 'PHP ' . $beltoft_services_version ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="beltoft-hosting-btn" data-hosting-action="php_save" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Save', 'beltoft-services' ); ?></button>
					</span>
				</div>
			</div>
			<?php if ( '' !== $beltoft_services_php['rollback'] ) : ?>
				<div class="bs-action">
					<p class="bs-action__text"><?php /* translators: %s: PHP version. */ echo esc_html( sprintf( __( 'The version was changed from PHP %s. You can go back if something stopped working.', 'beltoft-services' ), $beltoft_services_php['rollback'] ) ); ?></p>
					<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="php_rollback" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php /* translators: %s: PHP version. */ echo esc_html( sprintf( __( 'Go back to PHP %s', 'beltoft-services' ), $beltoft_services_php['rollback'] ) ); ?></button></div>
				</div>
			<?php endif; ?>
		<?php else : ?>
			<dl class="bsh-kv"><div><dt><?php esc_html_e( 'Version', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( 'PHP ' . $beltoft_services_php['version'] ); ?></dd></div></dl>
		<?php endif; ?>
	</div>
</details>
