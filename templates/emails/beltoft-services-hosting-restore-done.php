<?php
/**
 * Customer email: a restore of a hosting account has ended.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-hosting-restore-done.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var string              $account_url        The service in My Account.
 * @var array<string,mixed> $hosting_restore    kind, ok, until, site.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_r  = is_array( $hosting_restore ?? null ) ? $hosting_restore : array( 'kind' => 'account', 'ok' => true, 'until' => '', 'site' => '' );
$beltoft_services_ok = ! empty( $beltoft_services_r['ok'] );
$beltoft_services_k  = (string) ( $beltoft_services_r['kind'] ?? 'account' );

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
<?php if ( $beltoft_services_ok ) : ?>
	<?php if ( 'undo' === $beltoft_services_k ) : ?>
<p><?php esc_html_e( 'Your hosting is back to the state it had before your last restore.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'files' === $beltoft_services_k ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: website. */ __( 'The files of the website %s were restored from your backup.', 'beltoft-services' ), (string) $beltoft_services_r['site'] ) ); ?></p>
	<?php elseif ( 'database' === $beltoft_services_k ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: website. */ __( 'The database of the website %s was restored from your backup.', 'beltoft-services' ), (string) $beltoft_services_r['site'] ) ); ?></p>
	<?php else : ?>
<p><?php esc_html_e( 'Your whole hosting was restored from your backup, files and databases.', 'beltoft-services' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== (string) $beltoft_services_r['until'] && 'undo' !== $beltoft_services_k ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'If it is not what you wanted, you can undo it in My Account until %s. A safety copy was made first.', 'beltoft-services' ), (string) $beltoft_services_r['until'] ) ); ?></p>
	<?php endif; ?>
<?php else : ?>
<p><?php esc_html_e( 'Your restore could not be completed. We have been told and are looking at it.', 'beltoft-services' ); ?></p>
<p><?php esc_html_e( 'If you started a restore of the whole hosting, a safety copy of the state before it is kept, and you can undo the restore in My Account.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your hosting in My Account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
