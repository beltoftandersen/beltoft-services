<?php
/**
 * My Account → Overview: the servers, the same rows as the Servers page.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/servers.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $rows  Rows from BeltoftServices\Account\Servers::rows().
 * @var int                              $total Number of servers the customer has.
 * @var string                           $url   Address of the Servers page.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__section beltoft-services-ov__servers">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Servers', 'beltoft-services' ); ?></h2>
		<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'All servers', 'beltoft-services' ); ?></a>
	</div>
	<?php include wc_locate_template( 'servers-list.php', 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' ); ?>
</section>
