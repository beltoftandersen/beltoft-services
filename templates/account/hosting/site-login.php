<?php
/**
 * The WordPress login section of a website: who the buttons sign in as, then the two ways in.
 *
 * Variables: $v: HostingView::site().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug  = $v['slug'];
$beltoft_services_login = $v['login'];
?>
<details class="bsh-sec" data-sec="login" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-login' ); ?>">
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'WordPress login', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php echo esc_html( $beltoft_services_login['user'] ); ?></span></summary>
	<div class="bsh-sec__body">
		<?php if ( array() !== $beltoft_services_login['who'] ) : ?>
			<dl class="bsh-kv">
				<?php foreach ( $beltoft_services_login['who'] as $beltoft_services_label => $beltoft_services_value ) : ?>
					<div><dt><?php echo esc_html( (string) $beltoft_services_label ); ?></dt><dd><?php echo esc_html( $beltoft_services_value ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>
		<?php if ( $v['ready'] ) : ?>
			<div class="bs-action" data-login-facts>
				<p class="bs-action__text"><?php echo esc_html( '' !== $beltoft_services_login['user'] ? __( 'Signs you in to WordPress as this user, in a new tab. No password is needed.', 'beltoft-services' ) : __( 'Signs you in to WordPress as its administrator, in a new tab. No password is needed.', 'beltoft-services' ) ); ?></p>
				<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="login" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-busy="<?php esc_attr_e( 'Opening WordPress in a new tab...', 'beltoft-services' ); ?>" data-done="<?php esc_attr_e( 'WordPress opened in a new tab.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Log in to WordPress', 'beltoft-services' ); ?></button></div>
			</div>
			<div class="bs-action">
				<p class="bs-action__text"><?php /* translators: %s: the website's WordPress sign-in address. */ echo esc_html( sprintf( __( 'You have no WordPress password until you set one. With a password you can also log in at %s.', 'beltoft-services' ), $beltoft_services_login['url'] ) ); ?></p>
				<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="password" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Set a WordPress password', 'beltoft-services' ); ?></button></div>
			</div>
		<?php endif; ?>
	</div>
</details>
