/**
 * My Account → Servers → the App tab: shows the app's login once, keeps an install that is still running
 * up to date by reloading the page now and then, and tries a failed install again. The login is only held in the page while the customer looks at it.
 * No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var app = window.beltoftServicesApp;
	var labels = { admin_user: 'user', admin_password: 'pass', root_email: 'email', root_password: 'pass', encryption_key: 'key' };

	if ( ! account || ! app || ! window.fetch ) {
		return;
	}

	function line( box, text ) {
		var out = box.querySelector( '[data-app-notice]' );

		if ( out ) {
			out.textContent = text;
		}
	}

	function show( box, credentials ) {
		var list = box.querySelector( '[data-app-login]' );

		if ( ! list ) {
			return;
		}

		list.textContent = '';
		Object.keys( credentials ).forEach( function ( key ) {
			var row = document.createElement( 'div' );
			var term = document.createElement( 'dt' );
			var value = document.createElement( 'dd' );
			var code = document.createElement( 'code' );

			term.textContent = app.strings[ labels[ key ] ] || key;
			code.textContent = credentials[ key ];
			value.appendChild( code );
			row.appendChild( term );
			row.appendChild( value );
			list.appendChild( row );
		} );
		list.hidden = false;
	}

	function reveal( box, button ) {
		var panel = box.closest( '[data-resource]' );

		if ( ! panel ) {
			return;
		}

		button.disabled = true;
		line( box, '' );
		window.fetch( account.root + panel.getAttribute( 'data-resource' ) + '/app-reveal', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
			body: '{}'
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			if ( ! response.ok ) {
				line( box, 409 === response.status ? app.strings.gone : app.strings.failed );
				button.disabled = 409 === response.status;

				return null;
			}

			return response.json().then( function ( body ) {
				if ( body && body.credentials ) {
					show( box, body.credentials );
					button.hidden = true;
				}
			} );
		} ).catch( function () {
			button.disabled = false;
			line( box, app.strings.failed );
		} );
	}

	function waitForRestart( box, button, panel, tries ) {
		window.setTimeout( function () {
			window.fetch( account.root + panel.getAttribute( 'data-resource' ), { credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce } } ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( data ) {
				if ( data && 'failed' !== data.app_state && 'unknown' !== data.app_state ) {
					window.location.reload();
				} else if ( tries < 30 ) {
					waitForRestart( box, button, panel, tries + 1 );
				} else {
					window.location.reload();
				}
			} ).catch( function () {
				if ( tries < 30 ) {
					waitForRestart( box, button, panel, tries + 1 );
				}
			} );
		}, 4000 );
	}

	// A failed install, tried again: the server queues the job again and the page reloads to show it installing.
	function retry( box, button ) {
		var panel = box.closest( '[data-resource]' );

		if ( ! panel ) {
			return;
		}

		button.disabled = true;
		line( box, '' );
		window.fetch( account.root + panel.getAttribute( 'data-resource' ) + '/actions/app_retry', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
			body: '{"args":{}}'
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			if ( ! response.ok ) {
				line( box, 429 === response.status ? app.strings.limit : app.strings.failed );
				button.disabled = false;

				return;
			}

			// The button stays off. The status route is asked until the app has left failed/unknown, then the page reloads.
			line( box, app.strings.retrying );
			waitForRestart( box, button, panel, 0 );
		} ).catch( function () {
			button.disabled = false;
			line( box, app.strings.failed );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-app-reveal], [data-app-retry]' );
		var box = button ? button.closest( '[data-app-pane]' ) : null;

		if ( button && box ) {
			if ( button.hasAttribute( 'data-app-retry' ) ) {
				retry( box, button );
			} else {
				reveal( box, button );
			}
		}
	} );

	// An install that is running: the page asks again every twenty seconds while it is shown.
	var pane = document.querySelector( '[data-app-pane]' );
	var state = pane ? pane.getAttribute( 'data-app-state' ) : '';

	if ( 'installing' === state || 'waiting' === state ) {
		window.setInterval( function () {
			if ( ! document.hidden && ! document.querySelector( 'dialog[open]' ) && ! pane.closest( '[hidden]' ) ) {
				window.location.reload();
			}
		}, 20000 );
	}
}() );
