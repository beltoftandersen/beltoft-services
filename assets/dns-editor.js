/*
 * The DNS records editor of a domain page. One row is one request: the server changes one name and type (an RRset)
 * and sends that set back, so the table never holds a record the zone does not. Routes: GET resources/<id>/dns-records,
 * PUT and DELETE resources/<id>/dns-records/<type>/<name>.
 */
( function () {
	'use strict';
	var config = window.beltoftServicesAccount;
	if ( ! config ) { return; }

	var TYPES = [ 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA' ];
	var EXTRAS = { MX: [ 'priority' ], SRV: [ 'priority', 'weight', 'port' ], CAA: [ 'flag', 'tag' ] };
	var TAGS = [ 'issue', 'issuewild', 'iodef' ];
	var TTLS = [ 60, 300, 3600, 14400, 86400 ];
	var SHOWN = 10;
	var counter = 0;

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( null === value || undefined === value || false === value ) { return; }
			if ( 'text' === key ) { node.textContent = value; } else if ( true === value ) { node.setAttribute( key, '' ); } else { node.setAttribute( key, value ); }
		} );
		( children || [] ).forEach( function ( child ) { if ( child ) { node.appendChild( child ); } } );
		return node;
	}

	function fmt( text, a, b ) {
		return String( text || '' ).replace( '%1$s', a ).replace( '%2$s', b ).replace( '%d', a ).replace( '%s', a );
	}

	function request( method, path, body ) {
		var options = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' } };
		if ( body ) { options.body = JSON.stringify( body ); }
		return window.fetch( config.root + path, options ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );
			if ( nonce ) { config.nonce = nonce; }
			return response.json().catch( function () { return {}; } ).then( function ( data ) { return { ok: response.ok, status: response.status, data: data || {} }; } );
		} );
	}

	function Editor( root ) {
		this.root = root;
		this.t = JSON.parse( root.getAttribute( 'data-i18n' ) || '{}' );
		this.zone = ( root.getAttribute( 'data-zone' ) || '' ).toLowerCase();
		this.resource = root.getAttribute( 'data-resource' );
		this.readonly = root.hasAttribute( 'data-readonly' );
		this.rows = [];
		this.editing = null; // a row id, or 'new'
		this.confirm = null; // a row id
		this.rowError = null; // { id, message }
		this.busy = false;
		this.all = false;
		this.query = '';
		this.focusAfter = null;
		this.draft = null; // what the customer typed in the row being edited, kept when a save fails
		this.setRows( JSON.parse( root.getAttribute( 'data-rows' ) || '[]' ) );
		this.build();
		this.render();
	}

	Editor.prototype.norm = function ( name ) {
		var n = String( name || '' ).trim().toLowerCase().replace( /\.$/, '' );
		if ( '' === n || n === this.zone ) { return '@'; }
		if ( this.zone && n.slice( -( this.zone.length + 1 ) ) === '.' + this.zone ) { return n.slice( 0, -( this.zone.length + 1 ) ); }
		return n;
	};

	Editor.prototype.key = function ( row ) {
		return this.norm( row.name ) + '|' + String( row.type ).toUpperCase();
	};

	Editor.prototype.setRows = function ( list ) {
		var self = this;
		this.rows = list.map( function ( row ) {
			var copy = {};
			Object.keys( row ).forEach( function ( k ) { copy[ k ] = row[ k ]; } );
			copy.id = ++counter;
			copy.name = self.norm( copy.name );
			return copy;
		} );
		this.sort();
	};

	Editor.prototype.sort = function () {
		var self = this;
		this.rows = this.rows.map( function ( row, i ) { return { row: row, i: i }; } ).sort( function ( a, b ) {
			var an = self.norm( a.row.name ), bn = self.norm( b.row.name );
			if ( an !== bn ) { if ( '@' === an ) { return -1; } if ( '@' === bn ) { return 1; } return an < bn ? -1 : 1; }
			if ( a.row.type !== b.row.type ) { return a.row.type < b.row.type ? -1 : 1; }
			return a.i - b.i;
		} ).map( function ( item ) { return item.row; } );
	};

	Editor.prototype.set = function ( key ) {
		var self = this;
		return this.rows.filter( function ( row ) { return self.key( row ) === key; } );
	};

	Editor.prototype.find = function ( id ) {
		for ( var i = 0; i < this.rows.length; i++ ) { if ( this.rows[ i ].id === id ) { return this.rows[ i ]; } }
		return null;
	};

	// A record as the routes take it. withTtl is for the rows the customer saw (the route compares them).
	Editor.prototype.payload = function ( row, withTtl ) {
		var out = { value: String( row.value ) };
		( EXTRAS[ row.type ] || [] ).forEach( function ( key ) { out[ key ] = 'tag' === key ? String( row.tag ) : parseInt( row[ key ], 10 ); } );
		if ( withTtl ) { out.ttl = parseInt( row.ttl, 10 ) || 3600; }
		return out;
	};

	Editor.prototype.ttlLabel = function ( ttl ) {
		return this.t[ 'ttl_' + ttl ] || fmt( this.t.ttl_other, ttl );
	};

	Editor.prototype.display = function ( row ) {
		var parts = [];
		if ( 'MX' === row.type ) { parts.push( row.priority ); }
		if ( 'SRV' === row.type ) { parts.push( row.priority, row.weight, row.port ); }
		if ( 'CAA' === row.type ) { parts.push( row.flag, row.tag ); }
		parts.push( 'CAA' === row.type ? '"' + row.value + '"' : row.value );
		return parts.join( ' ' );
	};

	Editor.prototype.build = function () {
		var self = this;
		this.error = this.root.querySelector( '.beltoft-dnse__error' ) || this.root.appendChild( el( 'p', { 'class': 'beltoft-dnse__error', role: 'alert', hidden: true } ) );
		this.status = el( 'p', { 'class': 'beltoft-dnse__status', role: 'status' } );
		this.bar = el( 'div', { 'class': 'beltoft-dnse__bar' } );
		this.holder = el( 'div', { 'class': 'beltoft-dnse__holder' } );
		this.root.appendChild( this.bar );
		this.root.appendChild( this.status );
		this.root.appendChild( this.holder );

		this.search = el( 'input', { type: 'search', 'class': 'beltoft-dnse__search', 'aria-label': this.t.search, placeholder: this.t.search, autocomplete: 'off' } );
		this.search.addEventListener( 'input', function () { self.query = self.search.value.trim().toLowerCase(); self.render(); } );
		this.add = el( 'button', { type: 'button', 'class': 'beltoft-dnse__add button', text: this.t.add } );
		this.add.addEventListener( 'click', function () { self.begin( 'new' ); } );
		this.bar.appendChild( this.search );
		if ( ! this.readonly ) { this.bar.appendChild( this.add ); }

		// Escape closes the open row wherever the focus is (a re-draw can leave it on the page).
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! event.defaultPrevented && ( null !== self.editing || null !== self.confirm ) && ! self.busy ) {
				var id = null !== self.editing ? self.editing : self.confirm;
				self.editing = null;
				self.confirm = null;
				self.draft = null;
				self.rowError = null;
				self.focusAfter = { id: id, role: 'edit' };
				self.render();
			}
		} );
	};

	Editor.prototype.say = function ( text ) {
		this.status.textContent = text || '';
	};

	Editor.prototype.fail = function ( message ) {
		this.error.textContent = message || this.t.network;
		this.error.hidden = false;
	};

	Editor.prototype.begin = function ( id ) {
		if ( this.busy || this.readonly ) { return; }
		this.editing = id;
		this.confirm = null;
		this.rowError = null;
		this.draft = null;
		this.error.hidden = true;
		this.say( '' );
		this.render();
		var first = this.holder.querySelector( '[data-edit-row] [data-f="name"]' );
		if ( first ) { first.focus(); }
	};

	Editor.prototype.visible = function ( row ) {
		if ( row.id === this.editing || row.id === this.confirm ) { return true; }
		if ( '' !== this.query ) {
			var text = [ row.name, row.type, row.value, row.priority, row.tag ].join( ' ' ).toLowerCase();
			return -1 !== text.indexOf( this.query );
		}
		return true;
	};

	Editor.prototype.render = function () {
		var self = this;
		var rows = this.rows;
		var anyOff = rows.some( function ( row ) { return row.disabled; } );
		var shown = rows.filter( function ( row ) { return self.visible( row ); } );
		var limited = '' === this.query && ! this.all && shown.length > SHOWN;

		if ( limited ) {
			var count = 0;
			shown = shown.filter( function ( row ) { if ( row.id === self.editing || row.id === self.confirm ) { return true; } count++; return count <= SHOWN; } );
		}

		this.search.hidden = rows.length <= SHOWN && '' === this.query;
		this.add.disabled = this.busy;
		this.holder.textContent = '';
		this.forms = [];

		if ( ! rows.length && 'new' !== this.editing ) {
			this.holder.appendChild( el( 'p', { 'class': 'beltoft-dnse__empty', text: this.t.empty } ) );
			this.finishRender();
			return;
		}

		var head = [ this.t.name, this.t.type, this.t.ttl, this.t.value ].map( function ( label ) { return el( 'th', { scope: 'col', text: label } ); } );
		if ( anyOff ) { head.push( el( 'th', { scope: 'col', text: this.t.status } ) ); }
		head.push( el( 'th', { scope: 'col' }, [ el( 'span', { 'class': 'screen-reader-text', text: this.t.actions } ) ] ) );
		var body = el( 'tbody' );
		var table = el( 'table', { 'class': 'beltoft-dnse__table' }, [
			el( 'caption', { 'class': 'screen-reader-text', text: this.t.caption } ),
			el( 'thead', {}, [ el( 'tr', {}, head ) ] ),
			body
		] );

		if ( 'new' === this.editing ) { this.editRow( body, { id: 'new', name: '', type: 'A', ttl: 3600, value: '' }, anyOff ); }

		shown.forEach( function ( row ) {
			if ( row.id === self.editing ) { self.editRow( body, row, anyOff ); } else { self.readRow( body, row, anyOff ); }
			if ( self.rowError && self.rowError.id === row.id ) { self.errorRow( body, anyOff ); }
		} );

		if ( this.rowError && 'new' === this.rowError.id ) { this.errorRow( body, anyOff, true ); }

		this.holder.appendChild( table );
		this.forms.forEach( function ( form ) { self.holder.appendChild( form ); } );

		if ( ! shown.length ) { this.holder.appendChild( el( 'p', { 'class': 'beltoft-dnse__empty', text: this.t.no_match } ) ); }

		if ( '' === this.query && rows.length > SHOWN ) {
			var more = el( 'button', { type: 'button', 'class': 'beltoft-services-domain__link beltoft-dnse__more', text: this.all ? this.t.show_fewer : fmt( this.t.show_all, rows.length ) } );
			more.addEventListener( 'click', function () { self.all = ! self.all; self.render(); } );
			this.holder.appendChild( more );
		}

		this.finishRender();
	};

	// After an error the row stays open: the focus goes to its value, so the alert is read with the field.
	Editor.prototype.focusField = function () {
		var field = this.holder.querySelector( '[data-edit-row] [data-f="value"]' );
		if ( field ) { field.focus(); }
	};

	Editor.prototype.finishRender = function () {
		var target = this.focusAfter;
		this.focusAfter = null;
		if ( ! target ) { return; }
		var node = null;
		if ( 'add' === target.role ) { node = this.add; } else if ( target.id ) { node = this.holder.querySelector( '[data-row="' + target.id + '"] [data-act="' + target.role + '"]' ); }
		if ( ! node ) { node = this.readonly ? this.search : this.add; }
		if ( node && ! node.hidden ) { node.focus(); }
	};

	Editor.prototype.errorRow = function ( body, anyOff, top ) {
		var cols = 5 + ( anyOff ? 1 : 0 );
		var tr = el( 'tr', { 'class': 'beltoft-dnse__rowerror' }, [ el( 'td', { colspan: String( cols ), role: 'alert', text: this.rowError.message } ) ] );
		if ( top && body.firstChild ) { body.insertBefore( tr, body.firstChild.nextSibling ); } else { body.appendChild( tr ); }
	};

	Editor.prototype.cell = function ( tr, label, content ) {
		var td = el( 'td', { 'data-label': label } );
		if ( 'string' === typeof content ) { td.textContent = content; } else if ( content ) { td.appendChild( content ); }
		tr.appendChild( td );
		return td;
	};

	Editor.prototype.readRow = function ( body, row, anyOff ) {
		var self = this;
		var tr = el( 'tr', { 'data-row': String( row.id ) } );
		var locked = this.readonly || row.disabled;
		this.cell( tr, this.t.name, row.name );
		this.cell( tr, this.t.type, row.type );
		this.cell( tr, this.t.ttl, this.ttlLabel( row.ttl ) );
		this.cell( tr, this.t.value, this.display( row ) ).className = 'beltoft-dnse__value';
		if ( anyOff ) { this.cell( tr, this.t.status, row.disabled ? this.t.off : '' ); }
		var actions = el( 'td', { 'class': 'beltoft-dnse__actions' } );

		if ( ! locked && row.id === this.confirm ) {
			actions.appendChild( el( 'span', { 'class': 'beltoft-dnse__ask', text: this.t.delete_q } ) );
			var yes = el( 'button', { type: 'button', 'class': 'beltoft-services-domain__link beltoft-dnse__danger', 'data-act': 'delete-yes', text: this.t.delete, disabled: this.busy } );
			var no = el( 'button', { type: 'button', 'class': 'beltoft-services-domain__link', 'data-act': 'delete-no', text: this.t.keep, disabled: this.busy } );
			yes.addEventListener( 'click', function () { self.remove( row ); } );
			no.addEventListener( 'click', function () { self.confirm = null; self.focusAfter = { id: row.id, role: 'delete' }; self.render(); } );
			actions.appendChild( yes );
			actions.appendChild( no );
		} else if ( ! locked ) {
			var edit = el( 'button', { type: 'button', 'class': 'beltoft-services-domain__link', 'data-act': 'edit', 'aria-label': fmt( this.t.edit_label, row.type, row.name ), text: this.t.edit, disabled: this.busy } );
			var del = el( 'button', { type: 'button', 'class': 'beltoft-services-domain__link', 'data-act': 'delete', 'aria-label': fmt( this.t.delete_label, row.type, row.name ), text: this.t.delete, disabled: this.busy } );
			edit.addEventListener( 'click', function () { self.begin( row.id ); } );
			del.addEventListener( 'click', function () {
				if ( self.busy ) { return; }
				self.confirm = row.id;
				self.editing = null;
				self.rowError = null;
				self.render();
				var keep = self.holder.querySelector( '[data-row="' + row.id + '"] [data-act="delete-no"]' );
				if ( keep ) { keep.focus(); }
			} );
			actions.appendChild( edit );
			actions.appendChild( del );
		}

		tr.appendChild( actions );
		body.appendChild( tr );
	};

	Editor.prototype.input = function ( formId, name, value, label, type ) {
		var input = el( 'input', { type: type || 'text', 'data-f': name, 'aria-label': label, placeholder: label, autocomplete: 'off', spellcheck: 'false', form: formId } );
		input.value = undefined === value || null === value ? '' : value;
		if ( 'number' === type ) { input.min = '0'; input.inputMode = 'numeric'; }
		return input;
	};

	Editor.prototype.select = function ( formId, name, values, current, label, text ) {
		var select = el( 'select', { 'data-f': name, 'aria-label': label, form: formId } );
		values.forEach( function ( value ) { select.appendChild( el( 'option', { value: String( value ), text: text ? text( value ) : String( value ) } ) ); } );
		select.value = String( current );
		return select;
	};

	Editor.prototype.editRow = function ( body, row, anyOff ) {
		var self = this;
		var isNew = 'new' === row.id;
		if ( this.draft && this.draft.id === row.id ) {
			var typed = {};
			Object.keys( row ).forEach( function ( k ) { typed[ k ] = row[ k ]; } );
			Object.keys( this.draft.values ).forEach( function ( k ) { typed[ k ] = self.draft.values[ k ]; } );
			row = typed;
		}
		var formId = 'beltoft-dnse-form-' + counter + '-' + ( isNew ? 'new' : row.id );
		var form = el( 'form', { id: formId, 'class': 'beltoft-dnse__form', novalidate: true } );
		var tr = el( 'tr', { 'data-row': String( row.id ), 'data-edit-row': '1' } );
		var siblings = isNew ? [] : this.set( this.key( row ) );
		var fixed = siblings.length > 1; // a set of several records keeps its name and type: they belong to the whole set
		var ttls = TTLS.slice();
		if ( -1 === ttls.indexOf( parseInt( row.ttl, 10 ) ) ) { ttls.push( parseInt( row.ttl, 10 ) ); ttls.sort( function ( a, b ) { return a - b; } ); }

		var name = this.input( formId, 'name', row.name, this.t.name );
		name.title = this.t.name_hint;
		var type = this.select( formId, 'type', TYPES, row.type || 'A', this.t.type );
		var ttl = this.select( formId, 'ttl', ttls, parseInt( row.ttl, 10 ) || 3600, this.t.ttl, function ( v ) { return self.ttlLabel( v ); } );
		if ( fixed ) {
			name.disabled = true;
			type.disabled = true;
			name.title = type.title = this.t.set_hint;
		}

		var box = el( 'div', { 'class': 'beltoft-dnse__fields' } );
		[ 'priority', 'weight', 'port', 'flag' ].forEach( function ( key ) {
			var input = self.input( formId, key, row[ key ], self.t[ key ], 'number' );
			input.className = 'beltoft-dnse__extra';
			box.appendChild( input );
		} );
		var tag = this.select( formId, 'tag', TAGS, row.tag || 'issue', this.t.tag );
		tag.className = 'beltoft-dnse__extra';
		box.appendChild( tag );
		var value = this.input( formId, 'value', row.value, this.t.value );
		box.appendChild( value );

		function extras() {
			var wanted = EXTRAS[ type.value ] || [];
			Array.prototype.forEach.call( box.querySelectorAll( '.beltoft-dnse__extra' ), function ( node ) {
				node.hidden = -1 === wanted.indexOf( node.getAttribute( 'data-f' ) );
			} );
		}
		type.addEventListener( 'change', extras );
		extras();

		this.cell( tr, this.t.name, name );
		this.cell( tr, this.t.type, type );
		this.cell( tr, this.t.ttl, ttl );
		this.cell( tr, this.t.value, box );
		if ( anyOff ) { this.cell( tr, this.t.status, '' ); }

		var actions = el( 'td', { 'class': 'beltoft-dnse__actions' } );
		var save = el( 'button', { type: 'submit', form: formId, 'class': 'beltoft-dnse__save button', text: this.busy ? this.t.saving : this.t.save, disabled: this.busy } );
		var cancel = el( 'button', { type: 'button', 'class': 'beltoft-services-domain__link', text: this.t.cancel, disabled: this.busy } );
		cancel.addEventListener( 'click', function () {
			self.editing = null;
			self.draft = null;
			self.rowError = null;
			self.focusAfter = isNew ? { role: 'add' } : { id: row.id, role: 'edit' };
			self.render();
		} );
		actions.appendChild( save );
		actions.appendChild( cancel );
		tr.appendChild( actions );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.save( row, tr );
		} );
		this.forms.push( form );
		body.appendChild( tr );
	};

	// What the customer typed in a row, or a message when it cannot be sent.
	Editor.prototype.read = function ( tr ) {
		var get = function ( key ) { var node = tr.querySelector( '[data-f="' + key + '"]' ); return node ? node.value.trim() : ''; };
		var out = { name: get( 'name' ), type: get( 'type' ), ttl: parseInt( get( 'ttl' ), 10 ) || 3600, value: get( 'value' ) };
		var error = '';
		if ( '' === out.value ) { error = this.t.need_value; }
		( EXTRAS[ out.type ] || [] ).forEach( function ( key ) {
			if ( 'tag' === key ) { out.tag = get( key ); return; }
			if ( ! /^\d+$/.test( get( key ) ) ) { error = error || this.t.need_number; return; }
			out[ key ] = parseInt( get( key ), 10 );
		}, this );
		out.name = this.norm( out.name );
		return { row: out, error: error };
	};

	Editor.prototype.save = function ( original, tr ) {
		var self = this;
		if ( this.busy ) { return; }
		var typed = this.read( tr );
		var isNew = 'new' === original.id;
		var values = {};
		Array.prototype.forEach.call( tr.querySelectorAll( '[data-f]' ), function ( node ) { values[ node.getAttribute( 'data-f' ) ] = node.value; } );
		this.draft = { id: original.id, values: values };

		if ( typed.error ) {
			this.rowError = { id: original.id, message: typed.error };
			this.render();
			this.focusField();
			return;
		}

		var next = typed.row;
		var newKey = this.key( next );
		var oldKey = isNew ? null : this.key( original );
		var ttl = next.ttl;
		var path;
		var body;

		if ( isNew || oldKey === newKey ) {
			// Add to the set at this name and type, or change one record in it. The route checks that the set is still what we saw.
			var seen = this.set( newKey );
			var records = [];
			var placed = false;
			seen.forEach( function ( row ) {
				if ( ! isNew && row.id === original.id ) { records.push( self.payload( next, false ) ); placed = true; } else { records.push( self.payload( row, false ) ); }
			} );
			if ( ! placed ) { records.push( this.payload( next, false ) ); }
			path = encodeURIComponent( next.type ) + '/' + encodeURIComponent( next.name );
			body = { ttl: ttl, records: records, expect: seen.map( function ( row ) { return self.payload( row, true ); } ) };
		} else {
			// A new name or type moves the set (it has the one record): one request changes both sets.
			path = encodeURIComponent( original.type ) + '/' + encodeURIComponent( original.name );
			body = { ttl: ttl, records: [ this.payload( next, false ) ], expect: this.set( oldKey ).map( function ( row ) { return self.payload( row, true ); } ), new_name: next.name, new_type: next.type };
		}

		this.send( 'PUT', path, body, original.id, function ( rows ) {
			self.rows = self.rows.filter( function ( row ) { return self.key( row ) !== newKey && ( null === oldKey || self.key( row ) !== oldKey ); } );
			self.rows = self.rows.concat( self.adopt( rows ) );
			self.sort();
			self.editing = null;
			self.draft = null;
			self.say( self.t.saved );
			var landed = self.set( newKey )[ 0 ];
			self.focusAfter = landed ? { id: landed.id, role: 'edit' } : { role: 'add' };
		} );
	};

	Editor.prototype.remove = function ( row ) {
		var self = this;
		if ( this.busy ) { return; }
		var key = this.key( row );
		var set = this.set( key );
		var path = encodeURIComponent( row.type ) + '/' + encodeURIComponent( row.name );
		var call;

		if ( set.length > 1 ) {
			var rest = set.filter( function ( item ) { return item.id !== row.id; } ).map( function ( item ) { return self.payload( item, false ); } );
			call = [ 'PUT', path, { ttl: parseInt( row.ttl, 10 ) || 3600, records: rest, expect: set.map( function ( item ) { return self.payload( item, true ); } ) } ];
		} else {
			call = [ 'DELETE', path, null ];
		}

		this.send( call[ 0 ], call[ 1 ], call[ 2 ], row.id, function ( rows ) {
			self.rows = self.rows.filter( function ( item ) { return self.key( item ) !== key; } ).concat( self.adopt( rows ) );
			self.sort();
			self.confirm = null;
			self.say( self.t.deleted );
			self.focusAfter = { role: 'add' };
		} );
	};

	Editor.prototype.adopt = function ( rows ) {
		var self = this;
		return ( rows || [] ).map( function ( row ) {
			var copy = {};
			Object.keys( row ).forEach( function ( k ) { copy[ k ] = row[ k ]; } );
			copy.id = ++counter;
			copy.name = self.norm( copy.name );
			return copy;
		} );
	};

	Editor.prototype.send = function ( method, path, body, rowId, done ) {
		var self = this;
		this.busy = true;
		this.rowError = null;
		this.error.hidden = true;
		this.say( this.t.saving );
		var pending = this.holder.querySelector( '[data-edit-row] .beltoft-dnse__save' );
		if ( pending ) { pending.disabled = true; pending.textContent = this.t.saving; }
		Array.prototype.forEach.call( this.holder.querySelectorAll( 'button' ), function ( b ) { b.disabled = true; } );

		request( method, this.resource + '/dns-records/' + path, body ).then( function ( result ) {
			self.busy = false;
			if ( result.ok ) {
				done( ( result.data || {} ).rows );
				self.render();
				return;
			}
			self.say( '' );
			var code = result.data.code;
			var message = result.data.message || self.t.network;
			if ( 'paused' === code ) {
				self.readonly = true;
				self.editing = null;
				self.confirm = null;
				self.add.hidden = true;
				self.fail( message );
				self.render();
			} else if ( 'stale' === code ) {
				// Someone changed the record since the table was drawn: show what is there now.
				self.editing = null;
				self.confirm = null;
				self.draft = null;
				self.reload( message );
			} else {
				self.rowError = { id: rowId, message: message };
				self.render();
				self.focusField();
			}
		} ).catch( function () {
			self.busy = false;
			self.say( '' );
			self.rowError = { id: rowId, message: self.t.network };
			self.render();
			self.focusField();
		} );
	};

	Editor.prototype.reload = function ( message ) {
		var self = this;
		request( 'GET', this.resource + '/dns-records' ).then( function ( result ) {
			if ( result.ok && Array.isArray( result.data.rows ) ) { self.setRows( result.data.rows ); }
			self.fail( message );
			self.render();
		} ).catch( function () { self.fail( message ); self.render(); } );
	};

	Array.prototype.forEach.call( document.querySelectorAll( '[data-dns-editor]' ), function ( root ) { new Editor( root ); } );
}() );
