<?php
// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- test bootstrap, not a web-facing file
/**
 * PHPUnit bootstrap — Rich Statistics test suite.
 *
 * Supports two modes:
 *
 *  1. Integration (WP_TESTS_DIR defined):
 *     The WordPress test library is loaded and tests extend WP_UnitTestCase.
 *     Run with: bash bin/install-wp-tests.sh ... && composer test
 *
 *  2. Unit (no WP_TESTS_DIR):
 *     Tests load Brain\Monkey stubs for WordPress functions so they run
 *     without a WordPress install. Suitable for CI on a plain PHP container.
 */

define( 'RSA_TESTS', true );

// Composer autoloader (Brain\Monkey, Mockery, etc.)
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// RSA_DIR is needed by both modes to locate class files.
define( 'RSA_DIR', dirname( __DIR__ ) . '/' );

// -----------------------------------------------------------------------
// Stub rs_fs() — neither mode needs the real Freemius SDK
// -----------------------------------------------------------------------
if ( ! function_exists( 'rs_fs' ) ) {
	function rs_fs(): object {
		static $stub = null;
		if ( $stub === null ) {
			$stub = new class {
				public function can_use_premium_code__premium_only(): bool { return false; }
				public function is_premium(): bool       { return false; }
				public function is_paying(): bool        { return false; }
				public function is_not_paying(): bool    { return true; }
				public function is_trial(): bool         { return false; }
				public function is_free_plan(): bool     { return true; }
				public function get_upgrade_url(): string { return '#'; }
			};
		}
		return $stub;
	}
}

// -----------------------------------------------------------------------
// WordPress integration path (WP_TESTS_DIR available)
// -----------------------------------------------------------------------
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( is_dir( $wp_tests_dir ) ) {
	// Do NOT pre-define ABSPATH or other WP constants here — the WP test
	// bootstrap defines them correctly from the WP core install path.
	// Pre-defining them causes harmless-looking but test-fatal PHP warnings
	// because phpunit.xml.dist has failOnWarning=true.

	// RSA plugin URL constants are needed when class files are loaded.
	define( 'RSA_VERSION',    '1.1.0' );
	define( 'RSA_URL',        'http://example.com/wp-content/plugins/rich-statistics/' );
	define( 'RSA_ASSETS_URL', RSA_URL . 'assets/' );
	define( 'RSA_MIN_WP',     '6.0' );
	define( 'RSA_MIN_PHP',    '8.0' );

	if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
		define( 'WP_TESTS_CONFIG_FILE_PATH', $wp_tests_dir . '/wp-tests-config.php' );
	}
	require_once $wp_tests_dir . '/includes/functions.php';

	// Load the plugin before WordPress finishes loading
	tests_add_filter( 'muplugins_loaded', function () {
		// Load only the core classes needed by integration tests.
		// Omit class-admin.php and class-email.php — they use rs_fs()->get_upgrade_url()
		// and other premium methods not stubbed in tests, and are not exercised by
		// the integration test suite.
		$classes = [
			'class-db',
			'class-bot-detection',
			'class-tracker',
			'class-analytics',
			'class-click-tracking',
			'class-heatmap',
			'class-rest-api',
		];
		foreach ( $classes as $cls ) {
			$f = RSA_DIR . 'includes/' . $cls . '.php';
			if ( file_exists( $f ) ) {
				require_once $f;
			}
		}

		// Boot the REST routes so 'rest_api_init' hook is wired up before tests fire it.
		if ( class_exists( 'RSA_Rest_API' ) ) {
			RSA_Rest_API::init();
		}

		// Ensure DB tables exist for all integration tests (AnalyticsTest, RestApiTest
		// don't call RSA_DB::install() in their setUp, so we do it here once).
		if ( class_exists( 'RSA_DB' ) ) {
			RSA_DB::install();
		}
	} );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
	return; // WP bootstrap takes over
}

// -----------------------------------------------------------------------
// Unit (no WordPress) — load Brain\Monkey stubs and plugin files directly
// -----------------------------------------------------------------------

// Define plugin constants that aren't set by the WP bootstrap in unit mode.
define( 'ABSPATH',        sys_get_temp_dir() . '/' );
define( 'RSA_VERSION',    '1.1.0' );
define( 'RSA_URL',        'http://example.com/wp-content/plugins/rich-statistics/' );
define( 'RSA_ASSETS_URL', RSA_URL . 'assets/' );
define( 'RSA_MIN_WP',     '6.0' );
define( 'RSA_MIN_PHP',    '8.0' );

// Provide minimal WP function stubs BEFORE loading plugin files, as some
// classes call add_action() / add_filter() at file scope on load.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): bool { return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ): mixed { return $default; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this IS the WP polyfill stub
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this IS the WP polyfill stub
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $s ): string { return trim( wp_strip_all_tags( $s ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $n ): int { return abs( (int) $n ); }
}

require_once RSA_DIR . 'includes/class-bot-detection.php';
require_once RSA_DIR . 'includes/class-db.php';
require_once RSA_DIR . 'includes/class-tracker.php';
