<?php
/**
 * My Account → Servers → one server: its page.
 *
 * Override by copying to yourtheme/beltoft-services/account/server.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $view License view (BeltoftLicenses View::license()).
 * @var string               $list Address of the list of servers.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-server">
	<p class="beltoft-services-server__back"><a href="<?php echo esc_url( $list ); ?>">&lsaquo; <?php esc_html_e( 'All servers', 'beltoft-services' ); ?></a></p>
	<?php \BeltoftServices\Account\Panel::render( $view['license'], $view ); ?>
</div>
