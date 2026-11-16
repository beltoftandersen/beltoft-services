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
	window.beltoftApiRef.copyButtons( root, '[data-bsdocs-copy]', '.bsdocs__code, .bsdocs__base' );

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

	window.beltoftApiRef.scrollSpy( root, {
		links: '.bsdocs__nav a[href^="#"]',
		sections: '[data-bsdocs-section]',
		opClass: 'bsdocs__op',
		groupSelector: '.bsdocs__group-section',
		onCurrent: function ( best, links ) {
			if ( links[ best.id ] && window.matchMedia( '(min-width: 1200px)' ).matches ) {
				keepInView( links[ best.id ] );
			}
		}
	} );
}() );
