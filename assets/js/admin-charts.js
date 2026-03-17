/**
 * Rich Statistics — Admin Charts
 * Renders all Chart.js charts from RSA_DATA injected by PHP.
 * Layout-specific chart initialisation is gated on the `view` key.
 */

/* global Chart, RSA_DATA */

( function () {
	'use strict';

	if ( typeof Chart === 'undefined' || typeof RSA_DATA === 'undefined' ) {
		return;
	}

	// ----------------------------------------------------------------
	// Global Chart.js defaults — consistent look across all charts
	// ----------------------------------------------------------------
	var PALETTE = [
		'#2271b1', '#00a32a', '#dba617', '#d63638',
		'#135e96', '#008a20', '#b8860b', '#a02020',
		'#1a9ed9', '#3bc1a3', '#9c6ed5', '#e8832a',
	];

	Chart.defaults.font.family  = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
	Chart.defaults.font.size    = 12;
	Chart.defaults.color        = '#646970';
	Chart.defaults.borderColor  = 'rgba(195,196,199,0.2)';

	var GRID = {
		color: 'rgba(195,196,199,0.15)',
		drawBorder: false,
	};

	var TOOLTIP_DEFAULTS = {
		backgroundColor  : '#1d2327',
		titleColor       : '#f0f0f1',
		bodyColor        : '#c3c4c7',
		borderColor      : 'rgba(255,255,255,0.08)',
		borderWidth      : 1,
		padding          : 10,
		cornerRadius     : 3,
		displayColors    : true,
		boxPadding       : 4,
	};

	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	function labels( arr, key ) {
		return arr.map( function ( i ) { return i[ key ]; } );
	}

	function values( arr, key ) {
		return arr.map( function ( i ) { return i[ key ]; } );
	}

	function canvasEl( id ) {
		return document.getElementById( id );
	}

	function paletteFor( n ) {
		var out = [];
		for ( var i = 0; i < n; i++ ) {
			out.push( PALETTE[ i % PALETTE.length ] );
		}
		return out;
	}

	function makeLinear( labels, datasets, opts ) {
		return {
			type: 'line',
			data: { labels: labels, datasets: datasets },
			options: Object.assign( {
				responsive     : true,
				maintainAspectRatio: true,
				interaction    : { mode: 'index', intersect: false },
				plugins        : { legend: { display: datasets.length > 1 }, tooltip: TOOLTIP_DEFAULTS },
				scales         : {
					x: { grid: GRID, ticks: { maxTicksLimit: 10 } },
					y: { grid: GRID, beginAtZero: true, ticks: { precision: 0 } }
				},
			}, opts || {} ),
		};
	}

	function makeBar( labelsArr, datasetsArr, opts ) {
		return {
			type: 'bar',
			data: { labels: labelsArr, datasets: datasetsArr },
			options: Object.assign( {
				responsive          : true,
				maintainAspectRatio : true,
				indexAxis           : opts && opts.horizontal ? 'y' : 'x',
				plugins: {
					legend  : { display: false },
					tooltip : TOOLTIP_DEFAULTS,
				},
				scales: {
					x: { grid: GRID, ticks: { maxTicksLimit: 12 } },
					y: { grid: GRID, beginAtZero: true, ticks: { precision: 0 } }
				},
			}, opts || {} ),
		};
	}

	function makeDoughnut( labelsArr, dataArr, opts ) {
		return {
			type: 'doughnut',
			data: {
				labels: labelsArr,
				datasets: [ {
					data            : dataArr,
					backgroundColor : paletteFor( dataArr.length ),
					borderWidth     : 2,
					borderColor     : '#fff',
					hoverOffset     : 6,
				} ],
			},
			options: Object.assign( {
				responsive          : true,
				maintainAspectRatio : true,
				cutout              : '60%',
				plugins: {
					legend : {
						position  : 'right',
						labels    : { usePointStyle: true, pointStyleWidth: 8, padding: 14 },
					},
					tooltip: TOOLTIP_DEFAULTS,
				},
			}, opts || {} ),
		};
	}

	// ----------------------------------------------------------------
	// View: Overview
	// ----------------------------------------------------------------

	function initOverview( data ) {
		var el = canvasEl( 'rsa-chart-daily' );
		if ( ! el || ! data.daily ) { return; }

		var daily    = data.daily;
		var dayLabels = labels( daily, 'day' );
		var views     = values( daily, 'views' );

		new Chart( el, makeLinear( dayLabels, [ {
			label           : 'Page Views',
			data            : views,
			borderColor     : PALETTE[0],
			backgroundColor : 'rgba(99,102,241,0.1)',
			borderWidth     : 2,
			pointRadius     : views.length > 30 ? 0 : 3,
			pointHoverRadius: 4,
			fill            : true,
			tension         : 0.3,
		} ] ) );
	}

	// ----------------------------------------------------------------
	// View: Pages
	// ----------------------------------------------------------------

	function initPages( data ) {
		var el = canvasEl( 'rsa-chart-pages' );
		if ( ! el || ! data ) { return; }

		var top = data.slice( 0, 15 );
		new Chart( el, makeBar(
			labels( top, 'page' ),
			[ {
				label           : 'Views',
				data            : values( top, 'views' ),
				backgroundColor : paletteFor( top.length ),
				borderRadius    : 4,
				borderSkipped   : false,
			} ],
			{ horizontal: true }
		) );
	}

	// ----------------------------------------------------------------
	// View: Audience
	// ----------------------------------------------------------------

	function initAudience( data ) {
		var views = {
			'rsa-chart-os'       : { arr: data.os,       key: 'label', val: 'count' },
			'rsa-chart-browser'  : { arr: data.browser,  key: 'label', val: 'count' },
			'rsa-chart-viewport' : { arr: data.viewport, key: 'label', val: 'count' },
			'rsa-chart-language' : { arr: data.language, key: 'label', val: 'count' },
		};

		Object.keys( views ).forEach( function ( id ) {
			var el = canvasEl( id );
			if ( ! el ) { return; }
			var cfg = views[ id ];
			new Chart( el, makeDoughnut(
				labels( cfg.arr, cfg.key ),
				values( cfg.arr, cfg.val )
			) );
		} );

		// Timezone: horizontal bar (potentially many values)
		var tzEl = canvasEl( 'rsa-chart-timezone' );
		if ( tzEl && data.timezone ) {
			var top = data.timezone.slice( 0, 15 );
			new Chart( tzEl, makeBar(
				labels( top, 'label' ),
				[ {
					label           : 'Sessions',
					data            : values( top, 'count' ),
					backgroundColor : PALETTE[0],
					borderRadius    : 4,
					borderSkipped   : false,
				} ],
				{ horizontal: true }
			) );
		}
	}

	// ----------------------------------------------------------------
	// View: Referrers
	// ----------------------------------------------------------------

	function initReferrers( data ) {
		var el = canvasEl( 'rsa-chart-referrers' );
		if ( ! el || ! data ) { return; }

		var top = data.slice( 0, 15 );
		new Chart( el, makeBar(
			labels( top, 'domain' ),
			[ {
				label           : 'Visits',
				data            : values( top, 'visits' ),
				backgroundColor : paletteFor( top.length ),
				borderRadius    : 4,
				borderSkipped   : false,
			} ],
			{ horizontal: true }
		) );
	}

	// ----------------------------------------------------------------
	// View: Behavior
	// ----------------------------------------------------------------

	function initBehavior( data ) {
		var timeEl = canvasEl( 'rsa-chart-time-hist' );
		if ( timeEl && data.time_histogram ) {
			new Chart( timeEl, makeBar(
				labels( data.time_histogram, 'bucket' ),
				[ {
					label           : 'Pages',
					data            : values( data.time_histogram, 'count' ),
					backgroundColor : PALETTE[1],
					borderRadius    : 4,
					borderSkipped   : false,
				} ]
			) );
		}

		var depthEl = canvasEl( 'rsa-chart-session-depth' );
		if ( depthEl && data.session_depth ) {
			new Chart( depthEl, makeDoughnut(
				labels( data.session_depth, 'bucket' ),
				values( data.session_depth, 'count' )
			) );
		}

		if ( data.user_flow && data.user_flow.length ) {
			initFlowChart( data.user_flow );
		}
	}

	// ----------------------------------------------------------------
	// Journey flow: 3-column Sankey — Entry Sources | Pages Visited | Exit Pages
	// ----------------------------------------------------------------

	function initFlowChart( journeyData ) {
		var container = document.getElementById( 'rsa-flow-chart' );
		if ( ! container ) { return; }

		var srcLinks  = ( journeyData && journeyData.source_to_page ) ? journeyData.source_to_page : [];
		var exitLinks = ( journeyData && journeyData.page_to_exit )   ? journeyData.page_to_exit   : [];
		if ( ! srcLinks.length && ! exitLinks.length ) { return; }

		var svgNS = 'http://www.w3.org/2000/svg';
		var TOP_N = 8;

		// ---- Aggregate totals per column -------------------------------
		var c0T    = {};  // col0: Entry Sources
		var c1InT  = {};  // col1: Pages — incoming from sources
		var c1OutT = {};  // col1: Pages — outgoing to exit pages
		var c2T    = {};  // col2: Exit Pages

		srcLinks.forEach( function ( l ) {
			c0T[ l.from ]   = ( c0T[ l.from ]   || 0 ) + l.count;
			c1InT[ l.to ]   = ( c1InT[ l.to ]   || 0 ) + l.count;
		} );
		exitLinks.forEach( function ( l ) {
			c1OutT[ l.from ] = ( c1OutT[ l.from ] || 0 ) + l.count;
			c2T[ l.to ]      = ( c2T[ l.to ]      || 0 ) + l.count;
		} );

		// col1 size = max( in-total, out-total ) so ribbons fit on both sides
		var c1T = {};
		var c1All = {};
		Object.keys( c1InT ).forEach(  function ( k ) { c1All[ k ] = true; } );
		Object.keys( c1OutT ).forEach( function ( k ) { c1All[ k ] = true; } );
		Object.keys( c1All ).forEach( function ( k ) {
			c1T[ k ] = Math.max( c1InT[ k ] || 0, c1OutT[ k ] || 0 );
		} );

		function topNodes( totals ) {
			return Object.keys( totals )
				.sort( function ( a, b ) { return totals[ b ] - totals[ a ]; } )
				.slice( 0, TOP_N );
		}

		var col0 = topNodes( c0T );
		var col1 = topNodes( c1T );
		var col2 = topNodes( c2T );

		var visSrc  = srcLinks.filter( function ( l ) {
			return col0.indexOf( l.from ) > -1 && col1.indexOf( l.to ) > -1;
		} );
		var visExit = exitLinks.filter( function ( l ) {
			return col1.indexOf( l.from ) > -1 && col2.indexOf( l.to ) > -1;
		} );
		if ( ! visSrc.length && ! visExit.length ) { return; }

		// ---- Layout constants ------------------------------------------
		var W      = 960;
		var nodeW  = 12;
		var GAP    = 8;
		var BAND_H = 380;
		var HEAD   = 28;  // vertical space above nodes for column headers
		var labelW = 190;
		var x0 = labelW;
		var x1 = Math.round( ( W - nodeW ) / 2 );
		var x2 = W - labelW - nodeW;

		function buildNodes( keys, totals, nodeX ) {
			var total  = keys.reduce( function ( s, k ) { return s + ( totals[ k ] || 0 ); }, 0 );
			var usable = BAND_H - GAP * Math.max( 0, keys.length - 1 );
			var nodes  = {};
			var y = HEAD;
			keys.forEach( function ( k ) {
				var h = Math.max( 14, Math.round( usable * ( totals[ k ] || 0 ) / total ) );
				nodes[ k ] = { x: nodeX, y: y, h: h, offIn: 0, offOut: 0 };
				y += h + GAP;
			} );
			return nodes;
		}

		var n0 = buildNodes( col0, c0T, x0 );
		var n1 = buildNodes( col1, c1T, x1 );
		var n2 = buildNodes( col2, c2T, x2 );

		function colBottom( nodes, keys ) {
			if ( ! keys.length ) { return HEAD; }
			var last = nodes[ keys[ keys.length - 1 ] ];
			return last ? last.y + last.h : HEAD;
		}
		var svgH = Math.max(
			colBottom( n0, col0 ), colBottom( n1, col1 ), colBottom( n2, col2 )
		) + 16;

		var svg = document.createElementNS( svgNS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + svgH );
		svg.setAttribute( 'width', '100%' );
		svg.style.display   = 'block';
		svg.style.maxHeight = '540px';

		var font = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';

		// ---- Column headers --------------------------------------------
		function colHeader( tx, anchor, label ) {
			var t = document.createElementNS( svgNS, 'text' );
			t.setAttribute( 'x', String( tx ) );
			t.setAttribute( 'y', '15' );
			t.setAttribute( 'text-anchor', anchor );
			t.setAttribute( 'font-size',   '10' );
			t.setAttribute( 'font-family', font );
			t.setAttribute( 'fill',        '#a0a5ae' );
			t.setAttribute( 'font-weight', '600' );
			t.textContent = label.toUpperCase();
			svg.appendChild( t );
		}
		if ( col0.length ) { colHeader( x0 - 6,           'end',    'Entry Source' ); }
		if ( col1.length ) { colHeader( x1 + nodeW / 2,   'middle', 'Pages Visited' ); }
		if ( col2.length ) { colHeader( x2 + nodeW + 6,   'start',  'Exit Page' ); }

		// ---- Ribbon helper ---------------------------------------------
		function ribbon( sn, sTot, dn, dTot, count, idx ) {
			if ( ! sn || ! dn || ! sTot || ! dTot ) { return; }
			var fh  = Math.max( 2, Math.round( sn.h * count / sTot ) );
			var th  = Math.max( 2, Math.round( dn.h * count / dTot ) );
			var y1t = sn.y + sn.offOut;
			var y2t = dn.y + dn.offIn;
			sn.offOut += fh;
			dn.offIn  += th;
			var y1b = y1t + fh;
			var y2b = y2t + th;
			var x1r = sn.x + nodeW;
			var x2l = dn.x;
			var cpx = ( x2l - x1r ) * 0.45;
			var p   = document.createElementNS( svgNS, 'path' );
			p.setAttribute( 'd',
				'M '  + x1r + ' ' + y1t +
				' C ' + ( x1r + cpx ) + ' ' + y1t + ' ' + ( x2l - cpx ) + ' ' + y2t + ' ' + x2l + ' ' + y2t +
				' L ' + x2l + ' ' + y2b +
				' C ' + ( x2l - cpx ) + ' ' + y2b + ' ' + ( x1r + cpx ) + ' ' + y1b + ' ' + x1r + ' ' + y1b +
				' Z'
			);
			p.setAttribute( 'fill',         PALETTE[ idx % PALETTE.length ] );
			p.setAttribute( 'fill-opacity', '0.28' );
			svg.appendChild( p );
		}

		// Draw source → page ribbons
		visSrc.forEach( function ( l, idx ) {
			ribbon( n0[ l.from ], c0T[ l.from ], n1[ l.to ], c1T[ l.to ], l.count, idx );
		} );
		// Draw page → exit page ribbons
		visExit.forEach( function ( l, idx ) {
			ribbon( n1[ l.from ], c1T[ l.from ], n2[ l.to ], c2T[ l.to ], l.count, idx + 4 );
		} );

		// ---- Node rectangles + labels ----------------------------------
		function drawNode( k, n, labelX, anchor, color ) {
			var rect = document.createElementNS( svgNS, 'rect' );
			rect.setAttribute( 'x',      n.x );
			rect.setAttribute( 'y',      n.y );
			rect.setAttribute( 'width',  nodeW );
			rect.setAttribute( 'height', n.h );
			rect.setAttribute( 'fill',   color );
			rect.setAttribute( 'rx',     '2' );
			svg.appendChild( rect );

			var MAX_L = 28;
			var lbl   = k.length > MAX_L ? '\u2026' + k.slice( -( MAX_L - 1 ) ) : k;
			var text  = document.createElementNS( svgNS, 'text' );
			text.setAttribute( 'x',           labelX );
			text.setAttribute( 'y',           n.y + n.h / 2 + 4 );
			text.setAttribute( 'text-anchor', anchor );
			text.setAttribute( 'font-size',   '11' );
			text.setAttribute( 'font-family', font );
			text.setAttribute( 'fill',        '#646970' );
			text.textContent = lbl;
			svg.appendChild( text );
		}

		col0.forEach( function ( k ) { drawNode( k, n0[ k ], x0 - 6,         'end',   PALETTE[0] ); } );
		col1.forEach( function ( k ) { drawNode( k, n1[ k ], x1 + nodeW + 6, 'start', PALETTE[1] ); } );
		col2.forEach( function ( k ) { drawNode( k, n2[ k ], x2 + nodeW + 6, 'start', PALETTE[3] ); } );

		container.appendChild( svg );
	}

	/* <fs_premium_only> */

	// ----------------------------------------------------------------
	// View: Click Map
	// ----------------------------------------------------------------

	function initClickMap( data ) {
		var el = canvasEl( 'rsa-chart-clicks' );
		if ( ! el || ! data ) { return; }

		var top = data.slice( 0, 15 );
		var rowLabels = top.map( function ( r ) {
			// Prefer the explicit matched rule as the label (most specific)
			if ( r.matched_rule ) { return r.matched_rule; }
			var label = r.tag || '';
			if ( r.id )            { label += '#' + r.id; }
			if ( r.protocol )      { label += ' (' + r.protocol + ')'; }
			return label || 'element';
		} );

		new Chart( el, makeBar(
			rowLabels,
			[ {
				label           : 'Clicks',
				data            : values( top, 'clicks' ),
				backgroundColor : paletteFor( top.length ),
				borderRadius    : 4,
				borderSkipped   : false,
			} ],
			{ horizontal: true }
		) );
	}

	/* </fs_premium_only> */

	// ----------------------------------------------------------------
	// Router: pick init function based on view key
	// ----------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		var view = RSA_DATA.view;
		var data = RSA_DATA.data;

		switch ( view ) {
			case 'overview':  initOverview( data );  break;
			case 'pages':     initPages( data );     break;
			case 'audience':  initAudience( data );  break;
			case 'referrers': initReferrers( data ); break;
			case 'behavior':  initBehavior( data );  break;
			case 'user-flow':
				initFlowChart( data.journey_flow );
				break;
			/* <fs_premium_only> */
			case 'click-map': initClickMap( data );  break;
			/* </fs_premium_only> */
		}
	} );

}() );
