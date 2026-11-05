/* Checkout: shows the inputs of the chosen website address option and keeps the temporary address line current.
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

		var more = root.querySelector( '[data-site-more-slug]' );

		if ( more ) {
			more.hidden = 'preview' === mode;
		}
	}

	function address( root ) {
		var out = root.querySelector( '[data-site-address-name]' );
		var input = root.querySelector( 'input[name$="[slug]"]' );

		if ( out && input ) {
			var name = input.value.toLowerCase().replace( /[^a-z0-9-]/g, '' );
			out.textContent = name || 'myshop';
		}
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches && event.target.matches( '.beltoft-services-site-field input[type="radio"]' ) ) {
			show( line( event.target ) );
		}
	} );

	document.addEventListener( 'input', function ( event ) {
		if ( event.target.matches && event.target.matches( '.beltoft-services-site-field input[name$="[slug]"]' ) ) {
			address( line( event.target ) );
		}
	} );
}() );
