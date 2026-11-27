/**
 * The "Check now" button of the "Point your domain" box (templates/account/app-dns-box.php): asks the server to look at the
 * customer's domain now (POST resources/<id>/dns-check) and draws the fresh box it answers with. The limits are the server's;
 * a 429 answer comes with the box in its limited state. A limited button enables itself when its time has come (data-next).
 * The box is replaced by the status poll too, so everything is delegated and the timers look at the page again when they fire.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesDnsCheck;
	var timer = 0;

	if ( ! account || ! cfg || ! window.beltoftApi || ! window.fetch ) {
		return;
	}

	var bs = window.beltoftStatus;
	var s = cfg.strings || {};

	function draw( html ) {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-app-own-domain]' ), function ( box ) {
			var holder = document.createElement( 'div' );
			var fresh;

			holder.innerHTML = html;
			fresh = holder.firstElementChild;

			if ( fresh ) {
				box.parentNode.replaceChild( fresh, box );
				Array.prototype.forEach.call( fresh.querySelectorAll( '[data-copy]' ), function ( button ) {
					button.hidden = false;
				} );
			}
		} );
		arm();
	}

	// A button that waits for its time is enabled when it comes.
	function arm() {
		var soonest = 0;

		window.clearTimeout( timer );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-dns-check][disabled]' ), function ( button ) {
			var next = parseInt( button.getAttribute( 'data-next' ), 10 ) || 0;

			if ( next && ( ! soonest || next < soonest ) ) {
				soonest = next;
			}
		} );

		if ( soonest ) {
			timer = window.setTimeout( free, Math.max( 500, soonest * 1000 - Date.now() + 500 ) );
		}
	}

	function free() {
		var again = false;

		Array.prototype.forEach.call( document.querySelectorAll( '[data-dns-check][disabled]' ), function ( button ) {
			var next = parseInt( button.getAttribute( 'data-next' ), 10 ) || 0;

			if ( next * 1000 <= Date.now() ) {
				button.disabled = false;
				button.textContent = button.getAttribute( 'data-label' );
			} else {
				again = true;
			}
		} );

		if ( again ) {
			arm();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-dns-check]' ) : null;
		var id;
		var handle;

		if ( ! button || button.disabled ) {
			return;
		}

		id = button.getAttribute( 'data-dns-check' );

		if ( bs && bs.locked( id ) ) {
			return;
		}

		button.disabled = true;
		button.textContent = s.checking;
		handle = bs ? bs.busy( s.working, id, button, { lock: false } ) : null;

		window.beltoftApi.call( account.root + id + '/dns-check', { method: 'POST', body: {}, holder: account } ).then( function ( reply ) {
			if ( reply.data && reply.data.box ) {
				draw( reply.data.box );
			}

			if ( reply.ok ) {
				if ( bs && handle ) { bs.done( handle, '' ); }
			} else if ( 429 === reply.status ) {
				if ( bs && handle ) { bs.done( handle, reply.data.message || '' ); }
			} else {
				if ( bs && handle ) { bs.fail( handle, s.failed ); }
				if ( ! reply.data || ! reply.data.box ) {
					button.disabled = false;
					button.textContent = button.getAttribute( 'data-label' );
				}
			}
		} ).catch( function () {
			button.disabled = false;
			button.textContent = button.getAttribute( 'data-label' );

			if ( bs && handle ) { bs.fail( handle, s.failed ); }
		} );
	} );

	arm();
}() );
