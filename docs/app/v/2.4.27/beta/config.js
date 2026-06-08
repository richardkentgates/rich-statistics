/**
 * Rich Statistics PWA — site configuration.
 *
 * The `env` property is auto-detected from the hostname.
 * Site URL must be entered manually by the user after adding their site.
 *
 * Environment overrides (config-dev.js, config-test.js) can set `env`
 * for local development or testing.
 */
( function () {
	window.RSA_CONFIG = window.RSA_CONFIG || {};

	// Auto-detect environment from hostname.
	if ( ! window.RSA_CONFIG.env ) {
		var host = window.location.hostname;
		if ( host === 'dev.richstatistics.com' || host === 'localhost' || host === '127.0.0.1' ) {
			window.RSA_CONFIG.env = 'development';
		} else if ( host === 'test.richstatistics.com' ) {
			window.RSA_CONFIG.env = 'test';
		} else {
			window.RSA_CONFIG.env = 'production';
		}
	}
}() );