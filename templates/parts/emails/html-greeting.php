<?php
/**
 * The greeting line of an HTML email.
 *
 * Included by the customer email templates. Expects $customer_name.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
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
