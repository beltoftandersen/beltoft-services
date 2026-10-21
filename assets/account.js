/**
 * My Account → Licenses: the service panels.
 *
 * Asks for each panel's state once (which also lets the server refresh usage in the
 * background), then every 10 seconds while the state differs from the desired one; once it
 * settles the page reloads to show the service's controls. Action buttons queue the action.
 * Uses the WordPress REST API with the wp_rest nonce. No dependencies.
 */
( function () {
	'use strict';

	var config = window.beltoftServicesAccount;

	if ( ! config || ! window.fetch ) {
		return;
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

			// Refused (paused, no longer active): stop asking.
			return response.ok ? response.json() : null;
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
			notice( panel, 429 === response.status ? config.strings.tooMany : ( 403 === response.status ? config.strings.refused : config.strings.failed ) );
		} ).catch( function () {
			button.disabled = false;
			notice( panel, config.strings.failed );
		} );
	}

	document.querySelectorAll( '.beltoft-services-panel[data-resource]' ).forEach( function ( panel ) {
		poll( panel );

		panel.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.beltoft-services-action' );

			if ( button && ! button.disabled ) {
				act( panel, button );
			}
		} );
	} );
}() );
