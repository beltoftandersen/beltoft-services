/* Checkout: shows the inputs of the chosen website address option.
 * Without this script every option stays visible and the server uses only the chosen option's inputs. */
( function () {
	'use strict';

	function line( el ) {
		return el.closest( '[data-site-line]' );
	}

	function show( root ) {
		var picked = root.querySelector( 'input[type="radio"]:checked' );
		var mode = picked ? picked.value : 'preview';

		root.querySelectorAll( '[data-site-panel]' ).forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-site-panel' ) !== mode;
		} );

		// An empty website has no WordPress administrator: the email row goes.
		var install = root.querySelector( '[data-site-install]' );
		var admin = root.querySelector( '[data-site-admin-email]' );

		if ( install && admin ) {
			admin.hidden = 'empty' === install.value;
		}

		var more = root.querySelector( '[data-site-more-slug]' );

		if ( more ) {
			more.hidden = 'preview' === mode;
		}
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches && event.target.matches( '.beltoft-services-site-field input[type="radio"], .beltoft-services-site-field [data-site-install]' ) ) {
			show( line( event.target ) );
		}
	} );
}() );
