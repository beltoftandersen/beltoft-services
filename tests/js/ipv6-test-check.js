/**
 * Node check of assets/ipv6-test.js (no browser): the decision and the notice. Run by tests/Proxmox/Ipv4/Ipv6TestScriptTest.php:
 *   node ipv6-test-check.js <plugin dir>
 * Prints "IPV6 TEST OK" when every check passed, else lines that start with FAIL.
 */
const vm = require( 'vm' );
const fs = require( 'fs' );
const plugin = process.argv[ 2 ].replace( /\/?$/, '/' );
const source = fs.readFileSync( plugin + 'assets/ipv6-test.js', 'utf8' );
const lines = [];

function ok( condition, message ) {
	if ( ! condition ) {
		lines.push( 'FAIL ' + message );
	}
}

function element( extra ) {
	const listeners = {};
	return Object.assign( {
		hidden: true, checked: false, disabled: false,
		addEventListener: ( type, fn ) => { listeners[ type ] = fn; },
		dispatchEvent: ( event ) => { if ( listeners[ event.type ] ) { listeners[ event.type ](); } return true; },
		click: () => listeners.click && listeners.click(),
		querySelector: () => null,
	}, extra || {} );
}

// The decision.
{
	const exported = { exports: {} };
	vm.runInNewContext( source, { module: exported } );
	const decide = exported.exports.decide;
	ok( 'ipv6' === decide( { loaded: true, offline: false } ), 'a loaded page is IPv6' );
	ok( 'none' === decide( { loaded: false, offline: false } ), 'a page that did not load is no IPv6' );
	ok( 'unknown' === decide( { loaded: false, offline: true } ), 'no network at all says nothing' );
	ok( 'ipv6' === decide( { loaded: true, offline: true } ), 'a loaded page wins' );
}

async function flow( fetchWorks, ticked ) {
	const notice = element();
	const add = element();
	const box = element( { checked: ticked, id: 'beltoft_services_ipv4' } );
	let changes = 0;
	notice.querySelector = () => add;
	const origDispatch = box.dispatchEvent;
	box.dispatchEvent = ( event ) => { changes++; return origDispatch( event ); };
	const win = {
		beltoftServicesIpv6: { url: 'https://ipv6test.example/ping', timeout: 100 },
		fetch: () => ( fetchWorks ? Promise.resolve( {} ) : Promise.reject( new Error( 'no route' ) ) ),
		setTimeout: () => 1, clearTimeout() {},
		navigator: { onLine: true },
		Event: function ( type ) { this.type = type; },
		document: { readyState: 'complete', querySelector: () => notice, getElementById: () => box, addEventListener() {} },
	};
	vm.runInNewContext( source, { window: win, module: { exports: {} } } );
	await new Promise( ( r ) => setImmediate( r ) );
	await new Promise( ( r ) => setImmediate( r ) );

	return { notice, add, box, changes: () => changes };
}

( async () => {
	let t = await flow( true, false );
	ok( true === t.notice.hidden, 'IPv6 works: no notice' );

	t = await flow( false, false );
	ok( false === t.notice.hidden, 'no IPv6: the notice shows' );
	ok( false === t.box.checked && 0 === t.changes(), 'nothing is added before the button is pressed' );
	t.add.click();
	ok( true === t.box.checked && 1 === t.changes(), 'the button ticks the IPv4 box and fires its change' );
	ok( true === t.notice.hidden, 'the notice goes away once the address is chosen' );

	t = await flow( false, true );
	ok( true === t.notice.hidden, 'a visitor who ticked the box does not see the notice' );

	// "Check my connection": the button answers in its own words and is free again.
	{
		let handler;
		const out = { textContent: '' };
		const button = { disabled: false, parentNode: { querySelector: () => out }, getAttribute: ( n ) => ( { 'data-checking': 'Checking', 'data-ipv6': 'YES', 'data-none': 'NO', 'data-unknown': 'UNKNOWN' }[ n ] || null ) };
		const run = async ( works ) => {
			const win = {
				beltoftServicesIpv6: { url: 'https://x.example/p', timeout: 1 }, fetch: () => ( works ? Promise.resolve( {} ) : Promise.reject( new Error( 'x' ) ) ),
				setTimeout: () => 1, clearTimeout() {}, navigator: { onLine: true }, Event: function () {},
				document: { readyState: 'complete', querySelector: () => null, getElementById: () => null, addEventListener: ( t, f ) => { if ( 'click' === t ) { handler = f; } } },
			};
			vm.runInNewContext( source, { window: win, module: { exports: {} } } );
			handler( { target: { closest: () => button } } );
			ok( 'Checking' === out.textContent && button.disabled, 'the button says it is checking' );
			await new Promise( ( r ) => setImmediate( r ) );
			await new Promise( ( r ) => setImmediate( r ) );
		};
		await run( true );
		ok( 'YES' === out.textContent && ! button.disabled, 'IPv6 answer in words, button free' );
		await run( false );
		ok( 'NO' === out.textContent, 'no IPv6 answer in words' );
	}

	console.log( lines.length ? lines.join( '\n' ) : 'IPV6 TEST OK' );
} )();
