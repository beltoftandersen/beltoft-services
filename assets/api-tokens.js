/**
 * My Account, Security: the Copy button next to a new API token. The token is only in the page for this one reply.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-api-copy]' ) : null;
		var label;
		var area;

		if ( ! button ) {
			return;
		}

		label = button.textContent;

		function done() {
			button.textContent = button.getAttribute( 'data-copied' ) || label;
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		}

		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( button.getAttribute( 'data-api-copy' ) ).then( done );
			return;
		}

		area = document.createElement( 'textarea' );
		area.value = button.getAttribute( 'data-api-copy' );
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();

		try {
			if ( document.execCommand( 'copy' ) ) {
				done();
			}
		} catch ( e ) {
			// Nothing to do: the token stays selectable in the page.
		}

		document.body.removeChild( area );
	} );
}() );
