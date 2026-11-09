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

	window.beltoftApi = { call: call, copy: copy };
}() );
