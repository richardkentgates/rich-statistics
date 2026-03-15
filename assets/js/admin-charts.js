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
		'#6366f1', '#22d3ee', '#f59e0b', '#10b981',
		'#f43f5e', '#a78bfa', '#34d399', '#fb923c',
		'#38bdf8', '#e879f9', '#a3e635', '#fbbf24',
	];

	Chart.defaults.font.family  = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
	Chart.defaults.font.size    = 12;
	Chart.defaults.color        = '#64748b';
	Chart.defaults.borderColor  = 'rgba(100,116,139,0.12)';

	var GRID = {
		color: 'rgba(100,116,139,0.1)',
		drawBorder: false,
	};

	var TOOLTIP_DEFAULTS = {
		backgroundColor  : '#1e293b',
		titleColor       : '#f1f5f9',
		bodyColor        : '#cbd5e1',
		borderColor      : 'rgba(255,255,255,0.08)',
		borderWidth      : 1,
		padding          : 10,
		cornerRadius     : 6,
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
	// View: Click Map
	// ----------------------------------------------------------------

	function initClickMap( data ) {
		var el = canvasEl( 'rsa-chart-clicks' );
		if ( ! el || ! data ) { return; }

		var top = data.slice( 0, 15 );
		var rowLabels = top.map( function ( r ) {
			var label = r.tag || '';
			if ( r.id )    { label += '#' + r.id; }
			else if ( r.protocol ) { label += ' (' + r.protocol + ')'; }
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
			case 'click-map': initClickMap( data );  break;
		}
	} );

}() );
