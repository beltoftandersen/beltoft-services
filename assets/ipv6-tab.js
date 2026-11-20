/**
 * My Account, Servers, the IPv6 tab: add or remove IPv6. Each button asks first (the question says the server restarts
 * once), posts the customer action, waits for the job through the status route, then reloads the page. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesIpv6;
	var root = document.querySelector( '[data-ipv6]' );

	if ( ! account || ! cfg || ! root || ! window.fetch || ! window.beltoftApi ) {
		return;
	}

	var S = cfg.strings;
	var id = root.getAttribute( 'data-resource' );
	var bs = window.beltoftStatus;
	var handle = null;
	var rounds = 0;

	function request( method, path, body ) {
		return window.beltoftApi.call( path, { method: method, body: body || undefined, holder: account } );
	}

	// action: the action posted (a job that was already running when the page opened passes '' and is followed until it is no longer working).
	function wait( action, since ) {
		function again() {
			rounds += 1;

			// Ten minutes without an answer: the service is given back with a message, not locked until a reload.
			if ( rounds > 150 ) {
				bs.fail( handle, S.error );
				handle = null;
				return;
			}

			window.setTimeout( function () {
				wait( action, since );
			}, 4000 );
		}

		request( 'GET', account.root + id ).then( function ( reply ) {
			var result = reply.data && reply.data.last_result;

			if ( reply.ok && ! reply.data.working && '' === action ) {
				window.location.reload();
				return;
			}

			if ( reply.ok && ! reply.data.working && result && result.action === action && result.at >= since ) {
				if ( result.ok ) {
					window.location.reload();
				} else {
					bs.fail( handle, result.text || S.failed );
					handle = null;
				}

				return;
			}

			again();
		} ).catch( again );
	}

	function run( action, button ) {
		if ( bs.locked( id ) ) {
			return;
		}

		rounds = 0;
		handle = bs.busy( S.working, id, button );
		request( 'POST', account.root + id + '/actions/' + action, { args: {} } ).then( function ( reply ) {
			if ( reply.ok ) {
				wait( action, reply.data && reply.data.at ? reply.data.at - 1 : 0 );
			} else {
				bs.fail( handle, ( reply.data && reply.data.message ) || S.error );
				handle = null;
			}
		} ).catch( function () {
			bs.fail( handle, S.error );
			handle = null;
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( 'button[data-ipv6-action]' );

		if ( ! button || bs.locked( id ) ) {
			return;
		}

		bs.confirm( { title: button.getAttribute( 'data-title' ), text: button.getAttribute( 'data-text' ), confirm: button.getAttribute( 'data-confirm' ) } ).then( function ( yes ) {
			if ( yes ) {
				run( button.getAttribute( 'data-ipv6-action' ), button );
			}
		} );
	} );

	// A job that was already running when the page opened is followed to its end.
	if ( root.getAttribute( 'data-ipv6-busy' ) === '1' ) {
		handle = bs.busy( S.working, id, null );
		wait( '', 0 );
	}
}() );
