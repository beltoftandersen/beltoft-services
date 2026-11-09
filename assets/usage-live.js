/* Keeps the CPU, Memory and Disk bars of the server list current while the page is visible. */
( function () {
	'use strict';
	var config = window.beltoftServicesUsage;
	if ( ! config || ! window.fetch || ! window.beltoftApi ) { return; }
	var busy = false;
	var timer = null;

	function bars() { return document.querySelectorAll( '[data-usage-resource]' ); }

	function apply( resources ) {
		bars().forEach( function ( box ) {
			var entry = resources[ box.getAttribute( 'data-usage-resource' ) ];
			if ( ! entry ) { return; }
			box.querySelectorAll( '[data-usage-bar]' ).forEach( function ( bar ) {
				var value = entry[ bar.getAttribute( 'data-usage-bar' ) ];
				if ( 'number' !== typeof value || ! isFinite( value ) ) { return; }
				var percent = Math.max( 0, Math.min( 100, Math.round( value ) ) );
				var fill = bar.querySelector( '[data-usage-fill]' );
				var text = bar.querySelector( '[data-usage-text]' );
				if ( fill ) { fill.style.width = percent + '%'; }
				if ( text ) { text.textContent = percent + '%'; }
			} );
		} );
	}

	function load() {
		if ( busy || 'visible' !== document.visibilityState ) { return; }
		busy = true;
		window.beltoftApi.call( config.url, { holder: config } ).then( function ( reply ) {
			if ( ! reply.ok || ! reply.parsed ) { throw new Error( 'usage' ); }
			return reply.data;
		} ).then( function ( body ) {
			if ( body && body.resources ) { apply( body.resources ); }
		} ).catch( function () {} ).then( function () { busy = false; } );
	}

	function stop() {
		if ( timer ) { window.clearInterval( timer ); timer = null; }
	}

	function start() {
		stop();
		if ( 'visible' !== document.visibilityState ) { return; }
		timer = window.setInterval( load, config.interval || 30000 );
	}

	if ( ! bars().length ) { return; }
	document.addEventListener( 'visibilitychange', function () {
		if ( 'visible' === document.visibilityState ) { load(); }
		start();
	} );
	start();
}() );
