<?php
/**
 * Customer email: the service is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-ready.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $deletion_date      Date of the deletion (deletion warning), or ''.
 * @var array<string, string> $details        Lines about the service (label => value), may be empty. Never a password.
 * @var string            $disk_server_url    Card of the server an extra disk is on, or ''.
 * @var string            $disk_guide_url     The disk's setup guide, or ''.
 * @var bool              $dns_only           DNS hosting only.
 * @var string[]          $nameservers        Our name servers (DNS hosting only).
 * @var string            $usage              Usage line (limit exceeded), or ''.
 * @var string            $additional_content Additional content from the email's settings.
 * @var \WC_Email         $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<p>
<?php
if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	printf( esc_html__( 'Hi %s,', 'beltoft-services' ), esc_html( $customer_name ) );
} else {
	esc_html_e( 'Hi,', 'beltoft-services' );
}
?>
</p>
<p>
<?php
/* translators: %s: product name. */
echo esc_html( sprintf( __( 'Your %s is ready to use.', 'beltoft-services' ), $product_name ) );
?>
</p>
<?php if ( $details ) : ?>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<?php foreach ( $details as $beltoft_services_label => $beltoft_services_value ) : ?>
	<tr>
		<th scope="row" style="text-align:left"><?php echo esc_html( (string) $beltoft_services_label ); ?></th>
		<td><?php echo esc_html( (string) $beltoft_services_value ); ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ( ! empty( $disk_server_url ) ) : ?>
<p><?php esc_html_e( 'Find your disk in My account > VPS > your server > Disks.', 'beltoft-services' ); ?> <a href="<?php echo esc_url( $disk_server_url ); ?>"><?php esc_html_e( 'Open your server', 'beltoft-services' ); ?></a>. <a href="<?php echo esc_url( $disk_guide_url ); ?>"><?php esc_html_e( 'Read the disk setup guide', 'beltoft-services' ); ?></a>.</p>
<?php endif; ?>
<?php if ( ! empty( $dns_only ) && $nameservers ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: our name servers, comma separated. */ __( 'Our name servers: %s', 'beltoft-services' ), implode( ', ', array_map( 'strval', $nameservers ) ) ) ); ?></p>
<p><?php esc_html_e( 'Change the name servers at your registrar to these. If your registrar shows a DS record (DNSSEC), remove it first and wait a day.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your services in your account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
