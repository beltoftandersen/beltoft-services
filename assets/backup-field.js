/**
 * Checkout: the "Daily backups" box swaps the cart's server for the variation with or without
 * backups, then asks WooCommerce to refresh the order review. The server's own text is never
 * shown: a fixed message is.
 */
( function ( $ ) {
	'use strict';

	var config = window.beltoftServicesBackups;
	var box = $( '#beltoft_services_backups' );

	if ( ! config || ! box.length ) {
		return;
	}

	box.on( 'change', function () {
		var wanted = box.is( ':checked' );

		box.prop( 'disabled', true );

		$.ajax( {
			url: config.url,
			method: 'POST',
			dataType: 'json',
			data: {
				nonce: box.data( 'nonce' ),
				with: wanted ? '1' : '0'
			}
		} ).done( function () {
			$( document.body ).trigger( 'update_checkout' );
		} ).fail( function () {
			box.prop( 'checked', ! wanted );
			window.alert( config.failed );
		} ).always( function () {
			box.prop( 'disabled', false );
		} );
	} );
}( window.jQuery ) );
