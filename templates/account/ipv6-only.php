<?php
/**
 * The short line of a server or a database that has an IPv6 address and no IPv4 address: what that means for the customer's own
 * connection, where to test it and the link that adds an IPv4 address. One template for the server's Access tab and the database's
 * connect box.
 *
 * Variables: $ipv6_only (kind: server|database, add_url, add_price, adding, sold_out).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_test = '<a href="https://test-ipv6.com/" target="_blank" rel="noopener noreferrer">test-ipv6.com</a>';
$beltoft_services_add  = '';

if ( '' !== (string) $ipv6_only['add_url'] ) {
	$beltoft_services_label = '' !== (string) $ipv6_only['add_price'] ? sprintf( /* translators: %s: the price per period, such as +€5.00 a month */ __( 'buy an IPv4 address (%s)', 'beltoft-services' ), $ipv6_only['add_price'] ) : __( 'buy an IPv4 address', 'beltoft-services' );
	$beltoft_services_add   = sprintf( /* translators: %s: a link with the words "buy an IPv4 address" */ __( 'Or %s.', 'beltoft-services' ), '<a href="' . esc_url( (string) $ipv6_only['add_url'] ) . '" data-ipv6-add>' . esc_html( $beltoft_services_label ) . '</a>' );
}

$beltoft_services_allowed = array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true, 'data-ipv6-add' => true ) );
?>
<p class="beltoft-services-ipv6only description" data-ipv6-only>
	<?php
	if ( 'database' === $ipv6_only['kind'] ) {
		echo wp_kses( sprintf( /* translators: %s: a link to test-ipv6.com */ __( 'This database has an IPv6 address only. To connect from outside, your own connection needs IPv6. Test it at %s. Applications on your private network are not affected.', 'beltoft-services' ), $beltoft_services_test ), $beltoft_services_allowed );
	} else {
		echo wp_kses( sprintf( /* translators: %s: a link to test-ipv6.com */ __( 'This server has an IPv6 address only. To connect over SSH your own connection needs IPv6. Test it at %s. The browser console under Console always works.', 'beltoft-services' ), $beltoft_services_test ), $beltoft_services_allowed );
	}

	echo ' ' . wp_kses( $beltoft_services_add, $beltoft_services_allowed ); // phpcs:ignore Generic.Formatting.DisallowMultipleStatements.SameLine
	?>
</p>
<?php
// The same test as the checkout's, on request. Without a test page (setting empty or no AAAA record) there is no button.
$beltoft_services_check = \BeltoftServices\Proxmox\Ipv4\Ipv6Test::button( (string) $ipv6_only['kind'], __( 'Check my connection', 'beltoft-services' ) );

if ( '' !== $beltoft_services_check ) :
	?>
	<p class="beltoft-services-ipv6only"><?php echo wp_kses( $beltoft_services_check, array( 'span' => array( 'class' => true, 'data-ipv6-result' => true, 'role' => true ), 'button' => array( 'type' => true, 'class' => true, 'data-ipv6-check' => true, 'data-checking' => true, 'data-ipv6' => true, 'data-none' => true, 'data-unknown' => true ) ) ); ?></p>
<?php endif; ?>
