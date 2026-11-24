/**
 * Checkout: an add-on tick box (managed server, priority support) adds or removes its line through its own wc-ajax
 * endpoint, then asks WooCommerce to refresh the order review. A fixed message is shown when it fails.
 */
( function ( $ ) {
	'use strict';

	var config = window.beltoftServicesCheckBox || {};

	$( document.body ).on( 'change', '.beltoft-services-check-box__input', function () {
		var box = $( this );
		var on = box.is( ':checked' );

		box.prop( 'disabled', true );

		$.ajax( {
			url: box.data( 'url' ),
			method: 'POST',
			dataType: 'json',
			data: { nonce: box.data( 'nonce' ), on: on ? 1 : 0 }
		} ).done( function () {
			$( document.body ).trigger( 'update_checkout' );
		} ).fail( function () {
			box.prop( 'checked', ! on );
			window.setTimeout( function () { window.beltoftStatus.fail( null, config.failed, box.get( 0 ) ); }, 0 );
		} ).always( function () {
			box.prop( 'disabled', false );
		} );
	} );
}( window.jQuery ) );
