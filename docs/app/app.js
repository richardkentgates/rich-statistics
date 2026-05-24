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
		dateFrom    : '',        // custom range start (YYYY-MM-DD)
		dateTo      : '',        // custom range end   (YYYY-MM-DD)
		view        : 'overview',
		charts      : {},        // keyed by canvas id
		cache       : {},        // keyed by endpoint+period
		connState   : 'online',  // 'online' | 'offline' | 'site-down'
		navOpen     : false,
		isPremium   : false,
		upgradeUrl  : '',        // Freemius upgrade URL
		channel     : 'stable',  // release channel from /info endpoint
	};

	// -----------------------------------------------------------------------
	// Init
	// -----------------------------------------------------------------------
	document.addEventListener( 'DOMContentLoaded', function () {
		loadStoredSites();

		// Load premium status from RSA_CONFIG (injected by WordPress)
		if ( window.RSA_CONFIG ) {
			state.isPremium  = window.RSA_CONFIG.isPremium  || false;
			state.upgradeUrl = window.RSA_CONFIG.upgradeUrl || '';
		}

		var nonceAuth = !! ( window.RSA_CONFIG && window.RSA_CONFIG.nonce && state.siteUrl );
		if ( ( state.siteUrl && state.credentials ) || nonceAuth ) {
			renderSiteSwitcher();
			showApp();
			if ( ! state.isPremium ) {
				markPremiumNav();
			}
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
		bindAiSettings();

		// Connection banners
		if ( navigator.onLine === false ) {
			setConnBanner( 'offline' );
		}
		window.addEventListener( 'offline', function () {
			setConnBanner( 'offline' );
		} );
		window.addEventListener( 'online', function () {
			// Connectivity restored: clear the banner and re-fetch the current view.
			setConnBanner( null );
			if ( state.siteUrl ) {
				state.cache = {};
				renderView( state.view );
			}
		} );
	} );

	// -----------------------------------------------------------------------
	// Multi-site storage
	// -----------------------------------------------------------------------

	function loadStoredSites() {
		state.sites    = JSON.parse( localStorage.getItem( 'rsa_sites' ) || '[]' );
		state.activeId = localStorage.getItem( 'rsa_active' ) || '';
		state.period   = localStorage.getItem( 'rsa_period'    ) || '30d';
		var storedAi   = localStorage.getItem( 'rsa_ai_provider' );
		state.aiProvider = storedAi ? JSON.parse( storedAi ) : null;
		if ( state.aiProvider ) {
			state.aiProvider.voiceInput  = state.aiProvider.voiceInput  !== undefined ? state.aiProvider.voiceInput  : false;
			state.aiProvider.voiceOutput = state.aiProvider.voiceOutput !== undefined ? state.aiProvider.voiceOutput : false;
			state.aiProvider.voiceLang   = state.aiProvider.voiceLang   || 'en-US';
			state.aiProvider.voiceSpeed  = state.aiProvider.voiceSpeed  || 1.0;
			state.aiProvider.autoSpeak   = state.aiProvider.autoSpeak   !== undefined ? state.aiProvider.autoSpeak   : false;
		}
		state.dateFrom = localStorage.getItem( 'rsa_date_from' ) || '';
		state.dateTo   = localStorage.getItem( 'rsa_date_to'   ) || '';

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

	// -----------------------------------------------------------------------
	// Persistent data cache (localStorage) — offline fallback in Tauri
	// (no service worker) and any session where the SW cache was evicted.
	// Keyed by the full request URL. Values are not encrypted — they contain
	// only the same aggregated stats data already visible in the dashboard.
	// -----------------------------------------------------------------------
	function dcSet( key, data ) {
		try {
			localStorage.setItem( 'rsa_dc_' + key, JSON.stringify( { ts: Date.now(), d: data } ) );
		} catch ( _ ) {} // Quota errors are ignored — cache is best-effort
	}

	function dcGet( key ) {
		try {
			var raw = localStorage.getItem( 'rsa_dc_' + key );
			if ( ! raw ) return null;
			return JSON.parse( raw ).d;
		} catch ( _ ) { return null; }
	}

	function setActiveSite( id ) {
		var targetSite = state.sites.find( function ( s ) { return s.id === id; } );
		// In the Tauri desktop app never navigate away to the hosted web app —
		// version routing is handled locally by checkPluginVersion / tauriNavigateToVersion.
		if ( ! isTauri() && targetSite && targetSite.appUrl ) {
			var targetOrigin = '';
			var targetProto  = '';
			try {
				var parsedAppUrl = new URL( targetSite.appUrl );
				targetOrigin = parsedAppUrl.origin;
				targetProto  = parsedAppUrl.protocol;
			} catch ( _ ) {}
			if ( targetOrigin && targetOrigin !== window.location.origin &&
					( targetProto === 'https:' || targetProto === 'http:' ) ) {
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
		if ( typeof crypto !== 'undefined' && crypto.randomUUID ) {
			return crypto.randomUUID();
		}
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
		// Inject custom date range into every request when active
		if ( params && state.period === 'custom' && state.dateFrom && state.dateTo ) {
			params = Object.assign( {}, params, { date_from: state.dateFrom, date_to: state.dateTo } );
		}
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
			dcSet( cacheKey, data );
			// Clear site-down banner now that the site responded successfully.
			if ( state.connState === 'site-down' ) {
				setConnBanner( null );
			}
			return data;
		} ).catch( function ( err ) {
			// Re-throw auth/HTTP errors — callers handle those via handleApiError.
			// Network errors (TypeError = no response at all): show the right banner
			// and serve stale data from in-memory or localStorage cache if available.
			if ( err.message === 'auth' ) throw err;
			var isNetworkErr = ( err instanceof TypeError || err.name === 'TypeError' );
			if ( isNetworkErr ) {
				var bannerType = navigator.onLine === false ? 'offline' : 'site-down';
				setConnBanner( bannerType );
				var cachedData = state.cache[ cacheKey ];
				if ( cachedData === undefined || cachedData === null ) {
					cachedData = dcGet( cacheKey );
				}
				if ( cachedData !== null && cachedData !== undefined ) {
					return cachedData;
				}
			}
			throw err;
		} );
	}

	// -----------------------------------------------------------------------
	// Login (welcome screen — shown when no sites are connected)
	// -----------------------------------------------------------------------
	function showLogin() {
		document.getElementById( 'rsa-login' ).hidden = false;
		document.getElementById( 'rsa-add-site' ).hidden = true;
		document.getElementById( 'rsa-app' ).hidden = true;
		var desktopInstall = document.getElementById( 'rsa-login-desktop-install' );
		if ( desktopInstall ) {
			desktopInstall.hidden = isTauri();
		}
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
	/**
	 * Return the base URL of the versioned app folder on the external host, or
	 * null if not running from an external versioned URL.
	 *
	 * External versioned URLs look like:
	 *   https://statistics.richardkentgates.com/app/1.3.0/
	 * The pattern is: <origin><anything>/app/<semver>/
	 */
	function getVersionedAppBase() {
		var href = window.location.href;
		var m = href.match( /^(https?:\/\/[^/]+(?:\/[^/]+)*\/v\/)([0-9]+\.[0-9]+\.[0-9]+)\// );
		if ( m ) return { base: m[1], current: m[2] };
		return null;
	}

	/**
	 * Returns true when running inside the native Tauri desktop window.
	 * Tauri 2 exposes __TAURI_INTERNALS__ on the window object.
	 */
	function isTauri() {
		return !! ( window.__TAURI_INTERNALS__ || window.__TAURI__ );
	}

	/**
	 * Returns the current semver extracted from the Tauri local URL, e.g.
	 * http://tauri.localhost/v/2.4.15/stable/  →  "2.4.15", or null if at root.
	 */
	function getTauriCurrentVersion() {
		var m = window.location.pathname.match( /^\/(?:v\/)?([0-9]+\.[0-9]+\.[0-9]+)\// );
		return m ? m[1] : null;
	}

	/**
	 * Navigate to a bundled versioned folder in the Tauri local server.
	 * Falls back to the latest bundled version if the requested one isn't found,
	 * and shows an update prompt if the plugin version is newer than all bundles.
	 *
	 * @param {string} pluginVersion - Semver version string.
	 * @param {string} [channel] - Release channel ('stable' or 'beta'). Default 'stable'.
	 */
	function tauriNavigateToVersion( pluginVersion, channel ) {
		channel = /^(stable|beta)$/.test( channel ) ? channel : 'stable';
		// Reject anything that is not a clean semver — prevents path traversal
		// if the API response were ever tampered with.
		if ( ! /^\d+\.\d+\.\d+$/.test( pluginVersion ) ) return;
		var current = getTauriCurrentVersion();
		if ( current === pluginVersion ) return; // Already on correct version

		var versionUrl  = '/v/' + pluginVersion + '/' + channel + '/';
		var indexUrl    = versionUrl + 'index.html';
		var versionsFile = channel === 'beta' ? '/versions-beta.json' : '/versions.json';

		fetch( versionsFile )
			.then( function ( r ) { return r.ok ? r.json() : []; } )
			.then( function ( bundled ) {
				// Fall back to versions.json if beta list is empty
				if ( ! bundled.length && versionsFile === '/versions-beta.json' ) {
					return fetch( '/versions.json' ).then( function ( r ) { return r.ok ? r.json() : []; } );
				}
				return bundled;
			} )
			.then( function ( bundled ) {
				if ( bundled.indexOf( pluginVersion ) !== -1 ) {
					// Verify the versioned folder is actually present before navigating.
					fetch( indexUrl, { method: 'HEAD' } )
						.then( function ( r ) {
							if ( r.ok ) {
								window.location.href = versionUrl;
							} else if ( channel !== 'stable' ) {
								// Channel subdir not found — try stable as fallback
								window.location.href = '/v/' + pluginVersion + '/stable/';
							}
						} )
						.catch( function () {} );
					return;
				}
				// Plugin not in bundled versions — check if a desktop update exists.
				// Only show the banner if an actual newer desktop build is available.
				var latest = bundled.slice().sort( function ( a, b ) {
					var pa = a.split( '.' ).map( Number );
					var pb = b.split( '.' ).map( Number );
					for ( var i = 0; i < 3; i++ ) {
						if ( pa[ i ] !== pb[ i ] ) return pb[ i ] - pa[ i ];
					}
					return 0;
				} )[ 0 ];
				fetch( '/dist/update.json' )
					.then( function ( r ) { return r.ok ? r.json() : null; } )
					.then( function ( upd ) {
						if ( upd && upd.version ) {
							var uv = upd.version.split( '.' ).map( Number );
							var bv = latest.split( '.' ).map( Number );
							var newer = false;
							for ( var i = 0; i < 3; i++ ) {
								if ( uv[ i ] > bv[ i ] ) { newer = true; break; }
								if ( uv[ i ] < bv[ i ] ) break;
							}
							if ( newer ) {
								showDesktopUpdateBanner( pluginVersion, bundled );
							}
						}
					} )
					.catch( function () {} );
				if ( latest && current !== latest ) {
					window.location.href = '/v/' + latest + '/' + channel + '/';
				}
			} )
			.catch( function () {} );
	}

	/**
	 * Show a dismissible banner inside the Tauri window when the installed
	 * plugin is newer than the desktop app bundle.
	 */
	function showDesktopUpdateBanner( pluginVersion, bundled ) {
		if ( document.getElementById( 'rsa-desktop-update-banner' ) ) return;
		var latest  = bundled.slice().sort( function ( a, b ) {
			var pa = a.split( '.' ).map( Number );
			var pb = b.split( '.' ).map( Number );
			for ( var i = 0; i < 3; i++ ) { if ( pa[i] !== pb[i] ) return pb[i] - pa[i]; }
			return 0;
		} )[ 0 ] || '?';
		var banner = document.createElement( 'div' );
		banner.id = 'rsa-desktop-update-banner';
		banner.innerHTML =
			'<span class="rsa-desktop-update-icon">&#9888;</span>' +
			'<span>Plugin v' + esc( pluginVersion ) + ' requires a newer desktop app ' +
			'(you have bundles up to v' + esc( latest ) + '). An update is available via your system package manager.</span>' +
			'<button id="rsa-desktop-update-dismiss" aria-label="Dismiss">&times;</button>';
		document.body.insertBefore( banner, document.body.firstChild );
		document.getElementById( 'rsa-desktop-update-dismiss' ).addEventListener( 'click', function () {
			banner.remove();
		} );
	}

	function checkPluginVersion() {
		if ( ! state.siteUrl ) return;

		var versionKey = 'rsa_pv_' + state.activeId;
		fetch( state.siteUrl + '/wp-json/rsa/v1/info', { headers: { 'Accept': 'application/json' } } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( json ) {
				if ( ! json || ! json.data ) return;
				var info = json.data;

				// Sync premium status from the server
				if ( info.is_premium !== undefined ) {
					state.isPremium = !! info.is_premium;
				}

				// Store release channel for version routing
				state.channel = info.channel === 'beta' ? 'beta' : 'stable';

				var badge = document.getElementById( 'rsa-plugin-version' );
				if ( badge ) badge.textContent = 'v' + info.version;

				// In Tauri: route to the matching bundled version folder (local, no external URLs).
				if ( isTauri() ) {
					tauriNavigateToVersion( info.version, state.channel );
					localStorage.setItem( versionKey, info.version );
					return;
				}

				// Browser: clear SW caches and reload if the plugin version changed.
				var stored = localStorage.getItem( versionKey );
				if ( stored && stored !== info.version ) {
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

		var premiumFeatures = {
			'campaigns'  : 'Campaigns',
			'user-flow'  : 'User Flow',
			clicks       : 'Click Tracking',
			heatmap      : 'Heatmap',
			export       : 'Export',
			woocommerce  : 'WooCommerce',
		};

	/** Mark premium nav links with a visual indicator for free users. */
	function markPremiumNav() {
		Object.keys( premiumFeatures ).forEach( function ( view ) {
			var link = document.querySelector( '.rsa-nav-link[data-view="' + view + '"]' );
			if ( link ) {
				link.classList.add( 'rsa-nav-premium' );
			}
		} );
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
				overview    : 'Overview',
				pages       : 'Top Pages',
				audience    : 'Audience',
				referrers   : 'Referrers',
				behavior    : 'Behavior',
				campaigns   : 'Campaigns',
				'user-flow' : 'User Flow',
				clicks      : 'Click Tracking',
				heatmap     : 'Heatmap',
				export      : 'Export',
				woocommerce : 'WooCommerce',
				install     : 'Install',
				'ai-settings': 'AI Settings',
			'ai-chat'    : 'AI Assistant',
			};
			document.getElementById( 'rsa-view-title' ).textContent = titles[ view ] || view;

			// Check premium gate before rendering
			if ( ! state.isPremium && premiumFeatures[ view ] ) {
				var container = document.getElementById( 'rsa-view-' + view );
				if ( container ) {
					showUpgradeOverlay( container, premiumFeatures[ view ] );
					return;
				}
			}

			renderView( view );
		}

	function bindPeriodSelect() {
		var periodSel     = document.getElementById( 'rsa-period-select' );
		var customDates   = document.getElementById( 'rsa-custom-dates' );
		var dateFromInput = document.getElementById( 'rsa-date-from' );
		var dateToInput   = document.getElementById( 'rsa-date-to' );

		function applyCustomDates() {
			if ( dateFromInput && dateToInput && dateFromInput.value && dateToInput.value ) {
				state.dateFrom = dateFromInput.value;
				state.dateTo   = dateToInput.value;
				localStorage.setItem( 'rsa_date_from', state.dateFrom );
				localStorage.setItem( 'rsa_date_to',   state.dateTo );
				state.cache = {};
				renderView( state.view );
			}
		}

		periodSel.addEventListener( 'change', function () {
			state.period = this.value;
			localStorage.setItem( 'rsa_period', state.period );
			state.cache = {};
			if ( customDates ) customDates.hidden = ( state.period !== 'custom' );
			if ( state.period !== 'custom' ) {
				state.dateFrom = '';
				state.dateTo   = '';
				renderView( state.view );
			}
		} );

		if ( dateFromInput ) dateFromInput.addEventListener( 'change', applyCustomDates );
		if ( dateToInput   ) dateToInput.addEventListener(   'change', applyCustomDates );

		// Init on load
		if ( customDates ) customDates.hidden = ( state.period !== 'custom' );
		if ( state.period === 'custom' ) {
			if ( dateFromInput && state.dateFrom ) dateFromInput.value = state.dateFrom;
			if ( dateToInput   && state.dateTo   ) dateToInput.value   = state.dateTo;
		}
	}

	function bindMenuToggle() {
		document.getElementById( 'rsa-menu-toggle' ).addEventListener( 'click', function ( e ) {
			e.stopPropagation(); // prevent click bubbling to rsa-main which would re-close the nav
			toggleNav();
		} );
		// Close nav when clicking outside of it on mobile
		document.getElementById( 'rsa-main' ).addEventListener( 'click', function () {
			if ( state.navOpen ) closeNav();
		} );
	}

	// -----------------------------------------------------------------------
	// -----------------------------------------------------------------------
	// PWA install prompt
	// -----------------------------------------------------------------------
	var _installPrompt = null;

	function bindInstallPrompt() {
		function allInstallBtns() {
			return Array.prototype.slice.call( document.querySelectorAll( '.rsa-install-btn' ) );
		}

		// Chrome / Edge / Samsung Internet fire this before showing the mini-infobar
		window.addEventListener( 'beforeinstallprompt', function ( e ) {
			e.preventDefault();
			_installPrompt = e;
			allInstallBtns().forEach( function ( btn ) { btn.hidden = false; } );
		} );

		// Hide buttons once installed
		window.addEventListener( 'appinstalled', function () {
			_installPrompt = null;
			allInstallBtns().forEach( function ( btn ) { btn.hidden = true; } );
		} );

		// Click handler — works for any .rsa-install-btn present now or later
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.rsa-install-btn' );
			if ( ! btn || ! _installPrompt ) return;
			_installPrompt.prompt();
			_installPrompt.userChoice.then( function ( outcome ) {
				_installPrompt = null;
				if ( outcome.outcome === 'accepted' ) {
					allInstallBtns().forEach( function ( b ) { b.hidden = true; } );
				}
			} );
		} );
	}

	function bindAiSettings() {
		document.addEventListener( 'input', function ( e ) {
			if ( e.target.id === 'rsa-ai-voice-speed' ) {
				var val = document.getElementById( 'rsa-ai-speed-val' );
				if ( val ) val.textContent = e.target.value;
			}
		} );
		document.addEventListener( 'change', function ( e ) {
			if ( e.target.id === 'rsa-ai-provider' ) {
				applyProviderPreset( e.target.value );
			}
			if ( e.target.id === 'rsa-ai-model' ) {
				var customRow = document.getElementById( 'rsa-ai-model-custom-row' );
				if ( customRow ) {
					customRow.style.display = e.target.value === '__custom__' ? '' : 'none';
				}
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.id === 'rsa-ai-tauri-detect' ) {
				onTauriDetect();
				return;
			}
			if ( e.target.id === 'rsa-ai-refresh-models' ) {
				onRefreshModels();
				return;
			}
			if ( e.target.id === 'rsa-ai-save' ) {
				var endpoint = document.getElementById( 'rsa-ai-endpoint' ).value.trim();
				var apiKey   = document.getElementById( 'rsa-ai-key' ).value.trim();
				var modelSelect = document.getElementById( 'rsa-ai-model' );
				var model = modelSelect ? modelSelect.value.trim() : '';
				if ( model === '__custom__' ) {
					var customInput = document.getElementById( 'rsa-ai-model-custom' );
					model = customInput ? customInput.value.trim() : '';
				}
				if ( ! endpoint || ! model ) return;
				var voiceInput  = document.getElementById( 'rsa-ai-voice-input' ) ? document.getElementById( 'rsa-ai-voice-input' ).checked : false;
				var voiceOutput = document.getElementById( 'rsa-ai-voice-output' ) ? document.getElementById( 'rsa-ai-voice-output' ).checked : false;
				var voiceLang   = document.getElementById( 'rsa-ai-voice-lang' ) ? document.getElementById( 'rsa-ai-voice-lang' ).value : 'en-US';
				var voiceSpeed  = document.getElementById( 'rsa-ai-voice-speed' ) ? parseFloat( document.getElementById( 'rsa-ai-voice-speed' ).value ) : 1.0;
				var autoSpeak   = document.getElementById( 'rsa-ai-auto-speak' ) ? document.getElementById( 'rsa-ai-auto-speak' ).checked : false;
				state.aiProvider = {
					endpoint: endpoint,
					apiKey: apiKey || '',
					model: model,
					voiceInput: voiceInput,
					voiceOutput: voiceOutput,
					voiceLang: voiceLang,
					voiceSpeed: voiceSpeed,
					autoSpeak: autoSpeak,
				};
				localStorage.setItem( 'rsa_ai_provider', JSON.stringify( state.aiProvider ) );
				var btn = document.getElementById( 'rsa-ai-save' );
				btn.textContent = 'Saved!';
				setTimeout( function () { btn.textContent = 'Save AI Settings'; }, 2000 );
			}
			if ( e.target.id === 'rsa-ai-clear' ) {
				state.aiProvider = null;
				localStorage.removeItem( 'rsa_ai_provider' );
				var prov = document.getElementById( 'rsa-ai-provider' );
				if ( prov ) prov.value = 'openai';
				var ep = document.getElementById( 'rsa-ai-endpoint' );
				if ( ep ) { ep.value = 'https://api.openai.com/v1/chat/completions'; ep.readOnly = true; ep.style.background = '#f5f5f5'; }
				var mod = document.getElementById( 'rsa-ai-model' );
				if ( mod ) { mod.innerHTML = '<option value="">Select a model…</option><option value="__custom__">Custom model…</option>'; mod.value = ''; }
				var customRow = document.getElementById( 'rsa-ai-model-custom-row' );
				if ( customRow ) customRow.style.display = 'none';
				var customInput = document.getElementById( 'rsa-ai-model-custom' );
				if ( customInput ) customInput.value = '';
				var keyF = document.getElementById( 'rsa-ai-key' );
				if ( keyF ) keyF.value = '';
				var keyRow = document.getElementById( 'rsa-ai-key-row' );
				if ( keyRow ) keyRow.style.display = '';
				var tauriRow = document.getElementById( 'rsa-ai-tauri-row' );
				if ( tauriRow ) tauriRow.style.display = 'none';
				var tauriStatus = document.getElementById( 'rsa-ai-tauri-status' );
				if ( tauriStatus ) tauriStatus.textContent = '';
				var vi = document.getElementById( 'rsa-ai-voice-input' );
				if ( vi ) vi.checked = false;
				var vo = document.getElementById( 'rsa-ai-voice-output' );
				if ( vo ) vo.checked = false;
				var as = document.getElementById( 'rsa-ai-auto-speak' );
				if ( as ) as.checked = false;
				var vs = document.getElementById( 'rsa-ai-voice-speed' );
				if ( vs ) vs.value = 1.0;
			}
		} );
	}

	function setConnBanner( type ) {
		var offlineBanner  = document.getElementById( 'rsa-banner-offline'  );
		var siteDownBanner = document.getElementById( 'rsa-banner-site-down' );
		if ( ! offlineBanner || ! siteDownBanner ) return;
		offlineBanner.hidden  = ( type !== 'offline'   );
		siteDownBanner.hidden = ( type !== 'site-down' );
		if ( type === 'site-down' ) {
			var nameEl = document.getElementById( 'rsa-banner-site-name' );
			if ( nameEl && state.siteUrl ) {
				try { nameEl.textContent = new URL( state.siteUrl ).hostname; }
				catch ( _ ) { nameEl.textContent = state.siteUrl; }
			}
		}
		state.connState = type || 'online';
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

		// Clear any existing auto-refresh.
		if ( state.refreshTimer ) {
			clearInterval( state.refreshTimer );
			state.refreshTimer = null;
		}

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
			case 'heatmap'    : renderHeatmap( container );     break;
			case 'export'     : renderExport( container );      break;
			case 'woocommerce': renderWoocommerce( container ); break;
			case 'install'    : renderInstall( container );      break;
			case 'ai-settings': renderAiSettings( container );   break;
			default: setLoading( false );
		}

		startAutoRefresh();
	}

	/**
	 * Start auto-refresh for the current view.
	 * Overview refreshes every 30s; most other views every 60s.
	 */
	function startAutoRefresh() {
		if ( state.refreshTimer ) {
			clearInterval( state.refreshTimer );
		}
		var interval = state.view === 'overview' ? 30000 : 60000;
		state.refreshTimer = setInterval( function () {
			// Only re-fetch if this view is still active and online.
			if ( state.connState === 'offline' ) return;
			state.cache = {};
			var fn = renderFunctions[ state.view ];
			if ( fn ) {
				var container = document.getElementById( 'rsa-view-' + state.view );
				if ( container ) fn( container );
			}
		}, interval );
	}

	// Map view names to render functions for auto-refresh.
	var renderFunctions = {
		overview   : renderOverview,
		pages      : renderPages,
		audience   : renderAudience,
		referrers  : renderReferrers,
		behavior   : renderBehavior,
		campaigns  : renderCampaigns,
		'user-flow': renderUserFlow,
		clicks     : renderClicks,
		heatmap    : renderHeatmap,
		export     : renderExport,
		woocommerce: renderWoocommerce,
		install    : renderInstall,
		'ai-settings': renderAiSettings,
		'ai-chat'  : renderAiChat,
	};

	function setLoading( on ) {
		document.getElementById( 'rsa-loading' ).hidden = ! on;
	}

	// -----------------------------------------------------------------------
	// AI Chat
	// -----------------------------------------------------------------------

	function getChatHistoryKey() {
		return 'rsa_chat_' + ( state.siteUrl || 'global' ).replace( /[^a-z0-9]/gi, '_' );
	}

	function loadChatHistory() {
		try {
			var raw = localStorage.getItem( getChatHistoryKey() );
			return raw ? JSON.parse( raw ) : [];
		} catch ( _ ) { return []; }
	}

	function saveChatHistory( history ) {
		try {
			localStorage.setItem( getChatHistoryKey(), JSON.stringify( history.slice( -100 ) ) );
		} catch ( _ ) {}
	}

	function clearChatHistory() {
		localStorage.removeItem( getChatHistoryKey() );
	}

	function parseAiMarkdown( text ) {
		var html = esc( text );
		// Bold
		html = html.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );
		// Italic
		html = html.replace( /\*(.+?)\*/g, '<em>$1</em>' );
		// Inline code
		html = html.replace( /`([^`]+)`/g, '<code style="background:#f5f5f5;padding:2px 5px;border-radius:3px;font-size:12px;font-family:monospace;">$1</code>' );
		// Code blocks (but NOT ```chart blocks - those are handled separately)
		html = html.replace( /```(?!chart\n)([\s\S]*?)```/g, function ( _, inner ) {
			return '<pre style="background:#f8f9fa;padding:10px;border-radius:6px;overflow-x:auto;font-size:12px;font-family:monospace;border:1px solid #e9ecef;margin:8px 0;"><code>' + inner.replace( /</g, '&lt;' ) + '</code></pre>';
		} );
		// Numbered lists — wrap in <ol>
		html = html.replace( /^([ \t]*)\d+\. (.+)$/gm, function ( _, indent, item ) {
			var pad = indent.length * 12;
			return '<li style="margin-left:' + pad + 'px;">' + item + '</li>';
		} );
		html = html.replace( /(<li[^>]*>.*<\/li>\n?)+/g, function ( match ) {
			return '<ol style="margin:6px 0;padding-left:18px;list-style:decimal;">' + match + '</ol>';
		} );
		// Unordered lists — wrap in <ul>
		html = html.replace( /^([ \t]*)[-*+] (.+)$/gm, function ( _, indent, item ) {
			var pad = indent.length * 12;
			return '<li style="margin-left:' + pad + 'px;">' + item + '</li>';
		} );
		html = html.replace( /(<li[^>]*>.*<\/li>\n?)+/g, function ( match ) {
			return '<ul style="margin:6px 0;padding-left:18px;list-style:disc;">' + match + '</ul>';
		} );
		// Line breaks
		html = html.replace( /\n/g, '<br>' );
		return html;
	}

	function renderAiChat( container ) {
		setLoading( false );
		var siteUrl = state.siteUrl;
		var headers = getAuthHeaders( siteUrl + '/wp-json/rsa/v1/ai/tool' );
		headers['Content-Type'] = 'application/json';

		var hasAiProvider = ! ! ( state.aiProvider && state.aiProvider.endpoint );
		var ap = state.aiProvider || {};
		var supportsSpeech = typeof SpeechRecognition !== 'undefined' || typeof webkitSpeechRecognition !== 'undefined';
		var supportsTTS    = typeof speechSynthesis !== 'undefined';
		var modelLabel = ap.model || 'No model selected';

		container.innerHTML =
			'<div style="max-width:900px;margin:0 auto;padding:0 16px;height:calc(100vh - 140px);display:flex;flex-direction:column;">' +

				// Header
				'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-shrink:0;">' +
					'<div>' +
						'<h2 style="margin:0;font-size:18px;">AI Analytics Assistant</h2>' +
						( hasAiProvider
							? '<span style="font-size:12px;color:#888;">Model: ' + esc( modelLabel ) + '</span>'
							: '<span style="font-size:12px;color:#c0392b;">No AI provider configured</span>' ) +
					'</div>' +
					'<div style="display:flex;gap:6px;">' +
						'<button id="rsa-ai-clear-chat" class="rsa-btn rsa-btn-ghost" style="padding:6px 12px;font-size:12px;" title="Clear conversation">Clear</button>' +
						( hasAiProvider ? '<a href="#" data-view="ai-settings" class="rsa-btn rsa-btn-ghost" style="padding:6px 12px;font-size:12px;text-decoration:none;">Settings</a>' : '' ) +
					'</div>' +
				'</div>' +

				// Setup banner
				( ! hasAiProvider
					? '<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:14px 16px;margin-bottom:12px;font-size:13px;color:#6d4c00;flex-shrink:0;">' +
						'<strong>AI provider not configured.</strong> Go to <a href="#" data-view="ai-settings" style="color:#6d4c00;text-decoration:underline;">AI Settings</a> ' +
						'to connect OpenAI, Ollama, or another LLM for conversational analytics.</div>'
					: '' ) +

				// Insights panel (collapsible)
				'<div id="rsa-ai-insights-wrap" style="margin-bottom:12px;flex-shrink:0;">' +
					'<button id="rsa-ai-toggle-insights" style="background:none;border:none;padding:0;font-size:12px;color:#888;cursor:pointer;display:flex;align-items:center;gap:4px;margin-bottom:6px;">' +
						'<span id="rsa-ai-insights-chevron">▼</span> Data Snapshot' +
					'</button>' +
					'<div id="rsa-ai-insights" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px 16px;font-size:13px;">' +
						'<span style="color:#888;">Loading snapshot…</span>' +
					'</div>' +
				'</div>' +

				// Quick actions
				( hasAiProvider
					? '<div id="rsa-ai-quick-actions" style="margin-bottom:12px;display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0;">' +
						'<span style="font-size:12px;color:#888;align-self:center;margin-right:4px;">Ask:</span>' +
						'<button class="rsa-ai-chip" data-q="What is my traffic overview?">Traffic overview</button>' +
						'<button class="rsa-ai-chip" data-q="Show me my top pages">Top pages</button>' +
						'<button class="rsa-ai-chip" data-q="What browsers do my visitors use?">Browsers</button>' +
						'<button class="rsa-ai-chip" data-q="Where is my traffic coming from?">Referrers</button>' +
						'<button class="rsa-ai-chip" data-q="Show me a chart of daily pageviews">Pageviews chart</button>' +
						'<button class="rsa-ai-chip" data-q="What is my bounce rate trend?">Bounce rate</button>' +
					'</div>'
					: '' ) +

				// Messages area
				'<div id="rsa-ai-messages" style="flex:1;overflow-y:auto;padding:12px;background:#fff;border:1px solid #e9ecef;border-radius:8px;margin-bottom:12px;min-height:200px;">' +
					( ! hasAiProvider ? '<div style="text-align:center;padding:40px 20px;color:#888;font-size:14px;">Configure an AI provider to start chatting with your data.</div>' : '' ) +
				'</div>' +

				// Typing indicator
				'<div id="rsa-ai-typing" style="display:none;flex-shrink:0;margin-bottom:8px;font-size:13px;color:#888;padding-left:4px;">' +
					'<span style="display:inline-flex;gap:3px;align-items:center;">' +
						'<span style="width:6px;height:6px;background:#bbb;border-radius:50%;animation:rsaPulse 1s infinite;"></span>' +
						'<span style="width:6px;height:6px;background:#bbb;border-radius:50%;animation:rsaPulse 1s infinite 0.2s;"></span>' +
						'<span style="width:6px;height:6px;background:#bbb;border-radius:50%;animation:rsaPulse 1s infinite 0.4s;"></span>' +
					'</span> Thinking…' +
				'</div>' +

				// Input area
				'<div style="display:flex;gap:8px;align-items:flex-end;flex-shrink:0;padding-bottom:8px;">' +
					( ap.voiceInput && supportsSpeech
						? '<button id="rsa-ai-mic" style="padding:10px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;cursor:pointer;font-size:18px;line-height:1;transition:all 0.2s;flex-shrink:0;" title="Speak your question">🎤</button>'
						: '' ) +
					'<div style="flex:1;position:relative;">' +
						'<textarea id="rsa-ai-input" rows="1" style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-size:14px;resize:none;overflow:hidden;box-sizing:border-box;min-height:40px;max-height:120px;" placeholder="' + ( hasAiProvider ? 'Ask about your analytics…' : 'Configure AI Settings first…' ) + '" ' + ( ! hasAiProvider ? 'disabled' : '' ) + '></textarea>' +
					'</div>' +
					'<button id="rsa-ai-send" style="padding:10px 20px;background:#2e6f8e;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;flex-shrink:0;transition:opacity 0.2s;" ' + ( ! hasAiProvider ? 'disabled' : '' ) + '>Send</button>' +
				'</div>' +

				// Voice status bar
				'<div style="display:flex;gap:12px;align-items:center;font-size:12px;color:#888;flex-shrink:0;padding-bottom:4px;min-height:20px;">' +
					( ap.voiceOutput && supportsTTS
						? '<button id="rsa-ai-stop-speech" class="rsa-btn rsa-btn-ghost" style="padding:4px 10px;font-size:11px;" hidden>🔊 Stop</button>'
						: '' ) +
					'<span id="rsa-ai-voice-status" style="flex:1;font-style:italic;"></span>' +
					'<span id="rsa-ai-msg-count" style="font-size:11px;color:#bbb;"></span>' +
				'</div>' +
			'</div>';

		var messagesDiv = document.getElementById( 'rsa-ai-messages' );
		var input = document.getElementById( 'rsa-ai-input' );
		var sendBtn = document.getElementById( 'rsa-ai-send' );
		var micBtn  = document.getElementById( 'rsa-ai-mic' );
		var stopBtn = document.getElementById( 'rsa-ai-stop-speech' );
		var voiceStatus = document.getElementById( 'rsa-ai-voice-status' );
		var typingDiv = document.getElementById( 'rsa-ai-typing' );
		var msgCountSpan = document.getElementById( 'rsa-ai-msg-count' );
		var insightsDiv = document.getElementById( 'rsa-ai-insights' );
		var insightsWrap = document.getElementById( 'rsa-ai-insights-wrap' );
		var insightsToggle = document.getElementById( 'rsa-ai-toggle-insights' );
		var insightsChevron = document.getElementById( 'rsa-ai-insights-chevron' );

		// --- Chat history ---
		var chatHistory = loadChatHistory();
		if ( chatHistory.length && messagesDiv ) {
			chatHistory.forEach( function ( h ) {
				addAiMessage( h.role, h.text, true );
			} );
		} else if ( hasAiProvider && messagesDiv ) {
			addAiMessage( 'ai', 'Hello! I can analyze your Rich Statistics data. Ask me anything — or pick a quick question above.' );
		}

		function updateMsgCount() {
			if ( msgCountSpan ) {
				msgCountSpan.textContent = chatHistory.length + ' message' + ( chatHistory.length !== 1 ? 's' : '' );
			}
		}
		updateMsgCount();

		// --- Insights toggle ---
		if ( insightsToggle && insightsDiv ) {
			insightsToggle.addEventListener( 'click', function () {
				var hidden = insightsDiv.style.display === 'none';
				insightsDiv.style.display = hidden ? '' : 'none';
				if ( insightsChevron ) insightsChevron.textContent = hidden ? '▼' : '▶';
			} );
		}

		// --- Fetch insights ---
		var tools = [ 'overview', 'pages', 'audience', 'referrers', 'behavior' ];
		Promise.all( tools.map( function ( tool ) {
			return fetch( siteUrl + '/wp-json/rsa/v1/ai/tool', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify( { tool: tool, params: { period: state.period, limit: 5 } } )
			} ).then( function ( r ) { return r.json(); } );
		} ) ).then( function ( results ) {
			if ( ! insightsDiv ) return;
			var items = [];
			results.forEach( function ( res ) {
				if ( ! res.ok || ! res.data || ! res.data.data ) return;
				var data = res.data;
				if ( data.tool === 'overview' && data.data ) {
					var o = data.data;
					if ( o.pageviews > 0 ) items.push( '<strong>' + fmt( o.pageviews ) + '</strong> pageviews' );
					if ( o.sessions > 0 ) items.push( '<strong>' + fmt( o.sessions ) + '</strong> sessions, ' + o.bounce_rate + '% bounce' );
				}
				if ( data.tool === 'pages' && data.data && data.data.length ) {
					var top = data.data[0];
					items.push( 'Top page: <strong>' + esc( top.page ) + '</strong> (' + fmt( top.views ) + ')' );
				}
				if ( data.tool === 'referrers' && data.data && data.data.length ) {
					items.push( 'Top referrer: <strong>' + esc( data.data[0].domain ) + '</strong>' );
				}
				if ( data.tool === 'audience' && data.data ) {
					var a = data.data;
					if ( a.browser_labels && a.browser_labels.length ) {
						items.push( 'Top browser: <strong>' + esc( a.browser_labels[0].label ) + '</strong> (' + fmt( a.browser_labels[0].count ) + ')' );
					}
				}
				if ( data.tool === 'campaigns' && data.data && data.data.length ) {
					items.push( 'Top campaign: <strong>' + esc( data.data[0].campaign || data.data[0].source ) + '</strong> (' + fmt( data.data[0].sessions ) + ')' );
				}
			} );
			if ( items.length ) {
				insightsDiv.innerHTML = items.map( function ( i ) {
					return '<span style="display:inline-block;background:#fff;padding:4px 10px;border-radius:4px;border:1px solid #e9ecef;margin:3px;font-size:12px;">' + i + '</span>';
				} ).join( '' );
			} else {
				insightsDiv.innerHTML = '<span style="color:#bbb;font-size:12px;">No data for this period.</span>';
			}
		} ).catch( function () {
			if ( insightsDiv ) insightsDiv.innerHTML = '<span style="color:#bbb;font-size:12px;">Could not load snapshot.</span>';
		} );

		if ( ! hasAiProvider || ! input || ! sendBtn ) return;

		// --- Textarea auto-resize ---
		function autoResize() {
			input.style.height = 'auto';
			input.style.height = Math.min( input.scrollHeight, 120 ) + 'px';
		}
		input.addEventListener( 'input', autoResize );

		// --- Quick action chips ---
		var chipContainer = document.getElementById( 'rsa-ai-quick-actions' );
		if ( chipContainer ) {
			chipContainer.addEventListener( 'click', function ( e ) {
				var chip = e.target.closest( '.rsa-ai-chip' );
				if ( chip ) {
					input.value = chip.getAttribute( 'data-q' );
					autoResize();
					doSend();
				}
			} );
		}

		// --- Clear chat ---
		var clearBtn = document.getElementById( 'rsa-ai-clear-chat' );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				if ( ! confirm( 'Clear this conversation?' ) ) return;
				clearChatHistory();
				chatHistory = [];
				if ( messagesDiv ) messagesDiv.innerHTML = '';
				addAiMessage( 'ai', 'Conversation cleared. How can I help?' );
				updateMsgCount();
			} );
		}

		// --- Voice input ---
		var recognition = null;
		var isListening = false;

		function startListening() {
			if ( isListening ) return;
			var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
			if ( ! SR ) {
				if ( voiceStatus ) voiceStatus.textContent = 'Voice input not supported.';
				return;
			}
			recognition = new SR();
			recognition.lang = ap.voiceLang || 'en-US';
			recognition.interimResults = true;
			recognition.continuous = false;

			recognition.onresult = function ( e ) {
				var transcript = '';
				for ( var i = e.resultIndex; i < e.results.length; i++ ) {
					transcript += e.results[i][0].transcript;
					if ( e.results[i].isFinal ) {
						input.value = transcript;
						input.style.color = '';
						autoResize();
						doSend();
					} else {
						input.value = transcript;
						input.style.color = '#888';
						autoResize();
					}
				}
			};
			recognition.onend = function () {
				isListening = false;
				input.style.color = '';
				if ( micBtn ) {
					micBtn.style.borderColor = '#ddd';
					micBtn.style.background = '#fff';
					micBtn.textContent = '🎤';
				}
				input.placeholder = 'Ask about your analytics…';
			};
			recognition.onerror = function ( e ) {
				isListening = false;
				input.style.color = '';
				if ( micBtn ) {
					micBtn.style.borderColor = '#ddd';
					micBtn.style.background = '#fff';
					micBtn.textContent = '🎤';
				}
				if ( voiceStatus ) voiceStatus.textContent = 'Mic error: ' + e.error;
			};

			isListening = true;
			if ( micBtn ) {
				micBtn.style.borderColor = '#e74c3c';
				micBtn.style.background = '#fdecea';
				micBtn.textContent = '⏹';
			}
			input.placeholder = 'Listening… speak now';
			if ( voiceStatus ) voiceStatus.textContent = 'Listening…';
			recognition.start();
		}

		if ( micBtn ) {
			micBtn.addEventListener( 'click', function () {
				if ( isListening ) {
					if ( recognition ) { recognition.stop(); isListening = false; }
					input.style.color = '';
					micBtn.style.borderColor = '#ddd';
					micBtn.style.background = '#fff';
					micBtn.textContent = '🎤';
					input.placeholder = 'Ask about your analytics…';
					if ( voiceStatus ) voiceStatus.textContent = '';
				} else {
					startListening();
				}
			} );
		}

		// --- Voice output ---
		function speakText( text ) {
			if ( ! ap.voiceOutput || ! supportsTTS ) return;
			window.speechSynthesis.cancel();
			var clean = text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
			var utterance = new SpeechSynthesisUtterance( clean );
			utterance.lang = ap.voiceLang || 'en-US';
			utterance.rate  = ap.voiceSpeed || 1.0;
			if ( stopBtn ) stopBtn.hidden = false;
			if ( voiceStatus ) voiceStatus.textContent = 'Speaking…';
			utterance.onend = function () {
				if ( stopBtn ) stopBtn.hidden = true;
				if ( voiceStatus ) voiceStatus.textContent = '';
			};
			utterance.onerror = function () {
				if ( stopBtn ) stopBtn.hidden = true;
				if ( voiceStatus ) voiceStatus.textContent = 'Speech error.';
			};
			window.speechSynthesis.speak( utterance );
		}

		// Listen for read-aloud requests from message action buttons
		document.addEventListener( 'rsa-speak-text', function ( e ) {
			if ( e.detail && e.detail.text ) {
				speakText( e.detail.text );
			}
		} );

		if ( stopBtn ) {
			stopBtn.addEventListener( 'click', function () {
				window.speechSynthesis.cancel();
				stopBtn.hidden = true;
				if ( voiceStatus ) voiceStatus.textContent = '';
			} );
		}

		// --- Typing indicator ---
		function showTyping() {
			if ( typingDiv ) typingDiv.style.display = 'block';
			if ( messagesDiv ) messagesDiv.scrollTop = messagesDiv.scrollHeight;
		}
		function hideTyping() {
			if ( typingDiv ) typingDiv.style.display = 'none';
		}

		// --- Send message ---
		var _speakNext = false;

		function doSend() {
			var msg = input.value.trim().replace( /\uFEFF/g, '' );
			if ( ! msg ) return;
			var wasVoice = input.style.color === 'rgb(136, 136, 136)';
			input.style.color = '';
			input.value = '';
			input.style.height = 'auto';
			addAiMessage( 'user', msg );
			chatHistory.push( { role: 'user', text: msg } );
			saveChatHistory( chatHistory );
			updateMsgCount();
			sendBtn.disabled = true;
			sendBtn.style.opacity = '0.6';
			showTyping();
			_speakNext = ap.autoSpeak && ! wasVoice;

			var toolsToFetch = [ 'overview', 'pages', 'audience', 'referrers', 'behavior' ];

			Promise.all( toolsToFetch.map( function ( tool ) {
				return fetch( siteUrl + '/wp-json/rsa/v1/ai/tool', {
					method: 'POST',
					headers: headers,
					body: JSON.stringify( { tool: tool, params: { period: state.period, limit: 10 } } )
				} ).then( function ( r ) { return r.json(); } );
			} ) ).then( function ( toolResults ) {
				var contextData = {};
				toolResults.forEach( function ( res ) {
					if ( res.ok && res.data ) {
						contextData[ res.data.tool ] = res.data.data;
					}
				} );

				var systemPrompt = 'You are a privacy-first analytics assistant. Answer based ONLY on the provided data. Never invent numbers. Be concise but helpful.\n\n' +
					'When a chart would help, output a JSON chart block EXACTLY like this (no extra text inside the block):\n' +
					'```chart\n{"type":"bar","labels":["A","B"],"datasets":[{"label":"Views","data":[10,20]}]}\n```\n' +
					'Types: bar, line, doughnut. Always include labels and datasets array.\n\n' +
					'Format your response using markdown: **bold**, *italic*, `code`, and lists.\n\n' +
					'If asked for a specific chart (e.g. "pageviews chart"), generate the chart from the data and include it.';

				var body = {
					model: ap.model || 'gpt-4o-mini',
					messages: [
						{ role: 'system', content: systemPrompt + '\n\nData:\n' + JSON.stringify( contextData, null, 2 ) },
						{ role: 'user', content: msg }
					],
					max_tokens: 800
				};
				var llmHeaders = { 'Content-Type': 'application/json' };
				if ( ap.apiKey ) {
					llmHeaders['Authorization'] = 'Bearer ' + ap.apiKey;
				}
				return fetch( ap.endpoint, {
					method: 'POST',
					headers: llmHeaders,
					body: JSON.stringify( body )
				} );
			} ).then( function ( r ) { return r.json(); } )
			.then( function ( llmData ) {
				hideTyping();
				sendBtn.disabled = false;
				sendBtn.style.opacity = '1';
				var answer = ( llmData.choices && llmData.choices[0] && llmData.choices[0].message && llmData.choices[0].message.content )
					|| ( llmData.error && llmData.error.message )
					|| 'Unable to generate a response. Check your AI provider settings.';
				addAiMessage( 'ai', answer );
				chatHistory.push( { role: 'ai', text: answer } );
				saveChatHistory( chatHistory );
				updateMsgCount();
				if ( _speakNext ) speakText( answer );
			} )
			.catch( function () {
				hideTyping();
				sendBtn.disabled = false;
				sendBtn.style.opacity = '1';
				var errText = 'Connection error. Check your AI provider endpoint and network.';
				addAiMessage( 'ai', errText );
				chatHistory.push( { role: 'ai', text: errText } );
				saveChatHistory( chatHistory );
				updateMsgCount();
			} );
		}

		sendBtn.addEventListener( 'click', doSend );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				doSend();
			}
		} );

		setTimeout( function () { if ( input ) input.focus(); }, 200 );
	}

	function addAiMessage( who, text, isHistory ) {
		var div = document.getElementById( 'rsa-ai-messages' );
		if ( ! div ) return;

		var wrapper = document.createElement( 'div' );
		wrapper.style.cssText = 'margin-bottom:14px;display:flex;gap:10px;' + ( who === 'user' ? 'flex-direction:row-reverse;' : '' );

		// Avatar
		var avatar = document.createElement( 'div' );
		avatar.style.cssText = 'width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;margin-top:2px;' +
			( who === 'user' ? 'background:#2e6f8e;color:#fff;' : 'background:#e9ecef;color:#555;' );
		avatar.textContent = who === 'user' ? 'You' : '🤖';
		wrapper.appendChild( avatar );

		// Message bubble
		var bubble = document.createElement( 'div' );
		bubble.style.cssText = 'max-width:75%;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.6;' +
			( who === 'user'
				? 'background:#e3f2fd;border:1px solid #bbdefb;color:#1a5276;'
				: 'background:#fff;border:1px solid #e9ecef;color:#333;box-shadow:0 1px 3px rgba(0,0,0,0.04);' );

		// Parse markdown
		var chartRegex = /```chart\n([\s\S]*?)\n```/g;
		var charts = [];
		var chartMatch;
		while ( ( chartMatch = chartRegex.exec( text ) ) !== null ) {
			charts.push( chartMatch[1] );
		}
		var displayText = text.replace( /```chart\n[\s\S]*?\n```/g, '' ).trim();

		var contentDiv = document.createElement( 'div' );
		contentDiv.innerHTML = parseAiMarkdown( displayText );
		bubble.appendChild( contentDiv );

		// Render charts
		if ( charts.length ) {
			charts.forEach( function ( chartJson, idx ) {
				try {
					var chartData = JSON.parse( chartJson );
					var canvasId = 'c-ai-' + Date.now() + '-' + idx + '-' + Math.random().toString( 36 ).slice( 2, 6 );
					var chartWrap = document.createElement( 'div' );
					chartWrap.style.cssText = 'margin-top:10px;height:200px;background:#fafafa;border-radius:6px;padding:8px;border:1px solid #f0f0f0;';
					var canvas = document.createElement( 'canvas' );
					canvas.id = canvasId;
					chartWrap.appendChild( canvas );
					bubble.appendChild( chartWrap );

					setTimeout( function () {
						var el = document.getElementById( canvasId );
						if ( ! el || typeof Chart === 'undefined' ) return;
						var type = chartData.type === 'line' ? 'line' : chartData.type === 'doughnut' ? 'doughnut' : 'bar';
						var cfg = {
							type: type,
							data: {
								labels: chartData.labels || [],
								datasets: ( chartData.datasets || [] ).map( function ( ds, i ) {
									var color = PALETTE[ i % PALETTE.length ];
									var dsCfg = {
										label: ds.label || '',
										data: ds.data || [],
										backgroundColor: type === 'line' ? color + '33' : color + 'cc',
										borderColor: color,
										borderWidth: 1,
									};
									if ( type === 'line' ) {
										dsCfg.fill = true;
										dsCfg.tension = 0.3;
										dsCfg.pointRadius = 3;
										dsCfg.pointBackgroundColor = color;
									}
									return dsCfg;
								} ),
							},
							options: {
								responsive: true,
								maintainAspectRatio: false,
								plugins: {
									legend: {
										display: type === 'doughnut' || ( chartData.datasets || [] ).length > 1,
										position: 'bottom',
										labels: { boxWidth: 10, font: { size: 11 }, padding: 10 }
									},
									tooltip: { mode: 'index', intersect: false, backgroundColor: 'rgba(0,0,0,0.8)', padding: 8, titleFont: { size: 12 }, bodyFont: { size: 11 } },
								},
								scales: type === 'doughnut' ? {} : {
									y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#f0f0f0' } },
									x: { ticks: { font: { size: 11 }, maxRotation: 45 }, grid: { display: false } },
								},
								animation: { duration: 600 },
							},
						};
						if ( state.charts[ canvasId ] ) {
							state.charts[ canvasId ].destroy();
						}
						state.charts[ canvasId ] = new Chart( el, cfg );
					}, 50 );
				} catch ( _ ) {}
			} );
		}

		// Action buttons for AI messages
		if ( who === 'ai' && ! isHistory ) {
			var actionsDiv = document.createElement( 'div' );
			actionsDiv.style.cssText = 'margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;';

			var copyBtn = document.createElement( 'button' );
			copyBtn.textContent = 'Copy';
			copyBtn.style.cssText = 'padding:3px 10px;border:1px solid #e9ecef;border-radius:4px;background:#fff;cursor:pointer;font-size:11px;color:#666;';
			copyBtn.addEventListener( 'click', function () {
				var plain = text.replace( /```chart\n[\s\S]*?\n```/g, '[Chart]' ).trim();
				navigator.clipboard.writeText( plain ).then( function () {
					copyBtn.textContent = 'Copied!';
					setTimeout( function () { copyBtn.textContent = 'Copy'; }, 1500 );
				} );
			} );
			actionsDiv.appendChild( copyBtn );

			var speakBtn = document.createElement( 'button' );
			speakBtn.textContent = '🔊 Read';
			speakBtn.style.cssText = 'padding:3px 10px;border:1px solid #e9ecef;border-radius:4px;background:#fff;cursor:pointer;font-size:11px;color:#666;';
			speakBtn.addEventListener( 'click', function () {
				var plain = text.replace( /```chart\n[\s\S]*?\n```/g, 'Chart shown.' ).trim();
				var event = new CustomEvent( 'rsa-speak-text', { detail: { text: plain } } );
				document.dispatchEvent( event );
			} );
			actionsDiv.appendChild( speakBtn );

			bubble.appendChild( actionsDiv );
		}

		wrapper.appendChild( bubble );
		div.appendChild( wrapper );

		// Auto-scroll only if near bottom
		var isNearBottom = div.scrollHeight - div.scrollTop - div.clientHeight < 100;
		if ( isNearBottom || ! isHistory ) {
			div.scrollTop = div.scrollHeight;
		}
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
					arr.map( function ( v ) {
						var val = ( v && typeof v === 'object' ) ? v.value : v;
						var lbl = ( v && typeof v === 'object' ) ? v.label : v;
						return '<option value="' + esc( val ) + '"' + ( val === current ? ' selected' : '' ) + '>' + esc( lbl ) + '</option>';
					} ).join( '' );
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
					arr.map( function ( v ) {
						var val = ( v && typeof v === 'object' ) ? v.value : v;
						var lbl = ( v && typeof v === 'object' ) ? v.label : v;
						return '<option value="' + esc( val ) + '"' + ( val === current ? ' selected' : '' ) + '>' + esc( lbl ) + '</option>';
					} ).join( '' );
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
		if ( ! state.isPremium ) { container.innerHTML = ''; return; }
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
					arr.map( function ( v ) {
						var val = ( v && typeof v === 'object' ) ? v.value : v;
						var lbl = ( v && typeof v === 'object' ) ? v.label : v;
						return '<option value="' + esc( val ) + '"' + ( val === current ? ' selected' : '' ) + '>' + esc( lbl ) + '</option>';
					} ).join( '' );
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
		if ( ! state.isPremium ) { container.innerHTML = ''; return; }
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
						var val = ( v && typeof v === 'object' ) ? v.value : v;
						var lbl = ( v && typeof v === 'object' ) ? v.label : v;
						return '<option value="' + esc( val ) + '"' + ( val === current ? ' selected' : '' ) + '>' + esc( lbl ) + '</option>';
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
		if ( ! state.isPremium ) { container.innerHTML = ''; return; }
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
		if ( ! state.isPremium ) { container.innerHTML = ''; return; }
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

		// Map normalised weight [0-1] to an RGBA colour: blue → cyan → green → yellow → red
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

		// Draw the heatmap on a dark canvas with depth guides
		function drawCanvas( canvas, heatData ) {
			var W   = canvas.width;
			var H   = canvas.height;
			var ctx = canvas.getContext( '2d' );
			if ( ! ctx ) return;

			// Dark background
			ctx.fillStyle = '#111c2b';
			ctx.fillRect( 0, 0, W, H );

			// Subtle horizontal bands at 25% scroll-depth intervals
			ctx.strokeStyle = 'rgba(255,255,255,0.06)';
			ctx.lineWidth = 1;
			[ 0.25, 0.5, 0.75 ].forEach( function ( pct ) {
				var y = Math.round( pct * H ) + 0.5;
				ctx.beginPath(); ctx.moveTo( 0, y ); ctx.lineTo( W, y ); ctx.stroke();
			} );

			// Fold line at ~30% — typical above-the-fold boundary
			ctx.save();
			ctx.strokeStyle = 'rgba(74,144,184,0.4)';
			ctx.lineWidth = 1;
			ctx.setLineDash( [ 6, 4 ] );
			var foldY = Math.round( 0.3 * H ) + 0.5;
			ctx.beginPath(); ctx.moveTo( 0, foldY ); ctx.lineTo( W, foldY ); ctx.stroke();
			ctx.restore();
			ctx.font = '10px -apple-system,BlinkMacSystemFont,sans-serif';
			ctx.fillStyle = 'rgba(74,144,184,0.65)';
			ctx.fillText( 'above fold', 6, foldY - 4 );

			// Y-axis depth labels (right edge)
			ctx.font = '10px -apple-system,BlinkMacSystemFont,sans-serif';
			ctx.fillStyle = 'rgba(255,255,255,0.22)';
			ctx.textAlign = 'right';
			[ [ 0, '0%' ], [ 0.25, '25%' ], [ 0.5, '50%' ], [ 0.75, '75%' ], [ 1, '100%' ] ].forEach( function ( pair ) {
				var yPos = Math.round( pair[0] * H );
				ctx.fillText( pair[1], W - 4, Math.max( 11, yPos + ( pair[0] === 1 ? -3 : 11 ) ) );
			} );
			ctx.textAlign = 'left';

			// Heat dots
			if ( heatData.length ) {
				var maxW = Math.max.apply( null, heatData.map( function ( p ) { return p.weight || 1; } ) );
				heatData.forEach( function ( p ) {
					var t    = ( p.weight || 1 ) / maxW;
					var px   = ( p.x / 100 ) * W;
					var py   = ( p.y / 100 ) * H;
					var brad = Math.max( 18, Math.round( t * 64 ) );
					if ( isNaN( px ) || isNaN( py ) ) return;
					var grad = ctx.createRadialGradient( px, py, 0, px, py, brad );
					grad.addColorStop( 0,   heatColour( t, 0.92 ) );
					grad.addColorStop( 0.5, heatColour( t, 0.45 ) );
					grad.addColorStop( 1,   heatColour( t, 0 ) );
					ctx.fillStyle = grad;
					ctx.beginPath();
					ctx.arc( px, py, brad, 0, Math.PI * 2 );
					ctx.fill();
				} );
			}
		}

		// Build tooltip HTML for a hovered hotspot dot
		function buildTip( dot ) {
			var head = '<div class="rsa-hm-tip-head">' +
				fmt( dot.weight ) + ' click' + ( dot.weight !== 1 ? 's' : '' ) +
				' · (' + Math.round( dot.x ) + '%, ' + Math.round( dot.y ) + '%)' +
			'</div>';
			if ( ! dot.elements || ! dot.elements.length ) return head;
			var rows = dot.elements.map( function ( e ) {
				var label = ( e.text || '' ).trim();
				if ( ! label ) label = '\u2014';
				if ( label.length > 34 ) label = label.slice( 0, 34 ) + '\u2026';
				var tag = e.tag ? ' <span class="rsa-hm-tag">&lt;' + esc( e.tag ) + '&gt;</span>' : '';
				return '<tr><td>' + esc( label ) + tag + '</td><td>' + fmt( e.count ) + '</td></tr>';
			} ).join( '' );
			return head + '<table class="rsa-hm-tip-tbl"><tbody>' + rows + '</tbody></table>';
		}

		// Bind canvas mousemove → nearest dot → tooltip
		function bindHotspotTooltip( canvas, data ) {
			var hasDotData = data.some( function ( d ) { return d.elements && d.elements.length; } );
			var tipEl = document.getElementById( 'rsa-hm-tip' );
			if ( ! tipEl ) return;
			// Always show weight even if no element breakdown
			canvas.style.cursor = 'crosshair';
			canvas.addEventListener( 'mousemove', function ( ev ) {
				var rect     = canvas.getBoundingClientRect();
				var mx       = ( ( ev.clientX - rect.left ) / rect.width  ) * 100;
				var my       = ( ( ev.clientY - rect.top  ) / rect.height ) * 100;
				// Wrap relative positioning for tooltip placement
				var wrapRect = canvas.parentElement.getBoundingClientRect();

				var best = null, bestDist = Infinity;
				data.forEach( function ( d ) {
					var dx = d.x - mx;
					// Compensate for aspect ratio so hit test feels circular on screen
					var dy = ( d.y - my ) * ( 540 / 756 );
					var dist = Math.sqrt( dx * dx + dy * dy );
					if ( dist < bestDist ) { bestDist = dist; best = d; }
				} );

				if ( best && bestDist < 5.5 ) {
					tipEl.innerHTML = buildTip( best );
					var tipW = 224;
					var tipH = tipEl.offsetHeight || 100;
					var tx   = ev.clientX - wrapRect.left + 6;
					var ty   = ev.clientY - wrapRect.top  + 6;
					if ( tx + tipW > wrapRect.width  - 4 ) { tx = ev.clientX - wrapRect.left - tipW - 6; }
					if ( ty + tipH > wrapRect.height - 4 ) { ty = ev.clientY - wrapRect.top  - tipH - 6; }
					tipEl.style.left = Math.max( 2, tx ) + 'px';
					tipEl.style.top  = Math.max( 2, ty ) + 'px';
					tipEl.hidden = false;
				} else {
					tipEl.hidden = true;
				}
			} );
			canvas.addEventListener( 'mouseleave', function () { tipEl.hidden = true; } );
		}

		// Build the click-element table from /clicks data
		function clickTable( clicks ) {
			if ( ! clicks || ! clicks.length ) {
				return '<p class="rsa-empty" style="margin:0;font-size:13px">No element click data for this page.</p>';
			}
			var maxCount = clicks[0].count || 1;
			var rows = clicks.slice( 0, 25 ).map( function ( c ) {
				var label = ( c.element_text || '' ).trim();
				if ( ! label && c.href_value ) label = c.href_value;
				if ( ! label ) label = '\u2014';
				if ( label.length > 42 ) label = label.slice( 0, 42 ) + '\u2026';
				var tag   = c.element_tag ? '<span class="rsa-hm-tag">&lt;' + esc( c.element_tag ) + '&gt;</span>' : '';
				var bar   = Math.round( ( ( c.count || 0 ) / maxCount ) * 100 );
				return '<tr>' +
					'<td class="rsa-hm-label">' + esc( label ) + tag + '</td>' +
					'<td class="rsa-hm-bar-cell">' +
						'<div class="rsa-hm-bar-bg"><div class="rsa-hm-bar-fill" style="width:' + bar + '%"></div></div>' +
					'</td>' +
					'<td class="rsa-hm-count">' + fmt( c.count || 0 ) + '</td>' +
				'</tr>';
			} ).join( '' );
			return '<table class="rsa-hm-table">' +
				'<thead><tr>' +
					'<th>Element</th>' +
					'<th></th>' +
					'<th>Clicks</th>' +
				'</tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
			'</table>';
		}

		function loadHeatmap() {
			var sel      = document.getElementById( 'rsa-hm-page' );
			var pagePath = sel ? ( sel.value || '/' ) : '/';
			var results  = document.getElementById( 'rsa-hm-results' );
			results.innerHTML = '<p class="rsa-field-hint" style="padding:16px 0">Loading\u2026</p>';

			Promise.all( [
				apiGet( 'heatmap', { period: state.period, page: pagePath } ),
				apiGet( 'clicks',  { period: state.period, page: pagePath } ),
			] ).then( function ( responses ) {
				var heatData = responses[0] || [];
				var clicks   = ( ( responses[1] || {} ).clicks ) || [];

				if ( ! heatData.length && ! clicks.length ) {
					results.innerHTML =
						'<div class="rsa-chart-card" style="margin-top:16px">' +
							'<p class="rsa-empty">No click data for <code>' + esc( pagePath ) + '</code> in this period.</p>' +
						'</div>';
					return;
				}

				var totalClicks = heatData.reduce( function ( s, p ) { return s + ( p.weight || 0 ); }, 0 );
				var legend =
					'<div class="rsa-hm-legend">' +
						'<span>Low</span>' +
						'<div class="rsa-hm-legend-bar"></div>' +
						'<span>High</span>' +
					'</div>';

				results.innerHTML =
					'<div class="rsa-chart-card rsa-hm-card" style="margin-top:16px">' +
						'<div class="rsa-hm-head">' +
							'<span class="rsa-hm-path">' + esc( pagePath ) + '</span>' +
							'<span class="rsa-hm-meta">' + fmt( totalClicks ) + ' interaction' + ( totalClicks !== 1 ? 's' : '' ) + '</span>' +
						'</div>' +
						'<div class="rsa-hm-body">' +
							'<div class="rsa-hm-canvas-wrap">' +
								'<canvas id="c-heatmap" width="540" height="756" style="display:block;width:100%;border-radius:var(--rsa-radius)"></canvas>' +
								'<div id="rsa-hm-tip" class="rsa-hm-tip" hidden></div>' +
								legend +
							'</div>' +
							'<div class="rsa-hm-table-wrap">' +
								'<p class="rsa-hm-table-title">Top Clicked Elements</p>' +
								clickTable( clicks ) +
							'</div>' +
						'</div>' +
					'</div>';

				var canvas = document.getElementById( 'c-heatmap' );
				if ( canvas ) {
					drawCanvas( canvas, heatData );
					bindHotspotTooltip( canvas, heatData );
				}

			} ).catch( function () {
				results.innerHTML =
					'<div class="rsa-chart-card" style="margin-top:16px">' +
						'<p class="rsa-empty">Could not load heatmap data. Please try again.</p>' +
					'</div>';
			} );
		}

		// Populate page dropdown from filter-options (heatmap_pages = pages with actual click data), then auto-load
		apiGet( 'filter-options', { period: state.period } ).then( function ( opts ) {
			var pages = ( opts && opts.heatmap_pages && opts.heatmap_pages.length ) ? opts.heatmap_pages
				: ( opts && opts.pages && opts.pages.length ) ? opts.pages : [ '/' ];
			var sel   = document.getElementById( 'rsa-hm-page' );
			if ( sel ) {
				sel.innerHTML = pages.map( function ( p ) {
					var val = ( p && typeof p === 'object' ) ? p.value : p;
					var lbl = ( p && typeof p === 'object' ) ? p.label : p;
					return '<option value="' + esc( val ) + '">' + esc( lbl ) + '</option>';
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
	// WooCommerce
	// -----------------------------------------------------------------------
	function renderWoocommerce( container ) {
		if ( ! state.isPremium ) { container.innerHTML = ''; return; }
		apiGet( 'woocommerce', { period: state.period } ).then( function ( data ) {
			if ( ! data.woocommerce_active ) {
				container.innerHTML = '<div class="rsa-chart-card"><p class="rsa-field-hint" style="text-align:center">WooCommerce is not active on this site.</p></div>';
				setLoading( false );
				return;
			}

			var funnel       = data.funnel              || { views: 0, cart: 0, orders: 0 };
			var revenueByDay = data.revenue_by_day      || [];
			var topViewed    = data.top_products_viewed || [];
			var topCart      = data.top_products_cart   || [];
			var revenue      = typeof data.revenue_total === 'number' ? data.revenue_total : 0;

			var viewedRows = topViewed.map( function ( p, i ) {
				return '<tr><td>' + ( i + 1 ) + '</td><td>' + esc( truncate( p.product_name, 40 ) ) + '</td><td>' + fmt( p.views ) + '</td></tr>';
			} );
			var cartRows = topCart.map( function ( p, i ) {
				return '<tr><td>' + ( i + 1 ) + '</td><td>' + esc( truncate( p.product_name, 40 ) ) + '</td><td>' + fmt( p.events ) + '</td></tr>';
			} );

			// Build conversion funnel rows: Stage / Count / Conversion %
			var funnelSteps = [
				{ label: 'Product Views', count: funnel.views  },
				{ label: 'Add to Cart',   count: funnel.cart   },
				{ label: 'Orders',        count: funnel.orders },
			];
			var funnelRows = funnelSteps.map( function ( step, i ) {
				var prev = i === 0 ? null : funnelSteps[ i - 1 ].count;
				var rateStr = prev === null ? '&mdash;' : ( prev > 0 ? ( ( step.count / prev ) * 100 ).toFixed( 1 ) + '%' : '0%' );
				return '<tr><td>' + esc( step.label ) + '</td><td>' + fmt( step.count ) + '</td><td>' + rateStr + '</td></tr>';
			} );

			container.innerHTML =
				tmplKpiGrid( [
					{ label: 'Product Views', value: fmt( funnel.views )           },
					{ label: 'Add to Cart',   value: fmt( funnel.cart )            },
					{ label: 'Orders',        value: fmt( funnel.orders )          },
					{ label: 'Revenue',       value: '$' + revenue.toFixed( 2 )   },
				] ) +
				'<div class="rsa-grid-2" style="margin-top:20px">' +
					'<div class="rsa-table-card"><h3>Conversion Funnel</h3><div class="rsa-table-wrap"><table class="rsa-table">' +
						'<thead><tr><th>Stage</th><th>Count</th><th>Conversion</th></tr></thead>' +
						'<tbody>' + funnelRows.join( '' ) + '</tbody>' +
					'</table></div></div>' +
					'<div class="rsa-table-card"><h3>Revenue Over Time</h3>' +
						( revenueByDay.length
							? '<div class="rsa-chart-wrap"><canvas id="c-wc-revenue"></canvas></div>'
							: '<p class="rsa-field-hint">No order data in the selected period.</p>'
						) +
					'</div>' +
				'</div>' +
				'<div class="rsa-grid-2" style="margin-top:20px">' +
					'<div class="rsa-table-card"><h3>Top Products &mdash; Views</h3><div class="rsa-table-wrap"><table class="rsa-table">' +
						'<thead><tr><th>#</th><th>Product</th><th>Views</th></tr></thead>' +
						'<tbody>' + ( viewedRows.length ? viewedRows.join( '' ) : '<tr><td colspan="3">No data.</td></tr>' ) + '</tbody>' +
					'</table></div></div>' +
					'<div class="rsa-table-card"><h3>Top Products &mdash; Add to Cart</h3><div class="rsa-table-wrap"><table class="rsa-table">' +
						'<thead><tr><th>#</th><th>Product</th><th>Events</th></tr></thead>' +
						'<tbody>' + ( cartRows.length ? cartRows.join( '' ) : '<tr><td colspan="3">No data.</td></tr>' ) + '</tbody>' +
					'</table></div></div>' +
				'</div>';

			setLoading( false );

			if ( revenueByDay.length ) {
				drawBar( 'c-wc-revenue',
					revenueByDay.map( function ( d ) { return d.day; } ),
					revenueByDay.map( function ( d ) { return parseFloat( d.revenue ); } ),
					'Revenue ($)'
				);
			}
		} ).catch( function ( err ) { handleApiError( err, container ); } );
	}

	// -----------------------------------------------------------------------
	// Install
	// -----------------------------------------------------------------------
	function renderInstall( container ) {
		container.innerHTML =
			'<div style="max-width:800px;margin:0 auto;padding:0 16px;">' +
			'<h2 style="font-size:20px;margin-bottom:24px;">Install Rich Statistics Desktop App</h2>' +
			'<p style="color:#888;">Access your analytics from your desktop — no browser required.</p>' +

			'<div class="rsa-card" style="margin-bottom:16px;">' +
				'<div class="rsa-card-header"><strong>Linux</strong></div>' +
				'<div style="padding:16px;">' +
					'<p class="rsa-install-subtitle">Install via APT (recommended)</p>' +
					'<pre class="rsa-install-code">curl -fsSL https://app.richstatistics.com/apt/public.gpg \\\n    | sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/rich-statistics.gpg\n\necho "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/rich-statistics.gpg] \\\n    https://app.richstatistics.com/apt stable main" \\\n    | sudo tee /etc/apt/sources.list.d/rich-statistics.list\n\nsudo apt update &amp;&amp; sudo apt install rich-statistics</pre>' +
					'<p class="rsa-install-subtitle">Or download .deb directly</p>' +
					'<a class="rsa-linux-arch-link rsa-install-deb-link" href="https://app.richstatistics.com/dist/rich-statistics-linux-amd64.deb">x86-64</a>' +
					'<a class="rsa-linux-arch-link rsa-install-deb-link" href="https://app.richstatistics.com/dist/rich-statistics-linux-arm64.deb">ARM64</a>' +
				'</div>' +
			'</div>' +

			'<div class="rsa-card" style="margin-bottom:16px;">' +
				'<div class="rsa-card-header"><strong>Windows</strong></div>' +
				'<div style="padding:16px;">' +
					'<p class="rsa-install-subtitle">Download installer (.exe)</p>' +
					'<p style="margin-bottom:8px"><a class="rsa-install-deb-link" href="https://app.richstatistics.com/dist/rich-statistics-windows.exe" style="display:inline-block;padding:8px 16px;background:#4a90b8;color:#fff;border-radius:6px;text-decoration:none;font-size:14px">Download Windows .exe</a></p>' +
				'</div>' +
			'</div>' +

			'<p style="font-size:12px;color:#888;margin-top:32px;">' +
			'Desktop binaries are updated with each release. ' +
			'Installation via APT is recommended for automatic updates.</p>' +
			'</div>';

		setLoading( false );
	}

	// -----------------------------------------------------------------------
	// Provider presets for AI settings
	// -----------------------------------------------------------------------
	var providerPresets = {
		openai: {
			endpoint: 'https://api.openai.com/v1/chat/completions',
			defaultModel: 'gpt-4o-mini',
			needsKey: true,
			label: 'OpenAI'
		},
		ollama: {
			endpoint: 'http://localhost:11434/v1/chat/completions',
			defaultModel: 'llama3.2',
			needsKey: false,
			label: 'Ollama'
		},
		lmstudio: {
			endpoint: 'http://localhost:1234/v1/chat/completions',
			defaultModel: '',
			needsKey: false,
			label: 'LM Studio'
		},
		llamacpp: {
			endpoint: 'http://localhost:8080/v1/chat/completions',
			defaultModel: '',
			needsKey: false,
			label: 'llama.cpp'
		},
		custom: {
			endpoint: '',
			defaultModel: '',
			needsKey: true,
			label: 'Custom'
		}
	};

	/** Fallback model lists when discovery fails. */
	var fallbackModels = {
		openai:   ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo'],
		ollama:   ['llama3.2', 'llama3.1', 'llama3', 'mistral', 'qwen2.5'],
		lmstudio: [],
		llamacpp: [],
		custom:   []
	};

	/** Derive a /models or /api/tags URL from the chat-completions endpoint. */
	function getModelsUrl( endpoint ) {
		if ( ! endpoint ) return '';
		// Ollama native API
		if ( endpoint.indexOf( ':11434' ) !== -1 ) {
			try {
				var url = new URL( endpoint );
				return url.protocol + '//' + url.host + '/api/tags';
			} catch ( _ ) {}
		}
		// OpenAI-compatible /v1/models
		try {
			var url = new URL( endpoint );
			var path = url.pathname;
			if ( path.indexOf( '/chat/completions' ) !== -1 ) {
				url.pathname = path.replace( '/chat/completions', '/models' );
			} else if ( path.indexOf( '/completions' ) !== -1 ) {
				url.pathname = path.replace( '/completions', '/models' );
			} else {
				url.pathname = '/v1/models';
			}
			return url.toString();
		} catch ( _ ) {
			return '';
		}
	}

	/** Query the endpoint for available models. */
	function fetchAvailableModels( endpoint, providerKey, apiKey ) {
		if ( providerKey === 'ollama' && isTauri() && window.__TAURI__ ) {
			return tauriDetectLocal( endpoint ).catch( function () { return []; } );
		}
		var url = getModelsUrl( endpoint );
		if ( ! url ) return Promise.resolve( [] );

		var headers = {};
		if ( apiKey ) headers['Authorization'] = 'Bearer ' + apiKey;

		return fetch( url, { method: 'GET', headers: headers } )
			.then( function ( res ) {
				if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
				return res.json();
			} )
			.then( function ( data ) {
				var models = [];
				if ( Array.isArray( data.models ) ) {
					models = data.models.map( function ( m ) { return m.name || m.model || ''; } );
				} else if ( Array.isArray( data.data ) ) {
					models = data.data.map( function ( m ) { return m.id || ''; } );
				}
				return models.filter( function ( m ) { return m; } );
			} )
			.catch( function () { return []; } );
	}

	/** Populate the model <select> with fetched options. */
	function populateModelSelect( models, currentModel, providerKey ) {
		var select = document.getElementById( 'rsa-ai-model' );
		var customRow = document.getElementById( 'rsa-ai-model-custom-row' );
		if ( ! select ) return;

		var opts = [];
		var seen = {};

		function addOpt( value, label, selected ) {
			if ( seen[ value ] ) return;
			seen[ value ] = true;
			opts.push( '<option value="' + esc( value ) + '"' + ( selected ? ' selected' : '' ) + '>' + esc( label ) + '</option>' );
		}

		if ( currentModel && currentModel !== '__custom__' ) {
			addOpt( currentModel, currentModel + ' (current)', true );
		}

		models.forEach( function ( m ) { addOpt( m, m, false ); } );

		var fallbacks = fallbackModels[ providerKey ] || [];
		fallbacks.forEach( function ( m ) { addOpt( m, m, false ); } );

		addOpt( '__custom__', 'Custom model…', false );

		select.innerHTML = opts.join( '' );

		if ( customRow ) {
			customRow.style.display = select.value === '__custom__' ? '' : 'none';
		}
	}

	/** Refresh button handler. */
	function onRefreshModels() {
		var endpoint = document.getElementById( 'rsa-ai-endpoint' ).value.trim();
		var providerKey = document.getElementById( 'rsa-ai-provider' ).value;
		var apiKey = document.getElementById( 'rsa-ai-key' ).value.trim();
		var select = document.getElementById( 'rsa-ai-model' );
		var status = document.getElementById( 'rsa-ai-model-status' );
		var currentModel = select ? select.value : '';
		if ( currentModel === '__custom__' ) {
			var customInput = document.getElementById( 'rsa-ai-model-custom' );
			currentModel = customInput ? customInput.value.trim() : '';
		}

		if ( status ) {
			status.textContent = 'Fetching models…';
			status.style.color = '#888';
		}

		fetchAvailableModels( endpoint, providerKey, apiKey ).then( function ( models ) {
			populateModelSelect( models, currentModel, providerKey );
			if ( status ) {
				if ( models.length ) {
					status.textContent = models.length + ' model' + ( models.length > 1 ? 's' : '' ) + ' found';
					status.style.color = '#065f46';
				} else {
					status.textContent = 'No models discovered. Using defaults.';
					status.style.color = '#888';
				}
			}
		} );
	}

	/** Tauri: auto-find and start local provider, return models list. */
	function tauriDetectLocal( endpoint ) {
		if ( ! isTauri() || ! window.__TAURI__ ) return Promise.reject();
		return window.__TAURI__.invoke( 'check_ollama' ).then( function ( status ) {
			if ( ! status.installed ) throw new Error( 'Ollama not installed' );
			if ( ! status.running ) {
				return window.__TAURI__.invoke( 'start_ollama' ).then( function () {
					return window.__TAURI__.invoke( 'list_ollama_models' );
				} );
			}
			return window.__TAURI__.invoke( 'list_ollama_models' );
		} );
	}

	/** Apply provider preset to the form fields. */
	function applyProviderPreset( providerKey ) {
		var preset = providerPresets[ providerKey ] || providerPresets.custom;
		var epField = document.getElementById( 'rsa-ai-endpoint' );
		var keyRow = document.getElementById( 'rsa-ai-key-row' );
		var keyField = document.getElementById( 'rsa-ai-key' );
		var tauriRow = document.getElementById( 'rsa-ai-tauri-row' );
		var tauriStatus = document.getElementById( 'rsa-ai-tauri-status' );

		if ( epField ) {
			epField.value = preset.endpoint || '';
			epField.readOnly = providerKey !== 'custom';
			epField.style.background = providerKey === 'custom' ? '#fff' : '#f5f5f5';
		}
		if ( keyRow ) keyRow.style.display = preset.needsKey ? '' : 'none';
		if ( keyField && ! preset.needsKey ) keyField.value = '';

		// Tauri-specific: show detect button for Ollama
		if ( providerKey === 'ollama' && isTauri() && tauriRow ) {
			tauriRow.style.display = '';
			if ( tauriStatus ) tauriStatus.textContent = '';
		} else if ( tauriRow ) {
			tauriRow.style.display = 'none';
		}

		// Populate model dropdown for presets with known endpoints
		if ( providerKey !== 'custom' ) {
			setTimeout( function () { onRefreshModels(); }, 0 );
		} else {
			var select = document.getElementById( 'rsa-ai-model' );
			if ( select ) {
				select.innerHTML = '<option value="">Select a model…</option><option value="__custom__">Custom model…</option>';
			}
			var customRow = document.getElementById( 'rsa-ai-model-custom-row' );
			if ( customRow ) customRow.style.display = 'none';
		}
	}

	/** Tauri: detect and populate local models. */
	function onTauriDetect() {
		var btn = document.getElementById( 'rsa-ai-tauri-detect' );
		var status = document.getElementById( 'rsa-ai-tauri-status' );
		if ( ! btn || ! status ) return;
		btn.disabled = true;
		status.textContent = 'Checking for Ollama…';
		status.style.color = '#888';

		tauriDetectLocal().then( function ( models ) {
			if ( models && models.length ) {
				status.textContent = 'Found ' + models.length + ' model' + ( models.length > 1 ? 's' : '' ) + ': ' + models.join( ', ' );
				status.style.color = '#065f46';
				var currentModel = document.getElementById( 'rsa-ai-model' ).value || '';
				populateModelSelect( models, currentModel, 'ollama' );
			} else {
				status.innerHTML = 'Ollama is running but no models found. <a href="#" id="rsa-ai-pull-link">Pull a model</a>';
				status.style.color = '#991b1b';
			}
			btn.disabled = false;
		} ).catch( function ( err ) {
			status.textContent = err && err.message ? err.message : 'Could not reach Ollama. Make sure it is installed.';
			status.style.color = '#991b1b';
			btn.disabled = false;
		} );
	}

	// -----------------------------------------------------------------------
	// AI Settings
	// -----------------------------------------------------------------------
	function renderAiSettings( container ) {
		var ap = state.aiProvider || {};
		var currentEndpoint = ap.endpoint || 'https://api.openai.com/v1/chat/completions';
		var currentModel = ap.model || '';

		// Infer provider from saved endpoint
		var inferredProvider = 'custom';
		if ( currentEndpoint.indexOf( 'api.openai.com' ) !== -1 ) inferredProvider = 'openai';
		else if ( currentEndpoint.indexOf( ':11434' ) !== -1 ) inferredProvider = 'ollama';
		else if ( currentEndpoint.indexOf( ':1234' ) !== -1 ) inferredProvider = 'lmstudio';
		else if ( currentEndpoint.indexOf( ':8080' ) !== -1 ) inferredProvider = 'llamacpp';

		container.innerHTML =
			'<div style="max-width:800px;margin:0 auto;padding:0 16px;">' +
			'<h2 style="font-size:20px;margin-bottom:8px;">AI Assistant Provider</h2>' +
			'<p style="color:#888;font-size:13px;margin-bottom:24px;">Configure which LLM the AI assistant uses. Pick a provider, then choose a model from the dropdown (or enter a custom name).</p>' +

			'<div class="rsa-card" style="margin-bottom:16px;">' +
				'<div class="rsa-card-header"><strong>Provider</strong></div>' +
				'<div style="padding:16px;">' +

					// Provider selector
					'<div class="rsa-form-row">' +
						'<label class="rsa-filter-label" for="rsa-ai-provider">Provider</label>' +
						'<select id="rsa-ai-provider" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:15px;box-sizing:border-box;">' +
							'<option value="openai"'   + ( inferredProvider === 'openai'   ? ' selected' : '' ) + '>OpenAI</option>' +
							( isTauri() ?
							'<option value="ollama"'   + ( inferredProvider === 'ollama'   ? ' selected' : '' ) + '>Ollama</option>' +
							'<option value="lmstudio"' + ( inferredProvider === 'lmstudio' ? ' selected' : '' ) + '>LM Studio</option>' +
							'<option value="llamacpp"' + ( inferredProvider === 'llamacpp' ? ' selected' : '' ) + '>llama.cpp</option>'
							: '' ) +
							'<option value="custom"'   + ( inferredProvider === 'custom'   ? ' selected' : '' ) + '>Custom</option>' +
						'</select>' +
					'</div>' +

					// Endpoint (read-only for presets, editable for Custom)
					'<div class="rsa-form-row">' +
						'<label class="rsa-filter-label" for="rsa-ai-endpoint">API Endpoint</label>' +
						'<input type="url" id="rsa-ai-endpoint" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;' +
							( inferredProvider === 'custom' ? '' : ' background:#f5f5f5;' ) + '"' +
							' value="' + esc( currentEndpoint ) + '"' +
							' placeholder="https://api.openai.com/v1/chat/completions"' +
							( inferredProvider === 'custom' ? '' : ' readonly' ) + '>' +
					'</div>' +

					// API Key (hidden for local providers)
					'<div class="rsa-form-row" id="rsa-ai-key-row"' +
						( providerPresets[ inferredProvider ].needsKey ? '' : ' style="display:none;"' ) + '>' +
						'<label class="rsa-filter-label" for="rsa-ai-key">API Key</label>' +
						'<input type="password" id="rsa-ai-key" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;"' +
							' value="' + esc( ap.apiKey || '' ) + '"' +
							' placeholder="sk-...">' +
					'</div>' +

					// Model (dropdown with refresh)
					'<div class="rsa-form-row">' +
						'<label class="rsa-filter-label" for="rsa-ai-model">Model</label>' +
						'<div style="display:flex;gap:8px;align-items:center;">' +
							'<select id="rsa-ai-model" style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;">' +
								'<option value="">Select a model…</option>' +
								( currentModel ? '<option value="' + esc( currentModel ) + '" selected>' + esc( currentModel ) + '</option>' : '' ) +
								'<option value="__custom__">Custom model…</option>' +
							'</select>' +
							'<button type="button" id="rsa-ai-refresh-models" class="rsa-btn rsa-btn-ghost" style="padding:6px 12px;font-size:12px;white-space:nowrap;">↻ Refresh</button>' +
						'</div>' +
						'<span id="rsa-ai-model-status" style="font-size:12px;color:#888;margin-top:4px;display:block;"></span>' +
					'</div>' +
					'<div class="rsa-form-row" id="rsa-ai-model-custom-row" style="display:none;">' +
						'<label class="rsa-filter-label" for="rsa-ai-model-custom">Custom Model Name</label>' +
						'<input type="text" id="rsa-ai-model-custom" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;"' +
							' placeholder="e.g. ' + esc( providerPresets[ inferredProvider ].defaultModel || 'gpt-4o-mini' ) + '">' +
					'</div>' +

					// Tauri-specific: local detection (hidden in browser)
					( isTauri() ? '' +
					'<div class="rsa-form-row" id="rsa-ai-tauri-row"' +
						( inferredProvider === 'ollama' ? '' : ' style="display:none;"' ) + '>' +
						'<label class="rsa-filter-label"></label>' +
						'<div style="display:flex;align-items:center;gap:8px;">' +
							'<button id="rsa-ai-tauri-detect" class="rsa-btn rsa-btn-ghost" style="padding:6px 12px;font-size:12px;">Find &amp; Connect Local Ollama</button>' +
							'<span id="rsa-ai-tauri-status" style="font-size:12px;"></span>' +
						'</div>' +
					'</div>' : '' ) +

					// Voice settings
					'<div style="margin-top:16px;border-top:1px solid #e0e0e0;padding-top:12px;">' +
						'<strong style="font-size:13px;display:block;margin-bottom:8px;">Voice &amp; Speech</strong>' +
						'<label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-size:13px;cursor:pointer;">' +
							'<input type="checkbox" id="rsa-ai-voice-input"' +
								( ap.voiceInput ? ' checked' : '' ) + '>' +
							' Voice input (microphone) — speak your questions' +
						'</label>' +
						'<label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-size:13px;cursor:pointer;">' +
							'<input type="checkbox" id="rsa-ai-voice-output"' +
								( ap.voiceOutput ? ' checked' : '' ) + '>' +
							' Voice output (speaker) — hear answers read aloud' +
						'</label>' +
						'<label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-size:13px;cursor:pointer;">' +
							'<input type="checkbox" id="rsa-ai-auto-speak"' +
								( ap.autoSpeak ? ' checked' : '' ) + '>' +
							' Auto-speak new answers' +
						'</label>' +
						'<div style="display:flex;gap:12px;margin-top:8px;">' +
							'<div style="flex:1;">' +
								'<label for="rsa-ai-voice-lang" style="font-size:11px;color:#888;display:block;margin-bottom:2px;">Language</label>' +
								'<select id="rsa-ai-voice-lang" style="width:100%;padding:6px;border:1px solid #ddd;border-radius:4px;font-size:13px;">' +
									'<option value="en-US"' + ( ap.voiceLang === 'en-US' ? ' selected' : '' ) + '>English (US)</option>' +
									'<option value="en-GB"' + ( ap.voiceLang === 'en-GB' ? ' selected' : '' ) + '>English (UK)</option>' +
									'<option value="es-ES"' + ( ap.voiceLang === 'es-ES' ? ' selected' : '' ) + '>Spanish</option>' +
									'<option value="fr-FR"' + ( ap.voiceLang === 'fr-FR' ? ' selected' : '' ) + '>French</option>' +
									'<option value="de-DE"' + ( ap.voiceLang === 'de-DE' ? ' selected' : '' ) + '>German</option>' +
								'</select>' +
							'</div>' +
							'<div style="flex:1;">' +
								'<label for="rsa-ai-voice-speed" style="font-size:11px;color:#888;display:block;margin-bottom:2px;">Speed: <span id="rsa-ai-speed-val">' + ( ap.voiceSpeed || '1.0' ) + '</span></label>' +
								'<input type="range" id="rsa-ai-voice-speed" min="0.5" max="2.0" step="0.1"' +
									' value="' + ( ap.voiceSpeed || '1.0' ) + '"' +
									' style="width:100%;">' +
							'</div>' +
						'</div>' +
					'</div>' +

					'<div style="margin-top:12px;display:flex;gap:8px;">' +
						'<button id="rsa-ai-save" class="rsa-btn rsa-btn-primary" style="padding:8px 16px;">Save AI Settings</button>' +
						'<button id="rsa-ai-clear" class="rsa-btn rsa-btn-ghost" style="padding:8px 16px;">Clear</button>' +
					'</div>' +
				'</div>' +
			'</div>' +
			'</div>';

		setLoading( false );

		// Auto-populate model list for known providers
		if ( inferredProvider !== 'custom' ) {
			setTimeout( function () { onRefreshModels(); }, 0 );
		}
	}


	function renderExport( container ) {
		if ( ! state.isPremium ) { container.innerHTML = ''; return; }
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
				if ( err.message === 'auth' ) { showLogin(); return; }
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
			// Show the login screen so the user can re-authenticate, but keep
			// saved sites intact — a stale nonce or transient 401 must not
			// permanently destroy the stored site list.
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
	// Premium feature lock
	function showUpgradeOverlay( container, featureName ) {
		container.innerHTML =
			'<div class="rsa-premium-notice" style="text-align:center;padding:60px 20px;">' +
				'<div style="font-size:48px;margin-bottom:16px;">🔒</div>' +
				'<h3 style="margin-bottom:8px;">' + esc( featureName ) + ' is Premium</h3>' +
				'<p style="color:#666;margin-bottom:24px;">Unlock this feature with a premium licence.</p>' +
				( state.upgradeUrl
					? '<a href="' + esc( state.upgradeUrl ) + '" class="button button-primary button-hero" target="_blank">Upgrade Now</a>'
					: '<p style="color:#999;font-size:12px;">Contact the site administrator to upgrade.</p>'
				) +
			'</div>';
		setLoading( false );
	}

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
