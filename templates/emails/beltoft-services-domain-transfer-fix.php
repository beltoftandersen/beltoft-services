<?php
/**
 * Customer email: a transfer waits for a new transfer code or for the domain to be unlocked.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-domain-transfer-fix.php.
 *
 * @package BeltoftServices
 *
 * @var string    $email_heading        Heading.
 * @var string    $domain_name          The domain.
 * @var string    $transfer_fix_reason  auth_refused or locked.
 * @var string    $registrar_name       The current registrar, or ''.
 * @var string    $account_url          The domain in My Account.
 * @var string    $additional_content   Additional content from the email's settings.
 * @var \WC_Email $email                The email.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_who = '' !== $registrar_name ? $registrar_name : __( 'your current registrar', 'beltoft-services' );

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>
<?php if ( 'auth_refused' === $transfer_fix_reason ) : ?>
<p><?php
/* translators: %s: the customer's domain name. */
echo esc_html( sprintf( __( 'The transfer code for %s was not accepted.', 'beltoft-services' ), $domain_name ) );
?></p>
<p><?php
/* translators: %s: the current registrar. */
echo esc_html( sprintf( __( 'Ask %s for a new transfer code (also called auth code or EPP code). You find it in the domain settings there. Then open your domain in My Account and enter the new code.', 'beltoft-services' ), $beltoft_who ) );
?></p>
<p><?php esc_html_e( 'The transfer is sent again as soon as we have a new code. Without one it stops after 14 days.', 'beltoft-services' ); ?></p>
<?php else : ?>
<p><?php
/* translators: 1: the customer's domain name, 2: the current registrar. */
echo esc_html( sprintf( __( 'The transfer of %1$s cannot start because the domain is locked at %2$s.', 'beltoft-services' ), $domain_name, $beltoft_who ) );
?></p>
<p><?php
/* translators: %s: the current registrar. */
echo esc_html( sprintf( __( 'Unlock the domain at %s (the setting is called transfer lock or registrar lock). Then open your domain in My Account and click Check now.', 'beltoft-services' ), $beltoft_who ) );
?></p>
<p><?php esc_html_e( 'We also try again every 6 hours. If the domain is still locked after 14 days, the transfer stops.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your domain in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
