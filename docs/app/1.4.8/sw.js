/**
 * Rich Statistics — Service Worker
 *
 * Strategy:
 *  • Versioned path (/app/1.x.x/) → Cache-first, immutable forever
 *  • App shell (HTML/CSS/JS)       → Network-first (fresh files every load; cache only for offline fallback)
 *  • API calls to wp-json/         → Network-first (cache result for offline fallback)
 *
 * When running from an external versioned URL (e.g. statistics.richardkentgates.com/app/1.3.0/),
 * app.js redirects to a new version folder on plugin update rather than clearing caches.
 * Each versioned folder is cached immutably so switching versions is instant.
 */

// Detect if running from a versioned external URL (e.g. /1.3.0/ or /app/1.3.0/).
// If so, use an immutable cache scoped to that exact version.
var versionMatch = self.location.pathname.match( /\/([0-9]+\.[0-9]+\.[0-9]+)\//);
var CACHE_NAME = versionMatch
	? 'rsa-v' + versionMatch[1].replace( /\./g, '-' )
	: 'rsa-' + self.location.hostname.replace( /[^a-z0-9]/gi, '-' );
var IMMUTABLE = !! versionMatch;

const SHELL_ASSETS = [
	'./index.html',
	'./config.js',
	'./app.js',
	'./app.css',
	'./manifest.json',
	'./chart.min.js',
];

// -------------------------------------------------------------------------
// Install: cache the app shell
// -------------------------------------------------------------------------
self.addEventListener( 'install', function ( event ) {
	event.waitUntil(
		caches.open( CACHE_NAME ).then( function ( cache ) {
			return cache.addAll( SHELL_ASSETS );
		} )
	);
	self.skipWaiting();
} );

// -------------------------------------------------------------------------
// Activate: purge stale caches, claim clients, signal update to open pages
// -------------------------------------------------------------------------
self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all(
				keys
					.filter( function ( key ) {
						if ( key === CACHE_NAME ) return false; // keep current
						// Never evict other versioned caches — they are immutable
						// and may be needed if the user navigates back.
						if ( IMMUTABLE && key.match( /^rsa-v[0-9]/ ) ) return false;
						return true;
					} )
					.map( function ( key ) { return caches.delete( key ); } )
			);
		} ).then( function () {
			// Claim all open clients so they immediately switch to this SW.
			return self.clients.claim();
		} ).then( function () {
			// Tell every open page to reload so it picks up the new app files.
			// The client-side guard (hadController) prevents reloading on first install.
			return self.clients.matchAll( { includeUncontrolled: true, type: 'window' } ).then( function ( clients ) {
				clients.forEach( function ( client ) {
					client.postMessage( { type: 'SW_ACTIVATED' } );
				} );
			} );
		} )
	);
} );

// -------------------------------------------------------------------------
// Fetch: route requests
// -------------------------------------------------------------------------
self.addEventListener( 'fetch', function ( event ) {
	const url = new URL( event.request.url );

	// Pass through non-GET requests (REST ingest POSTs etc.)
	if ( event.request.method !== 'GET' ) {
		return;
	}

	// wp-json / REST API → network-first with offline fallback from cache
	if ( url.pathname.includes( '/wp-json/' ) ) {
		event.respondWith( networkFirstWithCache( event.request ) );
		return;
	}

	// App shell assets — immutable cache-first when running from a versioned
	// external URL, network-first otherwise.
	const isShellAsset = SHELL_ASSETS.some( function ( asset ) {
		return url.pathname.endsWith( asset.replace( './', '' ).replace( '../', '' ) );
	} );

	if ( isShellAsset ) {
		event.respondWith( IMMUTABLE ? cacheFirstImmutable( event.request ) : networkFirstWithCache( event.request ) );
		return;
	}
	// Everything else: network only
} );

// -------------------------------------------------------------------------
// Strategy helpers
// -------------------------------------------------------------------------
function cacheFirstWithRefresh( request ) {
	return caches.open( CACHE_NAME ).then( function ( cache ) {
		return cache.match( request ).then( function ( cached ) {
			// Kick off a background refresh regardless
			const networkFetch = fetch( request ).then( function ( response ) {
				if ( response && response.status === 200 ) {
					cache.put( request, response.clone() );
				}
				return response;
			} ).catch( function () { return null; } );

			return cached || networkFetch;
		} );
	} );
}

function networkFirstWithCache( request ) {
	return caches.open( CACHE_NAME ).then( function ( cache ) {
		return fetch( request ).then( function ( response ) {
			if ( response && response.status === 200 ) {
				cache.put( request, response.clone() );
			}
			return response;
		} ).catch( function () {
			return cache.match( request );
		} );
	} );
}

// Serve from cache forever; only fetch from network if not cached yet.
// Used for versioned external URL folders — assets never change there.
function cacheFirstImmutable( request ) {
	return caches.open( CACHE_NAME ).then( function ( cache ) {
		return cache.match( request ).then( function ( cached ) {
			if ( cached ) return cached;
			return fetch( request ).then( function ( response ) {
				if ( response && response.status === 200 ) {
					cache.put( request, response.clone() );
				}
				return response;
			} );
		} );
	} );
}
