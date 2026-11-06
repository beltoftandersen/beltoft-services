/**
 * Live progress of a service being set up, reinstalled, restored or removed.
 *
 * Draws one payload (the array Progress::build() makes) into the block that
 * templates/account/progress.php prints, and can poll for new payloads. It knows no route: the
 * caller gives it a source (a function that resolves to a payload), or the block carries a
 * data-progress-url that answers with the payload (or with {progress: payload}). The account card
 * draws the payload that its own status poll already fetched. All texts come from the server.
 * No dependencies.
 *
 *   BeltoftServicesProgress.render( element, payload )          draws; returns payload.active
 *   BeltoftServicesProgress.mount( element, { source, interval, onFinished } )   polls until finished
 */
( function () {
	'use strict';

	function steps( list, payload ) {
		var items = payload.steps || [];

		list.hidden = ! items.length;

		while ( list.firstChild ) {
			list.removeChild( list.firstChild );
		}

		items.forEach( function ( step ) {
			var item = document.createElement( 'li' );
			var hidden = document.createElement( 'span' );

			item.className = 'is-' + step.status;
			item.setAttribute( 'data-step', step.key );

			if ( 'current' === step.status ) {
				item.setAttribute( 'aria-current', 'step' );
			}

			item.appendChild( document.createTextNode( step.label + ' ' ) );
			hidden.className = 'screen-reader-text';
			hidden.textContent = step.status_label || '';
			item.appendChild( hidden );
			list.appendChild( item );
		} );
	}

	function render( element, payload ) {
		var text = element.querySelector( '[data-progress-text]' );
		var list = element.querySelector( '[data-progress-steps]' );
		var leave = element.querySelector( '[data-progress-leave]' );

		if ( ! payload || 'object' !== typeof payload ) {
			return false;
		}

		element.className = 'beltoft-services-progress is-' + payload.phase;
		element.setAttribute( 'data-phase', payload.phase );

		if ( payload.active ) {
			element.setAttribute( 'aria-busy', 'true' );
		} else {
			element.removeAttribute( 'aria-busy' );
		}

		// Only a changed sentence is written, so a screen reader hears each step once.
		if ( text && text.textContent !== payload.text ) {
			text.textContent = payload.text || '';
		}

		if ( list ) {
			steps( list, payload );
		}

		var note = element.querySelector( '[data-progress-note]' );

		if ( note ) {
			var noteText = note.querySelector( '[data-progress-note-text]' );
			var hosts = note.querySelector( '[data-progress-hosts]' );
			var link = note.querySelector( '[data-progress-link]' );
			var names = payload.hosts || [];

			note.hidden = ! payload.note;

			if ( noteText ) { noteText.textContent = payload.note || ''; }

			if ( hosts ) {
				while ( hosts.firstChild ) { hosts.removeChild( hosts.firstChild ); }
				names.forEach( function ( host ) {
					var li = document.createElement( 'li' );
					li.textContent = host;
					hosts.appendChild( li );
				} );
				hosts.hidden = ! names.length;
			}

			if ( link ) {
				while ( link.firstChild ) { link.removeChild( link.firstChild ); }
				if ( payload.link && payload.link.url ) {
					var a = document.createElement( 'a' );
					a.href = payload.link.url;
					a.textContent = payload.link.label || '';
					link.appendChild( a );
				}
				link.hidden = ! ( payload.link && payload.link.url );
			}
		}

		if ( leave ) {
			leave.textContent = payload.leave || '';
			leave.hidden = ! payload.leave;
		}

		return !! payload.active;
	}

	function fetchSource( url ) {
		return function () {
			return window.fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( body ) {
				return body && body.progress ? body.progress : body;
			} );
		};
	}

	function mount( element, options ) {
		var source = options.source || fetchSource( element.getAttribute( 'data-progress-url' ) );
		var interval = options.interval || 5000;
		var wasActive = 'queued' === element.getAttribute( 'data-phase' ) || 'running' === element.getAttribute( 'data-phase' );

		function again() {
			Promise.resolve( source() ).then( function ( payload ) {
				var active = payload ? render( element, payload ) : wasActive;

				if ( active ) {
					wasActive = true;
					wait();
				} else if ( payload && options.onFinished ) {
					options.onFinished( payload, wasActive );
				}
			} ).catch( wait );
		}

		// No polling in a hidden tab; it looks again when the tab comes back.
		function wait() {
			window.setTimeout( function () {
				if ( ! document.hidden ) {
					again();
					return;
				}

				document.addEventListener( 'visibilitychange', function back() {
					if ( ! document.hidden ) {
						document.removeEventListener( 'visibilitychange', back );
						again();
					}
				} );
			}, interval );
		}

		again();
	}

	window.BeltoftServicesProgress = { render: render, mount: mount };
}() );
