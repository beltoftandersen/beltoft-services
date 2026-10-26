<?php
/** Customer pending-domain email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( __( 'Your domain %s is waiting for a confirmation.', 'beltoft-services' ), $domain_name ) ); ?></p>
<p><?php echo esc_html( 'registrant_check' === $domain_action_reason ? __( 'Please complete the identity check sent by the registry.', 'beltoft-services' ) : __( 'Please approve the transfer request sent by the registry or current registrar.', 'beltoft-services' ) ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your domain in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email );
