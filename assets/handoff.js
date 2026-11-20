/**
 * The hand-off screen after payment: waits until the purchase event has been sent, then moves the browser to My Account.
 *
 * The page is the order received page, so the tag manager plugin has already put the purchase event in the dataLayer.
 * This script pushes one more message behind it with an eventCallback: the tag manager calls it when the tags of that
 * message are done, or after eventTimeout. A blocked or missing tag manager never calls it, so a timer leaves the page
 * at the latest after 4 seconds; the screen shows at least 2 seconds so the customer sees it. The move is
 * location.replace (the hand-off stays out of the history, Back does not bounce the customer forward again) and it
 * happens once. No dependencies.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-bs-handoff]' );
	var next = root ? root.getAttribute( 'data-next' ) : '';
	var MIN = 2000;
	var EVENT_TIMEOUT = 3800;
	var MAX = 4000;
	var started = Date.now();
	var left = false;
	var asked = false;

	if ( ! root || ! next ) {
		return;
	}

	function leave() {
		if ( left ) {
			return;
		}

		left = true;
		window.location.replace( next );
	}

	// The tag manager is done, or it is not coming: leave once the screen has shown for the minimum time.
	function go() {
		if ( asked ) {
			return;
		}

		asked = true;
		window.setTimeout( leave, Math.max( 0, MIN - ( Date.now() - started ) ) );
	}

	// Is there a purchase event in the dataLayer that has to be sent first?
	function hasPurchase() {
		var layer = window.dataLayer;
		var i;

		if ( ! layer || 'number' !== typeof layer.length ) {
			return false;
		}

		for ( i = 0; i < layer.length; i++ ) {
			if ( layer[ i ] && 'purchase' === layer[ i ].event ) {
				return true;
			}
		}

		return false;
	}

	var skip = root.querySelector( '[data-bs-handoff-skip]' );

	if ( skip ) {
		skip.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			leave();
		} );
	}

	// Whatever happens, the screen is left after MAX.
	window.setTimeout( go, MAX );

	if ( '1' === root.getAttribute( 'data-track' ) && hasPurchase() ) {
		window.dataLayer.push( { event: 'beltoft_handoff', eventCallback: go, eventTimeout: EVENT_TIMEOUT } );
	} else {
		go();
	}
}() );
