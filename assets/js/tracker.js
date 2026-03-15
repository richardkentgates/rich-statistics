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

	// Capture time on unload
	window.addEventListener( 'pagehide', function () {
		computeTimeOnPage();
		sendEvent();
	} );

	// Also try beforeunload as secondary trigger
	window.addEventListener( 'beforeunload', function () {
		computeTimeOnPage();
		sendEvent();
	} );

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
			var href = ( el.getAttribute( 'href' ) || '' ).toLowerCase();
			if ( ! href ) return null;
			if ( /^https?:/.test( href ) && protocols.http )   return 'http';
			if ( /^tel:/.test( href )    && protocols.tel )    return 'tel';
			if ( /^mailto:/.test( href ) && protocols.mailto ) return 'mailto';
			if ( /^geo:/.test( href )    && protocols.geo )    return 'geo';
			if ( /^sms:/.test( href )    && protocols.sms )    return 'sms';
			return null;
		}

		function elementMatchesConfig( el ) {
			// Check configured IDs
			if ( trackIds.length && el.id ) {
				for ( var i = 0; i < trackIds.length; i++ ) {
					if ( el.id === trackIds[ i ] ) return true;
				}
			}
			// Check configured classes
			if ( trackClasses.length ) {
				var classList = Array.prototype.slice.call( el.classList || [] );
				for ( var j = 0; j < trackClasses.length; j++ ) {
					if ( classList.indexOf( trackClasses[ j ] ) !== -1 ) return true;
				}
			}
			return false;
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
				var matchesConfig = elementMatchesConfig( el );

				// Only track if: protocol match OR configured ID/class
				if ( ! hrefProtocol && ! matchesConfig ) {
					el = el.parentElement;
					continue;
				}

				// Viewport-relative coordinates as percentages
				var vw = window.innerWidth  || 1;
				var vh = window.innerHeight || 1;
				var xPct = Math.round( ( e.clientX / vw ) * 10000 ) / 100;
				var yPct = Math.round( ( e.clientY / vh ) * 10000 ) / 100;

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

} ( window.jQuery, window.RSA ) );
