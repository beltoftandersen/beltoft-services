<?php
/**
 * The Country block section of a website: what it does now, and the form to change it (hidden until Change is pressed).
 *
 * Variables: $v: HostingView::country().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug = $v['slug'];
?>
<details class="bsh-sec" data-sec="country" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-country' ); ?>">
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'Country block', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php echo esc_html( $v['value'] ); ?></span></summary>
	<div class="bsh-sec__body">
		<?php if ( $v['enabled'] ) : ?>
			<dl class="bsh-kv" data-country-state>
				<div><dt><?php esc_html_e( 'Applies to', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $v['applies'] ); ?></dd></div><div><dt><?php esc_html_e( 'Blocked', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $v['blocked'] ); ?></dd></div>
			</dl>
			<?php if ( $v['inactive'] ) : ?>
				<p class="beltoft-hosting-warning"><?php esc_html_e( 'The list is saved, but the country database is not in place on your server yet, so nothing is blocked until it is.', 'beltoft-services' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $v['ready'] ) : ?>
			<div class="bs-action">
				<p class="bs-action__text"><?php echo esc_html( $v['enabled'] ? __( 'Change the countries, or where the block applies, or turn it off.', 'beltoft-services' ) : __( 'Visitors from every country can reach this website. Block the countries you choose, on the whole website or only on the login.', 'beltoft-services' ) ); ?></p>
				<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="country_edit" aria-expanded="false" aria-controls="<?php echo esc_attr( $v['id'] ); ?>"><?php echo esc_html( $v['enabled'] ? __( 'Change', 'beltoft-services' ) : __( 'Block countries', 'beltoft-services' ) ); ?></button></div>
			</div>
			<div class="beltoft-hosting-secure" id="<?php echo esc_attr( $v['id'] ); ?>" data-country-form<?php echo $v['enabled'] ? ' data-country-set' : ''; ?> hidden>
				<fieldset class="beltoft-hosting-fieldset"><legend><?php esc_html_e( 'Countries to block', 'beltoft-services' ); ?></legend>
					<?php if ( array() !== $v['lists'] ) : ?>
						<div class="beltoft-hosting-buttons">
							<?php foreach ( $v['lists'] as $beltoft_services_list ) : ?>
								<button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="country_list" data-codes="<?php echo esc_attr( $beltoft_services_list['codes'] ); ?>"<?php echo '' !== $beltoft_services_list['scope'] ? ' data-scope="' . esc_attr( $beltoft_services_list['scope'] ) . '"' : ''; ?>><?php echo esc_html( $beltoft_services_list['label'] ); ?></button>
							<?php endforeach; ?>
						</div>
						<?php foreach ( $v['lists'] as $beltoft_services_list ) : ?>
							<p class="beltoft-hosting-help"><?php echo esc_html( $beltoft_services_list['help'] ); ?></p>
						<?php endforeach; ?>
					<?php endif; ?>
					<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Find a country', 'beltoft-services' ); ?></span><input type="search" data-country-filter data-hosting-nosubmit autocomplete="off" spellcheck="false"></label>
					<div class="beltoft-hosting-countries" role="group" aria-label="<?php esc_attr_e( 'Countries', 'beltoft-services' ); ?>">
						<?php foreach ( $v['countries'] as $beltoft_services_country ) : ?>
							<label class="beltoft-hosting-check" data-country-name="<?php echo esc_attr( $beltoft_services_country['lower'] ); ?>"><input type="checkbox" data-country value="<?php echo esc_attr( $beltoft_services_country['code'] ); ?>"<?php echo $beltoft_services_country['checked'] ? ' checked' : ''; ?>> <span><?php echo esc_html( $beltoft_services_country['name'] ); ?></span></label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<fieldset class="beltoft-hosting-fieldset"><legend><?php esc_html_e( 'Where it applies', 'beltoft-services' ); ?></legend>
					<label class="beltoft-hosting-radio"><input type="radio" name="<?php echo esc_attr( $v['id'] ); ?>-scope" value="site"<?php echo 'login' !== $v['scope'] ? ' checked' : ''; ?>> <span><?php esc_html_e( 'Whole website', 'beltoft-services' ); ?></span></label>
					<label class="beltoft-hosting-radio"><input type="radio" name="<?php echo esc_attr( $v['id'] ); ?>-scope" value="login"<?php echo 'login' === $v['scope'] ? ' checked' : ''; ?>> <span><?php esc_html_e( 'Login and admin only', 'beltoft-services' ); ?></span></label>
					<p class="beltoft-hosting-help"><?php esc_html_e( 'Login and admin only covers wp-login.php, xmlrpc.php and the WordPress admin area (not admin-ajax.php and admin-post.php), and the file manager and database tool. The rest of the website stays open.', 'beltoft-services' ); ?></p>
					<?php foreach ( $v['lists'] as $beltoft_services_list ) : ?>
						<?php if ( '' !== $beltoft_services_list['scope_help'] ) : ?>
							<p class="beltoft-hosting-help"><?php echo esc_html( $beltoft_services_list['scope_help'] ); ?></p>
						<?php endif; ?>
					<?php endforeach; ?>
				</fieldset>
				<p class="beltoft-hosting-help"><?php esc_html_e( 'If your own country is blocked, you cannot reach the website or the buttons on this page that open it.', 'beltoft-services' ); ?></p>
				<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="country_save" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Save', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="country_cancel"><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
					<?php if ( $v['enabled'] ) : ?>
						<button type="button" class="beltoft-hosting-linkbtn beltoft-hosting-linkbtn--danger" data-hosting-action="country_off" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Turn off', 'beltoft-services' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		<?php elseif ( ! $v['enabled'] ) : ?>
			<p class="beltoft-hosting-line" data-country-state><?php esc_html_e( 'Visitors from every country can reach this website.', 'beltoft-services' ); ?></p>
		<?php endif; ?>

		<p class="beltoft-hosting-help"><?php esc_html_e( "The country is looked up from the visitor's address. This is not exact, and a VPN gets around it.", 'beltoft-services' ); ?></p>
		<p class="beltoft-hosting-help"><?php esc_html_e( 'This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com', 'beltoft-services' ); ?></p>
	</div>
</details>
