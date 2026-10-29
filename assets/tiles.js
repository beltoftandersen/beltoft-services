/**
 * Square radio tiles (operating system and app), at checkout and on the Reinstall tab.
 *
 * The tiles are real radio inputs, so everything works without this script. It adds two things a select could not do:
 *  - choosing an app switches the operating system tiles to the system the app runs on and greys out the others (the
 *    previous choice comes back when no app is chosen), and tells the checkout to refresh its order summary;
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
		var remembered = null;

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

		function lock( on ) {
			var note = os ? os.querySelector( '[data-tiles-note]' ) : null;
			var before = checked( os );
			var target = null;

			if ( ! os || ! want ) {
				return;
			}

			if ( on && null === remembered && before ) {
				remembered = before.value;
			}

			each( os.querySelectorAll( 'input[type="radio"]' ), function ( radio ) {
				radio.disabled = on && radio.value !== want;
				radio.closest( '.beltoft-services-tile' ).classList.toggle( 'is-disabled', radio.disabled );

				if ( on && radio.value === want ) {
					target = radio;
				}

				if ( ! on && null !== remembered && radio.value === remembered ) {
					target = radio;
				}
			} );

			if ( target ) {
				target.checked = true;
			}

			if ( ! on ) {
				remembered = null;
			}

			if ( note ) {
				note.hidden = ! on;
			}

			if ( target && before !== target ) {
				// The order summary reads the choice from the session: tell the form it changed.
				target.dispatchEvent( new Event( 'change', { bubbles: true } ) );

				if ( window.jQuery ) {
					window.jQuery( document.body ).trigger( 'update_checkout' );
				}
			}
		}

		function fill() {
			var radio = checked( apps );
			var list = radio ? versions( radio ) : [];

			if ( ! row || ! select ) {
				return;
			}

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
				option.selected = merge && entry.value === radio.value;
				select.appendChild( option );
			} );
		}

		function update() {
			var radio = checked( apps );

			lock( !! radio && ! radio.hasAttribute( 'data-none' ) );
			fill();
		}

		apps.addEventListener( 'change', function ( event ) {
			if ( event.target && 'radio' === event.target.type ) {
				update();
			}
		} );

		if ( select && merge ) {
			select.addEventListener( 'change', function () {
				var radio = checked( apps );

				if ( radio ) {
					radio.value = select.value;
				}
			} );
		}

		update();
	}

	each( document.querySelectorAll( '[data-tiles="app"]' ), setup );
}() );
