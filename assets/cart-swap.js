/**
 * Checkout: the "Daily backups" box and the extra disk selector change the cart through one queue. Only one
 * request is in flight; each one carries the full current choice read from the page when it is sent, so a
 * change made meanwhile is sent next and the last request always holds both. When the queue is empty
 * WooCommerce's order review is refreshed once.
 *
 *   beltoftCartSwap.changed( fieldElement );
 */
( function ( $ ) {
	'use strict';

	var running = false;
	var again = false;
	var source = null;

	function fields() {
		return { box: $( '#beltoft_services_backups' ), disk: $( '#beltoft_services_disk_size' ) };
	}

	// What to send: the page's current choice, to the backups endpoint when the box exists, else the disk's.
	function request() {
		var f = fields();
		var primary = f.box.length ? f.box : f.disk;
		var config = f.box.length ? window.beltoftServicesBackups : window.beltoftServicesDiskField;
		var data = { nonce: primary.data( 'nonce' ) };

		if ( f.box.length ) {
			data.with = f.box.is( ':checked' ) ? '1' : '0';
		}

		if ( f.disk.length ) {
			data.disk = f.disk.val();
		}

		return { url: config.url, data: data };
	}

	// After a refusal the page shows what the cart really holds.
	function show( reply ) {
		var f = fields();

		if ( ! reply ) {
			return;
		}

		if ( f.box.length && typeof reply.with === 'boolean' ) {
			f.box.prop( 'checked', reply.with );
		}

		if ( f.disk.length && reply.disk !== null && reply.disk !== undefined ) {
			f.disk.val( String( reply.disk ) );
		}
	}

	function failed() {
		var f = fields();
		var el = source || ( f.box.length ? f.box : f.disk ).get( 0 );
		var message = ( f.box.length ? window.beltoftServicesBackups : window.beltoftServicesDiskField ).failed;

		window.setTimeout( function () {
			window.beltoftStatus.fail( null, message, el );
		}, 0 );
	}

	function run() {
		var req = request();

		running = true;
		again = false;

		$.ajax( { url: req.url, method: 'POST', dataType: 'json', data: req.data } ).fail( function ( xhr ) {
			show( xhr && xhr.responseJSON );
			again = false;
			failed();
		} ).always( function () {
			if ( again ) {
				run();
				return;
			}

			running = false;
			source = null;
			$( document.body ).trigger( 'update_checkout' );
		} );
	}

	window.beltoftCartSwap = {
		changed: function ( el ) {
			source = el || source;

			if ( running ) {
				again = true;
				return;
			}

			run();
		}
	};
}( window.jQuery ) );
