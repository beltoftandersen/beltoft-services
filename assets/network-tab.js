/**
 * My Account → Servers → the Network tab: attach and detach the private network. Each button asks first, posts the
 * customer action, waits for the job through the status route, then reloads the page. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesNetwork;
	var root = document.querySelector( '[data-net]' );

	if ( ! account || ! cfg || ! root || ! window.fetch || ! window.beltoftApi ) {
		return;
	}

	var S = cfg.strings;
	var id = root.getAttribute( 'data-resource' );
	var timer = null;

	function request( method, path, body ) {
		return window.beltoftApi.call( path, { method: method, body: body || undefined, holder: account } );
	}

	var bs = window.beltoftStatus;
	var handle = null;

	function wait( action, since ) {
		request( 'GET', account.root + id ).then( function ( reply ) {
			var result = reply.data && reply.data.last_result;

			if ( reply.ok && ! reply.data.working && result && result.action === action && result.at >= since ) {
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

	// The network the customer chose: an id, or "new" with a name. Without the choice box (a server that is attached) nothing is sent.
	function chosen() {
		var select = root.querySelector( '[data-net-select]' );
		var name = root.querySelector( '[data-net-name]' );
		var args = {};

		if ( select ) {
			args.net = select.value;
		} else if ( name ) {
			args.net = 'new';
		}

		if ( args.net === 'new' && name ) {
			args.name = name.value;
		}

		return args;
	}

	function run( action, button, args ) {
		if ( bs.locked( id ) ) {
			return;
		}

		handle = bs.busy( S.working, id, button );
		request( 'POST', account.root + id + '/actions/' + action, { args: args || {} } ).then( function ( reply ) {
			if ( reply.ok ) {
				wait( action, reply.data && reply.data.at ? reply.data.at - 1 : 0 );
			} else {
				bs.fail( handle, ( reply.data && reply.data.message ) || S.error );
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
					run( 'private_attach', target, chosen() );
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

	// The name field shows only for a new network.
	var picker = root.querySelector( '[data-net-select]' );
	var nameRow = root.querySelector( '[data-net-name-row]' );

	if ( picker && nameRow ) {
		picker.addEventListener( 'change', function () {
			nameRow.hidden = picker.value !== 'new';
		} );
	}

	// A job that was already running when the page opened is followed to its end.
	var state = root.getAttribute( 'data-net-state' );

	if ( state === 'attaching' || state === 'detaching' ) {
		handle = bs.busy( S.working, id, null );
		wait( state === 'attaching' ? 'private_attach' : 'private_detach', 0 );
	}
}() );
