/**
 * Rich Statistics PWA — site configuration.
 *
 * When served from within the plugin directory (wp-content/plugins/…),
 * the site URL is auto-detected from this file's own path so the user
 * never has to type it in.  When served standalone the field is left
 * blank and the user fills it in manually.
 *
 * The `env` property is set when served from the WordPress admin (injected
 * by the plugin).  When served standalone it is auto-detected from the
 * hostname so config-dev.js / config-test.js are only needed for manual
 * override scenarios.
 */
( function () {
	window.RSA_CONFIG = window.RSA_CONFIG || {};

	// Auto-detect: extract WordPress site URL from this script's src path.
	var s = document.currentScript;
	if ( s && s.src ) {
		var idx = s.src.indexOf( '/wp-content/' );
		if ( idx !== -1 ) {
			window.RSA_CONFIG.autoSiteUrl = s.src.substring( 0, idx );
		}
	}

	// Auto-detect environment from hostname when not injected by plugin.
	if ( ! window.RSA_CONFIG.env ) {
		var host = window.location.hostname;
		if ( host === 'rs-dev.richardkentgates.com' || host === 'localhost' || host === '127.0.0.1' ) {
			window.RSA_CONFIG.env = 'development';
		} else if ( host === 'rs-test.richardkentgates.com' ) {
			window.RSA_CONFIG.env = 'test';
		} else {
			window.RSA_CONFIG.env = 'production';
		}
	}
}() );
