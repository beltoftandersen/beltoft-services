/**
 * My Account, dedicated server card: shows the root password after the emailed code. Needs the panel script
 * (window.beltoftServicesAccount). No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesManual;
	var S = window.beltoftStatus;

	if ( ! account || ! cfg || ! window.fetch || ! window.beltoftApi || ! S ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( '[data-manual-reveal], [data-manual-copy]' );
		var box = target ? target.closest( '.beltoft-services-manual' ) : null;
		var scope;

		if ( ! target || ! box ) {
			return;
		}

		scope = box.getAttribute( 'data-resource' );

		if ( target.hasAttribute( 'data-manual-copy' ) ) {
			window.beltoftApi.copy( box.querySelector( '[data-manual-password-field]' ).value, target );

			return;
		}

		if ( S.locked( scope ) ) {
			return;
		}

		window.beltoftApi.stepUp( 'manual_password', { holder: account } ).then( function ( code ) {
			var h;

			if ( ! code ) {
				return null;
			}

			h = S.busy( cfg.strings.working, scope, target );

			return window.beltoftApi.call( account.root + scope + '/manual-password', { method: 'POST', body: { step_code: code }, holder: account } ).then( function ( result ) {
				if ( ! result.ok || ! result.data.password ) {
					S.fail( h, 429 === result.status ? cfg.strings.limit : ( result.data && result.data.message ? result.data.message : cfg.strings.failed ) );

					return;
				}

				box.querySelector( '[data-manual-password-field]' ).value = result.data.password;
				box.querySelector( '[data-manual-password]' ).hidden = false;
				S.done( h, cfg.strings.note );
			} );
		} ).catch( function () {
			S.fail( null, cfg.strings.failed );
		} );
	} );
}() );
