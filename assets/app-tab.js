/**
 * My Account → Servers → the App tab: shows the app's login once, keeps an install that is still running
 * up to date by reloading the page now and then, and tries a failed install again. The login is only held in the page while the customer looks at it.
 * No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var app = window.beltoftServicesApp;
	var labels = { admin_user: 'user', admin_password: 'pass', root_email: 'email', root_password: 'pass', encryption_key: 'key', master_password: 'master' };

	if ( ! account || ! app || ! window.fetch || ! window.beltoftApi ) {
		return;
	}

	var bs = window.beltoftStatus;

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
		var handle;

		if ( ! panel || bs.locked( panel.getAttribute( 'data-resource' ) ) ) {
			return;
		}

		handle = bs.busy( app.strings.revealing, panel.getAttribute( 'data-resource' ), button );
		window.beltoftApi.call( account.root + panel.getAttribute( 'data-resource' ) + '/app-reveal', { method: 'POST', body: {}, holder: account } ).then( function ( reply ) {
			if ( ! reply.ok ) {
				bs.fail( handle, 409 === reply.status ? app.strings.gone : app.strings.failed );
				button.disabled = 409 === reply.status;

				return;
			}

			if ( ! reply.parsed ) {
				throw new Error( 'json' );
			}

			if ( reply.data.credentials ) {
				show( box, reply.data.credentials );
				button.hidden = true;
				bs.done( handle, app.strings.revealed );
			} else {
				bs.fail( handle, app.strings.failed );
			}
		} ).catch( function () {
			bs.fail( handle, app.strings.failed );
		} );
	}

	function waitForRestart( box, button, panel, tries ) {
		window.setTimeout( function () {
			window.beltoftApi.call( account.root + panel.getAttribute( 'data-resource' ), { holder: account } ).then( function ( reply ) {
				if ( reply.ok && ! reply.parsed ) {
					throw new Error( 'json' );
				}

				return reply.ok ? reply.data : null;
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
		var handle;

		if ( ! panel || bs.locked( panel.getAttribute( 'data-resource' ) ) ) {
			return;
		}

		handle = bs.busy( app.strings.retrying, panel.getAttribute( 'data-resource' ), button );
		window.beltoftApi.call( account.root + panel.getAttribute( 'data-resource' ) + '/actions/app_retry', { method: 'POST', body: { args: {} }, holder: account } ).then( function ( response ) {
			if ( ! response.ok ) {
				bs.fail( handle, 429 === response.status ? app.strings.limit : app.strings.failed );

				return;
			}

			// The bar keeps saying so and the buttons stay locked. The status route is asked until the app has left failed/unknown, then the page reloads.
			waitForRestart( box, button, panel, 0 );
		} ).catch( function () {
			bs.fail( handle, app.strings.failed );
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

	if ( 'installing' === state || 'waiting' === state || 'dns' === state ) {
		// Waiting for DNS changes state at most every few minutes, so that page asks less often.
		window.setInterval( function () {
			if ( ! document.hidden && ! document.querySelector( 'dialog[open]' ) && ! pane.closest( '[hidden]' ) ) {
				window.location.reload();
			}
		}, 'dns' === state ? 60000 : 20000 );
	}
}() );
