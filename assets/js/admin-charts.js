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
	}

	// ----------------------------------------------------------------
	// Journey flow: N-step Sankey — Step 1 | Step 2 | Step 3 | …
	// Each column = an actual chronological step in visitor sessions.
	// Sessions that end before reaching the next step show an "(exit)" node.
	// ----------------------------------------------------------------

	function initFlowChart( pathData ) {
		var container = document.getElementById( 'rsa-flow-chart' );
		if ( ! container ) { return; }

		var steps = ( pathData && pathData.steps ) ? pathData.steps : {};
		var links = ( pathData && pathData.links  ) ? pathData.links  : [];
		var stepNums = Object.keys( steps ).map( Number ).sort( function ( a, b ) { return a - b; } );
		if ( ! stepNums.length ) { return; }

		var svgNS  = 'http://www.w3.org/2000/svg';
		var W      = 960;
		var nodeW  = 12;
		var GAP    = 8;
		var BAND_H = 400;
		var HEAD   = 28;
		var labelW = 185;
		var font   = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';
		var EXIT_COLOR = '#a0a5ae';

		var numCols = stepNums.length;

		// x position for column c (0-based index)
		function colX( c ) {
			if ( numCols === 1 ) { return Math.round( ( W - nodeW ) / 2 ); }
			// Left col starts at labelW, right col ends at W - labelW - nodeW
			var left  = labelW;
			var right = W - labelW - nodeW;
			return Math.round( left + c * ( right - left ) / ( numCols - 1 ) );
		}

		// Build node layout for one column
		function buildNodes( pageList ) {
			var total  = pageList.reduce( function ( s, p ) { return s + p.sessions; }, 0 );
			var usable = BAND_H - GAP * Math.max( 0, pageList.length - 1 );
			var nodes  = {};
			var y      = HEAD;
			pageList.forEach( function ( p ) {
				var h = Math.max( 14, Math.round( usable * p.sessions / Math.max( total, 1 ) ) );
				nodes[ p.page ] = { y: y, h: h, offIn: 0, offOut: 0, sessions: p.sessions };
				y += h + GAP;
			} );
			return nodes;
		}

		// Columns: node maps indexed by step number
		var colNodes = {};
		stepNums.forEach( function ( sn ) {
			colNodes[ sn ] = buildNodes( steps[ sn ] );
		} );

		// SVG height = tallest column bottom + padding
		var svgH = HEAD + 16;
		stepNums.forEach( function ( sn ) {
			var pages = steps[ sn ];
			if ( ! pages || ! pages.length ) { return; }
			var last = colNodes[ sn ][ pages[ pages.length - 1 ].page ];
			if ( last ) { svgH = Math.max( svgH, last.y + last.h + 16 ); }
		} );

		var svg = document.createElementNS( svgNS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + svgH );
		svg.setAttribute( 'width', '100%' );
		svg.style.display   = 'block';
		svg.style.maxHeight = '560px';

		// ---- Column headers --------------------------------------------
		stepNums.forEach( function ( sn, ci ) {
			var x   = colX( ci );
			var mid = x + nodeW / 2;
			var t   = document.createElementNS( svgNS, 'text' );
			t.setAttribute( 'x',           String( mid ) );
			t.setAttribute( 'y',           '15' );
			t.setAttribute( 'text-anchor', 'middle' );
			t.setAttribute( 'font-size',   '10' );
			t.setAttribute( 'font-family', font );
			t.setAttribute( 'fill',        '#a0a5ae' );
			t.setAttribute( 'font-weight', '600' );
			t.textContent = ( 'STEP ' + sn ).toUpperCase();
			svg.appendChild( t );
		} );

		// ---- Ribbon helper ---------------------------------------------
		function ribbon( sn, dn, fromPage, toPage, sFromTot, sTotTo, count, colorIdx ) {
			var snNode = sn[ fromPage ];
			var dnNode = dn[ toPage ];
			if ( ! snNode || ! dnNode ) { return; }
			var fh  = Math.max( 2, Math.round( snNode.h * count / Math.max( snNode.sessions, 1 ) ) );
			var th  = Math.max( 2, Math.round( dnNode.h * count / Math.max( dnNode.sessions, 1 ) ) );
			var y1t = snNode.y + snNode.offOut;
			var y2t = dnNode.y + dnNode.offIn;
			snNode.offOut += fh;
			dnNode.offIn  += th;
			var y1b  = y1t + fh;
			var y2b  = y2t + th;
			var x1r  = snNode.x + nodeW;
			var x2l  = dnNode.x;
			var cpx  = ( x2l - x1r ) * 0.45;
			var p    = document.createElementNS( svgNS, 'path' );
			var isExit = ( toPage === '(exit)' );
			p.setAttribute( 'd',
				'M '  + x1r + ' ' + y1t +
				' C ' + ( x1r + cpx ) + ' ' + y1t + ' ' + ( x2l - cpx ) + ' ' + y2t + ' ' + x2l + ' ' + y2t +
				' L ' + x2l + ' ' + y2b +
				' C ' + ( x2l - cpx ) + ' ' + y2b + ' ' + ( x1r + cpx ) + ' ' + y1b + ' ' + x1r + ' ' + y1b +
				' Z'
			);
			p.setAttribute( 'fill',         isExit ? EXIT_COLOR : PALETTE[ colorIdx % PALETTE.length ] );
			p.setAttribute( 'fill-opacity', isExit ? '0.18' : '0.28' );
			svg.appendChild( p );
		}

		// Store x on each node map after positions are computed
		stepNums.forEach( function ( sn, ci ) {
			var nx = colX( ci );
			Object.keys( colNodes[ sn ] ).forEach( function ( page ) {
				colNodes[ sn ][ page ].x = nx;
			} );
		} );

		// ---- Draw ribbons (links) ---------------------------------------
		links.forEach( function ( l, idx ) {
			var fromSn = l.step;
			var toSn   = l.step + 1;
			// "(exit)" links go to a phantom node just to the right of the from column
			var snNodes = colNodes[ fromSn ];
			var dnNodes;
			if ( l.to === '(exit)' ) {
				// Create a temporary exit node if needed
				var exitKey = '(exit)';
				if ( ! colNodes[ fromSn + '_exit' ] ) {
					colNodes[ fromSn + '_exit' ] = {};
				}
				dnNodes = colNodes[ fromSn + '_exit' ];
				if ( ! dnNodes[ exitKey ] ) {
					var snNode = snNodes[ l.from ];
					var exitX  = colX( stepNums.indexOf( fromSn ) ) + nodeW + 40;
					dnNodes[ exitKey ] = { x: exitX, y: snNode ? snNode.y : HEAD, h: 20, offIn: 0, offOut: 0, sessions: l.count };
				}
			} else {
				dnNodes = colNodes[ toSn ];
			}
			if ( snNodes && dnNodes ) {
				ribbon( snNodes, dnNodes, l.from, l.to, 1, 1, l.count, idx );
			}
		} );

		// ---- Node rectangles + labels ----------------------------------
		function drawNode( page, node, nx, anchor, color ) {
			var rect = document.createElementNS( svgNS, 'rect' );
			rect.setAttribute( 'x',      nx );
			rect.setAttribute( 'y',      node.y );
			rect.setAttribute( 'width',  nodeW );
			rect.setAttribute( 'height', node.h );
			rect.setAttribute( 'fill',   color );
			rect.setAttribute( 'rx',     '2' );
			svg.appendChild( rect );

			var MAX_L = 28;
			var lbl   = page.length > MAX_L ? '\u2026' + page.slice( -( MAX_L - 1 ) ) : page;
			var tx    = anchor === 'end' ? nx - 6 : nx + nodeW + 6;
			var text  = document.createElementNS( svgNS, 'text' );
			text.setAttribute( 'x',           tx );
			text.setAttribute( 'y',           node.y + node.h / 2 + 4 );
			text.setAttribute( 'text-anchor', anchor );
			text.setAttribute( 'font-size',   '11' );
			text.setAttribute( 'font-family', font );
			text.setAttribute( 'fill',        page === '(exit)' ? '#a0a5ae' : '#646970' );
			text.textContent = lbl;
			svg.appendChild( text );
		}

		stepNums.forEach( function ( sn, ci ) {
			var nx     = colX( ci );
			var anchor = ci === numCols - 1 ? 'end' : 'start';
			// All middle columns use start, last column uses end only when it's alone on the right edge
			// Actually: first col labels go to right of node, last col labels go to right too (unless only col)
			// We always put labels to the right, except the leftmost might be tighter.
			// Simplest: labels always to the right of node (start), fits our standard layout.
			anchor = 'start';
			( steps[ sn ] || [] ).forEach( function ( pg, pidx ) {
				var node  = colNodes[ sn ][ pg.page ];
				if ( ! node ) { return; }
				node.x = nx;
				var color = pg.page === '(exit)' ? EXIT_COLOR : PALETTE[ pidx % PALETTE.length ];
				drawNode( pg.page, node, nx, 'start', color );
			} );
		} );

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
				initFlowChart( data.path_flow );
				break;
			/* <fs_premium_only> */
			case 'click-map': initClickMap( data );  break;
			/* </fs_premium_only> */
		}
	} );

}() );
