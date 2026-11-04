/**
 * My Account, Security: the Copy button next to a new API token. The token is only in the page for this one reply.
 */
( function () {
	'use strict';

	// One polite live region, so a screen reader hears the confirmation.
	var live = document.createElement( 'span' );
	live.className = 'screen-reader-text';
	live.setAttribute( 'role', 'status' );
	live.setAttribute( 'aria-live', 'polite' );
	document.body.appendChild( live );

	var status = window.beltoftStatus;
	var strings = ( window.beltoftApiTokens && window.beltoftApiTokens.strings ) || {};

	// What the last post did arrives in the page; the bar says it.
	if ( status ) {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-bs-flash]' ), function ( node ) {
			if ( 'fail' === node.getAttribute( 'data-bs-flash' ) ) {
				status.fail( null, node.textContent );
			} else {
				status.info( node.textContent );
			}
		} );
	}

	// WooCommerce's own forms that post and reload (account details, address): the bar says Working and the buttons lock.
	Array.prototype.forEach.call( document.querySelectorAll( 'form.woocommerce-EditAccountForm, .woocommerce-address-fields' ), function ( node ) {
		var form = 'FORM' === node.tagName ? node : node.closest( 'form' );

		if ( form && ! form.hasAttribute( 'data-bs-working' ) ) {
			form.setAttribute( 'data-bs-working', strings.working || 'Working…' );
		}
	} );

	// Create token needs a name (a styled message under the field); Send code and Create say what they do while the page reloads.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var name;
		var sending;

		if ( ! status || ! form.classList || ! form.classList.contains( 'beltoft-services-api__new' ) ) {
			return;
		}

		name = form.querySelector( '[name="token_name"]' );
		sending = event.submitter && 'send_code' === event.submitter.value;

		if ( ! sending && name && '' === name.value.trim() ) {
			event.preventDefault();
			status.fail( null, strings.name || 'Enter a name for the token.', name );

			return;
		}

		form.setAttribute( 'data-bs-working', sending ? ( strings.sending || 'Sending the code…' ) : ( strings.creating || 'Creating the token…' ) );
	}, true );

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
			live.textContent = '';
			window.setTimeout( function () {
				live.textContent = button.getAttribute( 'data-copied' ) || label;
			}, 50 );
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
