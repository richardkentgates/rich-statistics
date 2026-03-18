/**
 * Rich Statistics PWA — app.js
 *
 * Vanilla JS, zero dependencies except the bundled Chart.js already loaded
 * by index.html.  All REST calls go to /wp-json/rsa/v1/* using WP Application
 * Passwords (Basic auth, base64 encoded).
 *
 * Multi-site storage (localStorage):
 *   rsa_sites   – JSON array of { id, label, siteUrl, credentials }
 *   rsa_active  – id of the currently active site
 *   rsa_period  – last-selected period string
 *
 * Adding a site (OTP two-step flow):
 *   1. Admin clicks "Generate App Code" on the WordPress Profile page
 *   2. In the app, tap the site switcher → "+ Add site"
 *   3. Enter site URL + 6-digit code → code is verified server-side
 *   4. Enter Application Password → connected
 *
 * Views: overview | pages | audience | referrers | behavior | clicks
 */

( function () {
	'use strict';

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------
	var state = {
		sites       : [],        // array of { id, label, siteUrl, credentials }
		activeId    : '',        // id of the currently active site
		siteUrl     : '',        // computed from active site
		credentials : '',        // computed: base64(user:app_pass)
		period      : '30d',
		view        : 'overview',
		charts      : {},        // keyed by canvas id
		cache       : {},        // keyed by endpoint+period
		navOpen     : false,
		_otpVerified: null,      // { siteUrl, username, siteLabel } after step 1
	};

	// -----------------------------------------------------------------------
	// Init
	// -----------------------------------------------------------------------
	document.addEventListener( 'DOMContentLoaded', function () {
		loadStoredSites();

		if ( state.siteUrl && state.credentials ) {
			renderSiteSwitcher();
			showApp();
			renderView( state.view );
		} else {
			showLogin();
		}

		// Welcome screen — "Add Your Site" button
		var gsBtn = document.getElementById( 'rsa-get-started-btn' );
		if ( gsBtn ) {
			gsBtn.addEventListener( 'click', function () { showAddSiteOverlay( null ); } );
		}

		bindNav();
		bindPeriodSelect();
		bindMenuToggle();
		bindSignOut();
		bindAddSite();
	} );

	// -----------------------------------------------------------------------
	// Multi-site storage
	// -----------------------------------------------------------------------

	function loadStoredSites() {
		// Migrate single-site legacy format
		var oldUrl  = localStorage.getItem( 'rsa_site_url' );
		var oldCred = localStorage.getItem( 'rsa_credentials' );
		if ( oldUrl && oldCred && ! localStorage.getItem( 'rsa_sites' ) ) {
			var migrated = [ {
				id         : uid(),
				label      : hostname( oldUrl ),
				siteUrl    : oldUrl,
				credentials: oldCred,
				pendingToken: null,
			} ];
			localStorage.setItem( 'rsa_sites',  JSON.stringify( migrated ) );
			localStorage.setItem( 'rsa_active', migrated[0].id );
			localStorage.removeItem( 'rsa_site_url' );
			localStorage.removeItem( 'rsa_credentials' );
		}

		state.sites    = JSON.parse( localStorage.getItem( 'rsa_sites' ) || '[]' );
		state.activeId = localStorage.getItem( 'rsa_active' ) || '';
		state.period   = localStorage.getItem( 'rsa_period' ) || '30d';
		syncActiveState();
	}

	/** Compute siteUrl / credentials from the active site entry. */
	function syncActiveState() {
		var site = state.sites.find( function ( s ) { return s.id === state.activeId; } );
		if ( ! site && state.sites.length ) {
			site           = state.sites[0];
			state.activeId = site.id;
		}
		state.siteUrl     = site ? site.siteUrl     : '';
		state.credentials = site ? site.credentials : '';
		state.cache       = {};
	}

	function setActiveSite( id ) {
		state.activeId = id;
		localStorage.setItem( 'rsa_active', id );
		syncActiveState();
		renderSiteSwitcher();
	}

	/** Save a new site after a successful connection test.  Returns the site object. */
	function persistSite( siteUrl, username, appPassword, label ) {
		siteUrl = siteUrl.replace( /\/$/, '' );
		var site = {
			id          : uid(),
			label       : label || hostname( siteUrl ),
			siteUrl     : siteUrl,
			credentials : btoa( username + ':' + appPassword ),
		};
		state.sites.push( site );
		localStorage.setItem( 'rsa_sites', JSON.stringify( state.sites ) );
		state.activeId = site.id;
		localStorage.setItem( 'rsa_active', site.id );
		syncActiveState();
		return site;
	}

	function removeSite( id ) {
		state.sites = state.sites.filter( function ( s ) { return s.id !== id; } );
		localStorage.setItem( 'rsa_sites', JSON.stringify( state.sites ) );
		if ( state.activeId === id ) {
			state.activeId = state.sites.length ? state.sites[0].id : '';
			localStorage.setItem( 'rsa_active', state.activeId );
		}
		syncActiveState();
	}

	function clearAllSites() {
		state.sites       = [];
		state.activeId    = '';
		state.siteUrl     = '';
		state.credentials = '';
		state.cache       = {};
		localStorage.removeItem( 'rsa_sites' );
		localStorage.removeItem( 'rsa_active' );
	}

	function uid() {
		return Math.random().toString( 36 ).slice( 2, 10 ) + Date.now().toString( 36 );
	}

	function hostname( url ) {
		try { return new URL( url ).hostname; } catch ( _ ) { return url; }
	}

	// -----------------------------------------------------------------------
	// API
	// -----------------------------------------------------------------------
	function apiGet( endpoint, params ) {
		var url = state.siteUrl + '/wp-json/rsa/v1/' + endpoint;
		var qs  = [];
		if ( params ) {
			Object.keys( params ).forEach( function ( k ) {
				qs.push( encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] ) );
			} );
		}
		if ( qs.length ) url += '?' + qs.join( '&' );

		var cacheKey = url;
		if ( state.cache[ cacheKey ] ) {
			return Promise.resolve( state.cache[ cacheKey ] );
		}

		return fetch( url, {
			method : 'GET',
			headers: {
				'Authorization': 'Basic ' + state.credentials,
				'Accept'       : 'application/json',
			},
		} ).then( function ( res ) {
			if ( res.status === 401 || res.status === 403 ) {
				throw new Error( 'auth' );
			}
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} ).then( function ( json ) {
			// Unwrap REST API envelope: { ok: true, data: ... } → raw data
			var data = ( json && typeof json === 'object' && json.ok === true && 'data' in json )
				? json.data
				: json;
			state.cache[ cacheKey ] = data;
			return data;
		} );
	}

	// -----------------------------------------------------------------------
	// Login (welcome screen — shown when no sites are connected)
	// -----------------------------------------------------------------------
	function showLogin() {
		document.getElementById( 'rsa-login' ).hidden = false;
		document.getElementById( 'rsa-add-site' ).hidden = true;
		document.getElementById( 'rsa-app' ).hidden = true;
	}

	function showApp() {
		document.getElementById( 'rsa-login' ).hidden    = true;
		document.getElementById( 'rsa-add-site' ).hidden = true;
		document.getElementById( 'rsa-app' ).hidden      = false;

		var sel = document.getElementById( 'rsa-period-select' );
		sel.value = state.period;
	}

	// -----------------------------------------------------------------------
	// Site switcher (nav dropdown)
	// -----------------------------------------------------------------------
	function renderSiteSwitcher() {
		var label = document.getElementById( 'rsa-active-label' );
		var menu  = document.getElementById( 'rsa-site-menu' );
		if ( ! label || ! menu ) return;

		var active = state.sites.find( function ( s ) { return s.id === state.activeId; } );
		label.textContent = active ? active.label : '—';

		var items = state.sites.map( function ( s ) {
			var cls = 'rsa-site-menu-item' + ( s.id === state.activeId ? ' rsa-active' : '' );
			return '<div class="' + cls + '" data-id="' + esc( s.id ) + '">' +
				'<span class="rsa-site-menu-label">' + esc( s.label ) + '</span>' +
				'<button class="rsa-site-menu-remove" data-remove-id="' + esc( s.id ) + '" ' +
				        'title="Remove site" aria-label="Remove ' + esc( s.label ) + '">&times;</button>' +
				'</div>';
		} ).join( '' );

		menu.innerHTML = items + '<button class="rsa-site-menu-add" id="rsa-site-menu-add-btn">+ Add site</button>';

		menu.querySelectorAll( '.rsa-site-menu-item' ).forEach( function ( el ) {
			el.addEventListener( 'click', function ( e ) {
				if ( e.target.dataset.removeId ) return;
				var id = this.dataset.id;
				if ( id !== state.activeId ) {
					setActiveSite( id );
					destroyCharts();
					renderView( state.view );
				}
				menu.hidden = true;
			} );
		} );

		menu.querySelectorAll( '.rsa-site-menu-remove' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var id       = this.dataset.removeId;
				var wasActive = ( id === state.activeId );
				if ( ! confirm( 'Remove this site from the app?' ) ) return;
				removeSite( id );
				renderSiteSwitcher();
				if ( wasActive ) {
					destroyCharts();
					if ( state.siteUrl ) {
						renderView( state.view );
					} else {
						showLogin();
					}
				}
				menu.hidden = true;
			} );
		} );

		var addBtn = menu.querySelector( '#rsa-site-menu-add-btn' );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				menu.hidden = true;
				showAddSiteOverlay( null );
			} );
		}
	}

	function bindSiteSwitcher() {
		var btn  = document.getElementById( 'rsa-switcher-btn' );
		var menu = document.getElementById( 'rsa-site-menu' );
		if ( ! btn || ! menu ) return;

		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			menu.hidden = ! menu.hidden;
		} );
		document.addEventListener( 'click', function () {
			if ( menu ) menu.hidden = true;
		} );
	}

	function destroyCharts() {
		Object.keys( state.charts ).forEach( function ( id ) {
			state.charts[ id ].destroy();
		} );
		state.charts = {};
	}

	// -----------------------------------------------------------------------
	// Add Site overlay (OTP two-step: verify code → enter App Password)
	// -----------------------------------------------------------------------
	function showAddSiteOverlay( prefill ) {
		var overlay = document.getElementById( 'rsa-add-site' );
		if ( ! overlay ) return;

		// Reset to step 1
		var step1 = document.getElementById( 'rsa-add-step-1' );
		var step2 = document.getElementById( 'rsa-add-step-2' );
		if ( step1 ) step1.hidden = false;
		if ( step2 ) step2.hidden = true;

		var urlField    = document.getElementById( 'rsa-add-site-url' );
		var otpField    = document.getElementById( 'rsa-add-otp' );
		var otpErr      = document.getElementById( 'rsa-add-otp-error' );
		var addErr      = document.getElementById( 'rsa-add-error' );
		var verifyBtn   = document.getElementById( 'rsa-add-verify-btn' );

		if ( urlField  ) { urlField.value = ''; urlField.readOnly = false; }
		if ( otpField  ) { otpField.value = ''; }
		if ( otpErr    ) { otpErr.textContent = ''; }
		if ( addErr    ) { addErr.textContent = ''; }
		if ( verifyBtn ) { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify Code'; }

		state._otpVerified = null;

		document.getElementById( 'rsa-login' ).hidden = true;
		document.getElementById( 'rsa-app' ).hidden   = true;
		overlay.hidden = false;
		if ( urlField ) urlField.focus();
	}

	function hideAddSiteOverlay() {
		var overlay = document.getElementById( 'rsa-add-site' );
		if ( overlay ) overlay.hidden = true;
		if ( state.sites.length > 0 ) {
			document.getElementById( 'rsa-app' ).hidden = false;
		} else {
			showLogin();
		}
	}

	function bindAddSite() {
		var verifyBtn  = document.getElementById( 'rsa-add-verify-btn' );
		var cancelBtn  = document.getElementById( 'rsa-add-cancel-btn' );
		var backBtn    = document.getElementById( 'rsa-add-back-btn' );
		var confirmBtn = document.getElementById( 'rsa-add-confirm-btn' );
		var otpErr     = document.getElementById( 'rsa-add-otp-error' );
		var addErr     = document.getElementById( 'rsa-add-error' );

		// ---- Step 1: Verify OTP -----------------------------------------
		if ( verifyBtn ) {
			verifyBtn.addEventListener( 'click', function () {
				var siteUrl = ( ( document.getElementById( 'rsa-add-site-url' ) || {} ).value || '' ).trim();
				var otp     = ( ( document.getElementById( 'rsa-add-otp' )      || {} ).value || '' ).replace( /\D/g, '' );

				if ( otpErr ) otpErr.textContent = '';

				var urlObj;
				try { urlObj = new URL( siteUrl ); } catch ( _ ) { urlObj = null; }
				if ( ! urlObj || ( urlObj.protocol !== 'https:' && urlObj.protocol !== 'http:' ) ) {
						if ( otpErr ) { otpErr.textContent = 'Please enter a valid URL (including https://).'; }
					return;
				}
				if ( otp.length !== 6 ) {
						if ( otpErr ) { otpErr.textContent = 'Please enter the 6-digit code from your profile.'; }
					return;
				}

				verifyBtn.disabled    = true;
				verifyBtn.textContent = 'Verifying…';

				var base = siteUrl.replace( /\/$/, '' );
				fetch( base + '/wp-json/rsa/v1/verify-otp', {
					method : 'POST',
					headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
					body   : JSON.stringify( { otp: otp } ),
				} )
				.then( function ( res ) {
					return res.json().then( function ( data ) { return { status: res.status, data: data }; } );
				} )
				.then( function ( r ) {
					var payload = r.data && r.data.data;
					if ( ! payload || ! payload.verified ) {
						throw new Error( ( r.data && r.data.message ) || 'Invalid or expired code. Please generate a new one from your WordPress profile.' );
					}
					state._otpVerified = {
						siteUrl  : payload.site_url,
						username : payload.username,
						siteLabel: payload.site_label,
					};
					var labelEl = document.getElementById( 'rsa-add-site-label' );
					var usrEl   = document.getElementById( 'rsa-add-username' );
					var pwdEl   = document.getElementById( 'rsa-add-app-pass' );
					if ( labelEl ) labelEl.value = payload.site_url;
					if ( usrEl   ) usrEl.value   = payload.username;
					if ( pwdEl   ) { pwdEl.value = ''; pwdEl.focus(); }
					document.getElementById( 'rsa-add-step-1' ).hidden = true;
					document.getElementById( 'rsa-add-step-2' ).hidden = false;
				} )
				.catch( function ( err ) {
					if ( otpErr ) { otpErr.textContent = err.message; }
					verifyBtn.disabled    = false;
					verifyBtn.textContent = 'Verify Code';
				} );
			} );
		}

		// ---- Back (step 2 → step 1) ----------------------------------------
		if ( backBtn ) {
			backBtn.addEventListener( 'click', function () {
				document.getElementById( 'rsa-add-step-1' ).hidden = false;
				document.getElementById( 'rsa-add-step-2' ).hidden = true;
				state._otpVerified = null;
				var verifyBtnEl = document.getElementById( 'rsa-add-verify-btn' );
				if ( verifyBtnEl ) { verifyBtnEl.disabled = false; verifyBtnEl.textContent = 'Verify Code'; }
			} );
		}

		// ---- Cancel ---------------------------------------------------------
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () { hideAddSiteOverlay(); } );
		}

		// ---- Step 2: Connect with Application Password ----------------------
		if ( ! confirmBtn ) return;

		confirmBtn.addEventListener( 'click', function () {
			var appPass = ( ( document.getElementById( 'rsa-add-app-pass' ) || {} ).value || '' ).trim();

			if ( addErr ) addErr.textContent = '';

			if ( ! appPass ) {
				if ( addErr ) { addErr.textContent = 'Application Password is required.'; }
				return;
			}

			var pending  = state._otpVerified || {};
			var siteUrl  = pending.siteUrl  || '';
			var username = pending.username || '';
			var label    = pending.siteLabel || '';

			if ( ! siteUrl || ! username ) {
				if ( addErr ) { addErr.textContent = 'Session expired. Please start over.'; }
				return;
			}

			confirmBtn.disabled    = true;
			confirmBtn.textContent = 'Connecting…';

			var prevUrl  = state.siteUrl;
			var prevCred = state.credentials;
			state.siteUrl     = siteUrl;
			state.credentials = btoa( username + ':' + appPass );
			state.cache       = {};

			apiGet( 'overview', { period: '7d' } ).then( function () {
				persistSite( siteUrl, username, appPass, label );
				state._otpVerified = null;
				renderSiteSwitcher();
				hideAddSiteOverlay();
				destroyCharts();
				renderView( state.view );
			} ).catch( function ( err ) {
				state.siteUrl     = prevUrl;
				state.credentials = prevCred;
				state.cache       = {};
				var msg = err.message === 'auth'
					? 'Authentication failed. Check your Application Password.'
					: 'Could not reach the site. Please try again.';
				if ( addErr ) { addErr.textContent = msg; }
				confirmBtn.disabled    = false;
				confirmBtn.textContent = 'Connect';
			} );
		} );
	}

	// -----------------------------------------------------------------------
	// Navigation
	// -----------------------------------------------------------------------
	function bindNav() {
		document.querySelectorAll( '.rsa-nav-link' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				switchView( this.dataset.view );
				closeNav();
			} );
		} );
		bindSiteSwitcher();
	}

	function switchView( view ) {
		// Deactivate old view
		var oldEl = document.getElementById( 'rsa-view-' + state.view );
		if ( oldEl ) oldEl.hidden = true;

		// Deactivate old nav link
		var oldLink = document.querySelector( '.rsa-nav-link.rsa-active' );
		if ( oldLink ) oldLink.classList.remove( 'rsa-active' );

		state.view = view;

		// Activate new view
		var newEl = document.getElementById( 'rsa-view-' + view );
		if ( newEl ) newEl.hidden = false;

		// Activate new nav link
		var newLink = document.querySelector( '.rsa-nav-link[data-view="' + view + '"]' );
		if ( newLink ) newLink.classList.add( 'rsa-active' );

		// Update top bar title
		var titles = {
			overview   : 'Overview',
			pages      : 'Top Pages',
			audience   : 'Audience',
			referrers  : 'Referrers',
			behavior   : 'Behavior',
			campaigns  : 'Campaigns',
			'user-flow': 'User Flow',
			clicks     : 'Click Tracking',
		};
		document.getElementById( 'rsa-view-title' ).textContent = titles[ view ] || view;

		renderView( view );
	}

	function bindPeriodSelect() {
		document.getElementById( 'rsa-period-select' ).addEventListener( 'change', function () {
			state.period = this.value;
			localStorage.setItem( 'rsa_period', state.period );
			state.cache = {};  // invalidate on period change
			renderView( state.view );
		} );
	}

	function bindMenuToggle() {
		document.getElementById( 'rsa-menu-toggle' ).addEventListener( 'click', function () {
			toggleNav();
		} );
		// Close nav when clicking outside of it on mobile
		document.getElementById( 'rsa-main' ).addEventListener( 'click', function () {
			if ( state.navOpen ) closeNav();
		} );
	}

	function toggleNav() {
		state.navOpen = ! state.navOpen;
		document.getElementById( 'rsa-nav' ).classList.toggle( 'rsa-nav-open', state.navOpen );
	}

	function closeNav() {
		state.navOpen = false;
		document.getElementById( 'rsa-nav' ).classList.remove( 'rsa-nav-open' );
	}

	function bindSignOut() {
		document.getElementById( 'rsa-signout' ).addEventListener( 'click', function () {
			clearAllSites();
			destroyCharts();
			showLogin();
		} );
	}

	// -----------------------------------------------------------------------
	// View renderer
	// -----------------------------------------------------------------------
	function renderView( view ) {
		var container = document.getElementById( 'rsa-view-' + view );
		if ( ! container ) return;

		setLoading( true );

		switch ( view ) {
			case 'overview'  : renderOverview( container );   break;
			case 'pages'     : renderPages( container );      break;
			case 'audience'  : renderAudience( container );   break;
			case 'referrers' : renderReferrers( container );  break;
			case 'behavior'  : renderBehavior( container );   break;
			case 'campaigns' : renderCampaigns( container );  break;
			case 'user-flow' : renderUserFlow( container );   break;
			case 'clicks'    : renderClicks( container );     break;
			default: setLoading( false );
		}
	}

	function setLoading( on ) {
		document.getElementById( 'rsa-loading' ).hidden = ! on;
	}

	// -----------------------------------------------------------------------
	// Overview
	// -----------------------------------------------------------------------
	function renderOverview( container ) {
		apiGet( 'overview', { period: state.period } ).then( function ( data ) {
			container.innerHTML = tmplKpiGrid( [
				{ label: 'Pageviews',    value: fmt( data.pageviews )    },
				{ label: 'Sessions',     value: fmt( data.sessions )     },
				{ label: 'Avg. Time',    value: fmtSecs( data.avg_time ) },
				{ label: 'Bounce Rate',  value: fmtPct( data.bounce_rate ) },
			] ) + '<div class="rsa-chart-wrap"><canvas id="c-overview-daily"></canvas></div>';

			setLoading( false );
			drawLine( 'c-overview-daily', data.daily.map( function ( d ) { return d.date; } ),
				[{ label: 'Pageviews', data: data.daily.map( function ( d ) { return d.pageviews; } ) }] );
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// Pages
	// -----------------------------------------------------------------------
	function renderPages( container ) {
		apiGet( 'pages', { period: state.period } ).then( function ( data ) {
			var rows = data.pages.map( function ( p, i ) {
				return '<tr><td>' + ( i + 1 ) + '</td><td class="rsa-td-path">' +
					esc( p.page ) + '</td><td>' + fmt( p.pageviews ) + '</td><td>' +
					fmtSecs( p.avg_time ) + '</td></tr>';
			} );
			container.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-pages-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>#</th><th>Page</th><th>Views</th><th>Avg Time</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			setLoading( false );
			var top = data.pages.slice( 0, 10 );
			drawBar( 'c-pages-bar',
				top.map( function ( p ) { return truncate( p.page, 40 ); } ),
				top.map( function ( p ) { return p.pageviews; } ),
				'Pageviews',
				true   // horizontal
			);
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// Audience
	// -----------------------------------------------------------------------
	function renderAudience( container ) {
		apiGet( 'audience', { period: state.period } ).then( function ( data ) {
			container.innerHTML =
				'<div class="rsa-grid-2">' +
				'<div class="rsa-chart-card"><h3>Operating System</h3>' +
				'<canvas id="c-aud-os"></canvas></div>' +
				'<div class="rsa-chart-card"><h3>Browser</h3>' +
				'<canvas id="c-aud-br"></canvas></div>' +
				'<div class="rsa-chart-card"><h3>Viewport</h3>' +
				'<canvas id="c-aud-vp"></canvas></div>' +
				'<div class="rsa-chart-card"><h3>Language</h3>' +
				'<canvas id="c-aud-lang"></canvas></div>' +
				'</div>' +
				'<div class="rsa-chart-card"><h3>Timezone</h3>' +
				'<canvas id="c-aud-tz"></canvas></div>';

			setLoading( false );
			drawDoughnut( 'c-aud-os',   kvLabels( data.by_os ),   kvValues( data.by_os ) );
			drawDoughnut( 'c-aud-br',   kvLabels( data.by_browser ), kvValues( data.by_browser ) );
			drawDoughnut( 'c-aud-vp',   kvLabels( data.by_viewport ), kvValues( data.by_viewport ) );
			drawDoughnut( 'c-aud-lang', kvLabels( data.by_language ), kvValues( data.by_language ) );
			drawBar( 'c-aud-tz', kvLabels( data.by_timezone ), kvValues( data.by_timezone ), 'Sessions', true );
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// Referrers
	// -----------------------------------------------------------------------
	function renderReferrers( container ) {
		apiGet( 'referrers', { period: state.period } ).then( function ( data ) {
			var rows = data.referrers.map( function ( r ) {
				return '<tr><td>' + esc( r.domain || '(direct)' ) + '</td><td>' + fmt( r.pageviews ) + '</td></tr>';
			} );
			container.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-ref-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>Source</th><th>Pageviews</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			setLoading( false );
			var top = data.referrers.slice( 0, 10 );
			drawBar( 'c-ref-bar',
				top.map( function ( r ) { return r.domain || '(direct)'; } ),
				top.map( function ( r ) { return r.pageviews; } ),
				'Pageviews',
				true
			);
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// Behavior
	// -----------------------------------------------------------------------
	function renderBehavior( container ) {
		apiGet( 'behavior', { period: state.period } ).then( function ( data ) {
			container.innerHTML =
				'<div class="rsa-grid-2">' +
				'<div class="rsa-chart-card"><h3>Time on Page</h3>' +
				'<canvas id="c-beh-time"></canvas></div>' +
				'<div class="rsa-chart-card"><h3>Session Depth</h3>' +
				'<canvas id="c-beh-depth"></canvas></div>' +
				'</div>' +
				'<div class="rsa-table-card"><h3>Top Entry Pages</h3>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>Page</th><th>Entries</th></tr></thead>' +
				'<tbody>' + data.entry_pages.map( function ( p ) {
					return '<tr><td class="rsa-td-path">' + esc( p.page ) + '</td><td>' + fmt( p.count ) + '</td></tr>';
				} ).join( '' ) +
				'</tbody></table></div></div>';

			setLoading( false );
			// Time histogram
			drawBar( 'c-beh-time',
				data.time_histogram.map( function ( b ) { return b.bucket; } ),
				data.time_histogram.map( function ( b ) { return b.count; } ),
				'Sessions',
				false
			);
			// Session depth doughnut
			drawDoughnut( 'c-beh-depth',
				data.session_depth.map( function ( b ) { return b.bucket; } ),
				data.session_depth.map( function ( b ) { return b.count; } )
			);
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// Campaigns
	// -----------------------------------------------------------------------
	function renderCampaigns( container ) {
		apiGet( 'campaigns', { period: state.period } ).then( function ( data ) {
			var rows = data.campaigns.map( function ( c, i ) {
				return '<tr>' +
					'<td>' + ( i + 1 ) + '</td>' +
					'<td>' + esc( c.source   || '—' ) + '</td>' +
					'<td>' + esc( c.medium   || '—' ) + '</td>' +
					'<td class="rsa-td-text">' + esc( c.campaign || '—' ) + '</td>' +
					'<td>' + fmt( c.sessions )   + '</td>' +
					'<td>' + fmt( c.pageviews )  + '</td>' +
					'</tr>';
			} );

			if ( ! rows.length ) {
				container.innerHTML = '<p class="rsa-empty">No campaign data for this period.<br>' +
					'Add <code>utm_source</code>, <code>utm_medium</code>, and <code>utm_campaign</code> to your links.</p>';
				setLoading( false );
				return;
			}

			container.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-camp-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>#</th><th>Source</th><th>Medium</th><th>Campaign</th><th>Sessions</th><th>Pageviews</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			setLoading( false );
			var top = data.campaigns.slice( 0, 10 );
			drawBar( 'c-camp-bar',
				top.map( function ( c ) { return truncate( ( c.campaign || c.source || '?' ), 36 ); } ),
				top.map( function ( c ) { return c.sessions; } ),
				'Sessions',
				true
			);
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// User Flow
	// -----------------------------------------------------------------------
	function renderUserFlow( container ) {
		apiGet( 'user-flow', { period: state.period } ).then( function ( data ) {
			var steps = data.steps;
			var stepNums = Object.keys( steps ).map( Number ).sort( function ( a, b ) { return a - b; } );

			if ( ! stepNums.length ) {
				container.innerHTML = '<p class="rsa-empty">No path data for this period.</p>';
				setLoading( false );
				return;
			}

			var total = data.total_sessions || 0;
			var html  = '';

			stepNums.forEach( function ( sn ) {
				var nodes = steps[ sn ];
				var label = sn === 1 ? 'Entry' : 'Step ' + sn;
				var stepTotal = nodes.reduce( function ( s, n ) { return s + ( n.page === '(exit)' ? 0 : n.sessions ); }, 0 );

				html += '<div class="rsa-uf-step">' +
					'<div class="rsa-uf-step-hd">' +
						'<span class="rsa-uf-step-label">' + label + '</span>' +
						( total ? '<span class="rsa-uf-step-pct">' + fmtPct( stepTotal / total ) + ' retained</span>' : '' ) +
					'</div>' +
					'<table class="rsa-table rsa-uf-table">' +
					'<thead><tr><th>Page</th><th>Sessions</th></tr></thead><tbody>';

				nodes.forEach( function ( n ) {
					var isExit = n.page === '(exit)';
					html += '<tr' + ( isExit ? ' class="rsa-uf-exit"' : '' ) + '>' +
						'<td class="rsa-td-path">' + esc( n.page ) + '</td>' +
						'<td>' + fmt( n.sessions ) + '</td>' +
						'</tr>';
				} );

				html += '</tbody></table></div>';
			} );

			container.innerHTML = '<div class="rsa-uf-wrap">' + html + '</div>';
			setLoading( false );
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// Clicks (premium)
	// -----------------------------------------------------------------------
	function renderClicks( container ) {
		apiGet( 'clicks', { period: state.period } ).then( function ( data ) {
			if ( data.premium_required ) {
				container.innerHTML = '<div class="rsa-premium-notice">' +
					'<p>Click map data requires a Rich Statistics Premium licence.</p></div>';
				setLoading( false );
				return;
			}
			var rows = data.clicks.map( function ( c ) {
				return '<tr>' +
					'<td>' + esc( c.href_protocol ) + '</td>' +
					'<td class="rsa-td-text">' + esc( c.href_value || '—' ) + '</td>' +
					'<td>' + esc( c.element_tag ) + '</td>' +
					'<td>' + esc( c.element_text ) + '</td>' +
					'<td>' + fmt( c.count ) + '</td></tr>';
			} );
			container.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-click-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>Protocol</th><th>Destination</th><th>Tag</th><th>Text</th><th>Clicks</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			setLoading( false );
			var top = data.clicks.slice( 0, 10 );
			drawBar( 'c-click-bar',
				top.map( function ( c ) { return truncate( c.href_value || c.element_text || c.href_protocol, 30 ); } ),
				top.map( function ( c ) { return c.count; } ),
				'Clicks',
				true
			);
		} ).catch( handleApiError );
	}

	// -----------------------------------------------------------------------
	// Error handler
	// -----------------------------------------------------------------------
	function handleApiError( err ) {
		setLoading( false );
		if ( err.message === 'auth' ) {
			clearAllSites();
			showLogin();
		}
		// Offline — show stale content via service worker cache (already painted)
	}

	// -----------------------------------------------------------------------
	// Chart helpers (thin wrappers around Chart.js 4.x)
	// -----------------------------------------------------------------------
	var PALETTE = [
		'#6366f1','#f59e0b','#10b981','#ef4444','#3b82f6',
		'#8b5cf6','#ec4899','#14b8a6','#f97316','#84cc16',
		'#64748b','#a78bfa',
	];

	function resolveCanvas( id ) {
		var canvas = document.getElementById( id );
		if ( ! canvas ) return null;
		if ( state.charts[ id ] ) {
			state.charts[ id ].destroy();
			delete state.charts[ id ];
		}
		return canvas;
	}

	function drawLine( id, labels, datasets ) {
		var canvas = resolveCanvas( id );
		if ( ! canvas ) return;
		state.charts[ id ] = new Chart( canvas, {
			type: 'line',
			data: {
				labels: labels,
				datasets: datasets.map( function ( ds, i ) {
					return {
						label          : ds.label,
						data           : ds.data,
						borderColor    : PALETTE[ i % PALETTE.length ],
						backgroundColor: PALETTE[ i % PALETTE.length ] + '33',
						fill           : true,
						tension        : 0.3,
						pointRadius    : 2,
					};
				} ),
			},
			options: {
				responsive      : true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: datasets.length > 1 },
					tooltip: { mode: 'index', intersect: false },
				},
				scales: {
					y: { beginAtZero: true, ticks: { precision: 0 } },
				},
			},
		} );
	}

	function drawBar( id, labels, values, label, horizontal ) {
		var canvas = resolveCanvas( id );
		if ( ! canvas ) return;
		state.charts[ id ] = new Chart( canvas, {
			type: horizontal ? 'bar' : 'bar',
			data: {
				labels  : labels,
				datasets: [ {
					label          : label || 'Count',
					data           : values,
					backgroundColor: PALETTE.slice( 0, values.length ).map( function ( c ) { return c + 'cc'; } ),
					borderColor    : PALETTE.slice( 0, values.length ),
					borderWidth    : 1,
				} ],
			},
			options: {
				indexAxis       : horizontal ? 'y' : 'x',
				responsive      : true,
				maintainAspectRatio: false,
				plugins : { legend: { display: false } },
				scales  : {
					x: { beginAtZero: true, ticks: { precision: 0 } },
					y: { ticks: { font: { size: 11 } } },
				},
			},
		} );
	}

	function drawDoughnut( id, labels, values ) {
		var canvas = resolveCanvas( id );
		if ( ! canvas ) return;
		state.charts[ id ] = new Chart( canvas, {
			type: 'doughnut',
			data: {
				labels  : labels,
				datasets: [ {
					data           : values,
					backgroundColor: PALETTE.slice( 0, values.length ).map( function ( c ) { return c + 'dd'; } ),
					borderColor    : '#fff',
					borderWidth    : 2,
				} ],
			},
			options: {
				responsive         : true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						position: 'bottom',
						labels  : { boxWidth: 12, font: { size: 11 } },
					},
				},
			},
		} );
	}

	// -----------------------------------------------------------------------
	// Template helpers
	// -----------------------------------------------------------------------
	function tmplKpiGrid( items ) {
		return '<div class="rsa-kpi-grid">' +
			items.map( function ( item ) {
				return '<div class="rsa-kpi-card">' +
					'<div class="rsa-kpi-value">' + item.value + '</div>' +
					'<div class="rsa-kpi-label">' + item.label + '</div>' +
					'</div>';
			} ).join( '' ) +
			'</div>';
	}

	// -----------------------------------------------------------------------
	// Formatters
	// -----------------------------------------------------------------------
	function fmt( n ) {
		if ( n === null || n === undefined ) return '—';
		var num = parseInt( n, 10 );
		if ( num >= 1000000 ) return ( num / 1000000 ).toFixed( 1 ) + 'M';
		if ( num >= 1000 )    return ( num / 1000 ).toFixed( 1 ) + 'K';
		return num.toLocaleString();
	}

	function fmtSecs( s ) {
		if ( ! s ) return '—';
		var t = parseInt( s, 10 );
		if ( t < 60 ) return t + 's';
		return Math.floor( t / 60 ) + 'm ' + ( t % 60 ) + 's';
	}

	function fmtPct( n ) {
		if ( n === null || n === undefined ) return '—';
		return parseFloat( n ).toFixed( 1 ) + '%';
	}

	function esc( str ) {
		if ( ! str ) return '';
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function truncate( str, len ) {
		if ( ! str ) return '';
		return str.length > len ? str.slice( 0, len - 1 ) + '…' : str;
	}

	function kvLabels( obj ) {
		return obj ? Object.keys( obj )   : [];
	}

	function kvValues( obj ) {
		return obj ? Object.values( obj ) : [];
	}

} )();
