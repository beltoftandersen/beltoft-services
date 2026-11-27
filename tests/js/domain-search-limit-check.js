/**
 * Node check of assets/domain-search.js (no browser): a search the server refuses with 429 shows the limit line, disables the
 * button with a countdown ("Try again in 0:42"), ignores a search meanwhile, and frees the button when the time is up.
 * Run by tests/Domains/SearchLimitScriptTest.php:  node domain-search-limit-check.js <plugin dir>
 * Prints "DOMAIN SEARCH LIMIT OK" when every check passed, else lines that start with FAIL.
 */
const vm = require( 'vm' );
const fs = require( 'fs' );
const plugin = process.argv[ 2 ].replace( /\/?$/, '/' );

function node( tag ) {
	const n = {
		tag, className: '', textContent: '', hidden: false, disabled: false, dataset: {}, children: [], attrs: {}, listeners: {}, value: '', placeholder: '',
		classList: { toggle() {}, remove() {} },
		setAttribute( k, v ) { n.attrs[ k ] = v; }, removeAttribute( k ) { delete n.attrs[ k ]; },
		appendChild( c ) { n.children.push( c ); return c; }, prepend( c ) { n.children.unshift( c ); },
		replaceChildren( ...c ) { n.children = c; }, insertBefore( c ) { n.children.push( c ); return c; },
		after( c ) { n.sibling = c; }, remove() {}, replaceWith() {}, focus() {}, scrollIntoView() {},
		addEventListener( t, fn ) { n.listeners[ t ] = fn; },
		querySelector: () => null, querySelectorAll: () => [],
		get firstChild() { return n.children[ 0 ] || null; },
	};
	return n;
}

( async function () {
	const fails = [];
	const input = node( 'input' ); input.value = 'example.com';
	const button = node( 'button' ); button.textContent = 'Search';
	const results = node( 'div' );
	const root = node( 'div' );
	root.dataset = { check: '/check', bdsReady: '' };
	root.querySelector = ( sel ) => ( /input/.test( sel ) ? input : /results/.test( sel ) ? results : /:scope > button/.test( sel ) ? button : null );
	let line;
	const origAfter = button.after;
	button.after = ( c ) => { line = c; origAfter( c ); };
	let now = 1000000;
	const intervals = [];
	let fetches = 0;
	const window = {
		location: { href: 'https://x.test/domains/', pathname: '/domains/', search: '' },
		history: { replaceState() {}, state: null },
		sessionStorage: { getItem: () => null, setItem() {} },
		matchMedia: () => ( { matches: true } ),
		setInterval: ( fn ) => { intervals.push( fn ); return intervals.length; },
		clearInterval: ( id ) => { intervals[ id - 1 ] = null; },
		addEventListener() {},
	};
	window.wp = { i18n: { __: ( s ) => s, sprintf: ( f, ...a ) => f.replace( '%s', a[ 0 ] ) } };
	const document = {
		createElement: node,
		addEventListener() {},
		querySelectorAll: ( sel ) => ( /beltoft-domain-search/.test( sel ) && ! /transfer/.test( sel ) ? [ root ] : [] ),
		querySelector: () => null,
	};
	const FakeDate = { now: () => now };
	const fetch = async () => {
		fetches++;
		return { ok: false, status: 429, headers: { get: () => '42' }, json: async () => ( { code: 'rate_limited', message: 'Too many searches. Please wait a minute.', retry_after: 42, data: { status: 429, retry_after: 42 } } ) };
	};
	vm.runInNewContext( fs.readFileSync( plugin + 'assets/domain-search.js', 'utf8' ), {
		window, document, wp: window.wp, fetch, Date: FakeDate, URL, URLSearchParams, JSON, Array, Math, String, parseInt, Promise, Error, console,
	} );
	await button.listeners.click();
	if ( 3 !== fetches ) { fails.push( 'FAIL expected three lookups, got ' + fetches ); }
	if ( ! button.disabled ) { fails.push( 'FAIL the button is not disabled during the wait' ); }
	if ( 'Try again in 0:42' !== button.textContent ) { fails.push( 'FAIL button text: ' + button.textContent ); }
	if ( ! line || line.hidden || ! /searched many times in a short while/.test( line.textContent ) ) { fails.push( 'FAIL the limit line is not shown' ); }
	if ( results.children.some( ( c ) => /beltoft-domain-error/.test( c.className ) ) ) { fails.push( 'FAIL the plain error is shown as well' ); }
	now += 10000;
	intervals.filter( Boolean ).forEach( ( fn ) => fn() );
	if ( 'Try again in 0:32' !== button.textContent ) { fails.push( 'FAIL countdown text: ' + button.textContent ); }
	await button.listeners.click();
	if ( 3 !== fetches ) { fails.push( 'FAIL a search ran during the wait' ); }
	now += 33000;
	intervals.filter( Boolean ).forEach( ( fn ) => fn() );
	if ( button.disabled || 'Search' !== button.textContent || ! line.hidden ) { fails.push( 'FAIL the button is not normal after the wait: ' + button.textContent ); }
	console.log( fails.length ? fails.join( '\n' ) : 'DOMAIN SEARCH LIMIT OK' );
}() ).catch( ( e ) => { console.log( 'FAIL ' + e.stack ); } );
