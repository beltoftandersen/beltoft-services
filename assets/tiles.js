/**
 * Square radio tiles (operating system and app), at checkout and on the Reinstall tab.
 *
 * The tiles are real radio inputs, so everything works without this script. It adds two things a select could not do:
 *  - every system stays open. Choosing an app moves the system to one the app runs on (each app tile says which);
 *    choosing a system an app does not run on sets the app to "No app" and says why. Either change tells the checkout
 *    to refresh its order summary;
 *  - an app with more than one version gets a small version select under the tiles. At checkout that select is posted as
 *    it is; on the Reinstall tab ([data-tiles-merge]) it has no name and writes its choice into the checked tile's value.
 * No dependencies.
 */
( function () {
	'use strict';

	function each( list, run ) {
		Array.prototype.forEach.call( list, run );
	}

	function setup( apps ) {
		var form = apps.closest( 'form' );
		var os = form ? form.querySelector( '[data-tiles="os"]' ) : null;
		var scope = apps.closest( '[data-app-field], [data-app-reinstall]' ) || apps.parentNode;
		var row = scope.querySelector( '[data-tiles-version-row]' );
		var select = scope.querySelector( '[data-tiles-version]' );
		var merge = !! scope.closest( '[data-tiles-merge]' ) || scope.hasAttribute( 'data-tiles-merge' );
		var want = apps.getAttribute( 'data-os' );
		var note = document.createElement( 'p' );

		function checked( group ) {
			return group ? group.querySelector( 'input[type="radio"]:checked' ) : null;
		}

		function versions( radio ) {
			try {
				return JSON.parse( radio.getAttribute( 'data-versions' ) || '[]' );
			} catch ( e ) {
				return [];
			}
		}

		function families( radio ) {
			var list = radio && ! radio.hasAttribute( 'data-none' ) ? ( radio.getAttribute( 'data-families' ) || '' ) : '';

			return list ? list.split( ',' ) : [];
		}

		function family( radio ) {
			return radio ? radio.getAttribute( 'data-family' ) || '' : '';
		}

		function changed( radio ) {
			// The order summary reads the choice from the session: tell the form it changed.
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'update_checkout' );
			}
		}

		function say( text ) {
			note.textContent = text;
			note.hidden = '' === text;
		}

		// An app was chosen: when the system chosen is not one it runs on, choose the first one it runs on.
		function follow() {
			var app = checked( apps );
			var wanted = families( app );
			var system = checked( os );
			var target = null;

			say( '' );

			if ( ! os || ! wanted.length || ( system && -1 !== wanted.indexOf( family( system ) ) ) ) {
				return;
			}

			each( os.querySelectorAll( 'input[type="radio"]' ), function ( radio ) {
				if ( ! target && ! radio.disabled && -1 !== wanted.indexOf( family( radio ) ) ) {
					target = radio;
				}
			} );

			// Without family data (an older cluster setting) the group names the one app system.
			if ( ! target && want ) {
				target = os.querySelector( 'input[type="radio"][value="' + want + '"]' );
			}

			if ( target ) {
				target.checked = true;
				changed( target );
			}
		}

		// A system was chosen: when the chosen app does not run on it, choose no app and say why.
		function drop() {
			var app = checked( apps );
			var wanted = families( app );
			var system = checked( os );
			var none = apps.querySelector( 'input[type="radio"][data-none]' );
			var text = app ? app.getAttribute( 'data-dropped' ) || '' : '';

			if ( ! wanted.length || ! system || -1 !== wanted.indexOf( family( system ) ) || ! none ) {
				return;
			}

			none.checked = true;
			update();
			changed( none );
			say( text );
		}

		function fill() {
			var radio = checked( apps );
			var list = radio ? versions( radio ) : [];

			if ( ! row || ! select ) {
				return;
			}

			var wanted = select.getAttribute( 'data-selected' ) || '';

			select.textContent = '';
			row.hidden = ! ( radio && ! radio.hasAttribute( 'data-none' ) && list.length > 1 );

			if ( row.hidden ) {
				return;
			}

			list.forEach( function ( entry ) {
				var option = document.createElement( 'option' );

				option.value = entry.value;
				option.textContent = entry.label;
				option.disabled = !! entry.disabled;
				option.selected = merge ? entry.value === radio.value : '' !== wanted && entry.value === wanted;
				select.appendChild( option );
			} );
		}

		function update() {
			fill();
		}

		apps.addEventListener( 'change', function ( event ) {
			if ( event.target && 'radio' === event.target.type && event.isTrusted ) {
				update();
				follow();
			}
		} );

		if ( os ) {
			os.addEventListener( 'change', function ( event ) {
				if ( event.target && 'radio' === event.target.type && event.isTrusted ) {
					drop();
				}
			} );
		}

		if ( select && merge ) {
			select.addEventListener( 'change', function () {
				var radio = checked( apps );

				if ( radio ) {
					radio.value = select.value;
				}
			} );
		}

		note.className = 'beltoft-services-tiles__note description';
		note.setAttribute( 'role', 'status' );
		note.hidden = true;
		apps.appendChild( note );

		update();
		follow();
	}

	each( document.querySelectorAll( '[data-tiles="app"]' ), setup );
}() );
