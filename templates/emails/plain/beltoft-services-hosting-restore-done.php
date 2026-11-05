<?php
/**
 * Customer email (plain text): a restore of a hosting account has ended.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-hosting-restore-done.php.
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

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$beltoft_services_r  = is_array( $hosting_restore ?? null ) ? $hosting_restore : array( 'kind' => 'account', 'ok' => true, 'until' => '', 'site' => '' );
$beltoft_services_ok = ! empty( $beltoft_services_r['ok'] );
$beltoft_services_k  = (string) ( $beltoft_services_r['kind'] ?? 'account' );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_strip_all_tags( $email_heading );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	echo wp_strip_all_tags( sprintf( __( 'Hi %s,', 'beltoft-services' ), $customer_name ) ) . "\n\n";
} else {
	echo __( 'Hi,', 'beltoft-services' ) . "\n\n";
}

if ( $beltoft_services_ok ) {
	if ( 'undo' === $beltoft_services_k ) {
		echo __( 'Your hosting is back to the state it had before your last restore.', 'beltoft-services' ) . "\n\n";
	} elseif ( 'files' === $beltoft_services_k ) {
		/* translators: %s: website. */
		echo wp_strip_all_tags( sprintf( __( 'The files of the website %s were restored from your backup.', 'beltoft-services' ), (string) $beltoft_services_r['site'] ) ) . "\n\n";
	} elseif ( 'database' === $beltoft_services_k ) {
		/* translators: %s: website. */
		echo wp_strip_all_tags( sprintf( __( 'The database of the website %s was restored from your backup.', 'beltoft-services' ), (string) $beltoft_services_r['site'] ) ) . "\n\n";
	} else {
		echo __( 'Your whole hosting was restored from your backup, files and databases.', 'beltoft-services' ) . "\n\n";
	}

	if ( '' !== (string) $beltoft_services_r['until'] && 'undo' !== $beltoft_services_k ) {
		/* translators: %s: date and time. */
		echo wp_strip_all_tags( sprintf( __( 'If it is not what you wanted, you can undo it in My Account until %s. A safety copy was made first.', 'beltoft-services' ), (string) $beltoft_services_r['until'] ) ) . "\n\n";
	}
} else {
	echo __( 'Your restore could not be completed. We have been told and are looking at it.', 'beltoft-services' ) . "\n\n";
	echo __( 'If you started a restore of the whole hosting, a safety copy of the state before it is kept, and you can undo the restore in My Account.', 'beltoft-services' ) . "\n\n";
}

echo __( 'Open your hosting in My Account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable
