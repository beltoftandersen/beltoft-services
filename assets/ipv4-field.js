/**
 * Checkout: the "IPv4 address" line puts that many address lines in the cart (a number for a dedicated server, a box for a
 * VPS Basic or a database), then asks WooCommerce to refresh the order review. A fixed message is shown when it fails.
 */
( function ( $ ) {
	'use strict';

	var config = window.beltoftServicesIpv4;
	var field = $( '#beltoft_services_ipv4' );

	if ( ! config || ! field.length ) {
		return;
	}

	field.on( 'change', function () {
		var before = field.data( 'was' );
		var count = field.is( ':checkbox' ) ? ( field.is( ':checked' ) ? 1 : 0 ) : parseInt( field.val(), 10 ) || 0;

		field.prop( 'disabled', true );

		$.ajax( {
			url: config.url,
			method: 'POST',
			dataType: 'json',
			data: { nonce: field.data( 'nonce' ), count: count }
		} ).done( function ( answer ) {
			field.data( 'was', answer && typeof answer.count === 'number' ? answer.count : count );
			$( document.body ).trigger( 'update_checkout' );
		} ).fail( function () {
			if ( field.is( ':checkbox' ) ) {
				field.prop( 'checked', 1 === before );
			} else {
				field.val( before );
			}
			window.setTimeout( function () { window.beltoftStatus.fail( null, config.failed, field.get( 0 ) ); }, 0 ); // after the field is enabled again, so it can take the focus
		} ).always( function () {
			field.prop( 'disabled', false );
		} );
	} );
}( window.jQuery ) );
