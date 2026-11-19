/**
 * Node check of assets/db.js (no browser): when every status read of a waited-for action is rejected, the page must say so and
 * free the buttons. Run by tests/Account/DbWaitScriptTest.php:  node db-wait-check.js <plugin dir>
 * Prints "DB WAIT OK" when every check passed, else lines that start with FAIL.
 */
const vm = require( 'vm' );
const fs = require( 'fs' );
const plugin = process.argv[ 2 ].replace( /\/?$/, '/' );
const lines = [];
let clicked;
const timers = [];
const fails = [];
const reads = { posts: 0, gets: 0 };

const panel = { getAttribute: () => 'r1', querySelector: () => null };
const button = {
	getAttribute: () => null,
	hasAttribute: ( name ) => 'data-db-reset' === name,
	closest: ( sel ) => ( /data-resource/.test( sel ) ? panel : null ),
};
panel.querySelector = ( sel ) => ( /data-db-card/.test( sel ) ? { querySelector: () => null } : null );
button.closest = ( sel ) => ( /data-db-card/.test( sel ) ? { querySelector: () => null } : ( /data-resource/.test( sel ) ? panel : null ) );

const window = {
	fetch: () => Promise.reject( new Error( 'offline' ) ),
	location: { reload() { lines.push( 'FAIL the page was reloaded' ); }, set href( v ) { lines.push( 'FAIL moved to ' + v ); } },
	setTimeout: ( fn ) => { timers.push( fn ); return timers.length; },
	beltoftServicesAccount: { root: '/r/' },
	beltoftServicesDb: { strings: { failed: 'Something went wrong.', lost: 'We could not check. Please reload the page.', working: 'Working' } },
	beltoftApi: {
		call: ( url, options ) => {
			if ( options && 'POST' === options.method ) {
				reads.posts++;
				return Promise.resolve( { ok: true, status: 202, parsed: true, data: {} } );
			}
			reads.gets++;
			return Promise.reject( new Error( 'offline' ) );
		},
		// The reset asks for the emailed code first (0.47.0): the test types one.
		stepUp: () => Promise.resolve( '123456' ),
	},
	beltoftStatus: {
		locked: () => false,
		busy: () => ( { handle: true } ),
		fail: ( h, message ) => fails.push( message ),
		done: () => {},
		confirm: () => Promise.resolve( true ),
	},
};
const document = {
	querySelectorAll: () => [],
	addEventListener: ( type, fn ) => { if ( 'click' === type ) { clicked = fn; } },
};

vm.runInNewContext( fs.readFileSync( plugin + 'assets/db.js', 'utf8' ), { window, document, Date, Math, Array } );

( async () => {
	clicked( { target: { closest: () => button } } );
	for ( let i = 0; i < 1500; i++ ) {
		await new Promise( ( r ) => setImmediate( r ) );
		const fn = timers.shift();
		if ( fn ) { fn(); }
	}
	await new Promise( ( r ) => setImmediate( r ) );

	if ( reads.posts !== 1 ) { lines.push( 'FAIL the action was not posted once' ); }
	if ( reads.gets < 61 ) { lines.push( 'FAIL expected 61 status reads, got ' + reads.gets ); }
	if ( 1 !== fails.length ) { lines.push( 'FAIL expected one message after the reads ran out, got ' + fails.length ); }
	else if ( ! /reload/i.test( fails[ 0 ] ) ) { lines.push( 'FAIL the message does not tell to reload: ' + fails[ 0 ] ); }

	console.log( lines.length ? lines.join( '\n' ) : 'DB WAIT OK' );
	process.exit( lines.length ? 1 : 0 );
} )();
