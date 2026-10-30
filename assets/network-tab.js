/**
 * My Account → Servers → the Network tab: attach and detach the private network. Each button asks first, posts the
 * customer action, waits for the job through the status route, then reloads the page. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesNetwork;
	var root = document.querySelector( '[data-net]' );

	if ( ! account || ! cfg || ! root || ! window.fetch ) {
		return;
	}

	var S = cfg.strings;
	var id = root.getAttribute( 'data-resource' );
	var timer = null;

	function q( selector ) {
		return root.querySelector( selector );
	}

	function request( method, path, body ) {
		var options = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' } };

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		return window.fetch( path, options ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				return { ok: response.ok, status: response.status, body: json };
			} );
		} );
	}

	function say( text ) {
		q( '[data-net-message]' ).textContent = text || '';
	}

	function buttons( disabled ) {
		root.querySelectorAll( 'button[data-net-attach], button[data-net-detach]' ).forEach( function ( button ) {
			button.disabled = disabled;
		} );
	}

	function wait( action, since ) {
		request( 'GET', account.root + id ).then( function ( reply ) {
			var result = reply.body && reply.body.last_result;

			if ( reply.ok && ! reply.body.working && result && result.action === action && result.at >= since ) {
				if ( result.ok ) {
					window.location.reload();
				} else {
					say( result.text || S.failed );
					buttons( false );
				}

				return;
			}

			timer = window.setTimeout( function () {
				wait( action, since );
			}, 4000 );
		} );
	}

	function run( action ) {
		buttons( true );
		say( S.working );
		request( 'POST', account.root + id + '/actions/' + action, { args: {} } ).then( function ( reply ) {
			if ( reply.ok ) {
				wait( action, reply.body && reply.body.at ? reply.body.at - 1 : 0 );
			} else {
				say( ( reply.body && reply.body.message ) || S.error );
				buttons( false );
			}
		} );
	}

	function dialog( title, body, ok, then ) {
		var box = q( '[data-net-dialog]' );

		q( '[data-net-dialog-title]' ).textContent = title;
		q( '[data-net-dialog-body]' ).textContent = body;
		q( '[data-net-dialog-ok]' ).textContent = ok;
		q( '[data-net-dialog-form]' ).onsubmit = function ( event ) {
			event.preventDefault();
			box.close();
			then();
		};
		q( '[data-net-dialog-cancel]' ).onclick = function () {
			box.close();
		};

		if ( box.showModal ) {
			box.showModal();
		} else {
			box.setAttribute( 'open', '' );
		}
	}

	root.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button' );

		if ( ! target ) {
			return;
		}

		if ( target.hasAttribute( 'data-net-attach' ) ) {
			dialog( S.attachTitle, S.attachBody, S.attach, function () {
				run( 'private_attach' );
			} );
		} else if ( target.hasAttribute( 'data-net-detach' ) ) {
			dialog( S.detachTitle, S.detachBody, S.detach, function () {
				run( 'private_detach' );
			} );
		}
	} );

	// A job that was already running when the page opened is followed to its end.
	var state = root.getAttribute( 'data-net-state' );

	if ( state === 'attaching' || state === 'detaching' ) {
		buttons( true );
		wait( state === 'attaching' ? 'private_attach' : 'private_detach', 0 );
	}
}() );
