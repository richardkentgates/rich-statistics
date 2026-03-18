/**
 * Rich Statistics PWA — site configuration.
 *
 * When served from within the plugin directory (wp-content/plugins/…),
 * the site URL is auto-detected from this file's own path so the user
 * never has to type it in.  When served from GitHub Pages the field is
 * left blank and the user fills it in manually.
 */
( function () {
	window.RSA_CONFIG = window.RSA_CONFIG || {};

	// Auto-detect: extract WordPress site URL from this script's src path.
	// Works for root installs (yoursite.com) and subdirectory installs
	// (yoursite.com/blog) as well as both subdomain and subdirectory multisite.
	var s = document.currentScript;
	if ( s && s.src ) {
		var idx = s.src.indexOf( '/wp-content/' );
		if ( idx !== -1 ) {
			window.RSA_CONFIG.autoSiteUrl = s.src.substring( 0, idx );
		}
	}
}() );
