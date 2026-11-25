/**
 * Live progress of the services of an order: on the order received page and in the order section of the My Account overview.
 *
 * One request per interval to the order progress route (the block carries its URL; the overview's route is the customer's own
 * and the block carries the wp_rest nonce) serves every service row. Each row's progress block is drawn by assets/progress.js;
 * the row itself switches to "Ready" with its link to the card when the service is ready. In the overview (data-bs-flow) the
 * row also has a track that fills with the finished steps, the dashboard bar says the same through window.beltoftStatus, and a
 * closing line with each service's link shows when nothing is running any more. All texts come from the server. No dependencies.
 */
( function () {
	'use strict';

	var roots = document.querySelectorAll( '[data-beltoft-services-order]' );

	if ( ! roots.length || ! window.BeltoftServicesProgress || ! window.fetch ) {
		return;
	}

	// How long a page without services waits for them (ms).
	var WAIT_LIMIT = 180000;

	function countSteps( rows ) {
		var out = { done: 0, all: 0 };

		rows.forEach( function ( row ) {
			var steps = row.progress && row.progress.steps ? row.progress.steps : [];

			out.all += steps.length;
			steps.forEach( function ( step ) {
				if ( 'done' === step.status || 'skipped' === step.status ) {
					out.done += 1;
				}
			} );
		} );

		return out;
	}

	// Same count as Progress::percent().
	function percent( progress, ready ) {
		var steps = progress && progress.steps ? progress.steps : [];
		var counted;

		if ( ready && ! ( progress && progress.active ) ) {
			return 100;
		}

		// No steps: a ready service that is busy (a reboot) has no measure, so the bar moves instead of claiming 100.
		if ( ! steps.length ) {
			return 0;
		}

		counted = countSteps( [ { progress: progress } ] );

		return Math.round( 100 * counted.done / counted.all );
	}

	// A service that waits on the customer (a step they do) or stands still on purpose: not being set up, so nothing is polled for it.
	function waits( row ) {
		return !! row.waits || 'paused' === row.group || 'removed' === row.group;
	}

	// running, waiting, ready, mixed or failed: the same rule as OverviewOrder::state().
	function stateOf( rows ) {
		var ready = 0;
		var waiting = 0;
		var failed = 0;
		var i;

		if ( ! rows.length ) {
			return 'running';
		}

		for ( i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].progress && rows[ i ].progress.active ) {
				return 'running';
			}

			if ( 'failed' === rows[ i ].group ) {
				failed += 1;
			} else if ( waits( rows[ i ] ) ) {
				waiting += 1;
			} else if ( rows[ i ].ready ) {
				ready += 1;
			} else {
				return 'running';
			}
		}

		if ( ! failed ) {
			return waiting ? 'waiting' : 'ready';
		}

		return ready + waiting ? 'mixed' : 'failed';
	}

	function setupRoot( root ) {
		var url = root.getAttribute( 'data-progress-url' );
		var nonce = root.getAttribute( 'data-nonce' );
		var flow = root.hasAttribute( 'data-bs-flow' );
		var section = flow ? root.closest( '[data-bs-order]' ) : null;
		var known = parseInt( root.getAttribute( 'data-known' ) || '0', 10 );
		var order = root.getAttribute( 'data-order' ) || '';
		var shared = null;
		var sharedAt = 0;
		var handle = null;
		var slow = 1;
		var started = Date.now();

		if ( ! url ) {
			return;
		}

		// Services made after the page was served: the server draws them, so the page loads once more (never twice in a minute).
		function reloadOnce() {
			var key = 'beltoftOrderReload' + order;

			try {
				if ( Date.now() - parseInt( window.sessionStorage.getItem( key ) || '0', 10 ) < 60000 ) {
					return;
				}

				window.sessionStorage.setItem( key, String( Date.now() ) );
			} catch ( error ) {
				// Storage is blocked: the page is drawn with the new services at once, so one reload still ends the wait (rows.length > known
				// is false after it).
			}

			window.location.reload();
		}

		function bar( rows ) {
			var counted;
			var text;

			if ( ! flow || ! window.beltoftStatus ) {
				return;
			}

			counted = countSteps( rows );
			text = section ? section.querySelector( '[data-order-closing-text]' ) : null;

			if ( 'running' === stateOf( rows ) ) {
				if ( ! handle ) {
					handle = window.beltoftStatus.busy( root.getAttribute( 'data-bar-text' ) || 'Setting up your order', null, null, { lock: false } );
				}

				window.beltoftStatus.progress( handle, counted.all ? counted.done / counted.all : 0.06 );
			} else if ( handle ) {
				// closing() has filled the text before this runs. Some of the order failed: the bar says so, it is not "done".
				if ( 'failed' === stateOf( rows ) || 'mixed' === stateOf( rows ) ) {
					window.beltoftStatus.fail( handle, text ? text.textContent : '' );
				} else {
					window.beltoftStatus.done( handle, text ? text.textContent : '' );
				}

				handle = null;
			}
		}

		function waitingText( box, rows ) {
			var step = '';

			rows.forEach( function ( row ) {
				if ( ! step && row.waits ) {
					step = row.waits;
				}
			} );

			return step ? ( box.getAttribute( 'data-text-waiting-step' ) || '' ).replace( '%s', step ) : box.getAttribute( 'data-text-waiting-paused' );
		}

		function closing( rows ) {
			var box = section ? section.querySelector( '[data-order-closing]' ) : null;
			var state = stateOf( rows );
			var failed = 0;
			var text;
			var list;

			if ( ! box || 'running' === state ) {
				return;
			}

			rows.forEach( function ( row ) {
				if ( 'failed' === row.group ) {
					failed += 1;
				}
			} );

			text = box.querySelector( '[data-order-closing-text]' );
			list = box.querySelector( '[data-order-closing-list]' );

			if ( 'ready' === state ) {
				text.textContent = box.getAttribute( 'data-text-ready' );
			} else if ( 'waiting' === state ) {
				text.textContent = waitingText( box, rows );
			} else if ( 'mixed' === state ) {
				text.textContent = box.getAttribute( failed > 1 ? 'data-text-mixed-many' : 'data-text-mixed-one' ).replace( '2', String( failed ) );
			} else {
				text.textContent = box.getAttribute( 'data-text-failed' );
			}

			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}

			rows.forEach( function ( row ) {
				var item = root.querySelector( '[data-service="' + row.id + '"]' );
				var source = item ? item.querySelector( '[data-service-open] a' ) : null;
				var li;
				var a;

				if ( ! source || ( 'failed' !== row.group && ! row.ready ) ) {
					return;
				}

				li = document.createElement( 'li' );
				a = document.createElement( 'a' );
				a.setAttribute( 'href', row.url || '#' );
				a.textContent = source.textContent;
				li.appendChild( a );
				list.appendChild( li );
			} );

			box.hidden = false;
			section.setAttribute( 'data-state', state );
			section.className = section.className.replace( /\bis-(running|waiting|ready|mixed|failed)\b/, 'is-' + state );
		}

		function seen( rows ) {
			if ( rows.length > known && root.querySelectorAll( '[data-service]' ).length < rows.length ) {
				reloadOnce();
			}

			// The closing line first: the bar's last message is read from it.
			closing( rows );
			bar( rows );
		}

		function load() {
			var headers = nonce ? { 'X-WP-Nonce': nonce } : {};

			if ( shared && Date.now() - sharedAt < 1500 ) {
				return shared;
			}

			sharedAt = Date.now();
			shared = window.fetch( url, { credentials: 'same-origin', headers: headers } ).then( function ( response ) {
				var fresh = response.headers.get( 'X-WP-Nonce' );

				// Too many requests: look half as often (up to 8 times as long) until the server answers again.
				slow = 429 === response.status ? Math.min( slow * 2, 8 ) : 1;

				if ( fresh ) {
					nonce = fresh;
				}

				return response.ok ? response.json() : null;
			} ).then( function ( body ) {
				if ( body && body.services ) {
					seen( body.services );
				}

				return body;
			} ).catch( function () {
				return null;
			} );

			return shared;
		}

		function apply( item, row ) {
			var state = item.querySelector( '[data-service-state]' );
			var message = item.querySelector( '[data-service-message]' );
			var open = item.querySelector( '[data-service-open]' );
			var track = item.querySelector( '[data-flow-track]' );
			var listed;
			var value;

			item.className = 'beltoft-service' + ( flow ? ' bs-flow' : '' ) + ' is-' + row.group;

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

			// The server list on the same page is drawn once, while the service was still being set up: when it is ready, its row
			// stops saying Working (the same words as the order line).
			if ( row.ready && ! ( row.progress && row.progress.active ) ) {
				listed = document.querySelectorAll( '#beltoft-service-' + row.id + ' .beltoft-services-state.is-busy, [data-service-row="' + row.id + '"] .beltoft-services-state.is-busy' );

				Array.prototype.forEach.call( listed, function ( el ) {
					el.textContent = row.label;
					el.className = 'beltoft-services-state is-ok';
				} );
			}

			if ( track ) {
				value = percent( row.progress, row.ready );
				track.setAttribute( 'aria-valuenow', String( value ) );
				track.firstChild.style.width = value + '%';
			}
		}

		// The services did not show up in time (deleted, never made): the page stops asking and points to the order.
		function giveUp() {
			var line = root.querySelector( '[data-order-gaveup]' );
			var wait = root.querySelector( '.bs-order__waiting' );

			if ( wait ) {
				wait.hidden = true;
			}

			if ( line ) {
				line.hidden = false;
			}

			if ( handle && window.beltoftStatus ) {
				window.beltoftStatus.done( handle, '' );
				handle = null;
			}
		}

		// Nothing to follow yet: ask until the services exist (then the page reloads), for three minutes at most.
		if ( ! root.querySelector( '[data-service]' ) ) {
			( function again() {
				if ( Date.now() - started > WAIT_LIMIT ) {
					giveUp();

					return;
				}

				window.setTimeout( function () {
					load().then( again );
				}, 4000 * slow );
			}() );

			return;
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
				interval: function () {
					return 5000 * slow;
				},
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
	}

	Array.prototype.forEach.call( roots, setupRoot );
}() );
