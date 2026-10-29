<?php
/**
 * My Account → Overview: the welcome of a customer with no services yet.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/welcome.php.
 *
 * @package BeltoftServices
 *
 * @var string                                $name  The customer's first name, may be ''.
 * @var array<int, array<string, string>>     $cards Starting points: key, label, title, text, button, url.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__welcome">
	<h2>
		<?php
		echo '' !== $name
			/* translators: %s: the customer's first name */
			? esc_html( sprintf( __( 'Welcome, %s', 'beltoft-services' ), $name ) )
			: esc_html__( 'Welcome', 'beltoft-services' );
		?>
	</h2>
	<p><?php esc_html_e( 'Nothing here yet. Pick where to start. You come back here after checkout.', 'beltoft-services' ); ?></p>
	<div class="beltoft-services-ov__cards">
		<?php foreach ( $cards as $beltoft_services_i => $beltoft_services_card ) : ?>
			<div class="beltoft-services-ov__card">
				<span class="beltoft-services-ov__label"><?php echo esc_html( (string) ( $beltoft_services_card['label'] ?? '' ) ); ?></span>
				<h3><?php echo esc_html( (string) ( $beltoft_services_card['title'] ?? '' ) ); ?></h3>
				<p><?php echo esc_html( (string) ( $beltoft_services_card['text'] ?? '' ) ); ?></p>
				<a class="beltoft-services-ov__btn<?php echo 0 === $beltoft_services_i ? '' : ' is-ghost'; ?>" href="<?php echo esc_url( (string) $beltoft_services_card['url'] ); ?>"><?php echo esc_html( (string) ( $beltoft_services_card['button'] ?? '' ) ); ?></a>
			</div>
		<?php endforeach; ?>
	</div>
</section>
