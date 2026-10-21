/**
 * My Account → Licenses: the service panels.
 *
 * Asks for each panel's state once (which also lets the server refresh usage in the
 * background), then every 10 seconds while the state differs from the desired one; once it
 * settles the page reloads to show the service's controls. Action buttons queue the action.
 * Uses the WordPress REST API with the wp_rest nonce, kept fresh from each response's
 * X-WP-Nonce header; an Open link gets a fresh nonce from WordPress's rest-nonce action when it
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
	}

	function poll( panel ) {
		var id = panel.getAttribute( 'data-resource' );
		var first = panel.getAttribute( 'data-state' );

		request( 'GET', id ).then( function ( response ) {
			if ( 429 === response.status ) {
				window.setTimeout( function () {
					poll( panel );
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
			if ( ! data ) {
				return;
			}

			show( panel, data );

			// A failed service waits for the shop: no point in asking again.
			if ( data.state !== data.desired && 'failed' !== data.state ) {
				window.setTimeout( function () {
					poll( panel );
				}, parseInt( config.interval, 10 ) || 10000 );
			} else if ( data.state !== first ) {
				// Settled in a new state: the driver's part and the buttons change with it.
				window.location.reload();
			}
		} ).catch( function () {} );
	}

	function act( panel, button ) {
		var id = panel.getAttribute( 'data-resource' );

		button.disabled = true;

		request( 'POST', id + '/actions/' + encodeURIComponent( button.getAttribute( 'data-action' ) ) ).then( function ( response ) {
			if ( 202 === response.status ) {
				notice( panel, config.strings.queued );
				return;
			}

			button.disabled = false;

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
			button.disabled = false;
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
