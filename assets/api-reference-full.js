( function () {
	'use strict';

	var root = document.querySelector( '[data-bsdocs]' );

	if ( ! root ) {
		return;
	}

	root.classList.add( 'is-js' );

	var KEY = 'beltoft-services-apiref-lang';

	// Language tabs, remembered.
	var tabs = root.querySelectorAll( '[data-bsdocs-lang]' );

	function setLang( lang, remember ) {
		var found = false;

		tabs.forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-bsdocs-lang' ) === lang;

			found = found || on;
			tab.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );

		if ( ! found ) {
			return;
		}

		root.setAttribute( 'data-lang', lang );

		if ( remember ) {
			try {
				window.localStorage.setItem( KEY, lang );
			} catch ( e ) {}
		}
	}

	try {
		setLang( window.localStorage.getItem( KEY ), false );
	} catch ( e ) {}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			setLang( tab.getAttribute( 'data-bsdocs-lang' ), true );
		} );
	} );

	// Copy buttons: a sample's code, or the base address.
	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-bsdocs-copy]' ) : null;

		if ( ! button || ! navigator.clipboard ) {
			return;
		}

		var holder = button.closest( '.bsdocs__code, .bsdocs__base' );
		var code = holder ? holder.querySelector( 'code' ) : null;

		if ( ! code ) {
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

	// Search in the navigation.
	var search = root.querySelector( '[data-bsdocs-search]' );
	var nav = root.querySelector( '[data-bsdocs-nav]' );
	var empty = root.querySelector( '[data-bsdocs-empty]' );

	if ( search && nav ) {
		search.addEventListener( 'input', function () {
			var q = search.value.trim().toLowerCase();
			var shown = 0;

			root.classList.toggle( 'is-searching', q !== '' );

			nav.querySelectorAll( '.bsdocs__group' ).forEach( function ( group ) {
				var groupHit = q === '' || ( group.getAttribute( 'data-search' ) || '' ).indexOf( q ) !== -1;
				var items = group.querySelectorAll( 'ul > li' );
				var any = false;

				items.forEach( function ( item ) {
					var hit = groupHit || ( item.getAttribute( 'data-search' ) || '' ).indexOf( q ) !== -1;

					item.hidden = ! hit;
					any = any || hit;
				} );

				group.hidden = ! ( groupHit || any );

				if ( ! group.hidden ) {
					shown++;
				}
			} );

			if ( empty ) {
				empty.hidden = shown > 0;
			}
		} );

		search.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				search.value = '';
				search.dispatchEvent( new Event( 'input' ) );
			}
		} );

		nav.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( 'a' ) ) {
				root.classList.remove( 'is-searching' );
			}
		} );
	}

	// Jump list on tablet and phone.
	var jump = root.querySelector( '[data-bsdocs-jump]' );

	if ( jump ) {
		jump.addEventListener( 'change', function () {
			var target = jump.value ? document.getElementById( jump.value ) : null;

			if ( target ) {
				target.scrollIntoView();
				window.history.replaceState( null, '', '#' + jump.value );
			}

			jump.value = '';
		} );
	}

	// Scroll spy: highlight the section being read and keep it in view in the sidebar.
	var links = {};

	root.querySelectorAll( '.bsdocs__nav a[href^="#"]' ).forEach( function ( link ) {
		links[ link.getAttribute( 'href' ).slice( 1 ) ] = link;
	} );

	var sections = root.querySelectorAll( '[data-bsdocs-section]' );

	if ( ! ( 'IntersectionObserver' in window ) || ! sections.length ) {
		return;
	}

	var visible = {};
	var current = null;

	function keepInView( link ) {
		var side = root.querySelector( '.bsdocs__side' );

		if ( ! side || side.scrollHeight <= side.clientHeight ) {
			return;
		}

		var s = side.getBoundingClientRect();
		var l = link.getBoundingClientRect();
		var bar = root.querySelector( '.bsdocs__bar' );
		var top = s.top + ( bar ? bar.offsetHeight : 0 );

		if ( l.top < top ) {
			side.scrollTop -= top - l.top + 8;
		} else if ( l.bottom > s.bottom ) {
			side.scrollTop += l.bottom - s.bottom + 8;
		}
	}

	function update() {
		var best = null;
		var bestIsOp = false;

		sections.forEach( function ( section ) {
			if ( ! visible[ section.id ] ) {
				return;
			}

			var isOp = section.classList.contains( 'bsdocs__op' );
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

		var group = best.closest( '.bsdocs__group-section' );
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

		if ( links[ best.id ] && window.matchMedia( '(min-width: 1200px)' ).matches ) {
			keepInView( links[ best.id ] );
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
}() );
