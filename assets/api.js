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
 * to null. Options: holder (as for call), interval (milliseconds, default 4000), rounds, onTick( data ) with every status read,
 * handle (the beltoftStatus.busy() handle of the work: every status read that lists steps moves its progress line, "step n of m").
 * Nothing is rejected for a failed job: the outcome has ok false and the server's own text. While the browser tab is hidden no
 * status is read (beltoftApi.later); the look is made at once when the tab is shown again, and the pause does not use up a round.
 * With options.until = 'idle' it follows the service instead of one action: it resolves to { idle: true, data } when the status
 * shows no running task (`task` empty on the hosting overview, else no `working` and no `progress.active`), whatever the last
 * action was; options.path is the status path to read (default: the queued answer's status), the look is every 5 seconds (15
 * after ten minutes), and options.timeout (milliseconds, default seven hours) ends it with null, never with success. A 429 waits
 * for Retry-After in both modes.
 *
 * window.beltoftApi.stepUp( purpose, options ) asks the customer for the code we email before a secret is shown or changed. It emails
 * the code at once (the step-up route of the holder) and shows a dialog with the code field; it resolves to the typed code, or to
 * null when the customer cancels. The caller sends the code as step_code with its request. Options: holder (as for call; its
 * stepUpUrl and its strings stepTitle, stepText (%s: the masked address), stepSent, stepCode, stepResend, stepGo and stepCancel are used).
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

	// Runs fn after ms, and while the browser tab is hidden not at all: it runs at once when the tab is shown again. A tab nobody
	// looks at sends no status reads.
	function later( fn, ms ) {
		window.setTimeout( function () {
			if ( ! document.hidden ) {
				fn();
				return;
			}

			document.addEventListener( 'visibilitychange', function shown() {
				if ( ! document.hidden ) {
					document.removeEventListener( 'visibilitychange', shown );
					fn();
				}
			} );
		}, ms );
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

					if ( reply.ok && settings.handle && window.beltoftStatus && window.beltoftStatus.progress && data.progress && data.progress.active && data.progress.steps && data.progress.steps.length ) {
						window.beltoftStatus.progress( settings.handle, data.progress );
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
					later( look, wait || ( Date.now() - started > 600000 ? 3 * interval : interval ) );
					return;
				}

				if ( ! keepRound ) {
					rounds -= 1;
				}

				if ( rounds < 1 ) {
					resolve( null );
					return;
				}

				later( look, wait || interval );
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

	function stepUp( purpose, options ) {
		var holder = ( options && options.holder ) || window.beltoftServicesAccount || {};
		var words = holder.strings || {};

		return new Promise( function ( resolve ) {
			var dialog = document.createElement( 'dialog' );
			var form = document.createElement( 'form' );
			var title = document.createElement( 'h5' );
			var note = document.createElement( 'p' );
			var label = document.createElement( 'label' );
			var input = document.createElement( 'input' );
			var problem = document.createElement( 'p' );
			var buttons = document.createElement( 'p' );
			var go = document.createElement( 'button' );
			var again = document.createElement( 'button' );
			var cancel = document.createElement( 'button' );
			var finished = false;

			function finish( value ) {
				if ( finished ) {
					return;
				}

				finished = true;

				if ( dialog.close ) {
					dialog.close();
				}

				if ( dialog.parentNode ) {
					dialog.parentNode.removeChild( dialog );
				}

				resolve( value );
			}

			function send() {
				again.disabled = true;
				problem.textContent = '';

				return call( holder.stepUpUrl, { method: 'POST', body: { purpose: purpose }, holder: holder } ).then( function ( result ) {
					again.disabled = false;

					if ( result.ok ) {
						note.textContent = ( words.stepText || '' ).replace( '%s', ( result.data && result.data.email ) || '' ) + ' ' + ( words.stepSent || '' );
					} else {
						problem.textContent = ( result.data && result.data.message ) || words.failed || '';
					}
				}, function () {
					again.disabled = false;
					problem.textContent = words.failed || '';
				} );
			}

			dialog.className = 'beltoft-services-vm__dialog bs-dialog';
			dialog.setAttribute( 'aria-labelledby', 'bs-stepup-title' );
			form.className = 'bs-dialog__form';
			form.method = 'dialog';
			title.id = 'bs-stepup-title';
			title.textContent = words.stepTitle || '';
			label.textContent = words.stepCode || '';
			input.type = 'text';
			input.name = 'step_code';
			input.maxLength = 12;
			input.setAttribute( 'inputmode', 'numeric' );
			input.setAttribute( 'autocomplete', 'one-time-code' );
			input.setAttribute( 'spellcheck', 'false' );
			input.required = true;
			label.appendChild( document.createElement( 'br' ) );
			label.appendChild( input );
			problem.setAttribute( 'role', 'alert' );
			go.type = 'submit';
			go.className = 'button woocommerce-button bs-dialog__yes';
			go.textContent = words.stepGo || '';
			again.type = 'button';
			again.className = 'button woocommerce-button bs-dialog__no';
			again.textContent = words.stepResend || '';
			cancel.type = 'button';
			cancel.className = 'button woocommerce-button bs-dialog__no';
			cancel.textContent = words.stepCancel || '';
			buttons.className = 'beltoft-services-vm__buttons';
			buttons.appendChild( go );
			buttons.appendChild( again );
			buttons.appendChild( cancel );
			[ title, note, label, problem, buttons ].forEach( function ( part ) {
				form.appendChild( part );
			} );
			dialog.appendChild( form );
			document.body.appendChild( dialog );

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				finish( input.value.replace( /\s+/g, '' ) || null );
			} );
			again.addEventListener( 'click', send );
			cancel.addEventListener( 'click', function () {
				finish( null );
			} );
			dialog.addEventListener( 'cancel', function ( event ) {
				event.preventDefault();
				finish( null );
			} );

			if ( dialog.showModal ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}

			input.focus();
			send();
		} );
	}

	window.beltoftApi = { call: call, copy: copy, follow: follow, stepUp: stepUp, later: later };
}() );
