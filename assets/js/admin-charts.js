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
		'#4a90b8',  // primary calm blue
		'#6aaed6',  // lighter blue
		'#8ec6e0',  // soft sky
		'#2e6f8e',  // deeper slate-blue
		'#a8c8d8',  // pale steel
		'#3a7fa0',  // mid blue
		'#b5d5e5',  // lightest
		'#537b8e',  // blue-grey
		'#7ba8be',  // muted teal-blue
		'#c5dce8',  // near-white blue
		'#1d5570',  // dark anchor
		'#92b8cc',  // grey-blue
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
			backgroundColor : 'rgba(74,144,184,0.15)',
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
	// ----------------------------------------------------------------
	// View: User Flow — Path Explorer (Miller columns)
	// ----------------------------------------------------------------

	function initPathExplorer( pathData ) {
		var container = document.getElementById( 'rsa-flow-chart' );
		if ( ! container ) { return; }

		var steps    = ( pathData && pathData.steps ) ? pathData.steps : {};
		var links    = ( pathData && pathData.links  ) ? pathData.links  : [];
		var stepNums = Object.keys( steps ).map( Number ).sort( function ( a, b ) { return a - b; } );
		if ( ! stepNums.length ) { return; }

		// Build transition map: linkMap[stepNum][fromPage] = [{to, count}, ...] sorted desc
		var linkMap = {};
		links.forEach( function ( l ) {
			if ( ! linkMap[ l.step ] ) { linkMap[ l.step ] = {}; }
			if ( ! linkMap[ l.step ][ l.from ] ) { linkMap[ l.step ][ l.from ] = []; }
			linkMap[ l.step ][ l.from ].push( { to: l.to, count: l.count } );
		} );
		Object.keys( linkMap ).forEach( function ( sn ) {
			Object.keys( linkMap[ sn ] ).forEach( function ( pg ) {
				linkMap[ sn ][ pg ].sort( function ( a, b ) { return b.count - a.count; } );
			} );
		} );

		var numCols  = stepNums.length;
		var selected = new Array( numCols ).fill( null );
		var colEls   = [];

		// ------ Funnel summary bar ----------------------------------------
		// Compute per-step totals: step 1 = sum of sessions; subsequent steps
		// = sum of the "to" counts reachable from the selected path (we use the
		// raw step session totals as a drop-off funnel, similar to GA funnel).
		var stepTotals = [];
		stepNums.forEach( function ( sn ) {
			var arr = steps[ sn ] || [];
			var tot = arr.reduce( function ( s, p ) { return s + p.sessions; }, 0 );
			stepTotals.push( { step: sn, total: tot } );
		} );

		container.innerHTML = '';
		container.className = '';

		// Render funnel if we have at least 2 steps
		if ( stepTotals.length >= 2 ) {
			var maxTot   = stepTotals[ 0 ].total || 1;
			var funnel   = document.createElement( 'div' );
			funnel.className = 'rsa-funnel';

			stepTotals.forEach( function ( st, idx ) {
				var heightPct = Math.round( st.total / maxTot * 100 );
				var dropPct   = idx === 0
					? 100
					: ( maxTot > 0 ? Math.round( st.total / maxTot * 100 ) : 0 );

				var step = document.createElement( 'div' );
				step.className = 'rsa-funnel-step';

				var bg = document.createElement( 'div' );
				bg.className  = 'rsa-funnel-step-bg';
				bg.style.height = heightPct + '%';
				step.appendChild( bg );

				var lbl = document.createElement( 'div' );
				lbl.className   = 'rsa-funnel-step-label';
				lbl.textContent = idx === 0 ? 'Entry' : ( 'Step ' + ( idx + 1 ) );
				step.appendChild( lbl );

				var cnt = document.createElement( 'div' );
				cnt.className   = 'rsa-funnel-step-count';
				cnt.textContent = st.total.toLocaleString();
				step.appendChild( cnt );

				var pctEl = document.createElement( 'div' );
				pctEl.className   = 'rsa-funnel-step-pct' + ( dropPct < 50 ? ' is-drop' : '' );
				pctEl.textContent = idx === 0 ? '100%' : ( dropPct + '% of entry' );
				step.appendChild( pctEl );

				funnel.appendChild( step );
			} );

			container.appendChild( funnel );
		}

		// ------ Explorer columns ------------------------------------------
		var explorer = document.createElement( 'div' );
		explorer.className = 'rsa-explorer';
		container.appendChild( explorer );

		// Remap colEls to use the inner explorer div
		// (colEls will be populated below)

		// Build skeleton
		var explorerContainer = explorer;

		for ( var i = 0; i < numCols; i++ ) {
			var col = document.createElement( 'div' );
			col.className = 'rsa-explorer-col';

			var hdr = document.createElement( 'div' );
			hdr.className   = 'rsa-explorer-col-hdr';
			hdr.textContent = i === 0 ? 'Entry Page' : ( 'Step ' + ( i + 1 ) );
			col.appendChild( hdr );

			var list = document.createElement( 'div' );
			list.className = 'rsa-explorer-col-list';
			col.appendChild( list );

			explorerContainer.appendChild( col );
			colEls.push( list );
		}

		function renderCol( colIdx, pageList, colTotal ) {
			var list = colEls[ colIdx ];
			list.innerHTML = '';

			if ( ! pageList || ! pageList.length ) {
				for ( var j = colIdx + 1; j < numCols; j++ ) {
					colEls[ j ].innerHTML = '';
					selected[ j ] = null;
				}
				return;
			}

			pageList.forEach( function ( pg ) {
				var isExit   = pg.page === '(exit)';
				var isActive = selected[ colIdx ] === pg.page;
				var pct      = colTotal > 0 ? Math.round( pg.count / colTotal * 100 ) : 0;
				var hasNext  = ! isExit && colIdx + 1 < numCols;

				var item = document.createElement( 'div' );
				item.className = 'rsa-explorer-item'
					+ ( isActive ? ' is-selected' : '' )
					+ ( isExit   ? ' is-exit'     : '' )
					+ ( hasNext  ? ' is-clickable' : '' );

				var bar = document.createElement( 'div' );
				bar.className  = 'rsa-explorer-item-bar';
				bar.style.width = pct + '%';
				item.appendChild( bar );

				var lbl = document.createElement( 'span' );
				lbl.className   = 'rsa-explorer-item-label';
				lbl.textContent = pg.page;
				item.appendChild( lbl );

				var meta = document.createElement( 'span' );
				meta.className   = 'rsa-explorer-item-meta';
				meta.textContent = pg.count.toLocaleString() + '\u00a0(' + pct + '%)';
				item.appendChild( meta );

				if ( hasNext ) {
					var arr = document.createElement( 'span' );
					arr.className   = 'rsa-explorer-item-arrow';
					arr.textContent = '\u203a';
					item.appendChild( arr );

					item.addEventListener( 'click', ( function ( page, ci, pages, tot ) {
						return function () {
							selected[ ci ] = page;
							renderCol( ci, pages, tot );
							cascade( ci );
						};
					}( pg.page, colIdx, pageList, colTotal ) ) );
				}

				list.appendChild( item );
			} );
		}

		// Cascade: starting from colIdx, auto-select top non-exit page and
		// populate every column to the right until there is no more data.
		function cascade( fromColIdx ) {
			for ( var c = fromColIdx; c < numCols - 1; c++ ) {
				var selPage = selected[ c ];
				if ( ! selPage ) {
					for ( var cc = c + 1; cc < numCols; cc++ ) {
						colEls[ cc ].innerHTML = '';
						selected[ cc ] = null;
					}
					break;
				}
				var sn       = stepNums[ c ];
				var outLinks = linkMap[ sn ] && linkMap[ sn ][ selPage ];
				if ( outLinks && outLinks.length ) {
					var nTot   = outLinks.reduce( function ( s, l ) { return s + l.count; }, 0 );
					var nPages = outLinks.map( function ( l ) { return { page: l.to, count: l.count }; } );
					// Auto-select the top non-exit page in the next column
					var topNext = null;
					for ( var k = 0; k < nPages.length; k++ ) {
						if ( nPages[ k ].page !== '(exit)' ) { topNext = nPages[ k ].page; break; }
					}
					selected[ c + 1 ] = topNext;
					renderCol( c + 1, nPages, nTot );
				} else {
					for ( var cd = c + 1; cd < numCols; cd++ ) {
						colEls[ cd ].innerHTML = '';
						selected[ cd ] = null;
					}
					break;
				}
			}
		}

		// Populate column 0, pre-select top entry page, then cascade all columns
		var step1     = steps[ stepNums[ 0 ] ] || [];
		var step1Tot  = step1.reduce( function ( s, p ) { return s + p.sessions; }, 0 );
		var col0Pages = step1.map( function ( p ) { return { page: p.page, count: p.sessions }; } );

		var topEntry = null;
		for ( var ei = 0; ei < col0Pages.length; ei++ ) {
			if ( col0Pages[ ei ].page !== '(exit)' ) { topEntry = col0Pages[ ei ].page; break; }
		}
		selected[ 0 ] = topEntry;
		renderCol( 0, col0Pages, step1Tot );
		cascade( 0 );
	}

	/* <fs_premium_only> */

	// ----------------------------------------------------------------
	// View: Click Tracking
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
				initPathExplorer( data.path_flow );
				break;
			/* <fs_premium_only> */
			case 'click-map': initClickMap( data );  break;
			/* </fs_premium_only> */
		}
	} );

}() );
