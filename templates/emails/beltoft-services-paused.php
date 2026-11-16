<?php
/**
 * Customer email: the service is paused.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-paused.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $deletion_date      Date of the deletion (deletion warning), or ''.
 * @var string            $reason             Why it was paused, or ''.
 * @var string            $deletes_on         Date the service will be deleted, or '' when none is promised.
 * @var bool              $held               The service is suspended (breach hold).
 * @var bool              $dns_only           DNS hosting only.
 * @var string            $usage              Usage line (limit exceeded), or ''.
 * @var string            $additional_content Additional content from the email's settings.
 * @var \WC_Email         $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<p>
<?php
/* translators: %s: product name. */
echo esc_html( sprintf( __( 'Your %s has been paused.', 'beltoft-services' ), $product_name ) );
?>
</p>
<?php if ( '' !== $reason ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: reason. */ __( 'Reason: %s', 'beltoft-services' ), $reason ) ); ?></p>
<?php endif; ?>
<p>
<?php
if ( '' !== $deletes_on ) {
	if ( $held ) {
		/* translators: %s: date. */
		echo esc_html( sprintf( __( 'It will be deleted no earlier than %s.', 'beltoft-services' ), $deletes_on ) );
	} else {
		/* translators: %s: date. */
		echo esc_html( sprintf( __( 'It will be deleted no earlier than %s unless your subscription is active again before then.', 'beltoft-services' ), $deletes_on ) );
	}
} elseif ( ! $held ) {
	esc_html_e( 'Its data is kept, and it starts again once your subscription is active.', 'beltoft-services' );
}
?>
</p>
<?php if ( ! empty( $dns_only ) ) : ?>
<p><?php esc_html_e( 'Your DNS records are kept but no longer answered.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your services in your account', 'beltoft-services' ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';
