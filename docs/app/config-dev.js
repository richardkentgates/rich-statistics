/**
 * Rich Statistics PWA — site configuration for DEVELOPMENT environment.
 *
 * This config identifies the app as running in dev/beta mode.
 */
( function () {
	window.RSA_CONFIG = window.RSA_CONFIG || {};
	window.RSA_CONFIG.env = 'development';

	var s = document.currentScript;
	if ( s && s.src ) {
		var idx = s.src.indexOf( '/wp-content/' );
		if ( idx !== -1 ) {
			window.RSA_CONFIG.autoSiteUrl = s.src.substring( 0, idx );
		}
	}
}() );
