/**
 * Rich Statistics — Service Worker
 *
 * Strategy:
 *  • App shell (HTML/CSS/JS)  → Network-first (fresh files every load; cache only for offline fallback)
 *  • API calls to wp-json/    → Network-first (cache result for offline fallback)
 *
 * Cache key is scoped to this WP install’s hostname so different installs never
 * share a cache.  Version invalidation is handled at runtime by app.js
 * (checkPluginVersion), which clears all caches and reloads when the plugin
 * version on the server changes.  No hardcoded version number is needed here.
 */

// One cache per WP install origin — never share between sites.
var CACHE_NAME = 'rsa-' + self.location.hostname.replace( /[^a-z0-9]/gi, '-' );

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
// Activate: purge old versioned caches
// -------------------------------------------------------------------------
self.addEventListener( 'activate', function ( event ) {
	// Purge any caches from old naming schemes (e.g. 'rsa-v15') on SW update.
	event.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all(
				keys
					.filter( function ( key ) { return key !== CACHE_NAME; } )
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
