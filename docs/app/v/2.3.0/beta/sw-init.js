/**
 * Rich Statistics PWA — Service Worker registration.
 *
 * Kept in a separate file so the main index.html can declare a strict
 * Content-Security-Policy (script-src 'self') without needing 'unsafe-inline'.
 *
 * When a new SW installs and takes control (via skipWaiting + clients.claim),
 * we reload so open pages pick up the new app files immediately.
 */
( function () {
	if ( ! ( 'serviceWorker' in navigator ) ) return;

	// Remember whether a SW was already controlling this page before we
	// registered/refreshed — used to distinguish an UPDATE from first install.
	var _rsaHadSWController = !! navigator.serviceWorker.controller;

	navigator.serviceWorker.register( 'sw.js' ).catch( function () {} );

	// controllerchange fires when skipWaiting() causes a new SW to take over.
	navigator.serviceWorker.addEventListener( 'controllerchange', function () {
		if ( _rsaHadSWController ) {
			window.location.reload();
		} else {
			// First install — mark that we now have a controller so that
			// the next update will trigger a reload.
			_rsaHadSWController = true;
		}
	} );

	// SW also sends an explicit SW_ACTIVATED message after clients.claim().
	// Handles the case where controllerchange already fired before this listener
	// was attached.
	navigator.serviceWorker.addEventListener( 'message', function ( event ) {
		if ( event.data && event.data.type === 'SW_ACTIVATED' && _rsaHadSWController ) {
			window.location.reload();
		}
	} );
}() );
