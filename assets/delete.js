/**
 * My Account, Delete block of a pay-as-you-go server or database: asks the server to delete it after the typed word, shows the
 * bar while it works, says that it is being deleted and then opens the list. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var text = window.beltoftServicesDelete;

	if ( ! account || ! text || ! window.fetch || ! window.beltoftStatus ) {
		return;
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

	function send( box, button, input ) {
		var after = box.getAttribute( 'data-delete-after' );
		var id = box.getAttribute( 'data-resource-id' );
		var h = window.beltoftStatus.busy( text.strings.working, id, button );

		window.fetch( account.root + id + '/delete', {
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
				// The service stays locked until the page changes: nothing can be pressed twice.
				if ( input ) {
					input.disabled = true;
				}

				waitGone( id, after, 0 );

				return null;
			}

			return response.json().then( function ( data ) {
				if ( 429 === response.status ) {
					window.beltoftStatus.fail( h, text.strings.limit );
				} else if ( 403 === response.status && data && 'rest_cookie_invalid_nonce' === data.code ) {
					window.beltoftStatus.fail( h, text.strings.expired );
				} else if ( 422 === response.status && data && data.message ) {
					window.beltoftStatus.fail( h, data.message, input );
				} else {
					window.beltoftStatus.fail( h, text.strings.failed );
				}
			}, function () {
				window.beltoftStatus.fail( h, text.strings.failed );
			} );
		} ).catch( function () {
			window.beltoftStatus.fail( h, text.strings.failed );
		} );
	}

	function go( box, button ) {
		var input = box.querySelector( '[data-delete-confirm]' );
		var word = box.getAttribute( 'data-delete-word' ) || '';
		var ask = box.getAttribute( 'data-delete-ask' );
		var S = window.beltoftStatus;

		if ( S.locked( box.getAttribute( 'data-resource-id' ) ) ) {
			return;
		}

		// The typed word first, inline: a wrong or empty word never opens the question.
		if ( ! input || input.value.trim() !== word ) {
			S.fail( null, text.strings.type.replace( '%s', function () {
				return word;
			} ), input );

			return;
		}

		// A last caution, like Shut down: deleting cannot be undone.
		if ( ! ask ) {
			send( box, button, input );

			return;
		}

		S.confirm( { text: ask, confirm: button.textContent, danger: true } ).then( function ( yes ) {
			if ( yes ) {
				send( box, button, input );
			}
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
