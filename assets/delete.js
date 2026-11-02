/**
 * My Account, Delete block of a pay-as-you-go server or database: asks the server to delete it after the typed word, shows the
 * busy loader while it works, says that it is being deleted and then opens the list. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var text = window.beltoftServicesDelete;

	if ( ! account || ! text || ! window.fetch ) {
		return;
	}

	function say( box, line ) {
		var out = box.querySelector( '[data-delete-notice]' );

		if ( out ) {
			out.textContent = line;
		}
	}

	// A button at work: disabled, with the three squares of the other buttons (.is-busy in account.css).
	function busy( button, on ) {
		button.disabled = on;
		button.classList.toggle( 'is-busy', on );
		button.setAttribute( 'aria-busy', on ? 'true' : 'false' );
	}

	// Opens the list once the service is really gone (the status answers 404, or says deleted), so the list never shows it as
	// still working. Gives up after about two minutes and opens the list anyway.
	function waitGone( id, after, tries ) {
		function leave() {
			if ( after ) {
				window.location.href = after;
			} else {
				window.location.reload();
			}
		}

		window.setTimeout( function () {
			window.fetch( account.root + id, { credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce } } ).then( function ( response ) {
				if ( ! response.ok ) {
					return { gone: 404 === response.status || 403 === response.status };
				}

				return response.json().then( function ( data ) {
					return { gone: !! data && 'deleted' === data.state };
				} );
			} ).then( function ( result ) {
				if ( result.gone || tries >= 40 ) {
					leave();
				} else {
					waitGone( id, after, tries + 1 );
				}
			} ).catch( function () {
				if ( tries >= 40 ) {
					leave();
				} else {
					waitGone( id, after, tries + 1 );
				}
			} );
		}, 3000 );
	}

	function go( box, button ) {
		var input = box.querySelector( '[data-delete-confirm]' );
		var after = box.getAttribute( 'data-delete-after' );

		busy( button, true );
		say( box, text.strings.working );

		window.fetch( account.root + box.getAttribute( 'data-resource-id' ) + '/delete', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify( { confirm: input ? input.value : '' } )
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			if ( 202 === response.status ) {
				// The button keeps working until the page changes: nothing can be pressed twice.
				if ( input ) {
					input.disabled = true;
				}

				say( box, text.strings.working );
				waitGone( box.getAttribute( 'data-resource-id' ), after, 0 );

				return null;
			}

			busy( button, false );

			return response.json().then( function ( data ) {
				if ( 429 === response.status ) {
					say( box, text.strings.limit );
				} else if ( 403 === response.status && data && 'rest_cookie_invalid_nonce' === data.code ) {
					say( box, text.strings.expired );
				} else {
					say( box, 422 === response.status && data && data.message ? data.message : text.strings.failed );
				}
			}, function () {
				say( box, text.strings.failed );
			} );
		} ).catch( function () {
			busy( button, false );
			say( box, text.strings.failed );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-delete-go]' );
		var box = button ? button.closest( '[data-delete-box]' ) : null;

		if ( button && box && ! button.disabled ) {
			go( box, button );
		}
	} );
}() );
