/**
 * My Account, Delete block of a pay-as-you-go server or database: asks the server to delete it after the typed word, shows the
 * bar while it works, says that it is being deleted and then opens the list. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var text = window.beltoftServicesDelete;

	if ( ! account || ! text || ! window.fetch || ! window.beltoftApi || ! window.beltoftStatus ) {
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
			window.beltoftApi.call( account.root + id, { holder: account } ).then( function ( reply ) {
				if ( ! reply.ok ) {
					return { gone: 404 === reply.status || 403 === reply.status };
				}

				if ( ! reply.parsed ) {
					throw new Error( 'json' );
				}

				return { gone: 'deleted' === reply.data.state };
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

		window.beltoftApi.call( account.root + id + '/delete', { method: 'POST', body: { confirm: input ? input.value : '' }, holder: account } ).then( function ( reply ) {
			if ( 202 === reply.status ) {
				// The service stays locked until the page changes: nothing can be pressed twice.
				if ( input ) {
					input.disabled = true;
				}

				waitGone( id, after, 0 );

				return;
			}

			if ( 429 === reply.status ) {
				window.beltoftStatus.fail( h, text.strings.limit );
			} else if ( reply.expired ) {
				window.beltoftStatus.fail( h, text.strings.expired );
			} else if ( 422 === reply.status && reply.data.message ) {
				window.beltoftStatus.fail( h, reply.data.message, input );
			} else {
				window.beltoftStatus.fail( h, text.strings.failed );
			}
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
