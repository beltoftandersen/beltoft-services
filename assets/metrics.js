/* Customer VPS usage charts. No provider data is written into the document. */
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

	function svgNode( name, attrs ) {
		var node = document.createElementNS( ns, name );
		Object.keys( attrs ).forEach( function ( key ) { node.setAttribute( key, attrs[ key ] ); } );
		return node;
	}

	function value( point, spec, key ) {
		return 'number' === typeof point[ key ] && isFinite( point[ key ] ) ? point[ key ] / ( spec.divisor || 1 ) : null;
	}

	function render( chart, points, spec ) {
		var plot = chart.querySelector( '.beltoft-vm-usage__plot' );
		var readout = chart.querySelector( '[data-readout]' );
		plot.replaceChildren();
		readout.textContent = '';
		if ( ! points.length ) { return; }
		var max = 0;
		points.forEach( function ( point ) {
			spec.keys.forEach( function ( key ) { var n = value( point, spec, key ); if ( null !== n ) { max = Math.max( max, n ); } } );
		} );
		max = Math.max( max, spec.unit === '%' ? 100 : 0.01 );
		var svg = svgNode( 'svg', { viewBox: '0 0 600 160', preserveAspectRatio: 'none', role: 'img', 'aria-label': chart.querySelector( 'h5' ).textContent + ' ' + labels.graph } );
		svg.appendChild( svgNode( 'line', { x1: 0, y1: 159, x2: 600, y2: 159, class: 'beltoft-vm-usage__baseline' } ) );
		spec.keys.forEach( function ( key, series ) {
			var path = '', open = false;
			points.forEach( function ( point, index ) {
				var n = value( point, spec, key );
				if ( null === n ) { open = false; return; }
				var x = points.length === 1 ? 300 : index * 600 / ( points.length - 1 );
				var y = 154 - Math.max( 0, n ) / max * 146;
				path += ( open ? ' L ' : ' M ' ) + x.toFixed( 2 ) + ' ' + y.toFixed( 2 );
				open = true;
			} );
			if ( path ) { svg.appendChild( svgNode( 'path', { d: path, class: 'beltoft-vm-usage__series beltoft-vm-usage__series--' + series } ) ); }
		} );
		function show( index ) {
			var point = points[ index ];
			var date = new Date( point.t * 1000 ).toLocaleString();
			var parts = spec.keys.map( function ( key, series ) {
				var n = value( point, spec, key );
				return spec.names[ series ] + ': ' + ( null === n ? labels.noReading : n.toFixed( n < 10 ? 2 : 1 ) + ' ' + spec.unit );
			} );
			readout.textContent = date + '  ' + parts.join( '  ' );
		}
		svg.addEventListener( 'pointermove', function ( event ) {
			var rect = svg.getBoundingClientRect();
			show( Math.max( 0, Math.min( points.length - 1, Math.round( ( event.clientX - rect.left ) / rect.width * ( points.length - 1 ) ) ) ) );
		} );
		svg.addEventListener( 'pointerleave', function () { show( points.length - 1 ); } );
		plot.appendChild( svg );
		show( points.length - 1 );
	}

	document.querySelectorAll( '[data-vm-metrics]' ).forEach( function ( block ) {
		var panel = block.closest( '.beltoft-services-panel[data-resource]' );
		if ( ! panel ) { return; }
		var notice = block.querySelector( '[data-metrics-notice]' );
		var serial = 0;
		function load( range ) {
			var current = ++serial;
			notice.textContent = labels.loading;
			window.fetch( account.root + encodeURIComponent( panel.getAttribute( 'data-resource' ) ) + '/metrics?range=' + encodeURIComponent( range ), {
				credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce }
			} ).then( function ( response ) {
				var nonce = response.headers.get( 'X-WP-Nonce' );
				if ( nonce ) { account.nonce = nonce; }
				if ( ! response.ok ) { throw new Error( 'metrics' ); }
				return response.json();
			} ).then( function ( body ) {
				if ( current !== serial ) { return; }
				var points = body && Array.isArray( body.points ) ? body.points : [];
				notice.textContent = points.length ? '' : labels.empty;
				block.querySelectorAll( '[data-chart]' ).forEach( function ( chart ) { render( chart, points, specs[ chart.getAttribute( 'data-chart' ) ] ); } );
			} ).catch( function () { if ( current === serial ) { notice.textContent = labels.unavailable; } } );
		}
		block.querySelectorAll( '[data-range]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				block.querySelectorAll( '[data-range]' ).forEach( function ( other ) { other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' ); } );
				load( button.getAttribute( 'data-range' ) );
			} );
		} );
		load( 'hour' );
	} );
}() );
