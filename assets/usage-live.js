/* Keeps the CPU, Memory and Disk bars of the server list current while the page is visible.
 * Reads every 10 seconds; after 10 minutes without a page interaction it slows to once a minute, so a forgotten tab does not poll all day. */
( function () {
	'use strict';
	var config = window.beltoftServicesUsage;
	if ( ! config || ! window.fetch || ! window.beltoftApi ) { return; }
	var strings = config.strings || {};
	var busy = false;
	var timer = null;
	var lastActive = Date.now();

	function bars() { return document.querySelectorAll( '[data-usage-resource]' ); }

	function ids() {
		var list = [];
		bars().forEach( function ( box ) { list.push( box.getAttribute( 'data-usage-resource' ) ); } );
		return list;
	}

	function memory( bar, info ) {
		var fillNode = bar.querySelector( '[data-usage-fill]' );
		var text = bar.querySelector( '[data-usage-text]' );
		var sub = bar.querySelector( '[data-usage-sub]' );
		var state = info.state;
		bar.setAttribute( 'data-mem-state', state );
		bar.hidden = 'stopped' === state;
		if ( 'ok' === state && 'number' === typeof info.percent ) {
			if ( fillNode ) { fillNode.style.width = Math.max( 0, Math.min( 100, Math.round( info.percent ) ) ) + '%'; }
			if ( text ) { text.textContent = Math.round( info.percent ) + '%'; }
			if ( sub ) { sub.textContent = ''; }
			return;
		}
		if ( fillNode ) { fillNode.style.width = '0%'; }
		if ( text ) { text.textContent = 'no_agent' === state ? '–' : ( 'waiting' === state ? '…' : '' ); }
		if ( sub ) { sub.textContent = 'no_agent' === state ? ( strings.hint || '' ) : ''; }
	}

	function apply( resources ) {
		bars().forEach( function ( box ) {
			var entry = resources[ box.getAttribute( 'data-usage-resource' ) ];
			if ( ! entry ) { return; }
			box.querySelectorAll( '[data-usage-bar]' ).forEach( function ( bar ) {
				var key = bar.getAttribute( 'data-usage-bar' );
				if ( 'mem' === key && entry.memory ) { memory( bar, entry.memory ); return; }
				var value = entry[ key ];
				if ( 'number' !== typeof value || ! isFinite( value ) ) { return; }
				var percent = Math.max( 0, Math.min( 100, Math.round( value ) ) );
				var fillNode = bar.querySelector( '[data-usage-fill]' );
				var text = bar.querySelector( '[data-usage-text]' );
				if ( fillNode ) { fillNode.style.width = percent + '%'; }
				if ( text ) { text.textContent = percent + '%'; }
				bar.hidden = false;
			} );
		} );
	}

	function load() {
		if ( busy || 'visible' !== document.visibilityState ) { return; }
		busy = true;
		var url = config.url + ( config.url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'ids=' + encodeURIComponent( ids().join( ',' ) );
		window.beltoftApi.call( url, { holder: config } ).then( function ( reply ) {
			if ( ! reply.ok || ! reply.parsed ) { throw new Error( 'usage' ); }
			return reply.data;
		} ).then( function ( body ) {
			if ( body && body.resources ) { apply( body.resources ); }
		} ).catch( function () {} ).then( function () { busy = false; } );
	}

	function idle() { return Date.now() - lastActive > ( config.idle || 600000 ); }

	function stop() {
		if ( timer ) { window.clearTimeout( timer ); timer = null; }
	}

	function next() {
		stop();
		if ( 'visible' !== document.visibilityState ) { return; }
		timer = window.setTimeout( function () { load(); next(); }, idle() ? ( config.slow || 60000 ) : ( config.interval || 10000 ) );
	}

	// Any interaction is "someone is here": a tab that had slowed down goes back to the fast reading at once.
	function touched() {
		var was = idle();
		lastActive = Date.now();
		if ( was ) { load(); next(); }
	}

	if ( ! bars().length ) { return; }
	[ 'pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart' ].forEach( function ( name ) {
		window.addEventListener( name, touched, { passive: true } );
	} );
	document.addEventListener( 'visibilitychange', function () {
		if ( 'visible' === document.visibilityState ) { lastActive = Date.now(); load(); }
		next();
	} );
	next();
}() );
