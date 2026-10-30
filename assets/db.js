/**
 * My Account, managed database card: shows the password once (it replaces PASSWORD in the connection strings
 * while the customer looks at the page) and asks for a new password. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var db = window.beltoftServicesDb;

	if ( ! account || ! db || ! window.fetch ) {
		return;
	}

	function say( box, text ) {
		var out = box.querySelector( '[data-db-notice]' );

		if ( out ) {
			out.textContent = text;
		}
	}

	function post( panel, path, body ) {
		return window.fetch( account.root + panel.getAttribute( 'data-resource' ) + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
			body: body
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response;
		} );
	}

	function reveal( box, panel, button ) {
		button.disabled = true;
		say( box, '' );
		post( panel, '/db-reveal', '{}' ).then( function ( response ) {
			if ( ! response.ok ) {
				say( box, 409 === response.status ? db.strings.gone : db.strings.failed );
				button.disabled = 409 === response.status;

				return null;
			}

			return response.json().then( function ( data ) {
				if ( data && data.password ) {
					Array.prototype.forEach.call( box.querySelectorAll( '[data-db-conn]' ), function ( code ) {
						code.textContent = code.textContent.replace( 'PASSWORD', data.password );
					} );
					button.hidden = true;
					say( box, db.strings.once );
				}
			} );
		} ).catch( function () {
			button.disabled = false;
			say( box, db.strings.failed );
		} );
	}

	function waitForReset( box, panel, since, tries ) {
		window.setTimeout( function () {
			window.fetch( account.root + panel.getAttribute( 'data-resource' ), { credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce } } ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( data ) {
				var last = data && data.last_result;

				if ( last && 'db_reset_password' === last.action && last.at >= since ) {
					window.location.reload();
				} else if ( tries < 60 ) {
					waitForReset( box, panel, since, tries + 1 );
				} else {
					window.location.reload();
				}
			} ).catch( function () {
				if ( tries < 60 ) {
					waitForReset( box, panel, since, tries + 1 );
				}
			} );
		}, 5000 );
	}

	function reset( box, panel, button ) {
		var input = box.querySelector( '[data-db-confirm]' );
		var since = Math.floor( Date.now() / 1000 ) - 5;

		button.disabled = true;
		say( box, '' );
		post( panel, '/actions/db_reset_password', JSON.stringify( { args: { confirm: input ? input.value : '' } } ) ).then( function ( response ) {
			if ( response.status === 202 ) {
				say( box, db.strings.working );
				waitForReset( box, panel, since, 0 );

				return null;
			}

			button.disabled = false;

			return response.json().then( function ( data ) {
				say( box, 429 === response.status ? db.strings.limit : ( data && data.message ? data.message : db.strings.failed ) );
			} );
		} ).catch( function () {
			button.disabled = false;
			say( box, db.strings.failed );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-db-reveal], [data-db-reset]' );
		var box = button ? button.closest( '[data-db-card]' ) : null;
		var panel = box ? box.closest( '[data-resource]' ) : null;

		if ( ! button || ! box || ! panel ) {
			return;
		}

		if ( button.hasAttribute( 'data-db-reveal' ) ) {
			reveal( box, panel, button );
		} else {
			reset( box, panel, button );
		}
	} );
}() );
