/**
 * Rich Statistics PWA — app.js
 *
 * Vanilla JS, zero dependencies except the bundled Chart.js already loaded
 * by index.html.  All REST calls go to /wp-json/rsa/v1/* using WP Application
 * Passwords (Basic auth, base64 encoded).
 *
 * Multi-site storage (localStorage):
 *   rsa_sites   – JSON array of { id, label, siteUrl, credentials, pendingToken }
 *   rsa_active  – id of the currently active site
 *   rsa_period  – last-selected period string
 *
 * Adding a site:
 *   1. Click "Add This Site to App" on any WP profile page  →  downloads a .rsasite file
 *   2. Open app, tap the site switcher  →  "+ Add site"
 *   3. Import the .rsasite file  →  enter App Password  →  done
 *
 * Views: overview | pages | audience | referrers | behavior | clicks
 */

( function () {
	'use strict';

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------
	var state = {
		sites      : [],        // array of { id, label, siteUrl, credentials, pendingToken }
		activeId   : '',        // id of the currently active site
		siteUrl    : '',        // computed from active site
		credentials: '',        // computed: base64(user:app_pass)
		period     : '30d',
		view       : 'overview',
		charts     : {},        // keyed by canvas id
		cache      : {},        // keyed by endpoint+period
		navOpen    : false,
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

		bindLoginForm();
		bindImportFile();
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
	function persistSite( siteUrl, username, appPassword, label, pendingToken ) {
		siteUrl = siteUrl.replace( /\/$/, '' );
		var site = {
			id          : uid(),
			label       : label || hostname( siteUrl ),
			siteUrl     : siteUrl,
			credentials : btoa( username + ':' + appPassword ),
			pendingToken: pendingToken || null,
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

	/**
	 * Consume the single-use install token embedded in a .rsasite file.
	 * Called once after the first successful API call for a new site.
	 * Silent — failure never breaks anything.
	 */
	function verifyPendingToken( siteId ) {
		var site = state.sites.find( function ( s ) { return s.id === siteId; } );
		if ( ! site || ! site.pendingToken ) {
			return;
		}
		var token = site.pendingToken;
		// Clear immediately so it doesn't auto-retry on next load
		site.pendingToken = null;
		localStorage.setItem( 'rsa_sites', JSON.stringify( state.sites ) );

		fetch( site.siteUrl + '/wp-json/rsa/v1/verify-install', {
			method : 'POST',
			headers: {
				'Authorization': 'Basic ' + site.credentials,
				'Content-Type' : 'application/json',
			},
			body: JSON.stringify( { site_token: token } ),
		} ).catch( function () { /* intentionally silent */ } );
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
		} ).then( function ( data ) {
			state.cache[ cacheKey ] = data;
			return data;
		} );
	}

	// -----------------------------------------------------------------------
	// Login
	// -----------------------------------------------------------------------
	function bindLoginForm() {
		var form   = document.getElementById( 'rsa-login-form' );
		var errBox = document.getElementById( 'rsa-login-error' );
		var btn    = document.getElementById( 'rsa-connect-btn' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			errBox.hidden = true;
			btn.disabled  = true;
			btn.textContent = 'Connecting…';

			var siteUrl  = document.getElementById( 'rsa-site-url' ).value.trim();
			var username = document.getElementById( 'rsa-username' ).value.trim();
			var appPass  = document.getElementById( 'rsa-app-pass' ).value.trim();

			// Validate URL scheme before storing/sending (SSRF-safe: don't allow arbitrary redirects)
			var urlObj;
			try {
				urlObj = new URL( siteUrl );
			} catch ( _ ) {
				showLoginError( errBox, btn, 'Please enter a valid URL (including https://).' );
				return;
			}
			if ( urlObj.protocol !== 'https:' && urlObj.protocol !== 'http:' ) {
				showLoginError( errBox, btn, 'URL must start with http:// or https://.' );
				return;
			}

			// Test with temporary credentials (not yet persisted)
			state.siteUrl     = siteUrl.replace( /\/$/, '' );
			state.credentials = btoa( username + ':' + appPass );

			apiGet( 'overview', { period: '7d' } ).then( function () {
				var pending = state._pendingImport || {};
				var site    = persistSite( siteUrl, username, appPass, pending.siteLabel || null, pending.siteToken || null );
				state._pendingImport = null;
				if ( site.pendingToken ) {
					verifyPendingToken( site.id );
				}
				renderSiteSwitcher();
				showApp();
				renderView( 'overview' );
			} ).catch( function ( err ) {
				state.siteUrl     = '';
				state.credentials = '';
				var msg = err.message === 'auth'
					? 'Authentication failed. Check your username and Application Password.'
					: 'Could not reach the site. Check the URL and try again.';
				showLoginError( errBox, btn, msg );
			} );
		} );
	}

	function showLoginError( errBox, btn, msg ) {
		errBox.textContent = msg;
		errBox.hidden      = false;
		btn.disabled       = false;
		btn.textContent    = 'Connect';
	}

	function showLogin() {
		document.getElementById( 'rsa-login' ).hidden = false;
		document.getElementById( 'rsa-app' ).hidden   = true;
	}

	function showApp() {
		document.getElementById( 'rsa-login' ).hidden    = true;
		document.getElementById( 'rsa-add-site' ).hidden = true;
		document.getElementById( 'rsa-app' ).hidden      = false;

		var sel = document.getElementById( 'rsa-period-select' );
		sel.value = state.period;
	}

	// -----------------------------------------------------------------------
	// Import .rsasite file (login screen)
	// -----------------------------------------------------------------------
	function bindImportFile() {
		var input = document.getElementById( 'rsa-import-file' );
		if ( ! input ) return;

		input.addEventListener( 'change', function () {
			var file = this.files[0];
			if ( ! file ) return;
			parseSiteFile( file, function ( cfg ) {
				var urlField  = document.getElementById( 'rsa-site-url' );
				var userField = document.getElementById( 'rsa-username' );
				if ( urlField  ) { urlField.value  = cfg.siteUrl;  urlField.readOnly = true; }
				if ( userField ) { userField.value = cfg.username; }
				state._pendingImport = { siteToken: cfg.siteToken || null, siteLabel: cfg.siteLabel || null };
				var passField = document.getElementById( 'rsa-app-pass' );
				if ( passField ) passField.focus();
			} );
			this.value = '';
		} );
	}

	/** Parse a .rsasite JSON file.  Calls back with the parsed config or alerts on error. */
	function parseSiteFile( file, callback ) {
		var reader = new FileReader();
		reader.onload = function ( e ) {
			try {
				var cfg = JSON.parse( e.target.result );
				if ( typeof cfg.siteUrl !== 'string' || typeof cfg.username !== 'string' ) {
					throw new Error( 'missing fields' );
				}
				callback( cfg );
			} catch ( _ ) {
				alert( 'Could not read the .rsasite file. Make sure it is the original unmodified file from your WordPress profile.' );
			}
		};
		reader.readAsText( file );
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
	// Add Site overlay
	// -----------------------------------------------------------------------
	function showAddSiteOverlay( prefill ) {
		var overlay  = document.getElementById( 'rsa-add-site' );
		var urlField = document.getElementById( 'rsa-add-site-url' );
		var usrField = document.getElementById( 'rsa-add-username' );
		var pwdField = document.getElementById( 'rsa-add-app-pass' );
		var errBox   = document.getElementById( 'rsa-add-error' );
		if ( ! overlay ) return;

		if ( urlField ) { urlField.value = ( prefill && prefill.siteUrl  ) ? prefill.siteUrl  : ''; urlField.readOnly = false; }
		if ( usrField ) { usrField.value = ( prefill && prefill.username ) ? prefill.username : ''; }
		if ( pwdField ) { pwdField.value = ''; }
		if ( errBox   ) { errBox.hidden = true; }
		overlay._pendingImport = prefill || null;

		document.getElementById( 'rsa-app' ).hidden = true;
		overlay.hidden = false;
		if ( pwdField && prefill && prefill.siteUrl ) pwdField.focus();
	}

	function hideAddSiteOverlay() {
		var overlay = document.getElementById( 'rsa-add-site' );
		if ( overlay ) overlay.hidden = true;
		document.getElementById( 'rsa-app' ).hidden = false;
	}

	function bindAddSite() {
		// File import inside the overlay
		var addImport = document.getElementById( 'rsa-add-import-file' );
		if ( addImport ) {
			addImport.addEventListener( 'change', function () {
				var file = this.files[0];
				if ( ! file ) return;
				parseSiteFile( file, function ( cfg ) {
					var urlField = document.getElementById( 'rsa-add-site-url' );
					var usrField = document.getElementById( 'rsa-add-username' );
					var overlay  = document.getElementById( 'rsa-add-site' );
					if ( urlField ) { urlField.value = cfg.siteUrl;  urlField.readOnly = true; }
					if ( usrField ) { usrField.value = cfg.username; }
					if ( overlay  ) { overlay._pendingImport = { siteToken: cfg.siteToken || null, siteLabel: cfg.siteLabel || null }; }
					var passField = document.getElementById( 'rsa-add-app-pass' );
					if ( passField ) passField.focus();
				} );
				this.value = '';
			} );
		}

		// Cancel
		var cancelBtn = document.getElementById( 'rsa-add-cancel-btn' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', hideAddSiteOverlay );
		}

		// Connect
		var confirmBtn = document.getElementById( 'rsa-add-confirm-btn' );
		var errBox     = document.getElementById( 'rsa-add-error' );
		if ( ! confirmBtn ) return;

		confirmBtn.addEventListener( 'click', function () {
			var siteUrl  = ( ( document.getElementById( 'rsa-add-site-url' ) || {} ).value || '' ).trim();
			var username = ( ( document.getElementById( 'rsa-add-username'  ) || {} ).value || '' ).trim();
			var appPass  = ( ( document.getElementById( 'rsa-add-app-pass'  ) || {} ).value || '' ).trim();

			var urlObj;
			try { urlObj = new URL( siteUrl ); } catch ( _ ) { urlObj = null; }
			if ( ! urlObj || ( urlObj.protocol !== 'https:' && urlObj.protocol !== 'http:' ) ) {
				if ( errBox ) { errBox.textContent = 'Please enter a valid URL (including https://).'; errBox.hidden = false; }
				return;
			}
			if ( ! username || ! appPass ) {
				if ( errBox ) { errBox.textContent = 'Username and Application Password are required.'; errBox.hidden = false; }
				return;
			}

			confirmBtn.disabled    = true;
			confirmBtn.textContent = 'Connecting…';
			if ( errBox ) errBox.hidden = true;

			// Temporarily set state to test credentials
			var prevUrl  = state.siteUrl;
			var prevCred = state.credentials;
			state.siteUrl     = siteUrl.replace( /\/$/, '' );
			state.credentials = btoa( username + ':' + appPass );
			state.cache       = {};

			apiGet( 'overview', { period: '7d' } ).then( function () {
				var overlay = document.getElementById( 'rsa-add-site' );
				var pending = ( overlay && overlay._pendingImport ) || {};
				var site    = persistSite( siteUrl, username, appPass, pending.siteLabel || null, pending.siteToken || null );
				if ( site.pendingToken ) verifyPendingToken( site.id );
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
					: 'Could not reach the site. Check the URL.';
				if ( errBox ) { errBox.textContent = msg; errBox.hidden = false; }
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
			overview : 'Overview',
			pages    : 'Top Pages',
			audience : 'Audience',
			referrers: 'Referrers',
			behavior : 'Behavior',
			clicks   : 'Click Map',
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
			case 'overview' : renderOverview( container );  break;
			case 'pages'    : renderPages( container );     break;
			case 'audience' : renderAudience( container );  break;
			case 'referrers': renderReferrers( container ); break;
			case 'behavior' : renderBehavior( container );  break;
			case 'clicks'   : renderClicks( container );    break;
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
				data.session_depth.map( function ( b ) { return b.depth + ' page' + ( b.depth === 1 ? '' : 's' ); } ),
				data.session_depth.map( function ( b ) { return b.count; } )
			);
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
				return '<tr><td class="rsa-td-path">' + esc( c.page ) + '</td>' +
					'<td>' + esc( c.href_protocol ) + '</td>' +
					'<td>' + esc( c.element_tag ) + '</td>' +
					'<td>' + esc( c.element_text ) + '</td>' +
					'<td>' + fmt( c.count ) + '</td></tr>';
			} );
			container.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-click-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>Page</th><th>Protocol</th><th>Tag</th><th>Text</th><th>Clicks</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			setLoading( false );
			var top = data.clicks.slice( 0, 10 );
			drawBar( 'c-click-bar',
				top.map( function ( c ) { return truncate( c.element_text || c.href_protocol, 30 ); } ),
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
