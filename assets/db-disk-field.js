/**
 * Checkout: the "Extra storage" choice swaps the cart's database for the variation with that much extra storage,
 * then asks WooCommerce to refresh the order review. The server's own text is never shown: a fixed message is.
 */
( function ( $ ) {
	'use strict';

	var config = window.beltoftServicesDbDisk;
	var box = $( '#beltoft_services_db_disk' );

	if ( ! config || ! box.length ) {
		return;
	}

	box.on( 'change', function () {
		var wanted = box.val();
		var before = box.data( 'current' );

		box.prop( 'disabled', true );

		$.ajax( {
			url: config.url,
			method: 'POST',
			dataType: 'json',
			data: {
				nonce: box.data( 'nonce' ),
				extra: wanted
			}
		} ).done( function () {
			box.data( 'current', wanted );
			$( document.body ).trigger( 'update_checkout' );
		} ).fail( function () {
			box.val( before );
			window.alert( config.failed );
		} ).always( function () {
			box.prop( 'disabled', false );
		} );
	} );
}( window.jQuery ) );
