<?php
/** Customer domain security notice. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
$beltoft_lines = array(
	/* translators: %s: the customer's domain name. */
	'unlocked'    => __( 'Your domain %s was unlocked, so it can be transferred to another registrar.', 'beltoft-services' ),
	/* translators: %s: the customer's domain name. */
	'code_viewed' => __( 'The transfer code for your domain %s was shown in your account.', 'beltoft-services' ),
	/* translators: %s: the customer's domain name. */
	'ns_changed'  => __( 'The name servers of your domain %s were changed.', 'beltoft-services' ),
	/* translators: %s: the customer's domain name. */
	'relocked'    => __( 'Your domain %s was locked again, because it had been unlocked for several days.', 'beltoft-services' ),
);
?>
<p><?php echo esc_html( sprintf( $beltoft_lines[ $domain_security_event ] ?? $beltoft_lines['unlocked'], $domain_name ) ); ?></p>
<p><?php echo esc_html( 'relocked' === $domain_security_event ? __( 'You can unlock it again in your account when you need to transfer it.', 'beltoft-services' ) : __( 'If you did not do this, contact support now and change your account password.', 'beltoft-services' ) ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your domain in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
