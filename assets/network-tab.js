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

	var bs = window.beltoftStatus;
	var handle = null;

	function wait( action, since ) {
		request( 'GET', account.root + id ).then( function ( reply ) {
			var result = reply.body && reply.body.last_result;

			if ( reply.ok && ! reply.body.working && result && result.action === action && result.at >= since ) {
				if ( result.ok ) {
					window.location.reload();
				} else {
					bs.fail( handle, result.text || S.failed );
					handle = null;
				}

				return;
			}

			timer = window.setTimeout( function () {
				wait( action, since );
			}, 4000 );
		} );
	}

	function run( action, button ) {
		if ( bs.locked( id ) ) {
			return;
		}

		handle = bs.busy( S.working, id, button );
		request( 'POST', account.root + id + '/actions/' + action, { args: {} } ).then( function ( reply ) {
			if ( reply.ok ) {
				wait( action, reply.body && reply.body.at ? reply.body.at - 1 : 0 );
			} else {
				bs.fail( handle, ( reply.body && reply.body.message ) || S.error );
				handle = null;
			}
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button' );

		if ( ! target || bs.locked( id ) ) {
			return;
		}

		if ( target.hasAttribute( 'data-net-attach' ) ) {
			bs.confirm( { title: S.attachTitle, text: S.attachBody, confirm: S.attach } ).then( function ( yes ) {
				if ( yes ) {
					run( 'private_attach', target );
				}
			} );
		} else if ( target.hasAttribute( 'data-net-detach' ) ) {
			bs.confirm( { title: S.detachTitle, text: S.detachBody, confirm: S.detach } ).then( function ( yes ) {
				if ( yes ) {
					run( 'private_detach', target );
				}
			} );
		}
	} );

	// A job that was already running when the page opened is followed to its end.
	var state = root.getAttribute( 'data-net-state' );

	if ( state === 'attaching' || state === 'detaching' ) {
		handle = bs.busy( S.working, id, null );
		wait( state === 'attaching' ? 'private_attach' : 'private_detach', 0 );
	}
}() );
