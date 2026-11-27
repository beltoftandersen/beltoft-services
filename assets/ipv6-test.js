/**
 * Checkout: does the visitor's connection have IPv6? Only asked when the cart holds a server or database without an IPv4 address.
 * The browser tries to load a tiny page that has an IPv6 address only (config.url). When that fails, a plain notice shows next to the
 * IPv4 box with one button that ticks the box (the box then adds the address as it always does). The same test runs on request from every "Check my connection" button (server Access tab, database box, checkout notice). Nothing is added by itself, and a
 * visitor who ticks the box by hand does not see the notice.
 *
 * decide() is the one decision: it is also exported for the node check in tests/js/ipv6-test-check.js.
 */
( function ( root ) {
	'use strict';

	/**
	 * What a probe says about the visitor's connection.
	 *
	 * @param {{loaded: boolean, offline: boolean}} probe loaded: the page arrived; offline: the browser says there is no network at all.
	 * @return {string} 'ipv6' (reachable), 'none' (the page did not load) or 'unknown' (no network at all: nothing can be said).
	 */
	function decide( probe ) {
		if ( probe.loaded ) {
			return 'ipv6';
		}

		return probe.offline ? 'unknown' : 'none';
	}

	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = { decide: decide };
	}

	var config = root.beltoftServicesIpv6;
	var doc = root.document;

	if ( ! config || ! config.url || ! doc || ! root.fetch ) {
		return;
	}

	function probe() {
		var controller = root.AbortController ? new root.AbortController() : null;
		var timer = controller ? root.setTimeout( function () { controller.abort(); }, config.timeout || 5000 ) : 0;
		var offline = root.navigator && false === root.navigator.onLine;

		// no-cors: an opaque answer is enough, the page only has to arrive.
		return root.fetch( config.url, { mode: 'no-cors', cache: 'no-store', credentials: 'omit', signal: controller ? controller.signal : undefined } ).then( function () {
			root.clearTimeout( timer );

			return decide( { loaded: true, offline: false } );
		}, function () {
			root.clearTimeout( timer );

			return decide( { loaded: false, offline: !! offline } );
		} );
	}

	var onResult = null;

	// "Check my connection" / "Check again": the same test, on request, answered in words the button carries (data-ipv6, data-none, data-unknown).
	doc.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-ipv6-check]' ) : null;
		var out;

		if ( ! button || button.disabled ) {
			return;
		}

		out = button.parentNode ? button.parentNode.querySelector( '[data-ipv6-result]' ) : null;
		button.disabled = true;

		if ( out ) {
			out.textContent = button.getAttribute( 'data-checking' ) || '';
		}

		probe().then( function ( answer ) {
			button.disabled = false;

			if ( out ) {
				out.textContent = button.getAttribute( 'data-' + answer ) || '';
			}

			if ( onResult ) {
				onResult( answer );
			}
		} );
	} );

	function start() {
		var notice = doc.querySelector( '[data-ipv6-notice]' );
		var box = doc.getElementById( 'beltoft_services_ipv4' );
		var result = '';

		if ( ! notice || ! box ) {
			return;
		}

		function sync() {
			notice.hidden = ! ( 'none' === result && ! box.checked );
		}

		onResult = function ( answer ) {
			result = answer;
			sync();
		};

		box.addEventListener( 'change', sync );

		var add = notice.querySelector( '[data-ipv6-add]' );

		if ( add ) {
			add.addEventListener( 'click', function () {
				if ( box.disabled ) {
					return;
				}

				box.checked = true;
				box.dispatchEvent( new root.Event( 'change', { bubbles: true } ) );
				sync();
			} );
		}

		probe().then( onResult );
	}

	if ( 'loading' === doc.readyState ) {
		doc.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}( 'undefined' !== typeof window ? window : this ) );
