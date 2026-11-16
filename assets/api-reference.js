( function () {
	'use strict';

	var root = document.querySelector( '[data-apiref]' );

	if ( ! root ) {
		return;
	}

	root.classList.add( 'is-js' );

	// Copy buttons.
	window.beltoftApiRef.copyButtons( root, '[data-apiref-copy]', '.beltoft-services-apiref__panel' );

	// Highlight the link of the section that is being read.
	window.beltoftApiRef.scrollSpy( root, {
		links: '.beltoft-services-apiref__nav a[href^="#"]',
		sections: '[data-apiref-section]',
		opClass: 'beltoft-services-apiref__op',
		groupSelector: '.beltoft-services-apiref__group'
	} );
}() );
