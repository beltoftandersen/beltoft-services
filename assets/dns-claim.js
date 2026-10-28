/* My Account → Domains: add a domain for DNS hosting. Every text is set with textContent. */
( function () {
	'use strict';

	var config = window.beltoftDnsClaims;
	var root = document.querySelector( '.beltoft-dns-claim' );
	if ( ! config || ! root ) { return; }

	var t = config.strings;
	var list = root.querySelector( '[data-dns-claim-list]' );
	var form = root.querySelector( '[data-dns-claim-add]' );
	var claims = [];
	var local = {};
	var timers = {};

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( undefined !== text && null !== text ) { node.textContent = text; }
		return node;
	}

	function request( method, path, body ) {
		var options = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } };
		if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		return window.fetch( config.root + path, options ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( data && data.message ? data.message : ( 403 === response.status ? t.expired : t.failed ) );
					error.data = data || {};
					throw error;
				}
				return data;
			} );
		} );
	}

	/* A button that is disabled with a working label while a request runs. */
	function busy( button, run ) {
		var label = button.textContent;
		button.disabled = true;
		button.textContent = t.working;
		return run().then( function ( value ) {
			if ( button.isConnected ) { button.disabled = false; button.textContent = label; }
			return value;
		}, function ( error ) {
			if ( button.isConnected ) { button.disabled = false; button.textContent = label; }
			throw error;
		} );
	}

	function button( label, className ) {
		var node = el( 'button', className || 'button woocommerce-button', label );
		node.type = 'button';
		return node;
	}

	function state( id ) {
		if ( ! local[ id ] ) { local[ id ] = { off: {}, extra: [], skipped: [], note: '', error: '', scanning: false, polls: 0, gave_up: false }; }
		return local[ id ];
	}

	function find( id ) {
		for ( var i = 0; i < claims.length; i++ ) { if ( claims[ i ].id === id ) { return claims[ i ]; } }
		return null;
	}

	/* Claims that are over are not shown: nothing is left to do for them. */
	function finished( claim ) {
		return 'consumed' === claim.status || 'expired' === claim.status || 'cancelled' === claim.status;
	}

	function valid( claim ) {
		return !! claim && 'object' === typeof claim && 'number' === typeof claim.id && 'string' === typeof claim.status;
	}

	function put( claim ) {
		var known = find( claim.id );
		if ( known ) { claims[ claims.indexOf( known ) ] = claim; } else { claims.unshift( claim ); }
		draw( claim.id );
	}

	function drop( id ) {
		var known = find( id );
		if ( known ) { claims.splice( claims.indexOf( known ), 1 ); }
		var block = list.querySelector( '[data-claim="' + id + '"]' );
		if ( block ) { block.remove(); }
	}

	function loader( text ) {
		var box = el( 'div', 'beltoft-dns-claim__loading' );
		box.setAttribute( 'role', 'status' );
		box.appendChild( el( 'span', 'beltoft-dns-claim__bar' ) );
		box.appendChild( el( 'span', 'beltoft-dns-claim__loading-text', text ) );
		return box;
	}

	function message( text, className ) {
		return el( 'p', className || 'beltoft-dns-claim__message', text );
	}

	function copyRow( label, value ) {
		var row = el( 'div', 'beltoft-dns-claim__row' );
		var input = el( 'input' );
		input.type = 'text';
		input.readOnly = true;
		input.value = value;
		input.setAttribute( 'aria-label', label );
		var copy = button( t.copy, 'beltoft-dns-claim__link' );
		copy.addEventListener( 'click', function () {
			function done() {
				copy.textContent = t.copied;
				window.setTimeout( function () { copy.textContent = t.copy; }, 2000 );
			}
			function fallback() {
				input.select();
				try { if ( document.execCommand( 'copy' ) ) { done(); } } catch ( e ) {}
			}
			if ( window.navigator.clipboard && window.isSecureContext ) {
				window.navigator.clipboard.writeText( value ).then( done ).catch( fallback );
			} else {
				fallback();
			}
		} );
		row.appendChild( el( 'span', 'beltoft-dns-claim__label', label ) );
		row.appendChild( input );
		row.appendChild( copy );
		return row;
	}

	function cancelLink( claim ) {
		var cancel = button( t.cancel, 'beltoft-dns-claim__link' );
		cancel.addEventListener( 'click', function () {
			busy( cancel, function () { return request( 'DELETE', '/' + claim.id ); } ).then( function () { drop( claim.id ); }, function ( error ) {
				state( claim.id ).error = error.message;
				draw( claim.id );
			} );
		} );
		return cancel;
	}

	function errorLine( id ) {
		var s = state( id );
		return s.error ? message( s.error, 'beltoft-dns-claim__error' ) : null;
	}

	/* Polls one claim every few seconds, a limited number of times. */
	function poll( id, until ) {
		var s = state( id );
		window.clearTimeout( timers[ id ] );
		if ( s.polls >= config.attempts ) {
			s.gave_up = true;
			s.scanning = false;
			draw( id );
			return;
		}
		timers[ id ] = window.setTimeout( function () {
			s.polls++;
			request( 'GET', '/' + id ).then( function ( claim ) {
				var known = find( id );
				if ( ! known ) { return; }
				if ( ! valid( claim ) ) { poll( id, until ); return; }
				claims[ claims.indexOf( known ) ] = claim;
				if ( until( claim ) ) {
					s.scanning = false;
					s.polls = 0;
					draw( id );
				} else {
					poll( id, until );
				}
			}, function ( error ) {
				if ( 404 === ( error.data && error.data.data && error.data.data.status ) ) { drop( id ); return; }
				poll( id, until );
			} );
		}, config.interval );
	}

	function waitForCheck( id ) {
		poll( id, function ( claim ) { return 'checking' !== claim.status; } );
	}

	function pending( claim, block ) {
		var s = state( claim.id );
		block.appendChild( message( claim.message ) );
		block.appendChild( copyRow( t.type, 'TXT' ) );
		block.appendChild( copyRow( t.name, claim.txt_name || '' ) );
		block.appendChild( copyRow( t.value, claim.txt_value || '' ) );
		if ( claim.expires_at ) {
			var date = new Date( claim.expires_at );
			if ( ! isNaN( date.getTime() ) ) {
				block.appendChild( message( t.validUntil.replace( '%s', date.toLocaleString() ), 'beltoft-dns-claim__note' ) );
			}
		}
		var error = errorLine( claim.id );
		if ( error ) { block.appendChild( error ); }
		var actions = el( 'p', 'beltoft-dns-claim__actions' );
		var check = button( t.checkNow );
		check.addEventListener( 'click', function () {
			s.error = '';
			busy( check, function () { return request( 'POST', '/' + claim.id + '/check' ); } ).then( function ( view ) {
				s.polls = 0;
				s.gave_up = false;
				put( view );
			}, function ( failure ) { s.error = failure.message; draw( claim.id ); } );
		} );
		actions.appendChild( check );
		actions.appendChild( cancelLink( claim ) );
		block.appendChild( actions );
	}

	function checking( claim, block ) {
		var s = state( claim.id );
		if ( s.gave_up ) {
			block.appendChild( message( t.stillChecking ) );
		} else {
			block.appendChild( loader( t.checking ) );
			if ( ! timers[ claim.id ] || ! s.waiting ) {
				s.waiting = true;
				waitForCheck( claim.id );
			}
		}
		var failure = errorLine( claim.id );
		if ( failure ) { block.appendChild( failure ); }
		block.appendChild( el( 'p', 'beltoft-dns-claim__actions' ) ).appendChild( cancelLink( claim ) );
	}

	function key( row ) {
		return [ String( row.name || '' ).toLowerCase(), String( row.type || '' ).toUpperCase(), String( row.value || '' ) ].join( '|' );
	}

	/* Rows from the scan, the zone file and a saved draft, each name, type and value once. */
	function rowsFor( claim ) {
		var seen = {};
		var rows = [];
		var sources = [ claim.scan || [], ( claim.zone_file && claim.zone_file.records ) || [], claim.draft_records || [] ];
		sources.forEach( function ( source ) {
			source.forEach( function ( row ) {
				var id = key( row );
				if ( ! seen[ id ] ) { seen[ id ] = true; rows.push( { key: id, row: row } ); }
			} );
		} );
		return rows;
	}

	function shown( row ) {
		var parts = [];
		if ( 'MX' === row.type ) { parts.push( row.priority ); }
		if ( 'SRV' === row.type ) { parts.push( row.priority, row.weight, row.port ); }
		if ( 'CAA' === row.type ) { parts.push( row.flag, row.tag ); }
		parts.push( row.value );
		return parts.filter( function ( part ) { return undefined !== part && null !== part && '' !== part; } ).join( ' ' );
	}

	function table( claim, rows ) {
		var s = state( claim.id );
		var wrap = el( 'div', 'beltoft-dns-claim__table-wrap' );
		var grid = el( 'table', 'beltoft-dns-claim__table' );
		var head = el( 'tr' );
		head.appendChild( el( 'th' ) );
		[ t.name, t.type, t.value, t.ttl ].forEach( function ( label ) { head.appendChild( el( 'th', '', label ) ); } );
		grid.appendChild( el( 'thead' ) ).appendChild( head );
		var body = el( 'tbody' );
		rows.forEach( function ( item ) {
			var tr = el( 'tr' );
			var cell = el( 'td' );
			var box = el( 'input' );
			box.type = 'checkbox';
			box.checked = ! s.off[ item.key ];
			box.setAttribute( 'aria-label', item.row.name + ' ' + item.row.type );
			box.addEventListener( 'change', function () { s.off[ item.key ] = ! box.checked; } );
			cell.appendChild( box );
			tr.appendChild( cell );
			[ item.row.name, item.row.type, shown( item.row ), item.row.ttl ].forEach( function ( text, index ) {
				var td = el( 'td', index === 2 ? 'beltoft-dns-claim__value' : '', text );
				td.setAttribute( 'data-label', [ t.name, t.type, t.value, t.ttl ][ index ] );
				tr.appendChild( td );
			} );
			body.appendChild( tr );
		} );
		grid.appendChild( body );
		wrap.appendChild( grid );
		return wrap;
	}

	function verified( claim, block ) {
		var s = state( claim.id );
		block.appendChild( el( 'p', 'beltoft-dns-claim__status', t.verified ) );
		block.appendChild( message( claim.message ) );
		block.appendChild( el( 'h4', 'beltoft-dns-claim__step', t.bring ) );

		if ( s.scanning && ! s.gave_up ) {
			block.appendChild( loader( t.scanning ) );
		} else {
			var find_button = button( t.findRecords );
			find_button.addEventListener( 'click', function () {
				s.error = '';
				s.polls = 0;
				s.gave_up = false;
				busy( find_button, function () { return request( 'POST', '/' + claim.id + '/scan' ); } ).then( function ( view ) {
					s.scanning = null === view.scan;
					claims[ claims.indexOf( find( claim.id ) ) ] = view;
					draw( claim.id );
					if ( s.scanning ) { poll( claim.id, function ( next ) { return null !== next.scan; } ); }
				}, function ( failure ) { s.error = failure.message; draw( claim.id ); } );
			} );
			block.appendChild( el( 'p', 'beltoft-dns-claim__actions' ) ).appendChild( find_button );
			if ( s.gave_up ) { block.appendChild( message( t.stillChecking ) ); }
		}

		var zone = el( 'div', 'beltoft-dns-claim__zone' );
		var label = el( 'label', '', t.zoneLabel );
		var area = el( 'textarea' );
		area.rows = 6;
		area.id = 'beltoft-dns-zone-' + claim.id;
		area.spellcheck = false;
		area.value = s.zone || '';
		area.addEventListener( 'input', function () { s.zone = area.value; } );
		label.setAttribute( 'for', area.id );
		var read = button( t.readZone );
		read.addEventListener( 'click', function () {
			s.error = '';
			busy( read, function () { return request( 'POST', '/' + claim.id + '/zone-file', { text: area.value } ); } ).then( function ( view ) {
				claims[ claims.indexOf( find( claim.id ) ) ] = view;
				draw( claim.id );
			}, function ( failure ) { s.error = failure.message; draw( claim.id ); } );
		} );
		zone.appendChild( label );
		zone.appendChild( area );
		zone.appendChild( read );
		block.appendChild( zone );

		var rows = rowsFor( claim );
		if ( null !== claim.scan || ( claim.zone_file && claim.zone_file.records ) ) {
			if ( rows.length ) {
				block.appendChild( table( claim, rows ) );
			} else {
				block.appendChild( message( t.noRecords, 'beltoft-dns-claim__note' ) );
			}
		}

		var skipped = ( claim.zone_file && claim.zone_file.skipped ) || [];
		if ( skipped.length ) {
			block.appendChild( el( 'h4', 'beltoft-dns-claim__step', t.notImported ) );
			var ul = el( 'ul', 'beltoft-dns-claim__skipped' );
			skipped.forEach( function ( line ) {
				ul.appendChild( el( 'li', '', t.line.replace( '%d', line.line ) + ': ' + line.text + ' (' + line.reason + ')' ) );
			} );
			block.appendChild( ul );
		}

		var error = errorLine( claim.id );
		if ( error ) { block.appendChild( error ); }
		if ( s.note ) { block.appendChild( message( s.note, 'beltoft-dns-claim__note' ) ); }

		var save = button( t.saveRecords );
		save.disabled = ! rows.length;
		save.addEventListener( 'click', function () {
			var chosen = rows.filter( function ( item ) { return ! s.off[ item.key ]; } ).map( function ( item ) { return item.row; } );
			s.error = '';
			s.note = '';
			busy( save, function () { return request( 'POST', '/' + claim.id + '/records', { records: chosen } ); } ).then( function ( view ) {
				s.note = t.saved.replace( '%d', chosen.length );
				claims[ claims.indexOf( find( claim.id ) ) ] = view;
				draw( claim.id );
			}, function ( failure ) { s.error = failure.message; draw( claim.id ); } );
		} );
		block.appendChild( el( 'p', 'beltoft-dns-claim__actions' ) ).appendChild( save );

		if ( true === claim.ds_present ) { block.appendChild( message( t.dnssec, 'beltoft-dns-claim__warning' ) ); }

		/* The rows ticked now are what goes into the order: save them first unless they are the saved ones. */
		function unsaved() {
			var current = find( claim.id ) || claim;
			var chosen = rows.filter( function ( item ) { return ! s.off[ item.key ]; } );
			var saved = {};
			( current.draft_records || [] ).forEach( function ( row ) { saved[ key( row ) ] = true; } );
			var same = chosen.length === Object.keys( saved ).length && chosen.every( function ( item ) { return saved[ item.key ]; } );
			return same ? null : chosen.map( function ( item ) { return item.row; } );
		}

		var go = button( t.checkout );
		go.addEventListener( 'click', function () {
			s.error = '';
			busy( go, function () {
				var changed = rows.length ? unsaved() : null;
				var first = null === changed ? Promise.resolve( null ) : request( 'POST', '/' + claim.id + '/records', { records: changed } );
				return first.then( function ( view ) {
					if ( valid( view ) && find( claim.id ) ) { claims[ claims.indexOf( find( claim.id ) ) ] = view; }
					return request( 'POST', '/' + claim.id + '/checkout' );
				} );
			} ).then( function ( answer ) {
				if ( answer && answer.checkout_url ) {
					go.disabled = true;
					window.location.assign( answer.checkout_url );
				} else {
					s.error = t.failed;
					draw( claim.id );
				}
			}, function ( failure ) { s.error = failure.message; draw( claim.id ); } );
		} );
		var last = el( 'p', 'beltoft-dns-claim__actions' );
		last.appendChild( go );
		last.appendChild( cancelLink( claim ) );
		block.appendChild( last );
	}

	function draw( id ) {
		var claim = find( id );
		if ( ! claim ) { return; }
		if ( finished( claim ) ) {
			window.clearTimeout( timers[ id ] );
			drop( id );
			return;
		}
		var block = list.querySelector( '[data-claim="' + id + '"]' );
		var fresh = ! block;
		var s = state( id );
		if ( fresh ) {
			block = el( 'section', 'beltoft-dns-claim__claim' );
			block.setAttribute( 'data-claim', id );
		}
		while ( block.firstChild ) { block.removeChild( block.firstChild ); }
		block.setAttribute( 'data-status', claim.status );
		block.appendChild( el( 'h4', 'beltoft-dns-claim__domain', claim.domain_display || claim.domain ) );

		if ( 'checking' !== claim.status ) { s.waiting = false; }
		if ( 'pending' === claim.status ) { pending( claim, block ); }
		else if ( 'checking' === claim.status ) { checking( claim, block ); }
		else if ( 'verified' === claim.status ) { verified( claim, block ); }
		else if ( 'support' === claim.status ) {
			block.appendChild( message( t.support ) );
			var trouble = errorLine( claim.id );
			if ( trouble ) { block.appendChild( trouble ); }
			block.appendChild( el( 'p', 'beltoft-dns-claim__actions' ) ).appendChild( cancelLink( claim ) );
		}

		if ( fresh ) {
			var order = claims.indexOf( claim );
			list.insertBefore( block, list.children[ order ] || null );
		}
	}

	function showError( text, node ) {
		var line = root.querySelector( '[data-dns-claim-error]' );
		while ( line.firstChild ) { line.removeChild( line.firstChild ); }
		line.hidden = ! text && ! node;
		if ( text ) { line.textContent = text; }
		if ( node ) { line.appendChild( node ); }
	}

	/* The card of a domain the customer has: the licenses list anchors a card by its license. */
	function cardLink( resourceId ) {
		var panel = document.querySelector( '[data-resource="' + resourceId + '"]' );
		var card = panel ? panel.closest( 'section[aria-labelledby]' ) : null;
		if ( ! card ) { return null; }
		var link = el( 'a', '', t.showIt );
		link.href = '#' + card.getAttribute( 'aria-labelledby' );
		return link;
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		var input = form.elements.domain;
		var submit = form.querySelector( 'button' );
		var value = input.value.trim();
		if ( '' === value ) { return; }
		showError( '' );
		var label = submit.textContent;
		submit.disabled = true;
		submit.textContent = t.adding;
		function restore() {
			submit.disabled = false;
			submit.textContent = label;
		}
		request( 'POST', '', { domain: value } ).then( function ( claim ) {
			if ( ! valid( claim ) ) { throw new Error( t.failed ); }
			input.value = '';
			put( claim );
		} ).catch( function ( error ) {
			error = error || {};
			var id = error.data && error.data.data ? error.data.data.resource_id : 0;
			if ( 'already_yours' === ( error.data && error.data.code ) ) {
				var link = id ? cardLink( id ) : null;
				showError( t.alreadyYours + ( link ? ' ' : '' ), link );
			} else {
				showError( error.message || t.failed );
			}
		} ).then( restore, restore );
	} );

	request( 'GET', '' ).then( function ( all ) {
		if ( ! Array.isArray( all ) || ! all.every( valid ) ) { throw new Error( t.failed ); }
		claims = all.filter( function ( claim ) { return ! finished( claim ); } );
		claims.sort( function ( a, b ) { return b.id - a.id; } );
		claims.slice().forEach( function ( claim ) { draw( claim.id ); } );
	} ).catch( function ( error ) { showError( error && error.message ? error.message : t.failed ); } );
}() );
