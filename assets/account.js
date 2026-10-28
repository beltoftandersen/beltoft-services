/**
 * My Account → Licenses: the service panels.
 *
 * Asks for each panel's state once (which also lets the server refresh usage in the
 * background), then every 10 seconds while the state differs from the desired one; once it
 * settles the page reloads to show the service's controls. Action buttons queue the action.
 * Uses the WordPress REST API with the wp_rest nonce, kept fresh from each response's
 * X-WP-Nonce header; after a queued action the button stays off for about ten seconds, then the page reloads; an Open link gets a fresh nonce from WordPress's rest-nonce action when it
 * is clicked, so a page left open for a day still works. No dependencies.
 */
( function () {
	'use strict';

	var config = window.beltoftServicesAccount;

	if ( ! config || ! window.fetch ) {
		return;
	}

	function remember( response ) {
		var nonce = response.headers.get( 'X-WP-Nonce' );

		if ( nonce ) {
			config.nonce = nonce;
		}

		return response;
	}

	function request( method, path ) {
		return window.fetch( config.root + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json'
			},
			body: 'POST' === method ? '{}' : undefined
		} ).then( remember );
	}

	// Resolves to true when a 403 is WordPress refusing a stale nonce.
	function expired( response ) {
		if ( 403 !== response.status ) {
			return Promise.resolve( false );
		}

		return response.json().then( function ( body ) {
			return !! body && 'rest_cookie_invalid_nonce' === body.code;
		}, function () {
			return false;
		} );
	}

	function retryAfter( response ) {
		var seconds = parseInt( response.headers.get( 'Retry-After' ), 10 );

		return ( isNaN( seconds ) ? 60 : Math.max( 1, seconds ) ) * 1000;
	}

	function notice( panel, text ) {
		var line = panel.querySelector( '[data-notice]' );

		if ( line ) {
			line.textContent = text;
		}
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
			panel.beltoftActive = window.BeltoftServicesProgress.render( block, data.progress ) || panel.beltoftActive;
			return data.progress.active;
		}

		return false;
	}

	// A button that has been clicked shows it is working; reset() gives it back after an error.
	function busy( button ) {
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		button.classList.add( 'is-busy' );

		if ( undefined === button.beltoftLabel ) {
			button.beltoftLabel = button.textContent;
			button.textContent = config.strings.working;
		}
	}

	function reset( button ) {
		button.disabled = false;
		button.removeAttribute( 'aria-busy' );
		button.classList.remove( 'is-busy' );

		if ( undefined !== button.beltoftLabel ) {
			button.textContent = button.beltoftLabel;
			button.beltoftLabel = undefined;
		}
	}

	function poll( panel ) {
		var id = panel.getAttribute( 'data-resource' );
		var first = panel.getAttribute( 'data-state' );
		var run = ( panel.beltoftRun || 0 ) + 1;
		var block = panel.querySelector( '[data-progress]' );

		// Only the newest chain goes on: a queued action starts a fresh one at once.
		panel.beltoftRun = run;
		panel.beltoftActive = panel.beltoftActive || ( !! block && /^(queued|running)$/.test( block.getAttribute( 'data-phase' ) ) );

		request( 'GET', id ).then( function ( response ) {
			if ( 429 === response.status ) {
				window.setTimeout( function () {
					if ( run === panel.beltoftRun ) {
						poll( panel );
					}
				}, retryAfter( response ) );
				return null;
			}

			if ( response.ok ) {
				return response.json();
			}

			// Refused (paused, no longer active, or a stale session): stop asking.
			return expired( response ).then( function ( stale ) {
				if ( stale ) {
					notice( panel, config.strings.expired );
				}
				return null;
			} );
		} ).then( function ( data ) {
			var active;

			if ( ! data || run !== panel.beltoftRun ) {
				return;
			}

			active = show( panel, data );

			// A failed service waits for the shop: no point in asking again.
			if ( ( data.state !== data.desired || active ) && 'failed' !== data.state ) {
				window.setTimeout( function () {
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

			request( 'GET', id ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( data ) {
				if ( data && ( data.state !== first || false === data.working ) ) {
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

		busy( button );

		request( 'POST', id + '/actions/' + encodeURIComponent( button.getAttribute( 'data-action' ) ) ).then( function ( response ) {
			if ( 202 === response.status ) {
				notice( panel, config.strings.queued );
				settle( panel, 5 );
				return;
			}

			reset( button );

			return expired( response ).then( function ( stale ) {
				if ( stale ) {
					notice( panel, config.strings.expired );
				} else if ( 429 === response.status ) {
					notice( panel, config.strings.tooMany );
				} else {
					notice( panel, 403 === response.status ? config.strings.refused : config.strings.failed );
				}
			} );
		} ).catch( function () {
			reset( button );
			notice( panel, config.strings.failed );
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
		poll( panel );

		// A form of another script (the server card) queued a slow action: show its progress now.
		panel.addEventListener( 'beltoft-services:queued', function () {
			panel.beltoftActive = true;
			poll( panel );
		} );

		panel.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.beltoft-services-action' );
			var link = event.target.closest( 'a[data-beltoft-services-open]' );

			if ( link && config.nonceUrl && window.URL ) {
				event.preventDefault();
				open( panel, link );
				return;
			}

			if ( button && ! button.disabled ) {
				act( panel, button );
			}
		} );
	} );
}() );
