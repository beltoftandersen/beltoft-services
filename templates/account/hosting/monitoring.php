<?php
/**
 * The monitoring panel: the shared charts of a server's page, with the words of a hosting account.
 *
 * Variables: $v: HostingView::monitoring().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-hosting-panel" id="beltoft-hosting-monitoring">
	<?php \BeltoftServices\Monitoring\Charts::panel( $v ); ?>
</div>
