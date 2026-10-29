/**
 * My Account → Servers → the App tab: shows the app's login once, keeps an install that is still running
 * up to date by reloading the page now and then, and keeps the operating system of a reinstall on
 * Ubuntu 24.04 while an app is selected. The login is only held in the page while the customer looks at it.
 * No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var app = window.beltoftServicesApp;
	var labels = { admin_user: 'user', admin_password: 'pass', root_email: 'email', root_password: 'pass' };

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

			line( box, app.strings.retrying );
			window.setTimeout( function () {
				window.location.reload();
			}, 4000 );
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

	// A reinstall with an app stays on the app's operating system.
	var holder = document.querySelector( '[data-app-reinstall]' );
	var choice = holder ? holder.querySelector( '[data-app-reinstall-select]' ) : null;
	var system = choice ? choice.closest( 'form' ).querySelector( 'select[name="os"]' ) : null;

	function lock() {
		var want = holder.getAttribute( 'data-os' );
		var on = 'none' !== choice.value && want;

		if ( ! system ) {
			return;
		}

		Array.prototype.forEach.call( system.options, function ( option ) {
			option.disabled = on && option.value !== want;
		} );

		if ( on ) {
			system.value = want;
		}
	}

	if ( choice ) {
		choice.addEventListener( 'change', lock );
		lock();
	}
}() );
