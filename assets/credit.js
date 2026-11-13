/**
 * My Account, Credit: what the last form did goes to the status bar (assets/status.js reads the page's flash lines; a refused code also marks its field), and
 * an empty code gets a styled message instead of the browser's.
 */
( function () {
	'use strict';

	var status = window.beltoftStatus;
	var config = window.beltoftCredit || {};

	if ( ! status ) {
		return;
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var input = form.querySelector ? form.querySelector( '#beltoft-credit-code' ) : null;

		if ( input && form.classList.contains( 'beltoft-services-credit__code' ) && '' === input.value.trim() ) {
			event.preventDefault();
			status.fail( null, config.empty || 'Enter your coupon code.', input );
		}
	}, true );
}() );
