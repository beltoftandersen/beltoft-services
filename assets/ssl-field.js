/* Checkout, certificate lines: shows an input only while the option it belongs to is chosen (the approval address
 * for the email method, the CSR box for "I have a certificate request"). Hidden rows are skipped by the step check.
 * Without this script every row stays visible and the server uses only the chosen option's inputs. */
( function () {
	'use strict';

	function sync( line ) {
		line.querySelectorAll( '[data-ssl-show]' ).forEach( function ( row ) {
			var rule = row.getAttribute( 'data-ssl-show' ).split( ':' );
			var picked = line.querySelector( 'input[type="radio"][name$="[' + rule[0] + ']"]:checked' );

			row.hidden = ! picked || picked.value !== rule[1];
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		var t = event.target;

		if ( t.matches && t.matches( '.beltoft-services-ssl-field input[type="radio"]' ) ) {
			sync( t.closest( '.beltoft-services-ssl-field__line' ) );
		}
	} );
}() );
