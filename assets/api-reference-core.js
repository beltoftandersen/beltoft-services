/**
 * What the two layouts of the API reference share: the copy buttons and the highlight of the section being read.
 *
 * window.beltoftApiRef.copyButtons( root, buttonSelector, holderSelector ) makes the buttons inside root copy the text of
 * the <code> of their holder. window.beltoftApiRef.scrollSpy( root, options ) marks the link of the section being read
 * (options: links, sections, opClass, groupSelector and onCurrent( section, links ) after a change).
 */
( function () {
	'use strict';

	function copyButtons( root, buttonSelector, holderSelector ) {
		root.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( buttonSelector ) : null;

			if ( ! button || ! navigator.clipboard ) {
				return;
			}

			var holder = button.closest( holderSelector );
			var code = holder ? holder.querySelector( 'code' ) : null;

			if ( ! code ) {
				return;
			}

			var label = button.textContent;

			navigator.clipboard.writeText( code.textContent ).then( function () {
				button.textContent = button.getAttribute( 'data-copied' ) || 'Copied';
				button.setAttribute( 'aria-live', 'polite' );
				window.setTimeout( function () {
					button.textContent = label;
				}, 1600 );
			} );
		} );
	}

	function scrollSpy( root, options ) {
		var links = {};

		root.querySelectorAll( options.links ).forEach( function ( link ) {
			links[ link.getAttribute( 'href' ).slice( 1 ) ] = link;
		} );

		var sections = root.querySelectorAll( options.sections );

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

				var isOp = section.classList.contains( options.opClass );
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

			var group = best.closest( options.groupSelector );
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

			if ( options.onCurrent ) {
				options.onCurrent( best, links );
			}
		}

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				visible[ entry.target.id ] = entry.isIntersecting;
			} );

			update();
		}, { rootMargin: '-96px 0px -60% 0px' } );

		sections.forEach( function ( section ) {
			if ( section.id ) {
				observer.observe( section );
			}
		} );
	}

	window.beltoftApiRef = { copyButtons: copyButtons, scrollSpy: scrollSpy };
}() );
