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
	// User flow: SVG Sankey diagram of page-to-page transitions
	// ----------------------------------------------------------------

	function initFlowChart( flow, topN ) {
		topN = topN || 8;
		var container = document.getElementById( 'rsa-flow-chart' );
		if ( ! container || ! flow || ! flow.length ) { return; }

		var svgNS = 'http://www.w3.org/2000/svg';

		// Aggregate totals per source / destination
		var srcTotals = {}, dstTotals = {};
		flow.forEach( function ( t ) {
			srcTotals[ t.from_page ] = ( srcTotals[ t.from_page ] || 0 ) + t.count;
			dstTotals[ t.to_page ]   = ( dstTotals[ t.to_page ]   || 0 ) + t.count;
		} );

		var sources = Object.keys( srcTotals )
			.sort( function ( a, b ) { return srcTotals[ b ] - srcTotals[ a ]; } ).slice( 0, topN );
		var targets = Object.keys( dstTotals )
			.sort( function ( a, b ) { return dstTotals[ b ] - dstTotals[ a ]; } ).slice( 0, topN );

		var visible = flow.filter( function ( t ) {
			return sources.indexOf( t.from_page ) !== -1 && targets.indexOf( t.to_page ) !== -1;
		} );
		if ( ! visible.length ) { return; }

		// Layout constants
		var W      = 700;
		var nodeW  = 12;
		var GAP    = 8;
		var textW  = 185;
		var srcX   = textW;
		var dstX   = W - textW - nodeW;
		var BAND_H = 340;

		function buildNodes( keys, totals, nodeLeftX ) {
			var total  = keys.reduce( function ( s, k ) { return s + totals[ k ]; }, 0 );
			var nodes  = {};
			var usable = BAND_H - GAP * ( keys.length - 1 );
			var y      = 0;
			keys.forEach( function ( k ) {
				var h = Math.max( 14, Math.round( usable * totals[ k ] / total ) );
				nodes[ k ] = { x: nodeLeftX, y: y, h: h, offIn: 0, offOut: 0 };
				y += h + GAP;
			} );
			return nodes;
		}

		var srcNodes = buildNodes( sources, srcTotals, srcX );
		var dstNodes = buildNodes( targets, dstTotals, dstX );

		var lastS = srcNodes[ sources[ sources.length - 1 ] ];
		var lastD = dstNodes[ targets[ targets.length - 1 ] ];
		var svgH  = Math.max( lastS.y + lastS.h, lastD.y + lastD.h ) + 20;

		var svg = document.createElementNS( svgNS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + svgH );
		svg.setAttribute( 'width', '100%' );
		svg.style.display   = 'block';
		svg.style.maxHeight = '440px';

		// Draw ribbons first (behind nodes)
		visible.forEach( function ( t, idx ) {
			var sn = srcNodes[ t.from_page ];
			var dn = dstNodes[ t.to_page ];
			if ( ! sn || ! dn ) { return; }

			var fh  = Math.max( 2, Math.round( sn.h * t.count / srcTotals[ t.from_page ] ) );
			var th  = Math.max( 2, Math.round( dn.h * t.count / dstTotals[ t.to_page ] ) );
			var y1t = sn.y + sn.offOut;
			var y2t = dn.y + dn.offIn;
			var y1b = y1t + fh;
			var y2b = y2t + th;
			sn.offOut += fh;
			dn.offIn  += th;

			var x1  = srcX + nodeW;
			var x2  = dstX;
			var cpx = ( x2 - x1 ) * 0.45;

			var path = document.createElementNS( svgNS, 'path' );
			path.setAttribute( 'd',
				'M '  + x1 + ' ' + y1t +
				' C ' + ( x1 + cpx ) + ' ' + y1t + ' ' + ( x2 - cpx ) + ' ' + y2t + ' ' + x2 + ' ' + y2t +
				' L ' + x2 + ' ' + y2b +
				' C ' + ( x2 - cpx ) + ' ' + y2b + ' ' + ( x1 + cpx ) + ' ' + y1b + ' ' + x1 + ' ' + y1b +
				' Z'
			);
			path.setAttribute( 'fill', PALETTE[ idx % PALETTE.length ] );
			path.setAttribute( 'fill-opacity', '0.28' );
			svg.appendChild( path );
		} );

		// Node rectangles + labels
		var font = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';

		function drawNode( k, n, labelX, anchor, color ) {
			var rect = document.createElementNS( svgNS, 'rect' );
			rect.setAttribute( 'x', n.x );
			rect.setAttribute( 'y', n.y );
			rect.setAttribute( 'width', nodeW );
			rect.setAttribute( 'height', n.h );
			rect.setAttribute( 'fill', color );
			rect.setAttribute( 'rx', '2' );
			svg.appendChild( rect );

			var max  = 26;
			var lbl  = k.length > max ? '\u2026' + k.slice( -( max - 1 ) ) : k;
			var text = document.createElementNS( svgNS, 'text' );
			text.setAttribute( 'x', labelX );
			text.setAttribute( 'y', n.y + n.h / 2 + 4 );
			text.setAttribute( 'text-anchor', anchor );
			text.setAttribute( 'font-size', '11' );
			text.setAttribute( 'font-family', font );
			text.setAttribute( 'fill', '#646970' );
			text.textContent = lbl;
			svg.appendChild( text );
		}

		sources.forEach( function ( k ) { drawNode( k, srcNodes[ k ], srcX - 6,         'end',   PALETTE[0] ); } );
		targets.forEach( function ( k ) { drawNode( k, dstNodes[ k ], dstX + nodeW + 6, 'start', PALETTE[1] ); } );

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
				if ( data.user_flow && data.user_flow.length ) {
					initFlowChart( data.user_flow, RSA_DATA.top_n || 12 );
				}
				break;
			/* <fs_premium_only> */
			case 'click-map': initClickMap( data );  break;
			/* </fs_premium_only> */
		}
	} );

}() );
