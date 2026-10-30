/* Customer VPS usage charts. Inline SVG, no library. No provider data is written into the document. */
( function () {
	'use strict';
	var account = window.beltoftServicesAccount;
	var labels = window.beltoftServicesMetrics && window.beltoftServicesMetrics.strings;
	if ( ! account || ! labels || ! window.fetch ) { return; }
	var ns = 'http://www.w3.org/2000/svg';
	var specs = {
		cpu: { keys: [ 'cpu' ], names: [ labels.cpu ], unit: '%' },
		mem: { keys: [ 'mem', 'maxmem' ], names: [ labels.memory, labels.allocated ], unit: 'GB', divisor: 1073741824 },
		net: { keys: [ 'netin', 'netout' ], names: [ labels.in, labels.out ], unit: 'MB/s', divisor: 1048576 },
		io: { keys: [ 'diskread', 'diskwrite' ], names: [ labels.read, labels.write ], unit: 'MB/s', divisor: 1048576 }
	};
	var top = 8;
	var bottom = 154;

	function svgNode( name, attrs ) {
		var node = document.createElementNS( ns, name );
		Object.keys( attrs ).forEach( function ( key ) { node.setAttribute( key, attrs[ key ] ); } );
		return node;
	}

	function value( point, spec, key ) {
		return 'number' === typeof point[ key ] && isFinite( point[ key ] ) ? point[ key ] / ( spec.divisor || 1 ) : null;
	}

	// 0.8, 2, 31, 6.3: no trailing zeros.
	function format( n ) {
		return String( parseFloat( n.toFixed( n < 10 ? 2 : 1 ) ) );
	}

	function withUnit( n, unit ) {
		return format( n ) + ( '%' === unit ? '%' : ' ' + unit );
	}

	function fill( text, part ) {
		return String( text || '%s' ).replace( '%s', function () { return part; } );
	}

	function clock( seconds, range ) {
		var date = new Date( seconds * 1000 );
		if ( 'month' === range ) { return date.toLocaleDateString( [], { day: 'numeric', month: 'short' } ); }
		return 'day' === range ? date.toLocaleString( [], { weekday: 'short', hour: '2-digit', minute: '2-digit' } ) : date.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
	}

	function span( text ) {
		var node = document.createElement( 'span' );
		node.textContent = text;
		return node;
	}

	function render( chart, points, spec, range ) {
		var plot = chart.querySelector( '.beltoft-vm-usage__plot' );
		var readout = chart.querySelector( '[data-readout]' );
		var now = chart.querySelector( '[data-now]' );
		var axis = chart.querySelector( '[data-axis]' );
		plot.replaceChildren();
		readout.textContent = '';
		if ( now ) { now.replaceChildren(); }
		if ( axis ) { axis.replaceChildren(); }
		if ( ! points.length ) { return; }
		var max = 0;
		var peak = 0;
		var last = null;
		points.forEach( function ( point ) {
			spec.keys.forEach( function ( key ) { var n = value( point, spec, key ); if ( null !== n ) { max = Math.max( max, n ); } } );
			var first = value( point, spec, spec.keys[ 0 ] );
			if ( null !== first ) { peak = Math.max( peak, first ); last = point; }
		} );
		max = Math.max( max, spec.unit === '%' ? 100 : 0.01 );
		function xOf( index ) { return points.length === 1 ? 300 : index * 600 / ( points.length - 1 ); }
		function yOf( n ) { return bottom - Math.max( 0, n ) / max * ( bottom - top ); }
		var peakText = withUnit( peak, spec.unit );
		var svg = svgNode( 'svg', { viewBox: '0 0 600 160', preserveAspectRatio: 'none', role: 'img', 'aria-label': chart.querySelector( 'h5' ).textContent + ' ' + labels.graph + ', ' + fill( labels.peak, peakText ) } );
		[ 1 / 3, 2 / 3 ].forEach( function ( step ) {
			var y = bottom - step * ( bottom - top );
			svg.appendChild( svgNode( 'line', { x1: 0, y1: y.toFixed( 2 ), x2: 600, y2: y.toFixed( 2 ), class: 'beltoft-vm-usage__grid-line' } ) );
		} );
		svg.appendChild( svgNode( 'line', { x1: 0, y1: 159, x2: 600, y2: 159, class: 'beltoft-vm-usage__baseline' } ) );
		spec.keys.forEach( function ( key, series ) {
			var path = '';
			var area = '';
			var open = false;
			var from = 0;
			var to = 0;
			function close() {
				if ( open && 0 === series ) { area += ' L ' + to.toFixed( 2 ) + ' ' + bottom + ' L ' + from.toFixed( 2 ) + ' ' + bottom + ' Z'; }
				open = false;
			}
			points.forEach( function ( point, index ) {
				var n = value( point, spec, key );
				if ( null === n ) { close(); return; }
				var x = xOf( index );
				var y = yOf( n );
				path += ( open ? ' L ' : ' M ' ) + x.toFixed( 2 ) + ' ' + y.toFixed( 2 );
				if ( 0 === series ) { area += ( open ? ' L ' : ' M ' ) + x.toFixed( 2 ) + ' ' + y.toFixed( 2 ); }
				if ( ! open ) { from = x; }
				to = x;
				open = true;
			} );
			close();
			if ( area ) { svg.appendChild( svgNode( 'path', { d: area, class: 'beltoft-vm-usage__area' } ) ); }
			if ( path ) { svg.appendChild( svgNode( 'path', { d: path, class: 'beltoft-vm-usage__series beltoft-vm-usage__series--' + series } ) ); }
		} );
		function show( index ) {
			var point = points[ index ];
			var date = clock( point.t, range );
			var parts = spec.keys.map( function ( key, series ) {
				var n = value( point, spec, key );
				return spec.names[ series ] + ': ' + ( null === n ? labels.noReading : withUnit( n, spec.unit ) );
			} );
			readout.textContent = date + '   ' + parts.join( '   ' );
		}
		svg.addEventListener( 'pointermove', function ( event ) {
			var rect = svg.getBoundingClientRect();
			show( Math.max( 0, Math.min( points.length - 1, Math.round( ( event.clientX - rect.left ) / rect.width * ( points.length - 1 ) ) ) ) );
		} );
		svg.addEventListener( 'pointerleave', function () { readout.textContent = ''; } );
		plot.appendChild( svg );
		// The current value, large, and the last point marked with a square (a page element, so it keeps its shape when the graph stretches).
		if ( last && now ) {
			var big = document.createElement( 'b' );
			var small = document.createElement( 'small' );
			var current = value( last, spec, spec.keys[ 0 ] );
			var lastIndex = points.indexOf( last );
			var marker = document.createElement( 'i' );
			var kind = chart.getAttribute( 'data-chart' );
			big.textContent = 'cpu' === kind ? withUnit( current, spec.unit ) : format( current );
			small.textContent = 'mem' === kind && null !== value( last, spec, 'maxmem' ) ? fill( labels.of, format( value( last, spec, 'maxmem' ) ) ) : ( 'cpu' === kind ? '' : spec.unit + ' ' + spec.names[ 0 ] );
			now.appendChild( big );
			if ( small.textContent ) { now.appendChild( small ); }
			marker.className = 'beltoft-vm-usage__point';
			marker.style.left = ( xOf( lastIndex ) / 6 ) + '%';
			marker.style.top = ( yOf( current ) / 160 * 100 ) + '%';
			plot.appendChild( marker );
		}
		if ( axis ) {
			axis.appendChild( span( clock( points[ 0 ].t, range ) ) );
			axis.appendChild( span( fill( labels.peak, peakText ) ) );
			axis.appendChild( span( clock( points[ points.length - 1 ].t, range ) ) );
		}
	}

	document.querySelectorAll( '[data-vm-metrics]' ).forEach( function ( block ) {
		var panel = block.closest( '.beltoft-services-panel[data-resource]' );
		if ( ! panel ) { return; }
		var notice = block.querySelector( '[data-metrics-notice]' );
		var alerts = block.querySelector( '[data-usage-alerts]' );
		var alertsNote = block.querySelector( '[data-usage-alerts-note]' );
		if ( alerts ) {
			alerts.addEventListener( 'change', function () {
				var wanted = alerts.checked;
				alerts.disabled = true;
				window.fetch( account.root + encodeURIComponent( panel.getAttribute( 'data-resource' ) ) + '/usage-alerts', {
					method: 'POST', credentials: 'same-origin',
					headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { on: wanted } )
				} ).then( function ( response ) {
					var nonce = response.headers.get( 'X-WP-Nonce' );
					if ( nonce ) { account.nonce = nonce; }
					if ( ! response.ok ) { throw new Error( 'alerts' ); }
					return response.json();
				} ).then( function ( body ) {
					alerts.checked = !! ( body && body.on );
					alertsNote.textContent = alerts.checked ? labels.alertsOn : labels.alertsOff;
				} ).catch( function () {
					alerts.checked = ! wanted;
					alertsNote.textContent = labels.alertsFailed;
				} ).then( function () { alerts.disabled = false; } );
			} );
		}
		var serial = 0;
		var range = 'hour';
		var started = false;
		var inflight = false;
		// background: a refresh of the same range; it does not show the loading text, so the charts do not flicker.
		function load( background ) {
			var current = ++serial;
			var asked = range;
			inflight = true;
			if ( ! background ) { notice.textContent = labels.loading; }
			window.fetch( account.root + encodeURIComponent( panel.getAttribute( 'data-resource' ) ) + '/metrics?range=' + encodeURIComponent( asked ), {
				credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce }
			} ).then( function ( response ) {
				var nonce = response.headers.get( 'X-WP-Nonce' );
				if ( nonce ) { account.nonce = nonce; }
				if ( ! response.ok ) { throw new Error( 'metrics' ); }
				return response.json();
			} ).then( function ( body ) {
				if ( current !== serial ) { return; }
				inflight = false;
				var points = body && Array.isArray( body.points ) ? body.points : [];
				notice.textContent = points.length ? '' : labels.empty;
				block.classList.toggle( 'is-empty', ! points.length );
				block.querySelectorAll( '[data-chart]' ).forEach( function ( chart ) { render( chart, points, specs[ chart.getAttribute( 'data-chart' ) ], asked ); } );
			} ).catch( function () {
				if ( current !== serial ) { return; }
				inflight = false;
				if ( background && ! block.classList.contains( 'is-empty' ) ) { return; }
				notice.textContent = labels.unavailable;
				block.classList.add( 'is-empty' );
			} );
		}
		// Live: every 60 seconds while the page is visible and the charts are not in a hidden tab; one request at a time.
		var timer = null;
		function visible() { return 'visible' === document.visibilityState && ! block.closest( '[hidden]' ); }
		function tick() { if ( started && ! inflight && visible() ) { load( true ); } }
		function schedule() {
			if ( timer ) { window.clearInterval( timer ); timer = null; }
			if ( started && visible() ) { timer = window.setInterval( tick, 60000 ); }
		}
		// The charts are asked for when they can be seen: a tab that is not shown at load asks when it first is.
		function start() {
			if ( started || block.closest( '[hidden]' ) ) { return; }
			started = true;
			load( false );
			schedule();
		}
		block.querySelectorAll( '[data-range]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				block.querySelectorAll( '[data-range]' ).forEach( function ( other ) { other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' ); } );
				range = button.getAttribute( 'data-range' );
				load( false );
				schedule();
			} );
		} );
		panel.addEventListener( 'beltoft-services:tab', function () { start(); schedule(); } );
		document.addEventListener( 'visibilitychange', function () {
			schedule();
			if ( started && visible() ) { if ( inflight ) { return; } load( true ); }
		} );
		start();
	} );
}() );
