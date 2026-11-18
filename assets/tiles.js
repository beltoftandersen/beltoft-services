/**
 * Square radio tiles (operating system and app), at checkout and on the Reinstall tab.
 *
 * The tiles are real radio inputs, so everything works without this script. It adds two things a select could not do:
 *  - every system stays open. Choosing an app moves the system to one the app runs on (each app tile says which);
 *    choosing a system an app does not run on sets the app to "No app" and says why. Either change tells the checkout
 *    to refresh its order summary;
 *  - an app with more than one version gets a small version select under the tiles. At checkout that select is posted as
 *    it is; on the Reinstall tab ([data-tiles-merge]) it has no name and writes its choice into the checked tile's value.
 *  - a system tile stands for a family (Ubuntu, Debian, AlmaLinux): the versions are a select under the tiles, and the
 *    checked tile's value is the version chosen, so the posted field and value are what one tile per version posted.
 * No dependencies.
 */
( function () {
	'use strict';

	function each( list, run ) {
		Array.prototype.forEach.call( list, run );
	}

	// The order summary reads the choice from the posted form: every trusted tile click, whichever field it is in,
	// asks the checkout for one refresh. WooCommerce's update_checkout aborts a refresh still running, so the
	// last click always has the last response.
	function refresh() {
		if ( window.jQuery && window.jQuery( 'form.checkout' ).length ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	// -- System tiles: one tile per family, the versions in a select ---------------------------------

	function osEntries( radio ) {
		try {
			var list = JSON.parse( radio.getAttribute( 'data-versions' ) || '[]' );

			if ( list.length ) {
				return list;
			}
		} catch ( e ) {
			// A tile without versions stands for itself.
		}

		return [ { value: radio.value, family: radio.getAttribute( 'data-family' ) || '', label: '', line: '' } ];
	}

	// The tile takes this version: its value (what is posted), its family and the line under the name.
	function osSet( radio, entry ) {
		var line = radio.parentNode.querySelector( '.beltoft-services-tile__line' );

		radio.value = entry.value;
		radio.setAttribute( 'data-family', entry.family || '' );

		if ( line && entry.line ) {
			line.textContent = entry.line;
		}
	}

	// The select in the tile shows the version the tile holds.
	function syncSelect( radio ) {
		var select = radio.closest( 'label' ).querySelector( '[data-tiles-os-version]' );

		if ( select ) {
			select.value = radio.value;
		}
	}

	// A version select inside a tile: choosing a version checks that tile and gives it the version's value.
	each( document.querySelectorAll( '[data-tiles="os"]' ), function ( group ) {
		// First listener on the group, so what the app and refresh listeners below read is already up to date.
		group.addEventListener( 'change', function ( event ) {
			var select = event.target;
			var label = select && select.hasAttribute && select.hasAttribute( 'data-tiles-os-version' ) ? select.closest( 'label' ) : null;
			var radio = label ? label.querySelector( 'input[type="radio"]' ) : null;

			if ( ! radio ) {
				return;
			}

			osEntries( radio ).forEach( function ( entry ) {
				if ( entry.value === select.value ) {
					osSet( radio, entry );
				}
			} );
			radio.checked = true;
		} );
	} );

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
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		var touched = false;

		function say( text ) {
			note.textContent = text;
			note.hidden = '' === text;
		}

		// An app was chosen: when the system chosen is not one it runs on, choose the first one it runs on.
		// The first system an app lists is its default (Plesk lists AlmaLinux 10 first where the cluster has it): used until the customer picks a system.
		function follow() {
			var app = checked( apps );
			var wanted = families( app );
			var system = checked( os );
			var target = null;

			say( '' );

			if ( ! os || ! wanted.length ) {
				return;
			}

			if ( system && -1 !== wanted.indexOf( family( system ) ) && ( touched || wanted[ 0 ] === family( system ) ) ) {
				return;
			}

			each( wanted, function ( name ) {
				each( os.querySelectorAll( 'input[type="radio"]' ), function ( radio ) {
					if ( ! target && ! radio.disabled ) {
						osEntries( radio ).forEach( function ( entry ) {
							if ( ! target && name === entry.family ) {
								target = { radio: radio, entry: entry };
							}
						} );
					}
				} );
			} );

			// Without family data (an older cluster setting) the group names the one app system.
			if ( ! target && want ) {
				each( os.querySelectorAll( 'input[type="radio"]' ), function ( radio ) {
					osEntries( radio ).forEach( function ( entry ) {
						if ( ! target && entry.value === want ) {
							target = { radio: radio, entry: entry };
						}
					} );
				} );
			}

			if ( target ) {
				target.radio.checked = true;
				osSet( target.radio, target.entry );
				syncSelect( target.radio );
				changed( target.radio );
			}
		}

		// A system tile was chosen: when the chosen app runs on another version of that family, take that version.
		function fit() {
			var wanted = families( checked( apps ) );
			var system = checked( os );

			if ( ! system || ! wanted.length || -1 !== wanted.indexOf( family( system ) ) ) {
				return;
			}

			osEntries( system ).some( function ( entry ) {
				if ( -1 === wanted.indexOf( entry.family ) ) {
					return false;
				}

				osSet( system, entry );
				syncSelect( system );

				return true;
			} );
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

		// The versions that run on the chosen system (an app can have one version per system, such as Plesk on Ubuntu and on AlmaLinux).
		function fitting( radio ) {
			var list = radio ? versions( radio ) : [];
			var chosen = family( checked( os ) );
			var fit = list.filter( function ( entry ) {
				return ! chosen || ! entry.families || ! entry.families.length || -1 !== entry.families.indexOf( chosen );
			} );

			return fit.length ? fit : list;
		}

		function fill() {
			var radio = checked( apps );
			var list = fitting( radio );

			if ( ! row || ! select ) {
				return;
			}

			var wanted = select.getAttribute( 'data-selected' ) || '';

			// On the Reinstall tab the tile's value is what is posted: keep it on a version that fits the system.
			if ( merge && radio && ! radio.hasAttribute( 'data-none' ) && list.length && ! list.some( function ( entry ) { return entry.value === radio.value; } ) ) {
				radio.value = list[ 0 ].value;
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
				// follow() may have moved the system: the versions must fit the system it chose.
				fill();
				refresh();
			}
		} );

		if ( os ) {
			os.addEventListener( 'change', function ( event ) {
				if ( ( event.target && 'radio' === event.target.type && event.isTrusted ) || ( event.target && event.target.hasAttribute( 'data-tiles-os-version' ) && event.isTrusted ) ) {
					touched = true;
					if ( 'radio' === event.target.type ) {
						fit();
					}

					drop();
					fill();
					refresh();
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

	// A checkout without an app field still has system tiles to refresh the summary for. With an app field, setup() does it.
	each( document.querySelectorAll( '[data-tiles="os"]' ), function ( os ) {
		var form = os.closest( 'form' );

		if ( form && form.querySelector( '[data-tiles="app"]' ) ) {
			return;
		}

		os.addEventListener( 'change', function ( event ) {
			if ( ( event.target && 'radio' === event.target.type && event.isTrusted ) || ( event.target && event.target.hasAttribute( 'data-tiles-os-version' ) && event.isTrusted ) ) {
				refresh();
			}
		} );
	} );
}() );
