/**
 * Rich Statistics — Service Worker
 *
 * Strategy:
 *  • App shell (HTML/CSS/JS)  → Cache-first (serve cached, refresh in background)
 *  • API calls to wp-json/    → Network-first (cache result for offline fallback)
 */

const CACHE_VERSION = 'rsa-v15';

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
		caches.open( CACHE_VERSION ).then( function ( cache ) {
			return cache.addAll( SHELL_ASSETS );
		} )
	);
	self.skipWaiting();
} );

// -------------------------------------------------------------------------
// Activate: purge old versioned caches
// -------------------------------------------------------------------------
self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all(
				keys
					.filter( function ( key ) { return key !== CACHE_VERSION; } )
					.map( function ( key ) { return caches.delete( key ); } )
			);
		} )
	);
	self.clients.claim();
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

	// App shell assets → cache-first, refresh in background (stale-while-revalidate)
	const isShellAsset = SHELL_ASSETS.some( function ( asset ) {
		return url.pathname.endsWith( asset.replace( './', '' ).replace( '../', '' ) );
	} );

	if ( isShellAsset ) {
		event.respondWith( networkFirstWithCache( event.request ) );
		return;
	}
	// Everything else: network only
} );

// -------------------------------------------------------------------------
// Strategy helpers
// -------------------------------------------------------------------------
function cacheFirstWithRefresh( request ) {
	return caches.open( CACHE_VERSION ).then( function ( cache ) {
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
	return caches.open( CACHE_VERSION ).then( function ( cache ) {
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
