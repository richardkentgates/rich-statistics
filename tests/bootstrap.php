<?php
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

// Plugin constants needed by the classes under test
define( 'ABSPATH',       sys_get_temp_dir() . '/' );
define( 'RSA_VERSION',   '1.0.0' );
define( 'RSA_DIR',       dirname( __DIR__ ) . '/' );
define( 'RSA_URL',       'http://example.com/wp-content/plugins/rich-statistics/' );
define( 'RSA_ASSETS_URL', RSA_URL . 'assets/' );
define( 'RSA_MIN_WP',    '6.0' );
define( 'RSA_MIN_PHP',   '8.0' );

// -----------------------------------------------------------------------
// Stub rsa_fs() for unit tests — tests never need the real Freemius SDK
// -----------------------------------------------------------------------
if ( ! function_exists( 'rsa_fs' ) ) {
	function rsa_fs(): object {
		static $stub = null;
		if ( $stub === null ) {
			$stub = new class {
				public function can_use_premium_code(): bool { return false; }
				public function is_premium(): bool           { return false; }
				public function is_paying(): bool            { return false; }
				public function is_trial(): bool             { return false; }
				public function is_free_plan(): bool         { return true; }
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
	if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
		define( 'WP_TESTS_CONFIG_FILE_PATH', $wp_tests_dir . '/wp-tests-config.php' );
	}
	require_once $wp_tests_dir . '/includes/functions.php';

	// Load the plugin before WordPress finishes loading
	tests_add_filter( 'muplugins_loaded', function () {
		// Load only the core classes (not rich-statistics.php to avoid Freemius bootstrapping)
		$classes = [
			'class-db',
			'class-bot-detection',
			'class-tracker',
			'class-analytics',
			'class-admin',
			'class-email',
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
	} );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
	return; // WP bootstrap takes over
}

// -----------------------------------------------------------------------
// Unit (no WordPress) — load Brain\Monkey stubs and plugin files directly
// -----------------------------------------------------------------------

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
	function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $s ): string { return trim( strip_tags( $s ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $n ): int { return abs( (int) $n ); }
}

require_once RSA_DIR . 'includes/class-bot-detection.php';
require_once RSA_DIR . 'includes/class-db.php';
require_once RSA_DIR . 'includes/class-tracker.php';
