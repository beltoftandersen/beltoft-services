/* Checkout: the WHOIS privacy box of a domain line. One call per change; a failure puts the box back and says so. */
( function () {
	'use strict';
	var config = window.beltoftServicesOrderWhois;
	if ( ! config || ! config.url ) { return; }
	document.addEventListener( 'change', function ( event ) {
		var box = event.target && event.target.matches ? event.target : null;
		if ( ! box || ! box.matches( 'input[data-bs-whois]' ) ) { return; }
		var wanted = box.checked;
		var note = box.closest( 'td' ) ? box.closest( 'td' ).querySelector( '.beltoft-services-whois__note' ) : null;
		var body = new URLSearchParams();
		body.set( 'domain', box.getAttribute( 'data-bs-whois' ) );
		body.set( 'on', wanted ? '1' : '0' );
		body.set( 'nonce', config.nonce );
		box.disabled = true;
		window.fetch( config.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } ).then( function ( response ) {
			if ( ! response.ok ) { throw new Error( 'failed' ); }
			if ( note ) { note.textContent = ''; }
		} ).catch( function () {
			box.checked = ! wanted;
			if ( note ) { note.textContent = ( config.text && config.text.failed ) || ''; }
		} ).then( function () { box.disabled = false; } );
	} );
}() );
