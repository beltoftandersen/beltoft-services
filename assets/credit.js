/**
 * My Account, Credit: what the last form did goes to the status bar (a refused code also under its field), and
 * an empty code gets a styled message instead of the browser's.
 */
( function () {
	'use strict';

	var status = window.beltoftStatus;
	var config = window.beltoftCredit || {};

	if ( ! status ) {
		return;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.beltoft-services-credit [data-bs-flash]' ), function ( node ) {
		if ( 'fail' === node.getAttribute( 'data-bs-flash' ) ) {
			status.fail( null, node.textContent, node.getAttribute( 'data-bs-field' ) || undefined );
		} else {
			status.info( node.textContent );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var input = form.querySelector ? form.querySelector( '#beltoft-credit-code' ) : null;

		if ( input && form.classList.contains( 'beltoft-services-credit__code' ) && '' === input.value.trim() ) {
			event.preventDefault();
			status.fail( null, config.empty || 'Enter your credit code.', input );
		}
	}, true );
}() );
