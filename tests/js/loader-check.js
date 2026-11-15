/**
 * Browser check of assets/status.js (the progress line). Run by tests/StatusTest.php:
 *   node loader-check.js <playwright dir> <plugin dir>
 * Prints "LOADER OK" when every check passed, else lines that start with FAIL.
 */
const playwright = require( process.argv[ 2 ] );
const plugin = process.argv[ 3 ].replace( /\/?$/, '/' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const lines = [];
let failed = false;

function ok( condition, message ) {
	if ( ! condition ) {
		failed = true;
		lines.push( 'FAIL ' + message );
	}
}

const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
	+ '<link rel="stylesheet" href="file://' + plugin + 'assets/account.css"></head><body class="woocommerce-account">'
	+ '<div class="woocommerce-MyAccount-content" data-resource="r1"><div class="bs-pagehead"><span class="bs-inline" data-bs-inline><span class="bs-inline__text">Create a server.</span></span></div>'
	+ '<h2>Server</h2><p><button id="go" type="button">Go</button> <input id="name" value="x"></p><div style="height:1500px">long</div><p><button id="low" type="button">Low</button></p></div>'
	+ '<script>window.beltoftStatusConfig={strings:{stepOf:"step %1$d of %2$d",failedPrefix:"Failed: "}};</script>'
	+ '<script src="file://' + plugin + 'assets/status.js"></script></body></html>';

async function main() {
	const file = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'bs-loader-' ) ), 'index.html' );
	const browser = await playwright.chromium.launch();

	fs.writeFileSync( file, html );

	for ( const [ name, width ] of [ [ 'desktop', 1440 ], [ 'phone', 390 ] ] ) {
		const context = await browser.newContext( { viewport: { width: width, height: 800 } } );
		const page = await context.newPage();

		await page.clock.install();
		await page.goto( 'file://' + file );

		const geometry = () => page.evaluate( () => ( { height: document.documentElement.scrollHeight, go: document.getElementById( 'go' ).getBoundingClientRect().top, low: document.getElementById( 'low' ).getBoundingClientRect().top, h2: document.querySelector( 'h2' ).getBoundingClientRect().top } ) );
		const text = () => page.evaluate( () => document.querySelector( '.bs-loader__text' ).textContent );
		const shown = () => page.evaluate( () => { const el = document.querySelector( '.bs-loader' ); return !! el && ! el.hidden; } );
		const before = await geometry();

		await page.evaluate( () => { window.h = beltoftStatus.busy( 'Restoring…', 'r1', document.getElementById( 'go' ) ); } );
		ok( await shown(), name + ': busy shows the line' );
		ok( 1 === await page.locator( '.bs-loader' ).count() && 0 === await page.locator( '.bs-note' ).count(), name + ': one line, no note under the button' );

		const where = await page.evaluate( () => { const el = document.querySelector( '.bs-loader' ); return { position: getComputedStyle( el ).position, parent: el.parentNode.tagName, bottom: el.getBoundingClientRect().bottom }; } );

		if ( 'phone' === name ) {
			ok( 'fixed' === where.position && 'BODY' === where.parent && where.bottom > 700, name + ': fixed at the bottom of the screen' );
			ok( JSON.stringify( await geometry() ) === JSON.stringify( before ), name + ': nothing moved when it appeared' );
		} else {
			ok( 'fixed' !== where.position && 'SPAN' === where.parent, name + ': in the state line' );
		}

		await page.evaluate( () => beltoftStatus.progress( window.h, { step: 2, of: 4 }, 'Restoring…' ) );
		ok( 'Restoring (step 2 of 4)' === await text(), name + ': step text ' + await text() );
		ok( '25%' === await page.evaluate( () => document.querySelector( '.bs-loader__bar i' ).style.width ), name + ': determinate width' );

		await page.evaluate( () => beltoftStatus.progress( window.h, { steps: [ { status: 'done' }, { status: 'done' }, { status: 'current' } ] } ) );
		ok( 'Restoring (step 3 of 3)' === await text(), name + ': a Progress payload gives the step' );

		await page.evaluate( () => beltoftStatus.done( window.h, 'Restored.' ) );
		ok( 'Restored.' === await text(), name + ': done text' );

		await page.clock.fastForward( 4500 );
		ok( await shown(), name + ': done waits' );
		await page.clock.fastForward( 1000 );
		await page.clock.fastForward( 500 );
		ok( ! await shown(), name + ': done goes away after the wait' );
		ok( JSON.stringify( await geometry() ) === JSON.stringify( before ), name + ': the page is as before' );

		await page.evaluate( () => { const h = beltoftStatus.busy( 'Working…', 'r1', null ); beltoftStatus.fail( h, 'No answer.', '#name' ); } );
		await page.clock.fastForward( 60000 );
		ok( await shown() && 'Failed: No answer.' === await text(), name + ': fail stays' );
		ok( await page.evaluate( () => 'alert' === document.querySelector( '.bs-loader' ).getAttribute( 'role' ) && 'true' === document.getElementById( 'name' ).getAttribute( 'aria-invalid' ) ), name + ': fail is an alert and the field is marked' );
		await page.keyboard.press( 'Escape' );
		ok( ! await shown(), name + ': Escape closes the failure' );

		await context.close();
	}

	await browser.close();
	fs.rmSync( path.dirname( file ), { recursive: true, force: true } );
	console.log( failed ? lines.join( '\n' ) : 'LOADER OK' );
}

main().catch( ( error ) => {
	console.log( 'FAIL ' + error.message );
	process.exit( 1 );
} );
