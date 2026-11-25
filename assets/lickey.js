/**
 * My Account, licence page: changes the server address of a licence key. Posts to the generic action route
 * (resources/<id>/actions/change_address), follows the job to its end and reloads the page. Needs the panel script
 * (window.beltoftServicesAccount), beltoftApi and beltoftStatus. No dependencies.
 */
( function () {
	'use strict';

	// Copy button under the key: copies the text of the key field (works without the panel script).
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-lickey-copy]' );
		var field = button ? button.closest( '.beltoft-services-lickey' ).querySelector( 'textarea' ) : null;

		if ( field && window.beltoftApi ) {
			window.beltoftApi.copy( field.value, button );
		}
	} );

	var account = window.beltoftServicesAccount;
	var own = window.beltoftServicesLickey;
	var S = window.beltoftStatus;

	if ( ! account || ! own || ! window.fetch || ! window.beltoftApi || ! S ) {
		return;
	}

	var strings = own.strings;

	function problem( result ) {
		if ( 429 === result.status ) {
			return strings.limit;
		}

		return result.data && result.data.message ? result.data.message : strings.failed;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-lickey-go]' );
		var box = button ? button.closest( '[data-lickey-move]' ) : null;
		var source;
		var field;
		var scope;
		var args;
		var h;

		if ( ! button || ! box ) {
			return;
		}

		scope = box.getAttribute( 'data-resource' );
		source = box.querySelector( '[data-lickey-source]' );
		field = box.querySelector( '[data-lickey-ip]' );
		args = { source: source ? source.value : 'other', ip: field ? field.value.trim() : '' };

		if ( S.locked( scope ) ) {
			return;
		}

		if ( 'other' === args.source && '' === args.ip ) {
			h = S.busy( strings.working, scope, button );
			S.fail( h, strings.noIp, field );

			return;
		}

		h = S.busy( strings.working, scope, button );

		window.beltoftApi.call( account.root + scope + '/actions/change_address', { method: 'POST', body: { args: args }, holder: account } ).then( function ( result ) {
			if ( 202 !== result.status ) {
				S.fail( h, problem( result ), field );

				return null;
			}

			return window.beltoftApi.follow( result.data, { holder: account, handle: h } ).then( function ( outcome ) {
				if ( outcome && outcome.ok ) {
					S.done( h, outcome.text );
					window.setTimeout( function () {
						window.location.reload();
					}, 1500 );
				} else if ( outcome ) {
					S.fail( h, outcome.text || strings.failed );
				} else {
					S.fail( h, strings.pending );
				}
			} );
		} ).catch( function () {
			S.fail( h, strings.failed );
		} );
	} );
}() );
