/**
 * My Account, Payment methods: the result of the last card form goes to the status bar, and the card form and
 * the Add or Change link say they are working while the page leaves for the payment provider.
 */
( function () {
	'use strict';

	var status = window.beltoftStatus;
	var config = window.beltoftCard || {};

	if ( ! status ) {
		return;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.beltoft-services-card [data-bs-flash]' ), function ( node ) {
		if ( 'fail' === node.getAttribute( 'data-bs-flash' ) ) {
			status.fail( null, node.textContent );
		} else {
			status.info( node.textContent );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '[data-card-go]' ) : null;

		if ( link && ! status.locked( 'page' ) ) {
			status.busy( config.working || 'Working…', 'page', link );
		}
	} );
}() );
