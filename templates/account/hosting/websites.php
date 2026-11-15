<?php
/**
 * The websites: a heading, then one panel for each website, those being added, Add a website and Move a website here.
 *
 * Variables: $v: heading (print the "Websites" heading), sites (HostingView::site() for each), pending (HostingView::pending_site()
 * for each), add (the PHP versions of Add a website, or null), move (HostingView::move(), or null).
 *
 * @package BeltoftServices
 */

use BeltoftServices\Hosting\HostingView;

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-hosting-section" id="beltoft-hosting-sites"><h3 class="beltoft-hosting-section__title"><?php esc_html_e( 'Websites', 'beltoft-services' ); ?></h3></div>
<?php
foreach ( $v['sites'] as $beltoft_services_site ) {
	HostingView::render( 'site', $beltoft_services_site );
}

foreach ( $v['pending'] as $beltoft_services_site ) {
	HostingView::render( 'pending-site', $beltoft_services_site );
}

if ( null !== $v['add'] ) {
	HostingView::render( 'add-site', array( 'php_versions' => $v['add'] ) );
}

if ( null !== $v['move'] ) {
	HostingView::render( 'move', $v['move'] );
}
