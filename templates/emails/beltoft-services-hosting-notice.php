<?php
/**
 * Customer email: a notice about a hosting account.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-hosting-notice.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var string              $account_url        The service in My Account.
 * @var string              $product_name       The hosting plan.
 * @var array<string,mixed> $hosting_notice     kind, percent, used, limit, days, php, php_default, php_end, site.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_n = is_array( $hosting_notice ?? null ) ? $hosting_notice : array( 'kind' => 'storage_warning' );
$beltoft_services_k = (string) ( $beltoft_services_n['kind'] ?? 'storage_warning' );

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
<?php if ( 'storage_warning' === $beltoft_services_k ) : ?>
<p><?php echo esc_html( sprintf( /* translators: 1: percent, 2: used GB, 3: plan GB. */ __( 'Your hosting storage is %1$d%% full (%2$d GB of %3$d GB). Files and databases count together.', 'beltoft-services' ), (int) $beltoft_services_n['percent'], (int) $beltoft_services_n['used'], (int) $beltoft_services_n['limit'] ) ); ?></p>
<p><?php esc_html_e( 'Nothing has been stopped. Free some space, or change to a larger plan in My Account.', 'beltoft-services' ); ?></p>
<?php elseif ( 'storage_over' === $beltoft_services_k ) : ?>
<p><?php echo esc_html( sprintf( /* translators: 1: percent, 2: used GB, 3: plan GB. */ __( 'Your hosting storage is over your plan (%1$d%%, %2$d GB of %3$d GB).', 'beltoft-services' ), (int) $beltoft_services_n['percent'], (int) $beltoft_services_n['used'], (int) $beltoft_services_n['limit'] ) ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: %d: days. */ _n( 'Your websites keep running. If the storage is still over the plan after %d day, your websites can no longer save new content until you free space or change to a larger plan.', 'Your websites keep running. If the storage is still over the plan after %d days, your websites can no longer save new content until you free space or change to a larger plan.', (int) $beltoft_services_n['days'], 'beltoft-services' ), (int) $beltoft_services_n['days'] ) ); ?></p>
<?php elseif ( 'storage_restricted' === $beltoft_services_k ) : ?>
<p><?php esc_html_e( 'Your hosting storage has been over your plan for too long. Your websites still show, but they can not save new content (orders, comments, posts) until the storage is back within your plan.', 'beltoft-services' ); ?></p>
<p><?php esc_html_e( 'Free some space, or change to a larger plan in My Account. We lift this by ourselves as soon as the storage is back within the plan.', 'beltoft-services' ); ?></p>
<?php elseif ( 'storage_restored' === $beltoft_services_k ) : ?>
<p><?php esc_html_e( 'Your hosting storage is back within your plan, and your websites can save new content again.', 'beltoft-services' ); ?></p>
<?php elseif ( 'php_ending' === $beltoft_services_k ) : ?>
<p><?php echo esc_html( sprintf( /* translators: 1: website, 2: PHP version, 3: date. */ __( 'The website %1$s runs on PHP %2$s, which gets no more security updates after %3$s.', 'beltoft-services' ), $beltoft_services_n['site'], $beltoft_services_n['php'], $beltoft_services_n['php_end'] ) ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: 1: PHP version, 2: days. */ __( 'Please change it to PHP %1$s in My Account within %2$d days. On that date we move it ourselves, after a safety copy, and you can go back for 7 days.', 'beltoft-services' ), $beltoft_services_n['php_default'], (int) $beltoft_services_n['days'] ) ); ?></p>
<?php elseif ( 'php_switched' === $beltoft_services_k ) : ?>
<p><?php echo esc_html( sprintf( /* translators: 1: website, 2: old PHP version, 3: new PHP version. */ __( 'PHP %2$s lost security support, so the website %1$s now runs on PHP %3$s.', 'beltoft-services' ), $beltoft_services_n['site'], $beltoft_services_n['php'], $beltoft_services_n['php_default'] ) ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: %d: days. */ __( 'If something does not work, you can go back to the old version in My Account for %d days.', 'beltoft-services' ), (int) $beltoft_services_n['days'] ) ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your hosting in My Account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
