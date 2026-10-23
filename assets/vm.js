/**
 * My Account → Licenses: the virtual machine panel. Draws nothing itself; it wires the forms
 * (add or replace the SSH key, reinstall, restore) and the one-time password button that the server
 * printed. Posts to the same action route as the generic buttons, with the arguments in
 * {args: {...}}; the REST nonce is shared with account.js (kept fresh from each reply). The
 * password is only ever held in the page for as long as the customer looks at it. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var vm = window.beltoftServicesVm;

	if ( ! account || ! vm || ! window.fetch ) {
		return;
	}

	function post( panel, path, body ) {
		return window.fetch( account.root + panel.getAttribute( 'data-resource' ) + '/' + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': account.nonce,
				'Content-Type': 'application/json'
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response;
		} );
	}

	function notice( panel, text ) {
		var line = panel.querySelector( '[data-notice]' );

		if ( line ) {
			line.textContent = text;
		}
	}

	// The server's own refusal text is written for the customer; anything else gets a fixed line.
	function reply( panel, response, fallback ) {
		return response.json().then( function ( body ) {
			if ( 403 === response.status && body && 'rest_cookie_invalid_nonce' === body.code ) {
				notice( panel, vm.strings.expired );
			} else if ( 429 === response.status ) {
				notice( panel, vm.strings.tooMany );
			} else if ( 422 === response.status && body && 'string' === typeof body.message ) {
				notice( panel, body.message );
			} else {
				notice( panel, fallback );
			}
		}, function () {
			notice( panel, fallback );
		} );
	}

	function submit( panel, form ) {
		var args = {};
		var button = form.querySelector( 'button[type="submit"]' );

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( field.name ) {
				args[ field.name ] = field.value;
			}
		} );

		if ( 'reinstall' === form.getAttribute( 'data-action' ) && ! window.confirm( vm.strings.reinstall ) ) {
			return;
		}

		if ( 'restore' === form.getAttribute( 'data-action' ) && ! window.confirm( vm.strings.restore ) ) {
			return;
		}

		if ( button ) {
			button.disabled = true;
		}

		post( panel, 'actions/' + encodeURIComponent( form.getAttribute( 'data-action' ) ), { args: args } ).then( function ( response ) {
			if ( 202 === response.status ) {
				notice( panel, vm.strings.queued );
				window.setTimeout( function () {
					window.location.reload();
				}, 8000 );
				return null;
			}

			if ( button ) {
				button.disabled = false;
			}

			return reply( panel, response, vm.strings.failed );
		} ).catch( function () {
			if ( button ) {
				button.disabled = false;
			}
			notice( panel, vm.strings.failed );
		} );
	}

	function reveal( panel, button ) {
		var out = panel.querySelector( '[data-vm-password]' );

		button.disabled = true;

		post( panel, 'reveal', {} ).then( function ( response ) {
			if ( ! response.ok ) {
				return reply( panel, response, 409 === response.status ? vm.strings.gone : vm.strings.failed );
			}

			return response.json().then( function ( body ) {
				if ( out && body && 'string' === typeof body.password ) {
					out.textContent = body.password;
					out.hidden = false;
					button.hidden = true;
				}
			} );
		} ).catch( function () {
			button.disabled = false;
			notice( panel, vm.strings.failed );
		} );
	}

	document.querySelectorAll( '.beltoft-services-panel[data-resource]' ).forEach( function ( panel ) {
		panel.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest( '.beltoft-services-vm-form' );

			if ( form ) {
				event.preventDefault();
				submit( panel, form );
			}
		} );

		panel.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-vm-reveal]' );

			if ( button && ! button.disabled ) {
				reveal( panel, button );
			}
		} );
	} );
}() );
