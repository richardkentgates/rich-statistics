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

		var nonceAuth = !! ( window.RSA_CONFIG && window.RSA_CONFIG.nonce && state.siteUrl );
		if ( ( state.siteUrl && state.credentials ) || nonceAuth ) {
			renderSiteSwitcher();
			showApp();
			renderView( state.view );
			syncUserSettings();
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
		bindInstallPrompt();
		showIosInstallTip();
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

		// When the app is served from a WP site (/rs-app/), config.js sets
		// autoSiteUrl and serve_app() injects a nonce.  Auto-register the
		// current site with empty credentials — nonce authentication is used
		// instead of Application Passwords for same-origin calls.
		var autoUrl = window.RSA_CONFIG && window.RSA_CONFIG.autoSiteUrl;
		var autoNonce = window.RSA_CONFIG && window.RSA_CONFIG.nonce;
		if ( autoUrl && autoNonce ) {
			var normalised = autoUrl.replace( /\/$/, '' );
			var match = state.sites.find( function ( s ) {
				return s.siteUrl.replace( /\/$/, '' ) === normalised;
			} );
			if ( ! match ) {
				var autoSite = {
					id         : uid(),
					label      : ( window.RSA_CONFIG.autoLabel ) || hostname( autoUrl ),
					siteUrl    : normalised,
					appUrl     : window.RSA_CONFIG.appUrl || '',
					credentials: '',
				};
				state.sites.unshift( autoSite );
				localStorage.setItem( 'rsa_sites', JSON.stringify( state.sites ) );
				match = autoSite;
			}
			state.activeId = match.id;
			localStorage.setItem( 'rsa_active', match.id );
		}

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
		var targetSite = state.sites.find( function ( s ) { return s.id === id; } );
		if ( targetSite && targetSite.appUrl ) {
			var targetOrigin = '';
			try { targetOrigin = new URL( targetSite.appUrl ).origin; } catch ( _ ) {}
			if ( targetOrigin && targetOrigin !== window.location.origin ) {
				window.location.href = targetSite.appUrl;
				return;
			}
		}
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
		pushSiteListToAllSites();
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
		pushSiteListToAllSites();
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

	function normaliseUrl( url ) {
		return ( url || '' ).replace( /\/$/, '' ).toLowerCase();
	}

	/**
	 * Push the current site list (metadata only — no credentials) to every
	 * authenticated site so each WP install acts as a sync node.
	 */
	function pushSiteListToAllSites() {
		var sanitized = state.sites.map( function ( s ) {
			return { id: s.id, label: s.label, siteUrl: s.siteUrl, appUrl: s.appUrl || '' };
		} );
		state.sites.forEach( function ( site ) {
			var url     = site.siteUrl + '/wp-json/rsa/v1/user-settings';
			var headers = Object.assign( { 'Content-Type': 'application/json', 'Accept': 'application/json' }, getAuthHeaders( url ) );
			if ( ! headers['Authorization'] && ! headers['X-WP-Nonce'] ) return;
			fetch( url, {
				method : 'POST',
				headers: headers,
				body   : JSON.stringify( { sites: sanitized } ),
			} ).catch( function () {} );
		} );
	}

	/**
	 * On app load, fetch the site list stored on the active WP site for this
	 * user and reconcile it with the local list.  Sites that exist in the remote
	 * list but not locally are offered as additions (they were added on another
	 * device); sites that only exist locally are offered for sync.
	 */
	function syncUserSettings() {
		if ( ! state.siteUrl ) return;
		var url     = state.siteUrl + '/wp-json/rsa/v1/user-settings';
		var headers = Object.assign( { 'Accept': 'application/json' }, getAuthHeaders( url ) );
		if ( ! headers['Authorization'] && ! headers['X-WP-Nonce'] ) return;

		fetch( url, { headers: headers } )
		.then( function ( r ) { return r.ok ? r.json() : null; } )
		.then( function ( json ) {
			if ( ! json || ! json.data ) return;
			var remoteSites = json.data.sites || [];

			// Sites in remote but missing locally (added on another device)
			var toAdd = remoteSites.filter( function ( r ) {
				return ! state.sites.some( function ( l ) {
					return normaliseUrl( l.siteUrl ) === normaliseUrl( r.siteUrl );
				} );
			} );

			// Sites local but missing in remote (not yet pushed)
			var toSync = state.sites.filter( function ( l ) {
				return ! remoteSites.some( function ( r ) {
					return normaliseUrl( r.siteUrl ) === normaliseUrl( l.siteUrl );
				} );
			} );

			if ( toAdd.length ) {
				var addNames = toAdd.map( function ( s ) { return s.label || s.siteUrl; } ).join( '\n\u2022 ' );
				if ( confirm( 'The following sites are linked to your account but not yet on this device:\n\n\u2022 ' + addNames + '\n\nAdd them to this device?' ) ) {
					toAdd.forEach( function ( r ) {
						state.sites.push( {
							id         : r.id || uid(),
							label      : r.label || hostname( r.siteUrl ),
							siteUrl    : r.siteUrl,
							appUrl     : r.appUrl || '',
							credentials: '',
						} );
					} );
					localStorage.setItem( 'rsa_sites', JSON.stringify( state.sites ) );
					renderSiteSwitcher();
				} else if ( confirm( 'Remove these sites from your account sync?' ) ) {
					// User declined to add them — remove from remote by pushing current local list
					pushSiteListToAllSites();
				}
			}

			if ( toSync.length ) {
				var syncNames = toSync.map( function ( s ) { return s.label || s.siteUrl; } ).join( '\n\u2022 ' );
				if ( confirm( 'The following sites are on this device but not in your account sync:\n\n\u2022 ' + syncNames + '\n\nAdd them to sync?' ) ) {
					pushSiteListToAllSites();
				} else {
					// User chose not to sync — offer to remove from local
					var removeNames = toSync.filter( function ( s ) { return s.id !== state.activeId; } );
					if ( removeNames.length && confirm( 'Remove them from this device instead?' ) ) {
						removeNames.forEach( function ( s ) { removeSite( s.id ); } );
						renderSiteSwitcher();
					}
				}
			}

			// No mismatches — push local to keep all nodes current
			if ( ! toAdd.length && ! toSync.length ) {
				pushSiteListToAllSites();
			}
		} )
		.catch( function () {} );
	}

	// -----------------------------------------------------------------------
	// API
	// -----------------------------------------------------------------------

	/**
	 * Return the correct auth headers for a given absolute URL.
	 * Same-origin auto-site uses the injected WP REST nonce (cookie auth +
	 * nonce).
	 * Other sites use Application Password Basic auth.
	 */
	function getAuthHeaders( url ) {
		var nonce   = window.RSA_CONFIG && window.RSA_CONFIG.nonce;
		var autoUrl = window.RSA_CONFIG && window.RSA_CONFIG.autoSiteUrl;
		var headers = { 'Accept': 'application/json' };
		if ( nonce && autoUrl && url.toLowerCase().startsWith( autoUrl.toLowerCase() ) ) {
			headers['X-WP-Nonce'] = nonce;
		} else if ( state.credentials ) {
			headers['Authorization'] = 'Basic ' + state.credentials;
		}
		return headers;
	}

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
			headers: getAuthHeaders( url ),
		} ).then( function ( res ) {
			if ( res.status === 401 || res.status === 403 ) {
				// If using nonce auth and we get a 403, the nonce may have expired.
				// Fetch a fresh nonce from WP and retry once.
				var nonce = window.RSA_CONFIG && window.RSA_CONFIG.nonce;
				var autoUrl = window.RSA_CONFIG && window.RSA_CONFIG.autoSiteUrl;
				if ( res.status === 403 && nonce && autoUrl && url.toLowerCase().startsWith( autoUrl.toLowerCase() ) ) {
					return fetch( autoUrl + '/wp-json/', { headers: { 'Accept': 'application/json' } } )
						.then( function ( r ) { return r.ok ? r.json() : null; } )
						.then( function ( json ) {
							if ( json && json.nonce ) {
								window.RSA_CONFIG.nonce = json.nonce;
							}
							return fetch( url, { method: 'GET', headers: getAuthHeaders( url ) } );
						} )
						.then( function ( r2 ) {
							if ( r2.status === 401 || r2.status === 403 ) throw new Error( 'auth' );
							if ( ! r2.ok ) throw new Error( 'HTTP ' + r2.status );
							return r2.json();
						} );
				}
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

		checkPluginVersion();
	}

	// -----------------------------------------------------------------------
	// Plugin version sync
	// -----------------------------------------------------------------------

	/**
	 * Fetches /wp-json/rsa/v1/info (public endpoint) to:
	 *   1. Populate the version badge in the nav header.
	 *   2. Detect plugin updates: if the version has changed since the last visit,
	 *      clear all SW caches and reload so the browser fetches the updated app
	 *      files from the WP server instead of serving stale cached assets.
	 *
	 * This is the only mechanism needed — the SW uses network-first for all
	 * requests so users online always get fresh files anyway; this handles the
	 * edge case where cached assets would be served after an update.
	 */
	function checkPluginVersion() {
		if ( ! state.siteUrl ) return;

		var versionKey = 'rsa_pv_' + state.activeId;
		fetch( state.siteUrl + '/wp-json/rsa/v1/info', { headers: { 'Accept': 'application/json' } } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( json ) {
				if ( ! json || ! json.data ) return;
				var info = json.data;

				var badge = document.getElementById( 'rsa-plugin-version' );
				if ( badge ) badge.textContent = 'v' + info.version;

				// Cache the app_url on the site object so setActiveSite can navigate to it
				if ( info.app_url ) {
					var activeSite = state.sites.find( function ( s ) { return s.id === state.activeId; } );
					if ( activeSite && activeSite.appUrl !== info.app_url ) {
						activeSite.appUrl = info.app_url;
						localStorage.setItem( 'rsa_sites', JSON.stringify( state.sites ) );
					}
				}

				var stored = localStorage.getItem( versionKey );
				if ( stored && stored !== info.version ) {
					// Plugin was updated — clear SW caches so next render gets fresh files.
					localStorage.setItem( versionKey, info.version );
					if ( 'caches' in window ) {
						caches.keys().then( function ( keys ) {
							return Promise.all( keys.map( function ( k ) { return caches.delete( k ); } ) );
						} ).then( function () { window.location.reload( true ); } );
					} else {
						window.location.reload( true );
					}
					return;
				}
				localStorage.setItem( versionKey, info.version );
			} )
			.catch( function () {} ); // Silent — version check is best-effort
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

		// When served from a WP site, pre-fill the URL so the user doesn't have
		// to type it in.  No fallback: if autoSiteUrl is not set, leave blank.
		if ( ! prefill ) {
			var autoUrl = window.RSA_CONFIG && window.RSA_CONFIG.autoSiteUrl;
			if ( autoUrl && urlField ) {
				urlField.value = autoUrl;
			}
		}

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
			heatmap    : 'Heatmap',
			export     : 'Export',
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

	// -----------------------------------------------------------------------
	// iOS Safari install tip
	// -----------------------------------------------------------------------
	function showIosInstallTip() {
		var ua         = navigator.userAgent || '';
		var isIos      = /iphone|ipad|ipod/i.test( ua );
		var isSafari   = /safari/i.test( ua ) && ! /chrome|crios|fxios|android/i.test( ua );
		var standalone = 'standalone' in window.navigator && window.navigator.standalone;
		if ( ! isIos || ! isSafari || standalone ) return;

		var tip = document.createElement( 'div' );
		tip.id = 'rsa-ios-tip';
		tip.setAttribute( 'role', 'status' );
		tip.innerHTML =
			'<span>Tap <strong>Share</strong> ↗ then <strong>“Add to Home Screen”</strong> to install this app.</span>' +
			'<button type="button" aria-label="Dismiss" id="rsa-ios-tip-close">×</button>';
		document.body.appendChild( tip );
		document.getElementById( 'rsa-ios-tip-close' ).addEventListener( 'click', function () {
			tip.remove();
			try { localStorage.setItem( 'rsa_ios_tip_dismissed', '1' ); } catch ( e ) {}
		} );
	}

	// -----------------------------------------------------------------------
	// PWA install prompt
	// -----------------------------------------------------------------------
	var _installPrompt = null;

	function bindInstallPrompt() {
		// Chrome / Edge / Samsung Internet fire this before showing the mini-infobar
		window.addEventListener( 'beforeinstallprompt', function ( e ) {
			e.preventDefault();
			_installPrompt = e;
			var btn = document.getElementById( 'rsa-install-btn' );
			if ( btn ) btn.hidden = false;
		} );

		// Hide button once installed
		window.addEventListener( 'appinstalled', function () {
			_installPrompt = null;
			var btn = document.getElementById( 'rsa-install-btn' );
			if ( btn ) btn.hidden = true;
		} );

		var btn = document.getElementById( 'rsa-install-btn' );
		if ( ! btn ) return;
		btn.addEventListener( 'click', function () {
			if ( ! _installPrompt ) return;
			_installPrompt.prompt();
			_installPrompt.userChoice.then( function ( outcome ) {
				_installPrompt = null;
				if ( outcome.outcome === 'accepted' ) {
					btn.hidden = true;
				}
			} );
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
			case 'heatmap'   : renderHeatmap( container );    break;
			case 'export'    : renderExport( container );     break;
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
			container.innerHTML =
				tmplKpiGrid( [
					{ label: 'Pageviews',   value: fmt( data.pageviews )    },
					{ label: 'Sessions',    value: fmt( data.sessions )     },
					{ label: 'Avg. Time',   value: fmtSecs( data.avg_time ) },
					{ label: 'Bounce Rate', value: fmtPct( data.bounce_rate ) },
				] ) +
				'<div class="rsa-chart-wrap"><canvas id="c-overview-daily"></canvas></div>' +
				'<div class="rsa-grid-2" style="margin-top:20px">' +
					'<div class="rsa-table-card"><h3>Top Pages</h3><div class="rsa-table-wrap"><table class="rsa-table">' +
					'<thead><tr><th>#</th><th>Page</th><th>Views</th></tr></thead>' +
					'<tbody id="rsa-ov-pages-body"><tr><td colspan="3" class="rsa-field-hint">Loading\u2026</td></tr></tbody>' +
					'</table></div></div>' +
					'<div class="rsa-table-card"><h3>Top Referrers</h3><div class="rsa-table-wrap"><table class="rsa-table">' +
					'<thead><tr><th>#</th><th>Domain</th><th>Visits</th></tr></thead>' +
					'<tbody id="rsa-ov-ref-body"><tr><td colspan="3" class="rsa-field-hint">Loading\u2026</td></tr></tbody>' +
					'</table></div></div>' +
				'</div>';

			setLoading( false );
			drawLine( 'c-overview-daily', data.daily.map( function ( d ) { return d.day; } ),
				[ { label: 'Pageviews', data: data.daily.map( function ( d ) { return d.views; } ) } ] );

			// Load tables independently so a slow/failing endpoint doesn't hide everything
			apiGet( 'pages', { period: state.period, limit: 5 } ).then( function ( pd ) {
				var tbody = document.getElementById( 'rsa-ov-pages-body' );
				if ( ! tbody ) return;
				var rows = ( pd.pages || [] ).map( function ( p, i ) {
					return '<tr><td>' + ( i + 1 ) + '</td><td class="rsa-td-path">' + esc( p.page ) + '</td><td>' + fmt( p.views ) + '</td></tr>';
				} );
				tbody.innerHTML = rows.length ? rows.join( '' ) : '<tr><td colspan="3">No data.</td></tr>';
			} ).catch( function () {
				var tbody = document.getElementById( 'rsa-ov-pages-body' );
				if ( tbody ) tbody.innerHTML = '<tr><td colspan="3">Could not load.</td></tr>';
			} );

			apiGet( 'referrers', { period: state.period, limit: 5 } ).then( function ( rd ) {
				var tbody = document.getElementById( 'rsa-ov-ref-body' );
				if ( ! tbody ) return;
				var rows = ( rd.referrers || [] ).map( function ( r, i ) {
					return '<tr><td>' + ( i + 1 ) + '</td><td>' + esc( r.domain || '(direct)' ) + '</td><td>' + fmt( r.pageviews ) + '</td></tr>';
				} );
				tbody.innerHTML = rows.length ? rows.join( '' ) : '<tr><td colspan="3">No data.</td></tr>';
			} ).catch( function () {
				var tbody = document.getElementById( 'rsa-ov-ref-body' );
				if ( tbody ) tbody.innerHTML = '<tr><td colspan="3">Could not load.</td></tr>';
			} );
		} ).catch( function ( err ) { handleApiError( err, container ); } );
	}

	// -----------------------------------------------------------------------
	// Pages
	// -----------------------------------------------------------------------
	function renderPages( container ) {
		var filters = { path: '', browser: '', os: '', sort: 'views', sort_dir: 'desc' };

		function buildParams() {
			var p = { period: state.period, limit: 100, sort: filters.sort, sort_dir: filters.sort_dir };
			if ( filters.path )    p.path    = filters.path;
			if ( filters.browser ) p.browser = filters.browser;
			if ( filters.os )      p.os      = filters.os;
			return p;
		}

		function renderResults( data ) {
			var results = document.getElementById( 'rsa-pages-results' );
			if ( ! results ) return;

			if ( ! data.pages || ! data.pages.length ) {
				results.innerHTML = '<p class="rsa-empty">No page data for the selected filters.</p>';
				return;
			}

			function sortLink( field, label ) {
				var newDir = ( filters.sort === field && filters.sort_dir === 'desc' ) ? 'asc' : 'desc';
				var arrow  = filters.sort === field ? ( filters.sort_dir === 'desc' ? ' &#8595;' : ' &#8593;' ) : '';
				return '<a href="#" class="rsa-sort-link" data-field="' + field + '" data-dir="' + newDir + '">' + esc( label ) + arrow + '</a>';
			}

			var rows = data.pages.map( function ( p, i ) {
				return '<tr><td>' + ( i + 1 ) + '</td>' +
					'<td class="rsa-td-path">' + esc( p.page ) + '</td>' +
					'<td>' + fmt( p.views ) + '</td>' +
					'<td>' + fmtSecs( p.avg_time ) + '</td></tr>';
			} );

			results.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-pages-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>#</th><th>Page</th><th>' + sortLink( 'views', 'Views' ) + '</th>' +
				'<th>' + sortLink( 'avg_time', 'Avg Time' ) + '</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			results.querySelectorAll( '.rsa-sort-link' ).forEach( function ( a ) {
				a.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					filters.sort     = this.dataset.field;
					filters.sort_dir = this.dataset.dir;
					reloadResults();
				} );
			} );

			var top = data.pages.slice( 0, 10 );
			drawBar( 'c-pages-bar',
				top.map( function ( p ) { return truncate( p.page, 40 ); } ),
				top.map( function ( p ) { return p.views; } ),
				'Views', true
			);
		}

		function reloadResults() {
			var results = document.getElementById( 'rsa-pages-results' );
			if ( results ) results.innerHTML = '<p class="rsa-field-hint" style="padding:16px 0">Loading\u2026</p>';
			apiGet( 'pages', buildParams() ).then( renderResults ).catch( function ( err ) { handleApiError( err, container ); } );
		}

		Promise.all( [
			apiGet( 'filter-options', { period: state.period } ),
			apiGet( 'pages', buildParams() ),
		] ).then( function ( r ) {
			var opts = r[0], data = r[1];

			function optionsHtml( arr, current, placeholder ) {
				return '<option value="">' + esc( placeholder ) + '</option>' +
					arr.map( function ( v ) { return '<option value="' + esc( v ) + '"' + ( v === current ? ' selected' : '' ) + '>' + esc( v ) + '</option>'; } ).join( '' );
			}

			container.innerHTML =
				'<div class="rsa-filter-bar">' +
				( ( opts.pages || [] ).length    ? '<select id="rsa-f-path">'    + optionsHtml( opts.pages,    filters.path,    'All Pages'    ) + '</select>' : '' ) +
				( ( opts.browsers || [] ).length ? '<select id="rsa-f-browser">' + optionsHtml( opts.browsers, filters.browser, 'All Browsers' ) + '</select>' : '' ) +
				( ( opts.os || [] ).length       ? '<select id="rsa-f-os">'      + optionsHtml( opts.os,       filters.os,      'All OS'       ) + '</select>' : '' ) +
				'<span class="rsa-sort-label">Sort:</span>' +
				'<select id="rsa-f-sort">' +
				'<option value="views"'    + ( filters.sort === 'views'    ? ' selected' : '' ) + '>Views</option>' +
				'<option value="avg_time"' + ( filters.sort === 'avg_time' ? ' selected' : '' ) + '>Avg Time</option>' +
				'</select>' +
				'<select id="rsa-f-sort-dir">' +
				'<option value="desc"' + ( filters.sort_dir === 'desc' ? ' selected' : '' ) + '>\u2193 Desc</option>' +
				'<option value="asc"'  + ( filters.sort_dir === 'asc'  ? ' selected' : '' ) + '>\u2191 Asc</option>' +
				'</select>' +
				'<button type="button" class="rsa-btn rsa-btn-primary" id="rsa-pages-filter-btn">Filter</button>' +
				( ( filters.path || filters.browser || filters.os ) ? '<button type="button" class="rsa-btn rsa-btn-ghost" id="rsa-pages-clear-btn">Clear</button>' : '' ) +
				'</div>' +
				'<div id="rsa-pages-results"></div>';

			renderResults( data );
			setLoading( false );

			document.getElementById( 'rsa-pages-filter-btn' ).addEventListener( 'click', function () {
				var pathEl    = document.getElementById( 'rsa-f-path' );
				var brEl      = document.getElementById( 'rsa-f-browser' );
				var osEl      = document.getElementById( 'rsa-f-os' );
				var sortEl    = document.getElementById( 'rsa-f-sort' );
				var sortDirEl = document.getElementById( 'rsa-f-sort-dir' );
				filters.path     = pathEl    ? pathEl.value    : '';
				filters.browser  = brEl      ? brEl.value      : '';
				filters.os       = osEl      ? osEl.value      : '';
				filters.sort     = sortEl    ? sortEl.value    : 'views';
				filters.sort_dir = sortDirEl ? sortDirEl.value : 'desc';
				reloadResults();
			} );

			var clearBtn = document.getElementById( 'rsa-pages-clear-btn' );
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					filters = { path: '', browser: '', os: '', sort: 'views', sort_dir: 'desc' };
					renderPages( container );
				} );
			}
		} ).catch( function ( err ) { handleApiError( err, container ); } );
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
			var al = function ( arr ) { return ( arr || [] ).map( function ( d ) { return d.label; } ); };
			var av = function ( arr ) { return ( arr || [] ).map( function ( d ) { return d.count; } ); };
			drawDoughnut( 'c-aud-os',   al( data.by_os ),       av( data.by_os ) );
			drawDoughnut( 'c-aud-br',   al( data.by_browser ),  av( data.by_browser ) );
			drawDoughnut( 'c-aud-vp',   al( data.by_viewport ), av( data.by_viewport ) );
			drawDoughnut( 'c-aud-lang', al( data.by_language ), av( data.by_language ) );
			drawBar( 'c-aud-tz', al( data.by_timezone ), av( data.by_timezone ), 'Sessions', true );
		} ).catch( function ( err ) { handleApiError( err, container ); } );
	}

	// -----------------------------------------------------------------------
	// Referrers
	// -----------------------------------------------------------------------
	// -----------------------------------------------------------------------
	// Referrers
	// -----------------------------------------------------------------------
	function renderReferrers( container ) {
		var filters = { ref_page: '' };

		function buildParams() {
			var p = { period: state.period, limit: 100 };
			if ( filters.ref_page ) p.ref_page = filters.ref_page;
			return p;
		}

		function renderResults( data ) {
			var results = document.getElementById( 'rsa-ref-results' );
			if ( ! results ) return;

			var refs = data.referrers || [];
			if ( ! refs.length ) {
				results.innerHTML = '<p class="rsa-empty">No referral data for the selected filters.</p>';
				return;
			}

			var total = refs.reduce( function ( s, r ) { return s + r.pageviews; }, 0 );
			var rows  = refs.map( function ( r, i ) {
				var share = total > 0 ? ( r.pageviews / total * 100 ).toFixed( 1 ) : 0;
				return '<tr>' +
					'<td>' + ( i + 1 ) + '</td>' +
					'<td>' + esc( r.domain || '(direct)' ) + '</td>' +
					'<td class="rsa-td-path">' + esc( r.top_page || '—' ) + '</td>' +
					'<td>' + fmt( r.pageviews ) + '</td>' +
					'<td><div class="rsa-bar-cell">' +
					'<div class="rsa-bar-fill" style="width:' + share + '%"></div>' +
					'<span>' + share + '%</span></div></td>' +
					'</tr>';
			} );

			results.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-ref-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>#</th><th>Referring Domain</th><th>Top Landing Page</th><th>Visits</th><th>Share</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			var top = refs.slice( 0, 10 );
			drawBar( 'c-ref-bar',
				top.map( function ( r ) { return r.domain || '(direct)'; } ),
				top.map( function ( r ) { return r.pageviews; } ),
				'Visits', true
			);
		}

		function reloadResults() {
			var results = document.getElementById( 'rsa-ref-results' );
			if ( results ) results.innerHTML = '<p class="rsa-field-hint" style="padding:16px 0">Loading\u2026</p>';
			apiGet( 'referrers', buildParams() ).then( renderResults ).catch( function ( err ) { handleApiError( err, container ); } );
		}

		Promise.all( [
			apiGet( 'filter-options', { period: state.period } ),
			apiGet( 'referrers', buildParams() ),
		] ).then( function ( r ) {
			var opts = r[0], data = r[1];

			function optionsHtml( arr, current, placeholder ) {
				return '<option value="">' + esc( placeholder ) + '</option>' +
					arr.map( function ( v ) { return '<option value="' + esc( v ) + '"' + ( v === current ? ' selected' : '' ) + '>' + esc( v ) + '</option>'; } ).join( '' );
			}

			container.innerHTML =
				'<div class="rsa-filter-bar">' +
				( ( opts.pages || [] ).length ? '<select id="rsa-f-ref-page">' + optionsHtml( opts.pages, filters.ref_page, 'All Landing Pages' ) + '</select>' : '' ) +
				'<button type="button" class="rsa-btn rsa-btn-primary" id="rsa-ref-filter-btn">Filter</button>' +
				( filters.ref_page ? '<button type="button" class="rsa-btn rsa-btn-ghost" id="rsa-ref-clear-btn">Clear</button>' : '' ) +
				'</div>' +
				'<div id="rsa-ref-results"></div>';

			renderResults( data );
			setLoading( false );

			document.getElementById( 'rsa-ref-filter-btn' ).addEventListener( 'click', function () {
				var el = document.getElementById( 'rsa-f-ref-page' );
				filters.ref_page = el ? el.value : '';
				reloadResults();
			} );

			var clearBtn = document.getElementById( 'rsa-ref-clear-btn' );
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					filters = { ref_page: '' };
					renderReferrers( container );
				} );
			}
		} ).catch( function ( err ) { handleApiError( err, container ); } );
	}

	// -----------------------------------------------------------------------
	// Behavior
	// -----------------------------------------------------------------------
	function renderBehavior( container ) {
		apiGet( 'behavior', { period: state.period } ).then( function ( data ) {
			var entryRows = data.entry_pages.map( function ( p, i ) {
				return '<tr><td>' + ( i + 1 ) + '</td><td class="rsa-td-path">' + esc( p.page ) + '</td><td>' + fmt( p.count ) + '</td></tr>';
			} ).join( '' );
			var exitRows = ( data.exit_pages || [] ).map( function ( p, i ) {
				return '<tr><td>' + ( i + 1 ) + '</td><td class="rsa-td-path">' + esc( p.page ) + '</td><td>' + fmt( p.count ) + '</td></tr>';
			} ).join( '' );
			container.innerHTML =
				'<div class="rsa-grid-2">' +
				'<div class="rsa-chart-card"><h3>Time on Page</h3>' +
				'<canvas id="c-beh-time"></canvas></div>' +
				'<div class="rsa-chart-card"><h3>Session Depth (Pages Viewed)</h3>' +
				'<canvas id="c-beh-depth"></canvas></div>' +
				'</div>' +
				'<div class="rsa-grid-2">' +
				'<div class="rsa-table-card"><h3>Top Entry Pages</h3>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>#</th><th>Page</th><th>Sessions</th></tr></thead>' +
				'<tbody>' + ( entryRows || '<tr><td colspan="3">No data yet.</td></tr>' ) +
				'</tbody></table></div></div>' +
				'<div class="rsa-table-card"><h3>Top Exit Pages</h3>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>#</th><th>Page</th><th>Sessions</th></tr></thead>' +
				'<tbody>' + ( exitRows || '<tr><td colspan="3">No data yet.</td></tr>' ) +
				'</tbody></table></div></div>' +
				'</div>';

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
		} ).catch( function ( err ) { handleApiError( err, container ); } );
	}

	// -----------------------------------------------------------------------
	// Campaigns
	// -----------------------------------------------------------------------
	function renderCampaigns( container ) {
		var filters = { medium: '' };

		function buildParams() {
			var p = { period: state.period, limit: 100 };
			if ( filters.medium ) p.medium = filters.medium;
			return p;
		}

		function renderResults( data ) {
			var results = document.getElementById( 'rsa-camp-results' );
			if ( ! results ) return;

			if ( ! data.campaigns || ! data.campaigns.length ) {
				results.innerHTML = '<p class="rsa-empty">No campaign data for this period.<br>' +
					'Add <code>utm_source</code>, <code>utm_medium</code>, and <code>utm_campaign</code> to your links.</p>';
				return;
			}

			var totalSess = data.campaigns.reduce( function ( s, c ) { return s + c.sessions; }, 0 );
			var rows = data.campaigns.map( function ( c, i ) {
				var share = totalSess > 0 ? ( c.sessions / totalSess * 100 ).toFixed( 1 ) : 0;
				return '<tr>' +
					'<td>' + ( i + 1 ) + '</td>' +
					'<td><strong>' + esc( c.campaign || '—' ) + '</strong></td>' +
					'<td>' + esc( c.source  || '—' ) + '</td>' +
					'<td>' + esc( c.medium  || '—' ) + '</td>' +
					'<td>' + fmt( c.sessions )  + '</td>' +
					'<td>' + fmt( c.pageviews ) + '</td>' +
					'<td><div class="rsa-bar-cell">' +
					'<div class="rsa-bar-fill" style="width:' + share + '%"></div>' +
					'<span>' + share + '%</span></div></td>' +
					'</tr>';
			} );

			results.innerHTML =
				'<div class="rsa-chart-wrap"><canvas id="c-camp-bar"></canvas></div>' +
				'<div class="rsa-table-wrap"><table class="rsa-table">' +
				'<thead><tr><th>#</th><th>Campaign</th><th>Source</th><th>Medium</th><th>Sessions</th><th>Pageviews</th><th>Share</th></tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody></table></div>';

			var top = data.campaigns.slice( 0, 10 );
			drawBar( 'c-camp-bar',
				top.map( function ( c ) { return truncate( c.campaign || c.source || '?', 36 ); } ),
				top.map( function ( c ) { return c.sessions; } ),
				'Sessions', true
			);
		}

		// Get unique mediums from existing campaigns data
		apiGet( 'campaigns', buildParams() ).then( function ( data ) {
			var mediums = [];
			( data.campaigns || [] ).forEach( function ( c ) {
				if ( c.medium && mediums.indexOf( c.medium ) === -1 ) mediums.push( c.medium );
			} );

			function optionsHtml( arr, current, placeholder ) {
				return '<option value="">' + esc( placeholder ) + '</option>' +
					arr.map( function ( v ) { return '<option value="' + esc( v ) + '"' + ( v === current ? ' selected' : '' ) + '>' + esc( v ) + '</option>'; } ).join( '' );
			}

			container.innerHTML =
				'<div class="rsa-filter-bar">' +
				( mediums.length ? '<select id="rsa-f-medium">' + optionsHtml( mediums, filters.medium, 'All Mediums' ) + '</select>' : '' ) +
				'<button type="button" class="rsa-btn rsa-btn-primary" id="rsa-camp-filter-btn">Filter</button>' +
				( filters.medium ? '<button type="button" class="rsa-btn rsa-btn-ghost" id="rsa-camp-clear-btn">Clear</button>' : '' ) +
				'</div>' +
				'<div id="rsa-camp-results"></div>';

			renderResults( data );
			setLoading( false );

			document.getElementById( 'rsa-camp-filter-btn' ).addEventListener( 'click', function () {
				var el = document.getElementById( 'rsa-f-medium' );
				filters.medium = el ? el.value : '';
				var results = document.getElementById( 'rsa-camp-results' );
				if ( results ) results.innerHTML = '<p class="rsa-field-hint" style="padding:16px 0">Loading\u2026</p>';
				apiGet( 'campaigns', buildParams() ).then( renderResults ).catch( function ( err ) { handleApiError( err, container ); } );
			} );

			var clearBtn = document.getElementById( 'rsa-camp-clear-btn' );
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					filters = { medium: '' };
					renderCampaigns( container );
				} );
			}
		} ).catch( function ( err ) { handleApiError( err, container ); } );
	}

	// -----------------------------------------------------------------------
	// User Flow
	// -----------------------------------------------------------------------
	function renderUserFlow( container ) {
		var filters    = { entry_source: '', focus_page: '', min_sessions: 1, steps: 4 };
		var activeView = 'explorer'; // 'explorer' | 'journey'

		// Fetch entry source options AND page list together, then render filter bar
		Promise.all( [
			apiGet( 'user-flow/sources', { period: state.period } ),
			apiGet( 'filter-options',    { period: state.period } ),
		] ).then( function ( r ) {
			var sources = ( r[0] && r[0].sources ) || [];
			var pages   = ( r[1] && r[1].pages   ) || [];

			function srcOptions( current ) {
				return '<option value="">All Sources</option>' +
					sources.map( function ( v ) {
						return '<option value="' + esc( v ) + '"' + ( v === current ? ' selected' : '' ) + '>' + esc( v ) + '</option>';
					} ).join( '' );
			}

			function pageOptions( current ) {
				return '<option value="">All Pages</option>' +
					pages.map( function ( v ) {
						return '<option value="' + esc( v ) + '"' + ( v === current ? ' selected' : '' ) + '>' + esc( v ) + '</option>';
					} ).join( '' );
			}

			container.innerHTML =
				'<div class="rsa-filter-bar">' +
				( sources.length ? '<select id="rsa-uf-source">' + srcOptions( filters.entry_source ) + '</select>' : '' ) +
				( pages.length   ? '<select id="rsa-uf-focus">'  + pageOptions( filters.focus_page   ) + '</select>' : '' ) +
				'<label style="font-size:13px;display:flex;align-items:center;gap:4px;white-space:nowrap">Min sessions' +
					'<input type="number" id="rsa-uf-min" value="1" min="1" max="999"' +
					' style="width:58px;padding:6px;border:1px solid var(--rsa-border);border-radius:var(--rsa-radius);' +
					'font-size:13px;color:var(--rsa-text);background:var(--rsa-surface);margin-left:4px"></label>' +
				'<select id="rsa-uf-steps">' +
					'<option value="2">2 steps</option>' +
					'<option value="3">3 steps</option>' +
					'<option value="4" selected>4 steps</option>' +
					'<option value="5">5 steps</option>' +
				'</select>' +
				'<button type="button" class="rsa-btn rsa-btn-primary" id="rsa-uf-filter-btn">Filter</button>' +
				'</div>' +
				'<div id="rsa-uf-content"></div>';

			setLoading( false );
			loadPathFlow();

			document.getElementById( 'rsa-uf-filter-btn' ).addEventListener( 'click', function () {
				var srcEl   = document.getElementById( 'rsa-uf-source' );
				var focusEl = document.getElementById( 'rsa-uf-focus' );
				var minEl   = document.getElementById( 'rsa-uf-min' );
				var stepsEl = document.getElementById( 'rsa-uf-steps' );
				filters.entry_source = srcEl   ? srcEl.value                            : '';
				filters.focus_page   = focusEl ? focusEl.value                          : '';
				filters.min_sessions = minEl   ? Math.max( 1, parseInt( minEl.value, 10 ) || 1 ) : 1;
				filters.steps        = stepsEl ? parseInt( stepsEl.value, 10 ) || 4     : 4;
				loadPathFlow();
			} );
		} ).catch( function () {
			// Endpoints failed — show content area without filter bar
			container.innerHTML = '<div id="rsa-uf-content"></div>';
			setLoading( false );
			loadPathFlow();
		} );

		function loadPathFlow() {
			var content = document.getElementById( 'rsa-uf-content' );
			if ( ! content ) { return; }
			content.innerHTML = '<p class="rsa-field-hint" style="padding:16px 0">Loading\u2026</p>';

			var params = { period: state.period, steps: filters.steps, min_sessions: filters.min_sessions };
			if ( filters.entry_source ) { params.entry_source = filters.entry_source; }
			if ( filters.focus_page   ) { params.focus_page   = filters.focus_page; }

			apiGet( 'user-flow', params ).then( function ( data ) {
				var contentEl = document.getElementById( 'rsa-uf-content' );
				if ( contentEl ) { renderUFContent( contentEl, data ); }
			} ).catch( function ( err ) {
				var contentEl = document.getElementById( 'rsa-uf-content' );
				if ( contentEl ) { handleApiError( err, contentEl ); }
			} );
		}

		function renderUFContent( content, data ) {
			var steps    = data.steps || {};
			var stepNums = Object.keys( steps ).map( Number ).sort( function ( a, b ) { return a - b; } );

			if ( ! stepNums.length ) {
				content.innerHTML = '<p class="rsa-empty">No path data for the selected filters.</p>';
				return;
			}

			var total    = data.total_sessions || 0;
			var exits    = ( data.links || [] ).reduce( function ( s, l ) { return s + ( l.to === '(exit)' ? l.count : 0 ); }, 0 );
			var exitRate = total > 0 ? ( exits / total * 100 ).toFixed( 1 ) : '\u2014';
			var entryPgs = ( steps[ stepNums[0] ] || [] ).filter( function ( n ) { return n.page !== '(exit)'; } ).length;

			content.innerHTML =
				'<div class="rsa-kpi-grid" style="margin-bottom:16px">' +
					'<div class="rsa-kpi-card"><div class="rsa-kpi-value">' + fmt( total )    + '</div><div class="rsa-kpi-label">Sessions Tracked</div></div>' +
					'<div class="rsa-kpi-card"><div class="rsa-kpi-value">' + fmt( entryPgs ) + '</div><div class="rsa-kpi-label">Entry Pages</div></div>' +
					'<div class="rsa-kpi-card"><div class="rsa-kpi-value">' + stepNums.length + '</div><div class="rsa-kpi-label">Steps in Flow</div></div>' +
					'<div class="rsa-kpi-card"><div class="rsa-kpi-value">' + exitRate + '%'  + '</div><div class="rsa-kpi-label">Exit Rate</div></div>' +
				'</div>' +
				'<div class="rsa-view-toggle">' +
					'<button type="button" class="rsa-btn rsa-btn-primary" id="rsa-uf-btn-explorer">Path Explorer</button>' +
					'<button type="button" class="rsa-btn rsa-btn-ghost"   id="rsa-uf-btn-journey">Journey Table</button>' +
				'</div>' +
				'<div id="rsa-uf-view" style="margin-top:12px"></div>';

			document.getElementById( 'rsa-uf-btn-explorer' ).addEventListener( 'click', function () {
				activeView = 'explorer';
				showView( data );
			} );
			document.getElementById( 'rsa-uf-btn-journey' ).addEventListener( 'click', function () {
				activeView = 'journey';
				showView( data );
			} );

			showView( data );
		}

		function showView( pathData ) {
			var view = document.getElementById( 'rsa-uf-view' );
			if ( ! view ) { return; }

			var bE = document.getElementById( 'rsa-uf-btn-explorer' );
			var bJ = document.getElementById( 'rsa-uf-btn-journey' );
			if ( bE ) { bE.className = 'rsa-btn ' + ( activeView === 'explorer' ? 'rsa-btn-primary' : 'rsa-btn-ghost' ); }
			if ( bJ ) { bJ.className = 'rsa-btn ' + ( activeView === 'journey'  ? 'rsa-btn-primary' : 'rsa-btn-ghost' ); }

			if ( activeView === 'explorer' ) {
				view.innerHTML = '<div id="rsa-flow-chart"></div>';
				initPathExplorer( pathData );
			} else {
				view.innerHTML = '<p class="rsa-field-hint" style="padding:16px 0">Loading\u2026</p>';
				apiGet( 'user-flow/journey', { period: state.period, limit: 100 } ).then( function ( jd ) {
					if ( ! jd.rows || ! jd.rows.length ) {
						view.innerHTML = '<p class="rsa-empty">No journey data for this period.</p>';
						return;
					}
					var rows = jd.rows.map( function ( r ) {
						return '<tr>' +
							'<td class="rsa-td-path">' + esc( r.from_page ) + '</td>' +
							'<td class="rsa-td-path">' + esc( r.to_page ) + '</td>' +
							'<td>' + fmt( r.count ) + '</td>' +
							'</tr>';
					} );
					view.innerHTML =
						'<div class="rsa-table-wrap"><table class="rsa-table">' +
						'<thead><tr><th>From Page</th><th>To Page</th><th>Transitions</th></tr></thead>' +
						'<tbody>' + rows.join( '' ) + '</tbody></table></div>';
				} ).catch( function () {
					view.innerHTML = '<p class="rsa-empty">Could not load journey data.</p>';
				} );
			}
		}

		// Path Explorer — ported from admin-charts.js initPathExplorer()
		function initPathExplorer( pathData ) {
			var flowContainer = document.getElementById( 'rsa-flow-chart' );
			if ( ! flowContainer ) { return; }

			var steps    = ( pathData && pathData.steps ) ? pathData.steps : {};
			var links    = ( pathData && pathData.links  ) ? pathData.links  : [];
			var stepNums = Object.keys( steps ).map( Number ).sort( function ( a, b ) { return a - b; } );
			if ( ! stepNums.length ) {
				flowContainer.innerHTML = '<p class="rsa-empty">No flow data available.</p>';
				return;
			}

			// Build transition map  linkMap[step][fromPage] = [{to,count}...]
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

			// Funnel summary bar
			var stepTotals = stepNums.map( function ( sn ) {
				var arr = steps[ sn ] || [];
				return { step: sn, total: arr.reduce( function ( s, p ) { return s + p.sessions; }, 0 ) };
			} );

			flowContainer.innerHTML = '';

			if ( stepTotals.length >= 2 ) {
				var maxTot = stepTotals[0].total || 1;
				var funnel = document.createElement( 'div' );
				funnel.className = 'rsa-funnel';

				stepTotals.forEach( function ( st, idx ) {
					var heightPct = Math.round( st.total / maxTot * 100 );
					var dropPct   = idx === 0 ? 100 : Math.round( st.total / maxTot * 100 );

					var step = document.createElement( 'div' );
					step.className = 'rsa-funnel-step';

					var bg = document.createElement( 'div' );
					bg.className    = 'rsa-funnel-step-bg';
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

				flowContainer.appendChild( funnel );
			}

			// Explorer columns
			var explorer = document.createElement( 'div' );
			explorer.className = 'rsa-explorer';
			flowContainer.appendChild( explorer );

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

				explorer.appendChild( col );
				colEls.push( list );
			}

			function renderCol( colIdx, pageList, colTotal ) {
				var listEl = colEls[ colIdx ];
				listEl.innerHTML = '';

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
					item.className = 'rsa-explorer-item' +
						( isActive ? ' is-selected' : '' ) +
						( isExit   ? ' is-exit'     : '' ) +
						( hasNext  ? ' is-clickable' : '' );

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
						var arrow = document.createElement( 'span' );
						arrow.className   = 'rsa-explorer-item-arrow';
						arrow.textContent = '\u203a';
						item.appendChild( arrow );

						item.addEventListener( 'click', ( function ( page, ci, pages, tot ) {
							return function () {
								selected[ ci ] = page;
								renderCol( ci, pages, tot );
								cascade( ci );
							};
						}( pg.page, colIdx, pageList, colTotal ) ) );
					}

					listEl.appendChild( item );
				} );
			}

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

			// Populate first column and cascade
			var step1     = steps[ stepNums[0] ] || [];
			var step1Tot  = step1.reduce( function ( s, p ) { return s + p.sessions; }, 0 );
			var col0Pages = step1.map( function ( p ) { return { page: p.page, count: p.sessions }; } );
			var topEntry  = null;
			for ( var ei = 0; ei < col0Pages.length; ei++ ) {
				if ( col0Pages[ ei ].page !== '(exit)' ) { topEntry = col0Pages[ ei ].page; break; }
			}
			selected[ 0 ] = topEntry;
			renderCol( 0, col0Pages, step1Tot );
			cascade( 0 );
		}
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
		} ).catch( function ( err ) { handleApiError( err, container ); } );
	}

	// -----------------------------------------------------------------------
	// Heatmap
	// -----------------------------------------------------------------------
	function renderHeatmap( container ) {
		container.innerHTML =
			'<div class="rsa-chart-card">' +
				'<h3>Click Heatmap</h3>' +
				'<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">' +
					'<label style="font-size:13px;font-weight:600;flex-shrink:0">Page:</label>' +
					'<select id="rsa-hm-page" style="flex:1;min-width:160px;padding:8px 10px;border:1px solid var(--rsa-border);' +
						'border-radius:var(--rsa-radius);font-size:13px;color:var(--rsa-text);background:var(--rsa-surface)">' +
						'<option value="/">Loading\u2026</option>' +
					'</select>' +
				'</div>' +
			'</div>' +
			'<div id="rsa-hm-results"></div>';

		var isSameOrigin = !!( window.RSA_CONFIG && window.RSA_CONFIG.nonce );

		// Map normalised weight [0-1] to an RGBA colour: blue → green → yellow → red
		function heatColour( t, alpha ) {
			var seg, r, g, b;
			if ( t < 0.25 ) {
				seg = t / 0.25;
				r = 74;  g = Math.round( 144 + seg * ( 192 - 144 ) );  b = Math.round( 184 + seg * ( 255 - 184 ) );
			} else if ( t < 0.5 ) {
				seg = ( t - 0.25 ) / 0.25;
				r = Math.round( 74 + seg * ( 144 - 74 ) );  g = Math.round( 192 + seg * ( 220 - 192 ) );  b = Math.round( 255 - seg * 255 );
			} else if ( t < 0.75 ) {
				seg = ( t - 0.5 ) / 0.25;
				r = Math.round( 144 + seg * ( 245 - 144 ) );  g = Math.round( 220 - seg * ( 220 - 197 ) );  b = Math.round( seg * 24 );
			} else {
				seg = ( t - 0.75 ) / 0.25;
				r = Math.round( 245 - seg * ( 245 - 232 ) );  g = Math.round( 197 - seg * ( 197 - 83 ) );  b = Math.round( 24 + seg * ( 42 - 24 ) );
			}
			return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
		}

		// Draw heatmap dots onto a canvas; W/H are the canvas coordinate dimensions
		function drawDots( ctx, data, W, H ) {
			var maxW = Math.max.apply( null, data.map( function ( p ) { return p.weight || 1; } ) );
			data.forEach( function ( p ) {
				var t    = ( p.weight || 1 ) / maxW;
				var px   = ( p.x / 100 ) * W;
				var py   = ( p.y / 100 ) * H;
				var brad = Math.max( 24, Math.round( t * 80 ) );
				if ( isNaN( px ) || isNaN( py ) ) return;
				var grad = ctx.createRadialGradient( px, py, 0, px, py, brad );
				grad.addColorStop( 0,   heatColour( t, 0.85 ) );
				grad.addColorStop( 0.5, heatColour( t, 0.4 ) );
				grad.addColorStop( 1,   heatColour( t, 0 ) );
				ctx.fillStyle = grad;
				ctx.beginPath();
				ctx.arc( px, py, brad, 0, Math.PI * 2 );
				ctx.fill();
			} );
		}

		function loadHeatmap() {
			var sel      = document.getElementById( 'rsa-hm-page' );
			var pagePath = sel ? ( sel.value || '/' ) : '/';
			var results  = document.getElementById( 'rsa-hm-results' );
			results.innerHTML = '<p class="rsa-field-hint" style="padding:16px 0">Loading\u2026</p>';

			apiGet( 'heatmap', { period: state.period, page: pagePath } ).then( function ( data ) {
				if ( ! data || ! data.length ) {
					results.innerHTML =
						'<div class="rsa-chart-card" style="margin-top:16px">' +
							'<p class="rsa-empty">No heatmap data for <code>' + esc( pagePath ) + '</code> in this period.<br>' +
							'Data is aggregated nightly from click events.</p>' +
						'</div>';
					return;
				}

				var W = 800, H = 1120;
				var legend =
					'<div id="rsa-hm-legend" style="display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:var(--rsa-muted)">' +
						'<span>Low</span>' +
						'<div style="flex:1;height:8px;border-radius:4px;background:linear-gradient(to right,#4a90b8,#90c060,#f5c518,#e8532a)"></div>' +
						'<span>High</span>' +
					'</div>';

				if ( isSameOrigin ) {
					// Same-origin: show the real page in an iframe with a canvas overlay
					var iframeH = 700;
					var pageUrl = ( state.siteUrl || '' ).replace( /\/$/, '' ) + pagePath;
					results.innerHTML =
						'<div class="rsa-chart-card" style="margin-top:16px">' +
							'<h3>Click Heatmap \u2014 ' + esc( pagePath ) + '</h3>' +
							'<p class="rsa-field-hint" style="margin-bottom:12px">' + fmt( data.length ) + ' click point' + ( data.length !== 1 ? 's' : '' ) + ' \u2014 warmer colours indicate more clicks.</p>' +
							'<div id="rsa-hm-wrapper" style="position:relative;overflow:hidden;height:' + iframeH + 'px;border-radius:var(--rsa-radius)">' +
								'<iframe id="rsa-hm-iframe" src="' + esc( pageUrl ) + '" ' +
									'style="display:block;width:100%;height:' + iframeH + 'px;border:none" ' +
									'scrolling="no" sandbox="allow-same-origin allow-scripts"></iframe>' +
								'<canvas id="c-heatmap" ' +
									'style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none"></canvas>' +
							'</div>' +
							legend +
						'</div>';

					// Size canvas buffer to match wrapper pixel dimensions (synchronous — layout is settled)
					var canvas = document.getElementById( 'c-heatmap' );
					if ( canvas ) {
						var wrapper = document.getElementById( 'rsa-hm-wrapper' );
						canvas.width  = ( wrapper ? wrapper.clientWidth : 0 ) || W;
						canvas.height = iframeH;
						var ctx = canvas.getContext( '2d' );
						if ( ctx ) drawDots( ctx, data, canvas.width, canvas.height );
					}

				} else {
					// Fallback: canvas page silhouette (cross-origin)
					results.innerHTML =
						'<div class="rsa-chart-card" style="margin-top:16px">' +
							'<h3>Click Heatmap \u2014 ' + esc( pagePath ) + '</h3>' +
							'<p class="rsa-field-hint" style="margin-bottom:12px">' + fmt( data.length ) + ' click point' + ( data.length !== 1 ? 's' : '' ) + ' \u2014 warmer colours indicate more clicks.</p>' +
							'<div style="position:relative;width:100%">' +
								'<canvas id="c-heatmap" width="' + W + '" height="' + H + '" style="display:block;width:100%;border-radius:var(--rsa-radius)"></canvas>' +
							'</div>' +
							legend +
						'</div>';

					var canvas = document.getElementById( 'c-heatmap' );
					if ( ! canvas ) return;

					( function scaleCanvas() {
						var displayW = canvas.offsetWidth;
						if ( displayW > 0 ) {
							canvas.style.height = Math.round( displayW * H / W ) + 'px';
						} else {
							requestAnimationFrame( scaleCanvas );
						}
					}() );

					var ctx = canvas.getContext( '2d' );
					if ( ! ctx ) return;

					// ── Page silhouette ──────────────────────────────────────
					ctx.fillStyle = '#ffffff';
					ctx.fillRect( 0, 0, W, H );

					ctx.fillStyle = '#2c3e50';
					ctx.fillRect( 0, 0, W, 60 );

					ctx.fillStyle = '#4a90b8';
					ctx.fillRect( 20, 16, 100, 28 );

					ctx.fillStyle = 'rgba(255,255,255,0.5)';
					[ W - 220, W - 160, W - 100, W - 48 ].forEach( function ( x ) {
						ctx.fillRect( x, 22, 44, 16 );
					} );

					ctx.fillStyle = '#e8f0f7';
					ctx.fillRect( 0, 60, W, 180 );

					ctx.fillStyle = '#bbc8d4';
					ctx.fillRect( 60, 96, W - 280, 28 );
					ctx.fillRect( 60, 134, W - 360, 20 );

					ctx.fillStyle = '#4a90b8';
					ctx.beginPath();
					ctx.roundRect( 60, 168, 140, 40, 6 );
					ctx.fill();

					var col1W = Math.round( W * 0.62 );
					var col2X = col1W + 24;
					var col2W = W - col2X - 24;
					var yBase = 272;

					ctx.fillStyle = '#cdd5dc';
					ctx.fillRect( 24, yBase, 220, 22 );
					yBase += 40;

					ctx.fillStyle = '#e4e9ee';
					for ( var li = 0; li < 5; li++ ) {
						ctx.fillRect( 24, yBase + li * 22, ( li === 4 ? col1W * 0.6 : col1W - 24 ), 14 );
					}
					yBase += 5 * 22 + 24;

					ctx.fillStyle = '#dde4ec';
					ctx.fillRect( 24, yBase, col1W - 24, 180 );
					ctx.fillStyle = '#c0cbd6';
					ctx.fillRect( 24 + ( col1W - 24 ) / 2 - 24, yBase + 75, 48, 30 );
					yBase += 210;

					ctx.fillStyle = '#e4e9ee';
					for ( var li2 = 0; li2 < 4; li2++ ) {
						ctx.fillRect( 24, yBase + li2 * 22, ( li2 === 3 ? col1W * 0.4 : col1W - 24 ), 14 );
					}

					var sbY = 272 + 40;
					ctx.fillStyle = '#f2f5f8';
					ctx.beginPath();
					if ( ctx.roundRect ) { ctx.roundRect( col2X, sbY, col2W, 160, 8 ); } else { ctx.rect( col2X, sbY, col2W, 160 ); }
					ctx.fill();
					ctx.fillStyle = '#dde4ec';
					ctx.fillRect( col2X + 16, sbY + 20, col2W - 32, 14 );
					ctx.fillRect( col2X + 16, sbY + 44, col2W - 32, 10 );
					ctx.fillRect( col2X + 16, sbY + 62, col2W - 64, 10 );
					ctx.fillStyle = '#4a90b8';
					ctx.beginPath();
					if ( ctx.roundRect ) { ctx.roundRect( col2X + 16, sbY + 88, col2W - 32, 34, 6 ); } else { ctx.rect( col2X + 16, sbY + 88, col2W - 32, 34 ); }
					ctx.fill();

					ctx.fillStyle = '#2c3e50';
					ctx.fillRect( 0, H - 80, W, 80 );
					ctx.fillStyle = 'rgba(255,255,255,0.2)';
					[ 24, W / 4, W / 2, W * 0.75 ].forEach( function ( x ) {
						ctx.fillRect( x, H - 60, 80, 10 );
						ctx.fillRect( x, H - 44, 60, 8 );
						ctx.fillRect( x, H - 30, 70, 8 );
					} );
					// ── End page silhouette ────────────────────────────────

					drawDots( ctx, data, W, H );
				}

			} ).catch( function () {
				results.innerHTML =
					'<div class="rsa-chart-card" style="margin-top:16px">' +
						'<p class="rsa-empty">Could not load heatmap data. Please try again.</p>' +
					'</div>';
			} );
		}

		// Populate page dropdown from filter-options, then auto-load
		apiGet( 'filter-options', { period: state.period } ).then( function ( opts ) {
			var pages = ( opts && opts.pages && opts.pages.length ) ? opts.pages : [ '/' ];
			var sel   = document.getElementById( 'rsa-hm-page' );
			if ( sel ) {
				sel.innerHTML = pages.map( function ( p ) {
					return '<option value="' + esc( p ) + '">' + esc( p ) + '</option>';
				} ).join( '' );
				sel.addEventListener( 'change', loadHeatmap );
			}
			setLoading( false );
			loadHeatmap();
		} ).catch( function () {
			var sel = document.getElementById( 'rsa-hm-page' );
			if ( sel ) {
				sel.innerHTML = '<option value="/">/</option>';
				sel.addEventListener( 'change', loadHeatmap );
			}
			setLoading( false );
			loadHeatmap();
		} );
	}

	// -----------------------------------------------------------------------
	// Export
	// -----------------------------------------------------------------------
	function renderExport( container ) {
		var periodLabels = {
			'7d'       : 'Last 7 days',
			'30d'      : 'Last 30 days',
			'90d'      : 'Last 90 days',
			'thismonth': 'This month',
			'lastmonth': 'Last month',
			'custom'   : 'Custom range',
		};

		var selPeriod = state.period in periodLabels ? state.period : '30d';

		container.innerHTML =
			'<div class="rsa-chart-card rsa-export-form">' +
				'<h3>Export Data</h3>' +
				'<div class="rsa-form-row">' +
					'<label class="rsa-filter-label" for="rsa-exp-type">Data Type</label>' +
					'<select id="rsa-exp-type">' +
						'<option value="pageviews">Pageviews (events)</option>' +
						'<option value="sessions">Sessions</option>' +
						'<option value="clicks">Click events</option>' +
						'<option value="referrers">Referrers (aggregated)</option>' +
					'</select>' +
				'</div>' +
				'<div class="rsa-form-row">' +
					'<label class="rsa-filter-label" for="rsa-exp-period">Date Range</label>' +
					'<select id="rsa-exp-period">' +
						Object.keys( periodLabels ).map( function ( k ) {
							return '<option value="' + k + '"' + ( k === selPeriod ? ' selected' : '' ) + '>' + periodLabels[ k ] + '</option>';
						} ).join( '' ) +
					'</select>' +
					'<div id="rsa-exp-custom-dates" class="rsa-custom-dates" style="display:' + ( selPeriod === 'custom' ? 'flex' : 'none' ) + '">' +
						'<input type="date" id="rsa-exp-date-from" placeholder="From">' +
						'<span style="color:var(--rsa-muted);font-size:13px">to</span>' +
						'<input type="date" id="rsa-exp-date-to" placeholder="To">' +
					'</div>' +
				'</div>' +
				'<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">' +
					'<button type="button" class="rsa-btn rsa-btn-primary" id="rsa-export-csv">Download CSV</button>' +
					'<button type="button" class="rsa-btn rsa-btn-ghost"  id="rsa-export-json">Download JSON</button>' +
				'</div>' +
				'<div id="rsa-export-status" class="rsa-field-hint" style="margin-top:10px"></div>' +
			'</div>';

		setLoading( false );

		// Show/hide custom date picker
		document.getElementById( 'rsa-exp-period' ).addEventListener( 'change', function () {
			var customDates = document.getElementById( 'rsa-exp-custom-dates' );
			if ( customDates ) customDates.style.display = this.value === 'custom' ? 'flex' : 'none';
		} );

		function doExport( format ) {
			var status    = document.getElementById( 'rsa-export-status' );
			var csvBtn    = document.getElementById( 'rsa-export-csv' );
			var jsonBtn   = document.getElementById( 'rsa-export-json' );
			var dataType  = ( document.getElementById( 'rsa-exp-type' )    || {} ).value || 'pageviews';
			var period    = ( document.getElementById( 'rsa-exp-period' )  || {} ).value || '30d';
			var dateFrom  = ( document.getElementById( 'rsa-exp-date-from' ) || {} ).value || '';
			var dateTo    = ( document.getElementById( 'rsa-exp-date-to'   ) || {} ).value || '';

			status.textContent = 'Preparing download\u2026';
			if ( csvBtn )  csvBtn.disabled  = true;
			if ( jsonBtn ) jsonBtn.disabled = true;

			var qs = 'format=' + encodeURIComponent( format ) +
				'&period=' + encodeURIComponent( period ) +
				'&data_type=' + encodeURIComponent( dataType );
			if ( period === 'custom' && dateFrom ) qs += '&date_from=' + encodeURIComponent( dateFrom );
			if ( period === 'custom' && dateTo )   qs += '&date_to='   + encodeURIComponent( dateTo );

			var url = state.siteUrl + '/wp-json/rsa/v1/export?' + qs;

			fetch( url, {
				headers: { 'Authorization': 'Basic ' + state.credentials },
			} ).then( function ( res ) {
				if ( res.status === 401 || res.status === 403 ) { throw new Error( 'auth' ); }
				if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
				return format === 'csv' ? res.blob() : res.json().then( function ( json ) {
					var payload = ( json && json.ok && json.data ) ? json.data : json;
					return new Blob( [ JSON.stringify( payload, null, 2 ) ], { type: 'application/json' } );
				} );
			} ).then( function ( blob ) {
				var a      = document.createElement( 'a' );
				a.href     = URL.createObjectURL( blob );
				a.download = 'rsa-' + dataType + '-' + period + '.' + format;
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( a.href );
				status.textContent = 'Download started.';
				if ( csvBtn )  csvBtn.disabled  = false;
				if ( jsonBtn ) jsonBtn.disabled = false;
			} ).catch( function ( err ) {
				if ( err.message === 'auth' ) { clearAllSites(); showLogin(); return; }
				status.textContent = 'Export failed. Please try again.';
				if ( csvBtn )  csvBtn.disabled  = false;
				if ( jsonBtn ) jsonBtn.disabled = false;
			} );
		}

		document.getElementById( 'rsa-export-csv'  ).addEventListener( 'click', function () { doExport( 'csv' ); } );
		document.getElementById( 'rsa-export-json' ).addEventListener( 'click', function () { doExport( 'json' ); } );
	}

	// -----------------------------------------------------------------------
	// Error handler
	// -----------------------------------------------------------------------
	function handleApiError( err, container ) {
		setLoading( false );
		if ( err.message === 'auth' ) {
			clearAllSites();
			showLogin();
			return;
		}
		if ( ! container ) return;
		container.innerHTML =
			'<p class="rsa-empty">Could not load data (' + esc( err.message ) + '). ' +
			'Check your connection and try refreshing.</p>';
	}

	// -----------------------------------------------------------------------
	// Chart helpers (thin wrappers around Chart.js 4.x)
	// -----------------------------------------------------------------------
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
