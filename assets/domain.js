/* Domain actions use the same REST nonce and resource id as the service account panel. */
( function () {
	'use strict';
	var config = window.beltoftServicesAccount;
	if ( ! config ) { return; }
	function request( method, path, args ) {
		return window.fetch( config.root + path, { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, body: args ? JSON.stringify( { args: args } ) : undefined } ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );
			if ( nonce ) { config.nonce = nonce; }
			return response.json().then( function ( body ) { if ( ! response.ok ) { throw new Error( body.message || 'Request failed' ); } return body; } );
		} );
	}
	var TYPES = [ 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA' ];
	var EXTRAS = { MX: [ 'priority' ], SRV: [ 'priority', 'weight', 'port' ], CAA: [ 'flag', 'tag' ] };
	function field( name, value, label, type ) {
		var input = document.createElement( 'input' );
		input.type = type || 'text';
		input.setAttribute( 'data-f', name );
		input.setAttribute( 'aria-label', label );
		input.placeholder = label;
		input.autocomplete = 'off';
		input.value = value === undefined || value === null ? '' : value;
		if ( 'number' === input.type ) { input.min = '0'; }
		return input;
	}
	function showExtras( tr ) {
		var wanted = EXTRAS[ tr.querySelector( '[data-f=type]' ).value ] || [];
		Array.prototype.forEach.call( tr.querySelectorAll( '.beltoft-dns-extra' ), function ( input ) {
			input.hidden = wanted.indexOf( input.getAttribute( 'data-f' ) ) < 0;
		} );
	}
	function addRow( form, row ) {
		var t = JSON.parse( form.dataset.i18n );
		var tr = document.createElement( 'tr' );
		var cells = [ 0, 1, 2, 3, 4 ].map( function () { return tr.appendChild( document.createElement( 'td' ) ); } );
		[ t.name, t.type, t.ttl, t.value ].forEach( function ( label, i ) { cells[ i ].setAttribute( 'data-label', label ); } );
		var name = field( 'name', row.name, t.name );
		name.title = t.name_hint;
		cells[ 0 ].appendChild( name );
		var type = document.createElement( 'select' );
		type.setAttribute( 'data-f', 'type' );
		type.setAttribute( 'aria-label', 'Type' );
		TYPES.forEach( function ( value ) { var o = document.createElement( 'option' ); o.value = o.textContent = value; type.appendChild( o ); } );
		type.value = row.type || 'A';
		type.addEventListener( 'change', function () { showExtras( tr ); } );
		cells[ 1 ].appendChild( type );
		var ttl = field( 'ttl', row.ttl || 3600, 'TTL', 'number' );
		ttl.min = '60';
		ttl.max = '86400';
		cells[ 2 ].appendChild( ttl );
		var box = cells[ 3 ].appendChild( document.createElement( 'div' ) );
		box.className = 'beltoft-dns-value';
		[ 'priority', 'weight', 'port', 'flag' ].forEach( function ( key ) {
			var input = field( key, row[ key ], t[ key ], 'number' );
			input.className = 'beltoft-dns-extra';
			box.appendChild( input );
		} );
		var tag = field( 'tag', row.tag || 'issue', t.tag );
		tag.className = 'beltoft-dns-extra';
		box.appendChild( tag );
		box.appendChild( field( 'value', row.value, t.value ) );
		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'beltoft-services-domain__link beltoft-dns-remove';
		remove.textContent = t.remove;
		cells[ 4 ].appendChild( remove );
		form.querySelector( 'tbody' ).appendChild( tr );
		showExtras( tr );
		return tr;
	}
	function syncEmpty( form ) {
		var none = ! form.querySelector( 'tbody tr' );
		form.querySelector( 'table' ).hidden = none;
		form.querySelector( '.beltoft-services-domain__empty' ).hidden = ! none;
		filter( form );
	}
	// Long zones: the first rows show, a search finds the rest, and a toggle shows them all. Hidden rows
	// stay in the form, so saving still sends every record.
	var SHOWN = 10;
	function filterControls( form ) {
		if ( form.querySelector( '.beltoft-dns-search' ) ) { return; }
		var t = JSON.parse( form.dataset.i18n );
		var search = document.createElement( 'input' );
		search.type = 'search';
		search.className = 'beltoft-dns-search';
		search.placeholder = t.search;
		search.setAttribute( 'aria-label', t.search );
		search.addEventListener( 'input', function () { filter( form ); } );
		var none = document.createElement( 'p' );
		none.className = 'beltoft-dns-nomatch';
		none.textContent = t.no_match;
		none.hidden = true;
		var more = document.createElement( 'button' );
		more.type = 'button';
		more.className = 'beltoft-services-domain__link beltoft-dns-more';
		more.addEventListener( 'click', function () { form.dataset.all = form.dataset.all ? '' : '1'; filter( form ); } );
		var table = form.querySelector( 'table' );
		table.parentNode.insertBefore( search, table );
		table.parentNode.insertBefore( none, table.nextSibling );
		none.parentNode.insertBefore( more, none.nextSibling );
	}
	function filter( form ) {
		var rows = Array.prototype.slice.call( form.querySelectorAll( 'tbody tr' ) );
		var search = form.querySelector( '.beltoft-dns-search' );
		if ( rows.length <= SHOWN && ! search ) { return; }
		filterControls( form );
		search = form.querySelector( '.beltoft-dns-search' );
		var t = JSON.parse( form.dataset.i18n );
		var query = search.value.trim().toLowerCase();
		var matches = 0;
		rows.forEach( function ( tr, i ) {
			var text = Array.prototype.map.call( tr.querySelectorAll( 'input, select' ), function ( c ) { return c.value; } ).join( ' ' ).toLowerCase();
			var hit = '' === query ? true : -1 !== text.indexOf( query );
			if ( hit ) { matches++; }
			// A row added now is always shown, so it can be filled in.
			tr.hidden = ! tr.dataset.added && ( ! hit || ( '' === query && ! form.dataset.all && i >= SHOWN ) );
		} );
		var more = form.querySelector( '.beltoft-dns-more' );
		more.hidden = '' !== query || rows.length <= SHOWN;
		more.textContent = form.dataset.all ? t.show_fewer : t.show_all.replace( '%d', rows.length );
		form.querySelector( '.beltoft-dns-nomatch' ).hidden = '' === query || matches > 0;
		search.hidden = rows.length <= SHOWN && '' === query;
	}
	function serialise( form ) {
		var rows = [];
		Array.prototype.forEach.call( form.querySelectorAll( 'tbody tr' ), function ( tr ) {
			var get = function ( key ) { return tr.querySelector( '[data-f=' + key + ']' ).value.trim(); };
			var type = get( 'type' );
			var row = { name: get( 'name' ), type: type, ttl: parseInt( get( 'ttl' ), 10 ) || 3600, value: get( 'value' ) };
			( EXTRAS[ type ] || [] ).forEach( function ( key ) { row[ key ] = 'tag' === key ? get( key ) : parseInt( get( key ), 10 ); } );
			rows.push( row );
		} );
		form.querySelector( '[name=records]' ).value = JSON.stringify( rows );
	}
	Array.prototype.forEach.call( document.querySelectorAll( '.beltoft-dns-editor' ), function ( form ) {
		JSON.parse( form.dataset.rows || '[]' ).forEach( function ( row ) { addRow( form, row ); } );
		syncEmpty( form );
		if ( form.dataset.readonly ) {
			Array.prototype.forEach.call( form.querySelectorAll( 'input:not([type=hidden]), select' ), function ( control ) { control.disabled = true; } );
			Array.prototype.forEach.call( form.querySelectorAll( '.beltoft-dns-remove' ), function ( button ) { button.hidden = true; } );
		}
	} );
	document.addEventListener( 'click', function ( event ) {
		var add = event.target.closest( '.beltoft-dns-add' );
		if ( add ) { var fresh = addRow( add.closest( 'form' ), {} ); fresh.dataset.added = '1'; syncEmpty( add.closest( 'form' ) ); fresh.querySelector( '[data-f=name]' ).focus(); return; }
		var remove = event.target.closest( '.beltoft-dns-remove' );
		if ( remove ) { var form = remove.closest( 'form' ); remove.closest( 'tr' ).remove(); syncEmpty( form ); }
	} );
	document.addEventListener( 'change', function ( event ) {
		var radio = event.target.closest ? event.target.closest( '.beltoft-services-domain__ns input[name=mode]' ) : null;
		if ( radio ) { radio.form.querySelector( '.beltoft-services-domain__custom' ).hidden = 'custom' !== radio.value; }
	} );
	// The change runs in the background: say so, and reload when it is done so the page shows the new state.
	function settle( panel, form ) {
		var note = form.querySelector( '.beltoft-services-domain__status' );
		if ( ! note ) {
			note = document.createElement( 'span' );
			note.className = 'beltoft-services-domain__status';
			note.setAttribute( 'role', 'status' );
			form.appendChild( note );
		}
		note.textContent = '';
		var bar = document.createElement( 'span' );
		bar.className = 'beltoft-services-domain__bar';
		bar.setAttribute( 'aria-hidden', 'true' );
		var text = document.createElement( 'span' );
		text.textContent = panel.dataset.updating || '';
		var slow = document.createElement( 'span' );
		slow.className = 'beltoft-services-domain__slow';
		slow.textContent = panel.dataset.updatingNote || '';
		note.appendChild( bar );
		note.appendChild( text );
		note.appendChild( slow );
		Array.prototype.forEach.call( panel.querySelectorAll( 'button' ), function ( b ) { b.disabled = true; } );
		var rounds = 15; // every 4 seconds: well inside the status route's rate limit
		function next() {
			rounds -= 1;
			if ( rounds < 1 ) { window.location.reload(); return; }
			window.setTimeout( again, 4000 );
		}
		function again() {
			request( 'GET', panel.dataset.domainResource ).then( function ( body ) {
				if ( ! body.working ) { window.location.reload(); return; }
				next();
			} ).catch( next );
		}
		again();
	}
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '.beltoft-services-domain-form' );
		if ( ! form ) { return; }
		event.preventDefault();
		var panel = form.closest( '[data-domain-resource]' );
		var box = form.querySelector( '.beltoft-dns-error' );
		if ( box ) { box.hidden = true; }
		if ( form.classList.contains( 'beltoft-dns-editor' ) ) { serialise( form ); }
		var args = {};
		new FormData( form ).forEach( function ( value, key ) { args[ key ] = String( value ); } );
		var button = form.querySelector( 'button[type=submit]' );
		button.disabled = true;
		if ( 'unlock' === form.dataset.domainAction ) {
			try { window.sessionStorage.setItem( 'beltoftShowCode', panel.dataset.domainResource ); } catch ( e ) {}
		}
		request( 'POST', panel.dataset.domainResource + '/actions/' + form.dataset.domainAction, args ).then( function () { settle( panel, form ); } ).catch( function ( error ) { if ( box ) { box.textContent = error.message; box.hidden = false; } else { window.alert( error.message ); } button.disabled = false; } );
	} );
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.beltoft-services-domain-auth' );
		if ( ! button ) { return; }
		var panel = button.closest( '[data-domain-resource]' );
		button.disabled = true;
		request( 'GET', panel.dataset.domainResource + '/domain-authcode' ).then( function ( body ) {
			var code = panel.querySelector( '.beltoft-services-domain-code' );
			code.value = body.auth_code;
			code.closest( '.beltoft-services-domain__code' ).hidden = false;
			code.focus();
			code.select();
			button.disabled = false;
		} ).catch( function ( error ) { window.alert( error.message ); button.disabled = false; } );
	} );
	document.addEventListener( 'click', function ( event ) {
		var copy = event.target.closest( '.beltoft-services-domain__copy' );
		if ( ! copy ) { return; }
		var input = copy.parentNode.querySelector( 'input' );
		var label = copy.getAttribute( 'data-label' ) || copy.textContent;
		function done() {
			copy.setAttribute( 'data-label', label );
			copy.textContent = copy.getAttribute( 'data-copied' );
			window.setTimeout( function () { copy.textContent = label; }, 2000 );
		}
		function fallback() {
			input.select();
			try { if ( document.execCommand( 'copy' ) ) { done(); } } catch ( e ) {}
		}
		if ( window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( input.value ).then( done ).catch( fallback );
		} else {
			fallback();
		}
	} );
	// After unlocking, the transfer code is what the customer came for: show it once the page is back.
	( function () {
		var id = null;
		try { id = window.sessionStorage.getItem( 'beltoftShowCode' ); window.sessionStorage.removeItem( 'beltoftShowCode' ); } catch ( e ) {}
		var panel = id ? document.querySelector( '[data-domain-resource="' + id + '"]' ) : null;
		var show = panel ? panel.querySelector( '.beltoft-services-domain-auth' ) : null;
		if ( show ) { show.click(); }
	}() );
}() );

// DNS hosting card: move the registration here. The transfer goes through the same cart route as the
// domain search; the transfer code is sent once, over HTTPS, and stored encrypted by the server.
( function () {
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.matches || ! form.matches( 'form[data-domain-transfer]' ) ) { return; }
		event.preventDefault();
		var input = form.querySelector( 'input[name="auth_code"]' );
		var button = form.querySelector( 'button[type="submit"]' );
		var error = form.querySelector( '[role="alert"]' );
		var code = input ? input.value.trim() : '';
		if ( ! code ) { if ( input ) { input.focus(); } return; }
		var label = button ? button.textContent : '';
		if ( button ) { button.disabled = true; button.textContent = form.getAttribute( 'data-busy' ) || label; }
		if ( error ) { error.hidden = true; error.textContent = ''; }
		window.fetch( form.getAttribute( 'data-cart' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WC-Nonce': form.getAttribute( 'data-store-nonce' ), 'X-WP-Nonce': form.getAttribute( 'data-rest-nonce' ) },
			body: JSON.stringify( { domain: form.getAttribute( 'data-domain' ), mode: 'transfer', auth_code: code } )
		} ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( data ) { return { ok: response.ok, data: data }; } );
		} ).then( function ( result ) {
			if ( result.ok && result.data && result.data.checkout_url ) {
				window.location.assign( result.data.checkout_url );
				return;
			}
			throw new Error( ( result.data && result.data.message ) || 'Error' );
		} ).catch( function ( failure ) {
			if ( button ) { button.disabled = false; button.textContent = label; }
			if ( error ) { error.textContent = failure.message; error.hidden = false; }
		} );
	} );
}() );

// Domain cards start closed: the header shows the domain and its status, a click (or Enter / Space)
// opens the rest. A card that was open stays open when the page reloads after a change.
( function () {
	var KEY = 'beltoftDomainOpen';
	function opened() {
		try { return JSON.parse( window.sessionStorage.getItem( KEY ) || '[]' ); } catch ( e ) { return []; }
	}
	function remember( id, open ) {
		var list = opened().filter( function ( item ) { return item !== id; } );
		if ( open ) { list.push( id ); }
		try { window.sessionStorage.setItem( KEY, JSON.stringify( list ) ); } catch ( e ) { /* storage can be blocked */ }
	}
	Array.prototype.forEach.call( document.querySelectorAll( '.beltoft-services-domain' ), function ( panel ) {
		var card = panel.closest( 'section' );
		var head = card ? card.querySelector( 'header' ) : null;
		if ( ! card || ! head || head.hasAttribute( 'aria-expanded' ) ) { return; }
		var id = card.getAttribute( 'aria-labelledby' ) || panel.getAttribute( 'data-domain-resource' ) || '';
		head.classList.add( 'beltoft-services-domain__toggle' );
		head.setAttribute( 'role', 'button' );
		head.setAttribute( 'tabindex', '0' );
		// Closed comes from the stylesheet (no flash of open cards); the script only marks the open ones.
		function set( open ) {
			card.classList.toggle( 'beltoft-services-domain--open', !! open );
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		set( -1 !== opened().indexOf( id ) || ( '' !== window.location.hash && card.querySelector( window.location.hash.replace( /[^#\w-]/g, '' ) ) ) );
		function toggle( event ) {
			if ( event.target.closest( 'a, button, input, select, textarea' ) ) { return; }
			var open = 'true' !== head.getAttribute( 'aria-expanded' );
			set( open );
			remember( id, open );
		}
		head.addEventListener( 'click', toggle );
		head.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || ' ' === event.key ) { event.preventDefault(); toggle( event ); }
		} );
	} );
}() );
