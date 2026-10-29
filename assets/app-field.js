/**
 * Checkout: the App, Version and domain fields. Picking an app lists its versions, shows what it is,
 * offers the domain picker for an app that serves a website, and sets the operating system to the one
 * the app runs on (the server sets it too, whatever is posted). Without script every field still posts
 * and the server decides. No dependencies.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-app-field]' );

	if ( ! root ) {
		return;
	}

	var apps = {};
	var select = root.querySelector( '[data-app-select]' );
	var note = root.querySelector( '[data-app-note]' );
	var versionRow = root.querySelector( '[data-app-version-row]' );
	var version = root.querySelector( '[data-app-version]' );
	var domain = root.querySelector( '[data-app-domain]' );
	var domainOn = root.querySelector( '[data-app-domain-on]' );
	var domainFields = root.querySelector( '[data-app-domain-fields]' );
	var plain = note ? note.textContent : '';
	var system = document.querySelector( 'select[name="beltoft_services_os_template"]' );
	var os = root.getAttribute( 'data-os' );
	var remembered = null;

	try {
		apps = JSON.parse( root.getAttribute( 'data-apps' ) || '{}' );
	} catch ( e ) {
		apps = {};
	}

	function lockSystem( on ) {
		if ( ! system || ! os ) {
			return;
		}

		if ( on && null === remembered ) {
			remembered = system.value;
		}

		Array.prototype.forEach.call( system.options, function ( option ) {
			option.disabled = on && option.value !== os;
		} );

		if ( on ) {
			system.value = os;
		} else if ( null !== remembered ) {
			system.value = remembered;
			remembered = null;
		}
	}

	function update() {
		var chosen = apps[ select.value ];

		versionRow.hidden = ! chosen;
		if ( domain ) {
			domain.hidden = ! ( chosen && chosen.domain );
		}

		version.textContent = '';
		lockSystem( !! chosen );

		if ( ! chosen ) {
			note.textContent = plain;

			if ( domainOn ) {
				domainOn.checked = false;
			}

			if ( domainFields ) {
				domainFields.hidden = true;
			}

			return;
		}

		note.textContent = chosen.summary + ' ' + chosen.minutes;
		chosen.versions.forEach( function ( entry ) {
			var option = document.createElement( 'option' );

			option.value = entry.id;
			option.textContent = entry.label;
			version.appendChild( option );
		} );

		if ( domainOn && ! chosen.domain ) {
			domainOn.checked = false;
			domainFields.hidden = true;
		}
	}

	select.addEventListener( 'change', update );

	if ( domainOn ) {
		domainOn.addEventListener( 'change', function () {
			domainFields.hidden = ! domainOn.checked;
		} );
	}

	update();
}() );
