/**
 * Checkout: the app's own extras. Picking an app tile shows what it is and, for an app that serves a website,
 * the domain picker. The tiles themselves, the version select and the operating system switch are in tiles.js.
 * Without script every field still posts and the server decides. No dependencies.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-app-field]' );

	if ( ! root ) {
		return;
	}

	var apps = {};
	var note = root.querySelector( '[data-app-note]' );
	var domain = root.querySelector( '[data-app-domain]' );
	var domainOn = root.querySelector( '[data-app-domain-on]' );
	var domainFields = root.querySelector( '[data-app-domain-fields]' );
	var plain = note ? note.textContent : '';

	try {
		apps = JSON.parse( root.getAttribute( 'data-apps' ) || '{}' );
	} catch ( e ) {
		apps = {};
	}

	function chosenId() {
		var radio = root.querySelector( 'input[name="beltoft_services_app"]:checked' );

		return radio ? radio.value : '';
	}

	function update() {
		var chosen = apps[ chosenId() ];

		if ( domain ) {
			domain.hidden = ! ( chosen && chosen.domain );
		}

		if ( note ) {
			note.textContent = chosen ? chosen.minutes : plain;
		}

		if ( ! chosen || ! chosen.domain ) {
			if ( domainOn ) {
				domainOn.checked = false;
			}

			if ( domainFields ) {
				domainFields.hidden = true;
			}
		}
	}

	root.addEventListener( 'change', function ( event ) {
		if ( event.target && 'beltoft_services_app' === event.target.name ) {
			update();
		}
	} );

	if ( domainOn ) {
		domainOn.addEventListener( 'change', function () {
			domainFields.hidden = ! domainOn.checked;
		} );
	}

	update();
}() );
