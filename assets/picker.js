/**
 * My account → New server. The form posts and the server decides; this script only keeps the page honest while the customer
 * chooses: the backups box and the summary follow the selected plan, and an app the plan is too small for is greyed out
 * with the reason (the same words checkout uses, carried on the tile). Without script the page still works.
 * Needs tiles.js (the app and system tiles). No other dependencies.
 */
( function () {
	'use strict';

	var form = document.querySelector( '.beltoft-services-picker__form' );

	if ( ! form ) {
		return;
	}

	var plans = form.querySelectorAll( 'input[name="beltoft_services_picker_plan"]' );
	var row = form.querySelector( '[data-picker-backups]' );
	var box = row ? row.querySelector( 'input[type="checkbox"]' ) : null;
	var label = row ? row.querySelector( '[data-picker-backups-label]' ) : null;
	var summary = form.querySelector( '[data-picker-summary]' );
	var submit = form.querySelector( '[data-picker-submit]' );

	function each( list, run ) {
		Array.prototype.forEach.call( list, run );
	}

	function chosen() {
		return form.querySelector( 'input[name="beltoft_services_picker_plan"]:checked' );
	}

	function minimum( radio ) {
		try {
			return JSON.parse( radio.getAttribute( 'data-min' ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function fits( min, plan ) {
		return ( plan.cores >= ( min.cores || 0 ) ) && ( plan.memory >= ( min.memory_mb || 0 ) ) && ( plan.disk >= ( min.disk_gb || 0 ) );
	}

	function apps( plan ) {
		var group = form.querySelector( '[data-tiles="app"]' );
		var none = group ? group.querySelector( 'input[data-none]' ) : null;
		var lost = false;

		if ( ! group ) {
			return;
		}

		each( group.querySelectorAll( 'input[type="radio"]' ), function ( radio ) {
			var tile = radio.closest( '.beltoft-services-tile' );
			var face = tile ? tile.querySelector( '.beltoft-services-tile__face' ) : null;
			var why = tile ? tile.querySelector( '.beltoft-services-tile__why' ) : null;
			var off;

			if ( radio.hasAttribute( 'data-none' ) ) {
				return;
			}

			off = ! fits( minimum( radio ), plan );
			radio.disabled = off;
			tile.classList.toggle( 'is-disabled', off );

			if ( off && radio.checked ) {
				lost = true;
			}

			if ( off && face && ! why ) {
				why = document.createElement( 'span' );
				why.className = 'beltoft-services-tile__why';
				why.textContent = radio.getAttribute( 'data-why' ) || '';
				face.appendChild( why );
			}

			if ( ! off && why ) {
				why.parentNode.removeChild( why );
			}
		} );

		if ( lost && none ) {
			none.checked = true;
			none.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	function update() {
		var radio = chosen();
		var plan;
		var has;

		if ( ! radio ) {
			if ( submit ) {
				submit.disabled = true;
			}

			return;
		}

		plan = {
			cores: parseInt( radio.getAttribute( 'data-cores' ), 10 ) || 0,
			memory: parseInt( radio.getAttribute( 'data-memory' ), 10 ) || 0,
			disk: parseInt( radio.getAttribute( 'data-disk' ), 10 ) || 0
		};
		has = '1' === radio.getAttribute( 'data-has-backups' );

		if ( row ) {
			row.hidden = ! has;

			if ( box && ! has ) {
				box.checked = false;
			}

			if ( label ) {
				label.textContent = radio.getAttribute( 'data-backups-label' ) || '';
			}
		}

		if ( summary ) {
			summary.textContent = ( has && box && box.checked ? radio.getAttribute( 'data-summary-backups' ) : radio.getAttribute( 'data-summary' ) ) || '';
		}

		if ( submit ) {
			submit.disabled = false;
		}

		apps( plan );
	}

	each( plans, function ( radio ) {
		radio.addEventListener( 'change', update );
	} );

	if ( box ) {
		box.addEventListener( 'change', update );
	}

	// The tile script handles the app's own change; the summary needs nothing from it.
	update();
}() );
