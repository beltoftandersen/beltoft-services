/**
 * The Copy buttons of the DNS records guide (Domains\DnsRecordsGuide). A button holds the whole text in data-dns-copy, so a
 * long value (a DKIM key) is copied in one press without selecting it. The copy itself is window.beltoftApi.copy() (assets/api.js).
 * "Check now" is not handled here: each feature gives its button the attributes its own script already listens to
 * (assets/hosting.js, assets/mail.js).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-dns-copy]' ) : null;

		if ( ! button || ! window.beltoftApi || ! window.beltoftApi.copy ) {
			return;
		}

		event.preventDefault();
		window.beltoftApi.copy( button.getAttribute( 'data-dns-copy' ) || '', button ).catch( function () {} );
	} );
}() );
