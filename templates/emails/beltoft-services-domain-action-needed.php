<?php
/** Customer pending-domain email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<?php if ( 'move_dns' === $domain_action_reason ) : ?>
<p><?php
/* translators: %s: the customer's domain name. */
echo esc_html( sprintf( __( 'Your transfer of %s starts when the domain uses our name servers.', 'beltoft-services' ), $domain_name ) );
?></p>
<p><?php esc_html_e( 'We have copied your DNS records, so your website and email keep working. At your current registrar, turn off DNSSEC and set these name servers:', 'beltoft-services' ); ?></p>
<ul>
<?php foreach ( $our_nameservers as $beltoft_ns ) : ?>
<li><?php echo esc_html( $beltoft_ns ); ?></li>
<?php endforeach; ?>
</ul>
<p><?php esc_html_e( 'We check every few minutes and send the transfer once the domain uses them. If that has not happened within 14 days, the transfer is not sent.', 'beltoft-services' ); ?></p>
<?php else : ?>
<p><?php
/* translators: %s: the customer's domain name. */
echo esc_html( sprintf( __( 'Your domain %s is waiting for a confirmation.', 'beltoft-services' ), $domain_name ) );
?></p>
<p><?php echo esc_html( 'registrant_check' === $domain_action_reason ? __( 'Please complete the identity check sent by the registry.', 'beltoft-services' ) : __( 'Please approve the transfer request sent by the registry or current registrar.', 'beltoft-services' ) ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your domain in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
