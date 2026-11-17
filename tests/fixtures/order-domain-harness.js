/* Runs assets/order-domain.js against small stubs of the browser and prints one JSON line of results.
 * Usage: node order-domain-harness.js <script> <scenario> */
'use strict';
const fs = require( 'fs' );
const vm = require( 'vm' );

const file = process.argv[ 2 ];
const scenario = process.argv[ 3 ];
let source = fs.readFileSync( file, 'utf8' );
const at = source.lastIndexOf( '}() );' );

source = source.slice( 0, at ) + ';window.__od = { addConfirmed: addConfirmed };' + source.slice( at );

function node( attrs ) {
	return {
		attrs: attrs || {},
		children: [],
		className: '',
		textContent: '',
		setAttribute( k, v ) { this.attrs[ k ] = v; },
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		appendChild( c ) { this.children.push( c ); return c; },
		remove() { this.removed = true; }
	};
}

const handlers = {};
const events = { added: 0, requests: [] };
const body = { __n: 'body' };
let marker = node( { 'data-od-phone': '1', 'data-od-domains': '' } );
let phoneRow = null;
let line = node();
let note = null;
const log = { reloads: 0, assigns: [], notes: 0 };

function jq( target ) {
	return {
		length: 1,
		on( name, fn ) { ( handlers[ name ] = handlers[ name ] || [] ).push( fn ); return this; },
		off( name, fn ) { handlers[ name ] = ( handlers[ name ] || [] ).filter( ( f ) => f !== fn ); return this; },
		trigger( name ) {
			if ( 'update_checkout' === name ) {
				// The checkout answers a refresh with updated_checkout, unless the scenario says it is too slow.
				if ( answering ) { setTimeout( () => jq().trigger( 'updated_checkout' ), 5 ); }
				return;
			}
			( handlers[ name ] || [] ).slice().forEach( ( f ) => f() );
		}
	};
}

const store = {};
let answering = false;
const throwing = 'storage-throws' === scenario || 'od-param' === scenario;
const href = 'od-param' === scenario ? 'https://shop.test/checkout/?od_reload=1' : 'https://shop.test/checkout/';
const win = {
	beltoftServicesOrderDomain: { text: { notInOrder: 'NOT IN ORDER', phoneMissing: 'PHONE MISSING', addFailed: 'x' }, cart: '/cart', nonce: 'n', restNonce: 'r' },
	jQuery: jq,
	sessionStorage: {
		getItem( k ) { if ( throwing ) { throw new Error( 'no' ); } return k in store ? store[ k ] : null; },
		setItem( k, v ) { if ( throwing ) { throw new Error( 'no' ); } store[ k ] = v; },
		removeItem( k ) { if ( throwing ) { throw new Error( 'no' ); } delete store[ k ]; }
	},
	location: { href: href, reload() { log.reloads++; }, assign( u ) { log.assigns.push( u ); } },
	fetch() { events.requests.push( 1 ); return Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } ); },
	setTimeout: ( fn, ms ) => {
		if ( 30000 === ms && 'slow-third' === scenario ) { marker.attrs[ 'data-od-domains' ] = 'a.com'; answering = true; }
		return setTimeout( fn, 30000 === ms ? 200 : Math.min( ms, 20 ) );
	},
	clearTimeout: clearTimeout,
	URL: URL
};
const doc = {
	body: body,
	querySelector( sel ) {
		if ( sel === '.beltoft-services-od-phone' ) { return marker; }
		if ( sel === '[data-od-phone-note]' ) { return note; }
		if ( sel === '[data-od-line]' ) { return line; }
		return null;
	},
	querySelectorAll() { return []; },
	getElementById( id ) { return 'billing_phone' === id ? phoneRow : null; },
	createElement() { const n = node(); n.setAttribute = function ( k, v ) { this.attrs[ k ] = v; if ( 'data-od-phone-note' === k ) { note = this; } }; return n; },
	addEventListener() {}
};
line.appendChild = function ( c ) { this.children.push( c ); log.notes++; return c; };
win.document = doc;
win.window = win;
const context = vm.createContext( Object.assign( { document: doc, window: win, URL: URL, Promise: Promise, Event: function () {}, console: console }, {} ) );
win.window = win;
context.window = win;
Object.keys( win ).forEach( ( k ) => { context[ k ] = win[ k ]; } );
vm.runInContext( source, context );

const refresh = () => jq().trigger( 'updated_checkout' );

async function main() {
	if ( 'phone' === scenario || 'storage-throws' === scenario || 'od-param' === scenario ) {
		marker.attrs[ 'data-od-domains' ] = 'a.com';
		refresh();
		refresh();
		refresh();
		console.log( JSON.stringify( { reloads: log.reloads, assigns: log.assigns, notes: log.notes } ) );
	} else if ( 'phone-new-cart' === scenario ) {
		marker.attrs[ 'data-od-domains' ] = 'a.com';
		refresh();
		marker.attrs[ 'data-od-domains' ] = 'b.com';
		refresh();
		refresh();
		console.log( JSON.stringify( { reloads: log.reloads, notes: log.notes } ) );
	} else if ( 'slow' === scenario || 'slow-never' === scenario ) {
		// The refresh answers late: the marker lists the domain only after the add gave up.
		let adds = 0;
		win.fetch = () => { adds++; return Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } ); };
		context.fetch = win.fetch;
		let late = 0;
		const result = await win.__od.addConfirmed( 'a.com', 'register', '', () => { late++; } ).then( () => 'ok', ( e ) => e.message );
		if ( 'slow' === scenario ) {
			marker.attrs[ 'data-od-domains' ] = 'a.com';
			refresh();
		}
		console.log( JSON.stringify( { result: result, adds: adds, late: late } ) );
	} else if ( 'slow-third' === scenario ) {
		// The third read (after two adds) shows the domain: no error.
		let adds = 0;
		win.fetch = () => { adds++; return Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } ); };
		context.fetch = win.fetch;
		const result = await win.__od.addConfirmed( 'a.com', 'register', '', () => {} ).then( () => 'ok', ( e ) => e.message );
		console.log( JSON.stringify( { result: result, adds: adds } ) );
	}
}
main().then( () => process.exit( 0 ) );
