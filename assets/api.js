/**
 * The account area's one way to talk to the plugin's REST routes, and to copy text.
 *
 * window.beltoftApi.call( urlOrPath, options ) sends the wp_rest nonce in X-WP-Nonce, stores the refreshed nonce from the
 * response header back on the object that holds it, and resolves to { ok, status, data, parsed, expired, response }:
 * data is the parsed JSON (an empty object when the body is not JSON, parsed is false then), expired is true when
 * WordPress refused a stale nonce (403 rest_cookie_invalid_nonce), response is the raw Response (for headers such as
 * Retry-After). A network failure rejects, as fetch does.
 *
 * Options: method (default GET), body (an object, sent as JSON; a string is sent as it is), holder (the object with
 * .nonce and .root; default window.beltoftServicesAccount; each script passes the localized object it already reads),
 * headers (more headers), plus any other fetch option (cache, signal). A path that does not start with / or http is
 * joined to holder.root.
 *
 * window.beltoftApi.follow( queued, options ) follows a queued customer action to its end: queued is the 202 answer of a route
 * (its `status` path, /resources/<id>, which is read below the holder's root as the other account scripts do, and `at`), and it reads that status every few seconds until the job is over. It resolves to the outcome
 * {action, ok, at, text} (the resource's last_result) or, when the job is still going after options.rounds looks (default 30),
 * to null. Options: holder (as for call), interval (milliseconds, default 4000), rounds, onTick( data ) with every status read.
 * Nothing is rejected for a failed job: the outcome has ok false and the server's own text.
 * With options.until = 'idle' it follows the service instead of one action: it resolves to { idle: true, data } when the status
 * shows no running task (`task` empty on the hosting overview, else no `working` and no `progress.active`), whatever the last
 * action was; options.path is the status path to read (default: the queued answer's status), the look is every 5 seconds (15
 * after ten minutes), and options.timeout (milliseconds, default seven hours) ends it with null, never with success. A 429 waits
 * for Retry-After in both modes.
 *
 * window.beltoftApi.copy( text, button ) copies text (the clipboard, or a hidden textarea and the old copy command) and
 * resolves when it worked; with a button, its label shows the button's data-copied text for two seconds. No dependencies.
 */
( function () {
	'use strict';

	function resolveUrl( target, holder ) {
		if ( /^(https?:)?\/\//.test( target ) || '/' === target.charAt( 0 ) ) {
			return target;
		}

		return ( holder && holder.root ? holder.root : '' ) + target;
	}

	function call( target, options ) {
		var opts = {};
		var key;
		var settings = options || {};
		var holder = settings.holder || window.beltoftServicesAccount || {};
		var method = settings.method || 'GET';
		var headers = { 'X-WP-Nonce': holder.nonce };
		var body = settings.body;

		for ( key in settings ) {
			if ( Object.prototype.hasOwnProperty.call( settings, key ) && -1 === [ 'method', 'body', 'holder', 'headers' ].indexOf( key ) ) {
				opts[ key ] = settings[ key ];
			}
		}

		if ( undefined !== body ) {
			headers[ 'Content-Type' ] = 'application/json';
			opts.body = 'string' === typeof body ? body : JSON.stringify( body );
		} else if ( 'GET' !== method ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		for ( key in ( settings.headers || {} ) ) {
			if ( Object.prototype.hasOwnProperty.call( settings.headers, key ) ) {
				headers[ key ] = settings.headers[ key ];
			}
		}

		opts.method = method;
		opts.credentials = 'same-origin';
		opts.headers = headers;

		return window.fetch( resolveUrl( String( target ), holder ), opts ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				holder.nonce = nonce;
			}

			return response.json().then( function ( data ) {
				return {
					ok: response.ok,
					status: response.status,
					data: data || {},
					parsed: true,
					expired: 403 === response.status && !! data && 'rest_cookie_invalid_nonce' === data.code,
					response: response
				};
			}, function () {
				return { ok: response.ok, status: response.status, data: {}, parsed: false, expired: false, response: response };
			} );
		} );
	}

	function retryAfter( reply ) {
		var seconds = parseInt( reply.response && reply.response.headers ? reply.response.headers.get( 'Retry-After' ) : '', 10 );

		return ( isNaN( seconds ) ? 30 : Math.min( 120, Math.max( 5, seconds ) ) ) * 1000;
	}

	// Is the service idle in this status answer? The hosting overview says it with `task` (null when none); any other
	// resource has no job (`working` false) and no `progress.active` marker.
	function isIdle( data ) {
		if ( Object.prototype.hasOwnProperty.call( data, 'task' ) ) {
			return ! data.task;
		}

		return ! data.working && ! ( data.progress && data.progress.active );
	}

	function follow( queued, options ) {
		var settings = options || {};
		var holder = settings.holder || window.beltoftServicesAccount || {};
		var untilIdle = 'idle' === settings.until;
		var rounds = settings.rounds || 30;
		var interval = settings.interval || ( untilIdle ? 5000 : 4000 );
		var started = Date.now();
		var limit = settings.timeout || 25200000;
		var since = ( queued && queued.at ? queued.at : 0 ) - 1;
		var action = queued ? queued.action : '';
		var path = settings.path || ( queued && queued.status ? queued.status.replace( /^\/?resources\//, '' ) : '' );

		return new Promise( function ( resolve ) {
			function look() {
				call( path, { holder: holder } ).then( function ( reply ) {
					var data = reply.data || {};
					var result = data.last_result;

					if ( 429 === reply.status ) {
						next( retryAfter( reply ), true );
						return;
					}

					if ( reply.ok && settings.onTick ) {
						settings.onTick( data );
					}

					if ( untilIdle ) {
						if ( reply.ok && isIdle( data ) ) {
							resolve( { idle: true, data: data } );
							return;
						}
					} else if ( reply.ok && ! data.working && result && result.action === action && result.at >= since ) {
						resolve( result );
						return;
					}

					next();
				}, function () {
					next();
				} );
			}

			// A timeout is never an answer: the caller gets null and must not claim the work succeeded.
			function next( wait, keepRound ) {
				if ( untilIdle ) {
					if ( Date.now() - started > limit ) {
						resolve( null );
						return;
					}

					// After ten minutes the look is slower (a restore may take hours).
					window.setTimeout( look, wait || ( Date.now() - started > 600000 ? 3 * interval : interval ) );
					return;
				}

				if ( ! keepRound ) {
					rounds -= 1;
				}

				if ( rounds < 1 ) {
					resolve( null );
					return;
				}

				window.setTimeout( look, wait || interval );
			}

			look();
		} );
	}

	function copy( text, button ) {
		var label = button ? button.textContent : '';

		function shown() {
			if ( ! button ) {
				return;
			}

			button.textContent = button.getAttribute( 'data-copied' ) || label;
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		}

		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			return window.navigator.clipboard.writeText( text ).then( shown );
		}

		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );

			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();

			try {
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( 'copy' ) );
				}
			} catch ( e ) {
				reject( e );
			}

			document.body.removeChild( area );
		} ).then( shown );
	}

	window.beltoftApi = { call: call, copy: copy, follow: follow };
}() );
