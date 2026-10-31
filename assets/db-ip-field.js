/**
 * Checkout: the "Public IPv4 address" box swaps the cart's database for the variation with or without
 * the address, then asks WooCommerce to refresh the order review. The server's own text is never
 * shown: a fixed message is.
 */
( function ( $ ) {
	'use strict';

	var config = window.beltoftServicesDbIp;
	var box = $( '#beltoft_services_db_ip' );

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
