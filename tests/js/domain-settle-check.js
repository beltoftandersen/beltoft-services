/**
 * Node check of assets/domain.js (no browser): a domain action that ends with ok false must show the outcome text and free the
 * buttons (beltoftStatus.fail), not reload the page; one that ends ok reloads. Run by tests/Account/DomainSettleScriptTest.php:
 *   node domain-settle-check.js <plugin dir>
 * Prints "DOMAIN SETTLE OK" when every check passed, else lines that start with FAIL.
 */
const vm = require( 'vm' );
const fs = require( 'fs' );
const plugin = process.argv[ 2 ].replace( /\/?$/, '/' );

async function run( ok ) {
	const lines = [];
	const timers = [];
	const fails = [];
	let reloads = 0;
	let submit;
	const panel = { dataset: { domainResource: '187', updating: 'Updating…' }, querySelector: () => null };
	const form = {
		classList: { contains: () => false },
		dataset: { domainAction: 'unlock' },
		closest: ( sel ) => ( /data-domain-resource/.test( sel ) ? panel : null ),
		querySelector: () => null,
	};
	const window = {
		wp: { i18n: { __: ( s ) => s } },
		addEventListener() {}, location: { reload() { reloads++; } },
		setTimeout: ( fn ) => { timers.push( fn ); return timers.length; },
		sessionStorage: { setItem() {}, getItem: () => null, removeItem() {} },
		beltoftServicesAccount: { root: '/r/' },
		beltoftApi: {
			call: ( url, options ) => {
				if ( options && 'POST' === options.method ) {
					return Promise.resolve( { ok: true, status: 202, parsed: true, data: { queued: true, at: 1000 } } );
				}
				return Promise.resolve( { ok: true, status: 200, parsed: true, data: { working: false, last_result: { action: 'unlock', ok, at: 1001, text: 'This did not finish.' } } } );
			},
			follow: ( queued, options ) => Promise.resolve( ok ? { action: 'unlock', ok: true, at: 1001, text: '' } : { action: 'unlock', ok: false, at: 1001, text: 'This did not finish.' } ),
		},
		beltoftStatus: {
			locked: () => false,
			busy: () => ( { handle: true } ),
			fail: ( h, message ) => fails.push( message ),
			done: () => {},
		},
	};
	const document = {
		querySelectorAll: () => [],
		querySelector: () => null,
		addEventListener: ( type, fn ) => { if ( 'submit' === type && ! submit ) { submit = fn; } },
	};
	vm.runInNewContext( fs.readFileSync( plugin + 'assets/domain.js', 'utf8' ), { window, document, JSON, Array, Math, Date, FormData: function () { this.forEach = () => {}; }, String, parseInt } );
	submit( { target: { closest: () => form }, preventDefault() {} } );
	for ( let i = 0; i < 200; i++ ) {
		await new Promise( ( r ) => setImmediate( r ) );
		const fn = timers.shift();
		if ( fn ) { fn(); }
	}
	if ( ok ) {
		if ( 1 !== reloads ) { lines.push( 'FAIL success: expected one reload, got ' + reloads ); }
		if ( fails.length ) { lines.push( 'FAIL success: a failure was shown' ); }
	} else {
		if ( reloads ) { lines.push( 'FAIL failure: the page was reloaded and the outcome lost' ); }
		if ( 1 !== fails.length || ! /did not finish/.test( fails[ 0 ] || '' ) ) { lines.push( 'FAIL failure: the outcome text was not shown: ' + JSON.stringify( fails ) ); }
	}
	return lines;
}

( async () => {
	const lines = ( await run( false ) ).concat( await run( true ) );
	console.log( lines.length ? lines.join( '\n' ) : 'DOMAIN SETTLE OK' );
	process.exit( lines.length ? 1 : 0 );
} )();
