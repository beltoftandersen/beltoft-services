/**
 * Live progress of the services on the order received page.
 *
 * One request per interval to the order progress route (the block carries its URL, with the order
 * key) serves every service row. Each row's progress block is drawn by assets/progress.js; the row
 * itself switches to "Ready" with its link to the card when the service is ready. All texts come
 * from the server. No dependencies.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-beltoft-services-order]' );
	var url = root ? root.getAttribute( 'data-progress-url' ) : '';
	var shared = null;
	var sharedAt = 0;

	if ( ! root || ! url || ! window.BeltoftServicesProgress || ! window.fetch ) {
		return;
	}

	function load() {
		if ( shared && Date.now() - sharedAt < 1500 ) {
			return shared;
		}

		sharedAt = Date.now();
		shared = window.fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
			return response.ok ? response.json() : null;
		} ).catch( function () {
			return null;
		} );

		return shared;
	}

	function apply( item, row ) {
		var state = item.querySelector( '[data-service-state]' );
		var message = item.querySelector( '[data-service-message]' );
		var open = item.querySelector( '[data-service-open]' );

		item.className = 'beltoft-service is-' + row.group;

		if ( state && state.textContent !== row.label ) {
			state.textContent = row.label;
		}

		if ( message ) {
			message.textContent = row.message || '';
			message.hidden = ! row.message;
		}

		if ( open ) {
			open.hidden = ! row.ready;
		}
	}

	Array.prototype.forEach.call( root.querySelectorAll( '[data-service]' ), function ( item ) {
		var id = item.getAttribute( 'data-service' );
		var block = item.querySelector( '[data-progress]' );
		var phase = block ? block.getAttribute( 'data-phase' ) : 'idle';

		// A service that is ready or has stopped needs no polling.
		if ( ! block || ( 'queued' !== phase && 'running' !== phase ) ) {
			return;
		}

		window.BeltoftServicesProgress.mount( block, {
			interval: 5000,
			source: function () {
				return load().then( function ( body ) {
					var rows = body && body.services ? body.services : [];
					var row = null;

					rows.forEach( function ( candidate ) {
						if ( String( candidate.id ) === id ) {
							row = candidate;
						}
					} );

					if ( ! row ) {
						return null;
					}

					apply( item, row );

					return row.progress;
				} );
			}
		} );
	} );
}() );
