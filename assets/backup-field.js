/**
 * Checkout: the "Daily backups" box swaps the cart's server for the variation with or without
 * backups, through the shared queue (cart-swap.js), which then refreshes the order review. The
 * server's own text is never shown: a fixed message is.
 */
( function ( $ ) {
	'use strict';

	var config = window.beltoftServicesBackups;
	var box = $( '#beltoft_services_backups' );

	if ( ! config || ! box.length ) {
		return;
	}

	box.on( 'change', function () {
		window.beltoftCartSwap.changed( box.get( 0 ) );
	} );
}( window.jQuery ) );
