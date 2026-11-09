/**
 * My Account → Licenses: the service panels.
 *
 * Asks for each panel's state once (which also lets the server refresh usage in the
 * background), then every 10 seconds while the state differs from the desired one; once it
 * settles the page reloads to show the service's controls. Action buttons queue the action.
 * Uses the WordPress REST API with the wp_rest nonce, kept fresh from each response's
 * X-WP-Nonce header; after a queued action the button stays off for about ten seconds, then the page reloads; an Open link gets a fresh nonce from WordPress's rest-nonce action when it
 * is clicked, so a page left open for a day still works. Needs assets/api.js (window.beltoftApi).
 */
( function () {
	'use strict';

	var config = window.beltoftServicesAccount;

	if ( ! config || ! window.fetch || ! window.beltoftApi ) {
		return;
	}

	function request( method, path ) {
		return window.beltoftApi.call( config.root + path, { method: method, body: 'POST' === method ? '{}' : undefined, holder: config } );
	}

	function retryAfter( result ) {
		var seconds = parseInt( result.response.headers.get( 'Retry-After' ), 10 );

		return ( isNaN( seconds ) ? 60 : Math.max( 1, seconds ) ) * 1000;
	}

	var bs = window.beltoftStatus;

	// A problem goes to the status bar, under the page's tabs.
	function notice( panel, text ) {
		bs.fail( null, text );
	}

	// Waits for the tab to be visible before a poll runs, and polls again when it comes back.
	function later( run, ms ) {
		window.setTimeout( function () {
			if ( ! document.hidden ) {
				run();
				return;
			}

			document.addEventListener( 'visibilitychange', function again() {
				if ( ! document.hidden ) {
					document.removeEventListener( 'visibilitychange', again );
					run();
				}
			} );
		}, ms );
	}

	function show( panel, data ) {
		var badge = panel.querySelector( '[data-badge]' );
		var message = panel.querySelector( '[data-message]' );

		if ( badge && data.label ) {
			badge.textContent = data.label;
		}

		if ( message && data.message ) {
			message.textContent = data.message;
		}

		// The live progress block: drawn from the payload this same poll fetched.
		var block = panel.querySelector( '[data-progress]' );

		if ( block && data.progress && window.BeltoftServicesProgress ) {
			window.BeltoftServicesProgress.render( block, data.progress );
		}

		// Active only while the server says work is going on: a queued form alone proves nothing.
		// A shutdown or start that the VM has not followed yet counts as work: the poll goes on until it does (or the server gives up waiting).
		return !! ( data.working || data.power_pending || ( data.progress && data.progress.active ) );
	}

	function poll( panel ) {
		var id = panel.getAttribute( 'data-resource' );
		var first = panel.getAttribute( 'data-state' );
		var run = ( panel.beltoftRun || 0 ) + 1;
		var block = panel.querySelector( '[data-progress]' );

		// Only the newest chain goes on: a queued action starts a fresh one at once.
		panel.beltoftRun = run;
		panel.beltoftActive = panel.beltoftActive || ( !! block && /^(queued|running)$/.test( block.getAttribute( 'data-phase' ) ) );
		// Back on the page after a while: look at once.

		request( 'GET', id ).then( function ( result ) {
			if ( 429 === result.status ) {
				later( function () {
					if ( run === panel.beltoftRun ) {
						poll( panel );
					}
				}, retryAfter( result ) );
				return null;
			}

			if ( result.ok ) {
				// A body that is not JSON ends the polling, as it always has.
				return result.parsed ? result.data : null;
			}

			// Refused (paused, no longer active, or a stale session): stop asking.
			if ( result.expired ) {
				notice( panel, config.strings.expired );
			}

			return null;
		} ).then( function ( data ) {
			var active;

			if ( ! data || run !== panel.beltoftRun ) {
				return;
			}

			active = show( panel, data );
			// Other scripts of the card (the server card's done line) look at the same status.
			panel.dispatchEvent( new window.CustomEvent( 'beltoft-services:status', { detail: data } ) );
			panel.beltoftActive = panel.beltoftActive || active;

			// A failed service waits for the shop: no point in asking again.
			if ( ( data.state !== data.desired || active ) && 'failed' !== data.state ) {
				later( function () {
					if ( run === panel.beltoftRun ) {
						poll( panel );
					}
				}, active ? 5000 : ( parseInt( config.interval, 10 ) || 10000 ) );
			} else if ( data.state !== first || panel.beltoftActive ) {
				// Settled in a new state, or the work shown has finished: the driver's part and the buttons change with it.
				window.location.reload();
			}
		} ).catch( function () {} );
	}

	// After a queued action: look a few times (the job usually finishes within seconds), then
	// reload, which shows the new state and gives the button back.
	function settle( panel, rounds ) {
		var id = panel.getAttribute( 'data-resource' );
		var first = panel.getAttribute( 'data-state' );

		function again() {
			if ( rounds < 1 ) {
				window.location.reload();
				return;
			}

			rounds -= 1;

			request( 'GET', id ).then( function ( result ) {
				if ( result.ok && ! result.parsed ) {
					throw new Error( 'json' );
				}

				return result.ok ? result.data : null;
			} ).then( function ( data ) {
				if ( data && ! data.power_pending && ( data.state !== first || false === data.working ) ) {
					window.location.reload();
					return;
				}

				window.setTimeout( again, 2000 );
			} ).catch( function () {
				window.setTimeout( again, 2000 );
			} );
		}

		window.setTimeout( again, 2000 );
	}

	function act( panel, button ) {
		var id = panel.getAttribute( 'data-resource' );
		var action = button.getAttribute( 'data-action' );
		var own = window.beltoftServicesVm && window.beltoftServicesVm.strings ? window.beltoftServicesVm.strings : {};
		var handle;

		// One request per click: while this service works, nothing else is sent for it.
		if ( bs.locked( id ) ) {
			return;
		}

		handle = bs.busy( own[ action + 'Start' ] || config.strings.working, id, button );

		request( 'POST', id + '/actions/' + encodeURIComponent( action ) ).then( function ( result ) {
			var message;

			if ( 202 === result.status ) {
				// The bar keeps saying so, and the buttons stay locked, until the page reloads at the end.
				panel.beltoftWork = handle;
				settle( panel, 5 );
				return;
			}

			if ( result.expired ) {
				message = config.strings.expired;
			} else if ( 429 === result.status ) {
				message = config.strings.tooMany;
			} else {
				message = 403 === result.status ? config.strings.refused : config.strings.failed;
			}

			bs.fail( handle, message );
		} ).catch( function () {
			bs.fail( handle, config.strings.failed );
		} );
	}

	// Follows an Open link with a nonce fetched now, in the tab the link would have opened.
	function open( panel, link ) {
		var target = '_blank' === link.getAttribute( 'target' ) ? window.open( '', '_blank' ) : null;

		if ( target ) {
			target.opener = null;
		}

		window.fetch( config.nonceUrl, { credentials: 'same-origin' } ).then( function ( response ) {
			return response.ok ? response.text() : '';
		} ).then( function ( nonce ) {
			var url;

			nonce = String( nonce ).trim();

			if ( ! /^[a-f0-9]{10}$/.test( nonce ) ) {
				throw new Error( 'expired' );
			}

			config.nonce = nonce;
			url = new URL( link.href, window.location.href );
			url.searchParams.set( '_wpnonce', nonce );

			if ( target ) {
				target.location.href = url.toString();
			} else {
				window.location.href = url.toString();
			}
		} ).catch( function () {
			if ( target ) {
				target.close();
			}
			notice( panel, config.strings.expired );
		} );
	}

	document.querySelectorAll( '.beltoft-services-panel[data-resource]' ).forEach( function ( panel ) {
		// A panel the status route would refuse (paused, ended) is not asked on load.
		if ( '1' !== panel.getAttribute( 'data-idle' ) ) {
			poll( panel );
		}

		// A form of another script (the server card) queued a slow action: show its progress now.
		panel.addEventListener( 'beltoft-services:queued', function ( event ) {
			poll( panel );

			// A power action of the server page: look a few times, then reload, as the card's own buttons do.
			if ( event.detail && event.detail.settle ) {
				settle( panel, 5 );
			}
		} );

		panel.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.beltoft-services-action' );
			var link = event.target.closest( 'a[data-beltoft-services-open]' );

			if ( link && config.nonceUrl && window.URL ) {
				event.preventDefault();
				open( panel, link );
				return;
			}

			if ( button && ! button.disabled && ! button.hasAttribute( 'aria-busy' ) ) {
				act( panel, button );
			}
		} );
	} );

	// A link to a card's details block (the disk setup guide) opens it.
	function openTarget() {
		var id = window.location.hash.slice( 1 );
		try { id = decodeURIComponent( id ); } catch ( e ) { /* a malformed hash is used as it is */ }
		var target = id ? document.getElementById( id ) : null;

		if ( target && 'DETAILS' === target.tagName ) {
			target.open = true;
			target.scrollIntoView();
		}
	}

	openTarget();
	window.addEventListener( 'hashchange', openTarget );
}() );
