<?php
/**
 * E-mail: credit added to the customer's account (the gift card plugin's delivery e-mail while gift credit is on).
 *
 * Override by copying to yourtheme/woocommerce/emails/credit-added.php.
 *
 * @package BeltoftServices
 * @var object        $gift_card     The card: initial_amount, currency, code, message.
 * @var string        $email_heading Heading.
 * @var \WC_Email     $email         The e-mail.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p style="font-size: 28px; font-weight: bold; margin: 0 0 16px;"><?php echo wp_kses_post( wc_price( (float) $gift_card->initial_amount, array( 'currency' => (string) $gift_card->currency ) ) ); ?></p>
<p><?php esc_html_e( 'This credit has been added to your account. It pays your orders, renewals and pay as you go invoices by itself. You can see your balance under Credit in your account.', 'beltoft-services' ); ?></p>
<?php if ( ! empty( $gift_card->message ) ) : ?>
	<p style="padding: 8px 16px; border-left: 3px solid #ddd; margin: 16px 0;"><?php echo esc_html( (string) $gift_card->message ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( \BeltoftServices\GiftCredit\Page::url() ); ?>"><?php esc_html_e( 'See your credit', 'beltoft-services' ); ?></a></p>
<p style="font-size: 13px; color: #666;">
	<?php
	printf(
		/* translators: %s: the code. */
		esc_html__( 'No account with this e-mail address yet? Create one with this address, or type the code %s on the Credit page.', 'beltoft-services' ),
		'<strong>' . esc_html( (string) $gift_card->code ) . '</strong>'
	);
	?>
</p>
<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
do_action( 'woocommerce_email_footer', $email );
