( function () {
	'use strict';

	var root = document.querySelector( '[data-apiref]' );

	if ( ! root ) {
		return;
	}

	root.classList.add( 'is-js' );

	// Copy buttons.
	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-apiref-copy]' ) : null;

		if ( ! button ) {
			return;
		}

		var panel = button.closest( '.beltoft-services-apiref__panel' );
		var code = panel ? panel.querySelector( 'code' ) : null;

		if ( ! code || ! navigator.clipboard ) {
			return;
		}

		var label = button.textContent;

		navigator.clipboard.writeText( code.textContent ).then( function () {
			button.textContent = 'Copied';
			button.setAttribute( 'aria-live', 'polite' );
			window.setTimeout( function () {
				button.textContent = label;
			}, 1600 );
		} );
	} );

	// Highlight the link of the section that is being read.
	var links = {};

	root.querySelectorAll( '.beltoft-services-apiref__nav a[href^="#"]' ).forEach( function ( link ) {
		links[ link.getAttribute( 'href' ).slice( 1 ) ] = link;
	} );

	var sections = root.querySelectorAll( '[data-apiref-section]' );

	if ( ! ( 'IntersectionObserver' in window ) || ! sections.length ) {
		return;
	}

	var visible = {};
	var current = null;

	function update() {
		var best = null;
		var bestIsOp = false;

		sections.forEach( function ( section ) {
			if ( ! visible[ section.id ] ) {
				return;
			}

			var isOp = section.classList.contains( 'beltoft-services-apiref__op' );
			var top = section.getBoundingClientRect().top;

			if ( ! best || ( isOp && ! bestIsOp ) || ( isOp === bestIsOp && top < best.getBoundingClientRect().top ) ) {
				best = section;
				bestIsOp = isOp;
			}
		} );

		if ( ! best || best === current ) {
			return;
		}

		current = best;

		var group = best.closest( '.beltoft-services-apiref__group' );
		var active = {};

		active[ best.id ] = true;

		if ( group ) {
			active[ group.id ] = true;
		}

		Object.keys( links ).forEach( function ( id ) {
			links[ id ].classList.toggle( 'is-current', !! active[ id ] );

			if ( active[ id ] ) {
				links[ id ].setAttribute( 'aria-current', 'location' );
			} else {
				links[ id ].removeAttribute( 'aria-current' );
			}
		} );
	}

	var observer = new IntersectionObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			visible[ entry.target.id ] = entry.isIntersecting;
		} );

		update();
	}, { rootMargin: '-96px 0px -60% 0px' } );

	sections.forEach( function ( section ) {
		observer.observe( section );
	} );
}() );
