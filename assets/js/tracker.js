/**
 * Rich Statistics — Frontend Tracker
 *
 * Collects privacy-safe signals and sends them to the ingest endpoint.
 * Depends on WordPress-enqueued jQuery. No other dependencies.
 *
 * Signals collected:
 *   - OS, browser, language, timezone  (from window.navigator / Intl API)
 *   - Viewport dimensions
 *   - Current page path + query (no fragment, no sensitive params dropped server-side)
 *   - Referrer domain (path stripped server-side)
 *   - Time on page (calculated here, sent on visibility change or unload)
 *   - Bot-detection bitmask (see flags below)
 *
 * Premium (injected by class-click-tracking.php):
 *   - Click events on natively-clickable elements + protocol-matched anchors
 *   - Viewport-relative x/y percentages
 */

/* global RSA, jQuery */

( function ( $, config ) {
	'use strict';

	if ( ! config || ! config.ajaxUrl ) {
		return;
	}

	// ----------------------------------------------------------------
	// Bot-detection signal flags  (must match RSA_Bot_Detection constants)
	// ----------------------------------------------------------------
	var BOT = {
		WEBDRIVER          : 1,
		NO_PLUGINS         : 2,
		NO_LANGUAGES       : 4,
		ZERO_SCREEN        : 8,
		NO_TOUCH_API       : 16,
		INSTANT_LOAD       : 32,
		NO_CANVAS          : 64,
		HIDDEN_ON_ARRIVAL  : 128,
		NO_HUMAN_EVENT     : 256,
		CHROME_MISSING_OBJ : 512,
	};

	// ----------------------------------------------------------------
	// Gather bot-detection signals (run once, early)
	// ----------------------------------------------------------------
	var botSignals = 0;

	( function detectBotSignals() {
		var nav = window.navigator || {};

		// Headless browser indicator
		if ( nav.webdriver ) {
			botSignals |= BOT.WEBDRIVER;
		}

		// No plugins (headless Chrome, most scrapers)
		if ( typeof nav.plugins !== 'undefined' && nav.plugins.length === 0 ) {
			botSignals |= BOT.NO_PLUGINS;
		}

		// Empty languages array
		if ( ! nav.languages || nav.languages.length === 0 ) {
			botSignals |= BOT.NO_LANGUAGES;
		}

		// Zero screen dimensions
		if ( window.screen && ( window.screen.width === 0 || window.screen.height === 0 ) ) {
			botSignals |= BOT.ZERO_SCREEN;
		}

		// Claim to be mobile but no touch/pointer API
		var ua = ( nav.userAgent || '' ).toLowerCase();
		var claimsMobile = /android|iphone|ipad|mobile/.test( ua );
		var hasTouch = ( 'ontouchstart' in window ) || ( navigator.maxTouchPoints > 0 );
		if ( claimsMobile && ! hasTouch ) {
			botSignals |= BOT.NO_TOUCH_API;
		}

		// Instantaneous page load (< 50ms) — scrapers don't render
		if ( window.performance && window.performance.timing ) {
			var t = window.performance.timing;
			var loadTime = t.loadEventEnd - t.navigationStart;
			if ( loadTime > 0 && loadTime < 50 ) {
				botSignals |= BOT.INSTANT_LOAD;
			}
		}

		// No HTMLCanvasElement support
		if ( typeof HTMLCanvasElement === 'undefined' ) {
			botSignals |= BOT.NO_CANVAS;
		}

		// Page was hidden on arrival (server-side pre-fetch / prefetch)
		if ( document.hidden ) {
			botSignals |= BOT.HIDDEN_ON_ARRIVAL;
		}

		// Claims Chrome but no window.chrome object (headless Chrome)
		if ( /chrome/.test( ua ) && ! /edge|opr|yabrowser/.test( ua ) ) {
			if ( typeof window.chrome === 'undefined' || ! window.chrome ) {
				botSignals |= BOT.CHROME_MISSING_OBJ;
			}
		}
	}() );

	// ----------------------------------------------------------------
	// Human activity detection
	// Assume bot until we see at least one real interaction.
	// ----------------------------------------------------------------
	var humanDetected = false;

	function markHuman() {
		humanDetected = true;
	}

	// Listen briefly; after first event, detach listeners
	var humanEvents = [ 'mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'pointerdown' ];
	humanEvents.forEach( function ( ev ) {
		document.addEventListener( ev, function onHuman() {
			markHuman();
			document.removeEventListener( ev, onHuman, { passive: true } );
		}, { passive: true, once: true } );
	} );

	// ----------------------------------------------------------------
	// Collect static signals
	// ----------------------------------------------------------------
	var nav        = window.navigator || {};
	var language   = nav.language || ( nav.languages && nav.languages[0] ) || '';
	var timezone   = '';

	try {
		timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
	} catch ( e ) {}

	var viewportW  = window.innerWidth  || document.documentElement.clientWidth  || 0;
	var viewportH  = window.innerHeight || document.documentElement.clientHeight || 0;
	var page       = window.location.pathname + window.location.search;
	var referrer   = document.referrer || '';

	// ----------------------------------------------------------------
	// Session ID — lives in sessionStorage, never a cookie
	// ----------------------------------------------------------------
	var sessionId = ( function () {
		var key = 'rsa_sid';
		var existing = '';
		try {
			existing = sessionStorage.getItem( key ) || '';
		} catch ( e ) {}
		if ( existing && /^[0-9a-f-]{36}$/.test( existing ) ) {
			return existing;
		}
		// Generate UUIDv4
		var uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
		try {
			sessionStorage.setItem( key, uuid );
		} catch ( e ) {}
		return uuid;
	}() );

	// ----------------------------------------------------------------
	// UTM campaign tracking — read from URL on landing, persist for session
	// ----------------------------------------------------------------
	var utmSource   = '';
	var utmMedium   = '';
	var utmCampaign = '';

	( function readUtm() {
		var UTM_KEY = 'rsa_utm';
		var params  = new URLSearchParams( window.location.search );
		var src     = params.get( 'utm_source' )   || '';
		var med     = params.get( 'utm_medium' )   || '';
		var cam     = params.get( 'utm_campaign' ) || '';

		if ( src || med || cam ) {
			// Fresh UTM params in the URL — store them for the session
			var stored = JSON.stringify( { s: src, m: med, c: cam } );
			try { sessionStorage.setItem( UTM_KEY, stored ); } catch ( e ) {}
			utmSource   = src;
			utmMedium   = med;
			utmCampaign = cam;
		} else {
			// No UTM in URL — check if we already captured them for this session
			try {
				var raw = sessionStorage.getItem( UTM_KEY );
				if ( raw ) {
					var parsed = JSON.parse( raw );
					utmSource   = parsed.s || '';
					utmMedium   = parsed.m || '';
					utmCampaign = parsed.c || '';
				}
			} catch ( e ) {}
		}
	}() );

	// ----------------------------------------------------------------
	// Time-on-page tracking
	// ----------------------------------------------------------------
	var pageStartTime = Date.now();
	var timeOnPage    = 0;
	var sent          = false;

	function computeTimeOnPage() {
		timeOnPage = Math.round( ( Date.now() - pageStartTime ) / 1000 );
	}

	// Pause timer when tab hidden, resume on focus
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			computeTimeOnPage();
			sendEvent();
		} else {
			// Reset start time when page becomes visible again
			pageStartTime = Date.now();
			sent = false;
		}
	} );

	// ----------------------------------------------------------------
	// Send event to ingest endpoint
	// ----------------------------------------------------------------
	function sendEvent() {
		if ( sent ) {
			return;
		}
		sent = true;

		// Add NO_HUMAN_EVENT flag if we never saw a human signal
		var signals = botSignals;
		if ( ! humanDetected ) {
			signals |= BOT.NO_HUMAN_EVENT;
		}

		var payload = {
			action       : 'rsa_track',
			nonce        : config.nonce,
			session_id   : sessionId,
			page         : page,
			referrer     : referrer,
			language     : language,
			timezone     : timezone,
			viewport_w   : viewportW,
			viewport_h   : viewportH,
			time_on_page : timeOnPage,
			bot_signals  : signals,
			utm_source   : utmSource,
			utm_medium   : utmMedium,
			utm_campaign : utmCampaign,
		};

		// Use navigator.sendBeacon when available (fires reliably on page unload)
		var body = Object.keys( payload ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( payload[ k ] );
		} ).join( '&' );

		if ( navigator.sendBeacon ) {
			var blob = new Blob( [ body ], { type: 'application/x-www-form-urlencoded' } );
			navigator.sendBeacon( config.ajaxUrl, blob );
		} else {
			// Synchronous fallback via jQuery AJAX (already loaded by WP)
			$.ajax( {
				url      : config.ajaxUrl,
				method   : 'POST',
				data     : payload,
				async    : false,  // must complete before unload
			} );
		}
	}

	// Safety net: fire after 30s as last resort for rare mobile/WebView scenarios
	// where neither pagehide nor beforeunload ever fire. Keep delay long so it
	// does not truncate real time-on-page measurements for normal navigation.
	var safetyTimer = setTimeout( function () {
		if ( ! sent ) {
			computeTimeOnPage();
			sendEvent();
		}
	}, 30000 );

	// Capture time on unload
	window.addEventListener( 'pagehide', function () {
		clearTimeout( safetyTimer );
		computeTimeOnPage();
		sendEvent();
	} );

	// Also try beforeunload as secondary trigger
	window.addEventListener( 'beforeunload', function () {
		clearTimeout( safetyTimer );
		computeTimeOnPage();
		sendEvent();
	} );

	/* <fs_premium_only> */

	// ----------------------------------------------------------------
	// Premium — Click tracking
	// Injected via RSA.premium.clickEnabled flag.
	// Attached via event delegation on document to catch dynamic elements.
	// ----------------------------------------------------------------
	if ( config.premium && config.premium.clickEnabled ) {

		var protocols    = config.protocols || {};
		var trackIds     = config.premium.trackIds     || [];
		var trackClasses = config.premium.trackClasses || [];

		// Elements that have a native click interaction
		var NATIVE_CLICK_TAGS = { A: 1, BUTTON: 1, INPUT: 1, SELECT: 1, TEXTAREA: 1, LABEL: 1 };
		var NATIVE_INPUT_TYPES = { submit: 1, button: 1, checkbox: 1, radio: 1, file: 1 };

		function isNativelyClickable( el ) {
			var tag = ( el.tagName || '' ).toUpperCase();
			if ( NATIVE_CLICK_TAGS[ tag ] ) {
				if ( tag === 'INPUT' ) {
					return !! NATIVE_INPUT_TYPES[ ( el.type || '' ).toLowerCase() ];
				}
				return true;
			}
			var role = ( el.getAttribute( 'role' ) || '' ).toLowerCase();
			if ( role === 'button' || role === 'link' || role === 'menuitem' ) {
				return true;
			}
			return false;
		}

		function getHrefProtocol( el ) {
			// Download attribute on any element — track if enabled in settings
			if ( el.hasAttribute( 'download' ) && el.getAttribute( 'href' ) && protocols.download ) return 'download';
			var href = ( el.getAttribute( 'href' ) || '' ).toLowerCase();
			if ( ! href ) return null;
			if ( /^https?:/.test( href ) ) { return null; } // navigation — tracked as pageviews
			if ( /^tel:/.test( href )    && protocols.tel )    return 'tel';
			if ( /^mailto:/.test( href ) && protocols.mailto ) return 'mailto';
			if ( /^geo:/.test( href )    && protocols.geo )    return 'geo';
			if ( /^sms:/.test( href )    && protocols.sms )    return 'sms';
			return null;
		}

		/**
		 * Extract the meaningful destination value from a protocol href.
		 * e.g. tel:+15551234567  →  +15551234567
		 *      mailto:hi@example.com?subject=Hi  →  hi@example.com
		 *      sms:+15551234567  →  +15551234567
		 *      geo:37.786,-122.4  →  37.786,-122.4
		 *      download href  →  the raw href (file path/URL)
		 */
		function getHrefValue( el, protocol ) {
			if ( ! protocol ) return '';
			var raw = el.getAttribute( 'href' ) || '';
			if ( protocol === 'download' ) {
				// Trim to 512 chars; strip auth credentials if any
				return raw.replace( /:\/\/[^:@]+:[^@]+@/, '://' ).substring( 0, 512 );
			}
			// Strip the scheme prefix (tel:, mailto:, sms:, geo:)
			var colonIdx = raw.indexOf( ':' );
			var value    = colonIdx !== -1 ? raw.substring( colonIdx + 1 ) : raw;
			// mailto: may have a ?subject=… query — keep only the address
			if ( protocol === 'mailto' ) {
				var qIdx = value.indexOf( '?' );
				if ( qIdx !== -1 ) { value = value.substring( 0, qIdx ); }
			}
			return value.substring( 0, 512 );
		}

		/**
		 * Returns the matched configured rule string ('#id' or '.class')
		 * if the element matches a configured ID or class, otherwise null.
		 */
		function elementMatchesConfig( el ) {
			// Check configured IDs
			if ( trackIds.length && el.id ) {
				for ( var i = 0; i < trackIds.length; i++ ) {
					if ( el.id === trackIds[ i ] ) return '#' + trackIds[ i ];
				}
			}
			// Check configured classes
			if ( trackClasses.length ) {
				var classList = Array.prototype.slice.call( el.classList || [] );
				for ( var j = 0; j < trackClasses.length; j++ ) {
					if ( classList.indexOf( trackClasses[ j ] ) !== -1 ) return '.' + trackClasses[ j ];
				}
			}
			return null;
		}

		document.addEventListener( 'click', function ( e ) {
			var el = e.target;

			// Walk up the DOM a few levels to catch clicks on child elements inside a button/link
			for ( var depth = 0; depth < 4 && el && el !== document.body; depth++ ) {
				if ( ! isNativelyClickable( el ) ) {
					el = el.parentElement;
					continue;
				}

				var hrefProtocol = getHrefProtocol( el );
				var matchedRule  = elementMatchesConfig( el );

				// Only track if: protocol/download match OR configured ID/class match
				if ( ! hrefProtocol && ! matchedRule ) {
					el = el.parentElement;
					continue;
				}

				// Document-relative coordinates as percentages.
				// x: viewport-relative (horizontal scroll is rare).
				// y: (scrollY + clientY) / documentHeight so below-fold clicks map correctly.
				var vw      = window.innerWidth   || 1;
				var vh      = window.innerHeight  || 1;
				var scrollY = window.scrollY || document.documentElement.scrollTop || 0;
				var docH    = Math.max(
					document.documentElement.scrollHeight || 0,
					document.body.scrollHeight || 0,
					vh
				);
				var xPct = Math.round( ( e.clientX / vw ) * 10000 ) / 100;
				var yPct = Math.round( ( ( e.clientY + scrollY ) / docH ) * 10000 ) / 100;

				// Truncate class list to 512 chars
				var elClass = el.className ? String( el.className ).substring( 0, 512 ) : '';

				var clickPayload = {
					action        : 'rsa_track_click',
					nonce         : config.nonce,
					session_id    : sessionId,
					page          : page,
					element_tag   : ( el.tagName || '' ).toLowerCase(),
					element_id    : ( el.id || '' ).substring( 0, 255 ),
					element_class : elClass,
					element_text  : ( el.innerText || el.value || '' ).substring( 0, 255 ),
					href_protocol : hrefProtocol || '',
					href_value    : getHrefValue( el, hrefProtocol ),
					matched_rule  : matchedRule  || '',
					x_pct         : xPct,
					y_pct         : yPct,
				};

				// Fire-and-forget via sendBeacon
				var cBody = Object.keys( clickPayload ).map( function ( k ) {
					return encodeURIComponent( k ) + '=' + encodeURIComponent( clickPayload[ k ] );
				} ).join( '&' );

				if ( navigator.sendBeacon ) {
					navigator.sendBeacon( config.ajaxUrl, new Blob( [ cBody ], { type: 'application/x-www-form-urlencoded' } ) );
				} else {
					$.post( config.ajaxUrl, clickPayload );
				}

				break; // Only track the first matching ancestor
			}
		}, { passive: true } );
	}

	/* </fs_premium_only> */

} ( window.jQuery, window.RSA ) );
