/**
 * Node check of assets/thankyou.js (no browser): the sticky progress line (beltoftStatus.busy) must not start when the order card
 * (the [data-bs-order] section with its own track) is around the services, and must start when there is no such card.
 * Run by tests/Account/OrderBarScriptTest.php:  node order-bar-check.js <plugin dir>
 * Prints "ORDER BAR OK" when every check passed, else lines that start with FAIL.
 */
const vm = require( 'vm' );
const fs = require( 'fs' );
const plugin = process.argv[ 2 ].replace( /\/?$/, '/' );
const lines = [];

async function run( withCard ) {
	const calls = [];
	const timers = [];
	const section = { querySelector: () => null, setAttribute() {}, className: '' };
	const attrs = { 'data-progress-url': '/p', 'data-order': '7', 'data-known': '0', 'data-bs-flow': '' };
	const root = {
		hasAttribute: ( n ) => n in attrs,
		getAttribute: ( n ) => ( n in attrs ? attrs[ n ] : null ),
		closest: () => ( withCard ? section : null ),
		querySelector: () => null,
		querySelectorAll: () => [],
	};
	const window = {
		fetch: () => Promise.resolve( { ok: true, status: 200, headers: { get: () => null }, json: () => Promise.resolve( { services: [ { id: 1, group: 'x', ready: false, progress: { active: true, steps: [ { status: 'done' }, { status: 'running' } ] } } ] } ) } ),
		setTimeout: ( fn ) => { timers.push( fn ); return timers.length; },
		BeltoftServicesProgress: { mount() {} },
		location: { reload() {} },
		beltoftStatus: { busy: () => { calls.push( 'busy' ); return {}; }, progress() {}, done() {}, fail() {} },
	};
	const document = { querySelectorAll: () => [ root ], createElement: () => ( {} ) };

	vm.runInNewContext( fs.readFileSync( plugin + 'assets/thankyou.js', 'utf8' ), { window, document, Date, Math, parseInt, String } );

	if ( ! timers.length ) {
		lines.push( 'FAIL the script did not start its wait (' + ( withCard ? 'card' : 'no card' ) + ')' );
		return calls;
	}

	timers.shift()();
	await new Promise( ( r ) => setImmediate( r ) );
	await new Promise( ( r ) => setImmediate( r ) );

	return calls;
}

( async () => {
	const withCard = await run( true );
	const without = await run( false );

	if ( withCard.length ) {
		lines.push( 'FAIL the top line started although the order card shows the progress' );
	}

	if ( ! without.length ) {
		lines.push( 'FAIL the top line did not start without an order card' );
	}

	console.log( lines.length ? lines.join( '\n' ) : 'ORDER BAR OK' );
} )();
