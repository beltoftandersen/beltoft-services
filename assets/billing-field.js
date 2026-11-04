/**
 * Checkout: the Billing choice (Monthly or Pay as you go) swaps the cart's server for the variation with the other
 * billing model, then asks WooCommerce to refresh the order review. The price and the cap next to Pay as you go follow the
 * backups box. The server's own text is never shown: a fixed message is.
 */
( function ( $ ) {
	'use strict';

	var config = window.beltoftServicesBilling;
	var wrap = $( '.beltoft-services-billing' );
	var radios = wrap.find( 'input[name="beltoft_services_billing"]' );
	var rate = wrap.find( '[data-billing-rate]' );
	var monthly = wrap.find( '[data-billing-monthly]' );
	var backups = $( '#beltoft_services_backups' );

	if ( ! config || ! radios.length ) {
		return;
	}

	function usage() {
		return 'usage' === radios.filter( ':checked' ).val();
	}

	function terms() {
		var text = backups.length && backups.is( ':checked' ) ? wrap.attr( 'data-terms-backups' ) : '';

		return text || wrap.attr( 'data-terms' ) || '';
	}

	function monthlyText() {
		var text = backups.length && backups.is( ':checked' ) ? wrap.attr( 'data-monthly-backups' ) : '';

		return text || wrap.attr( 'data-monthly' ) || '';
	}

	function show() {
		rate.text( terms() );
		monthly.text( monthlyText() );
	}

	radios.on( 'change', function () {
		var wanted = usage();

		radios.prop( 'disabled', true );

		$.ajax( {
			url: config.url,
			method: 'POST',
			dataType: 'json',
			data: {
				nonce: radios.first().data( 'nonce' ),
				billing: wanted ? 'usage' : 'monthly'
			}
		} ).done( function () {
			show();
			$( document.body ).trigger( 'update_checkout' );
		} ).fail( function () {
			radios.filter( '[value="' + ( wanted ? 'monthly' : 'usage' ) + '"]' ).prop( 'checked', true );
			window.setTimeout(function () { window.beltoftStatus.fail( null, config.failed, radios.filter( ':checked' ).get( 0 ) ); }, 0); // after the field is enabled again, so it can take the focus
		} ).always( function () {
			radios.prop( 'disabled', false );
		} );
	} );

	backups.on( 'change', show );
}( window.jQuery ) );
