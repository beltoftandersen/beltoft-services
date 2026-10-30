/** Customer server console. */
( function () {
	'use strict';
	var cfg = window.beltoftServicesConsole;
	if ( ! cfg || ! window.fetch ) { return; }
	var root = document.querySelector( '[data-console]' );
	if ( ! root ) { return; }
	var button = root.querySelector( '[data-console-open]' );
	var status = root.querySelector( '[data-console-status]' );
	var screen = root.querySelector( '[data-console-screen]' );
	var toolbar = root.querySelector( '[data-console-toolbar]' );
	var input = root.querySelector( '[data-console-input]' );
	var rfb = null;
	var idleTimer = 0;
	var maxTimer = 0;
	function say( key ) { status.textContent = cfg.strings[key] || ''; }
	function stopTimers() { window.clearTimeout( idleTimer ); window.clearTimeout( maxTimer ); }
	function close( message ) {
		stopTimers();
		if ( rfb ) { var old = rfb; rfb = null; old.disconnect(); }
		screen.hidden = true; toolbar.hidden = true; button.hidden = false; button.disabled = false;
		if ( message ) { say( message ); }
	}
	function activity() {
		window.clearTimeout( idleTimer );
		idleTimer = window.setTimeout( function () { close( 'disconnected' ); }, 15 * 60 * 1000 );
	}
	function demo() {
		screen.classList.add( 'is-demo' ); screen.textContent = 'Beltoft server console\n\nUbuntu 24.04 LTS\n\nserver login: ';
		input.hidden = false;
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) { screen.textContent += input.value + '\\nPassword: '; input.value = ''; event.preventDefault(); activity(); }
		}, { once: false } );
		input.focus();
	}
	function open() {
		button.disabled = true; say( 'connecting' );
		window.fetch( cfg.rest, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, body: '{}' } )
			.then( function ( response ) { if ( ! response.ok ) { throw response; } return response.json(); } )
			.then( function ( data ) {
				screen.hidden = false; toolbar.hidden = false; button.hidden = true; activity();
				maxTimer = window.setTimeout( function () { close( 'disconnected' ); }, 60 * 60 * 1000 );
				if ( data.transport === 'demo' ) { say( 'connected' ); demo(); return; }
				return import( cfg.novnc ).then( function ( module ) {
					var url = new URL( data.path, window.location.origin ); url.protocol = 'https:' === window.location.protocol ? 'wss:' : 'ws:';
					rfb = new module.default( screen, url.href, { credentials: { password: data.password } } );
					rfb.scaleViewport = true; rfb.clipViewport = true;
					rfb.addEventListener( 'connect', function () { say( 'connected' ); activity(); } );
					rfb.addEventListener( 'disconnect', function () { rfb = null; close( 'disconnected' ); } );
					rfb.addEventListener( 'credentialsrequired', function () { close( 'failed' ); } );
				} );
			} ).catch( function ( error ) {
				var codes = { beltoft_services_console_busy: 'busy', beltoft_services_console_stopped: 'stopped', beltoft_services_console_unavailable: 'failed', beltoft_services_rate_limited: 'limit' };
				if ( ! error || ! error.json ) { close( 'failed' ); return; }
				error.json().then( function ( body ) { close( codes[ body && body.code ] || 'failed' ); }, function () { close( 'failed' ); } );
			} );
	}
	button.addEventListener( 'click', open );
	root.querySelector( '[data-console-disconnect]' ).addEventListener( 'click', function () { close( 'disconnected' ); } );
	root.querySelector( '[data-console-cad]' ).addEventListener( 'click', function () { if ( rfb ) { rfb.sendCtrlAltDel(); } } );
	// Keyboard shows a field: what is typed or pasted there is sent to the server as keypresses (a login prompt has
	// no clipboard). Paste types the clipboard the same way.
	function typeText( text ) {
		if ( ! rfb || ! text ) { return; }
		Array.from( text.replace( /\r?\n$/, '' ) ).forEach( function ( character ) { rfb.sendKey( character.codePointAt( 0 ) ); } );
		activity();
	}
	root.querySelector( '[data-console-keyboard]' ).addEventListener( 'click', function () { input.hidden = ! input.hidden; if ( ! input.hidden ) { input.focus(); } } );
	var paste = root.querySelector( '[data-console-paste]' );
	if ( paste ) {
		paste.hidden = ! ( navigator.clipboard && navigator.clipboard.readText );
		paste.addEventListener( 'click', function () {
			navigator.clipboard.readText().then( typeText, function () { input.hidden = false; input.focus(); } );
		} );
	}
	root.querySelector( '[data-console-fullscreen]' ).addEventListener( 'click', function () { if ( screen.requestFullscreen ) { screen.requestFullscreen(); } } );
	[ 'pointerdown', 'keydown', 'touchstart' ].forEach( function ( event ) { screen.addEventListener( event, activity, { passive: true } ); } );
	input.addEventListener( 'input', function () {
		if ( ! rfb ) { return; }
		typeText( input.value ); input.value = '';
	} );
	input.addEventListener( 'keydown', function ( event ) {
		if ( ! rfb ) { return; }
		var keys = { Enter: 0xff0d, Backspace: 0xff08, Tab: 0xff09 };
		if ( keys[event.key] ) { rfb.sendKey( keys[event.key] ); event.preventDefault(); activity(); }
	} );
}() );
