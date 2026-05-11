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
	// RSA plugin URL constants are needed when class files are loaded.
	define( 'RSA_VERSION',    '1.1.0' );
	define( 'RSA_URL',        'http://example.com/wp-content/plugins/rich-statistics/' );
	define( 'RSA_ASSETS_URL', RSA_URL . 'assets/' );
	define( 'RSA_APP_URL',    'https://rs-app.richardkentgates.com/' );
	define( 'RSA_APP_ENV',    'test' );
	define( 'RSA_MIN_WP',     '6.0' );
	define( 'RSA_MIN_PHP',    '8.0' );

	if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
		define( 'WP_TESTS_CONFIG_FILE_PATH', $wp_tests_dir . '/wp-tests-config.php' );
	}
	require_once $wp_tests_dir . '/includes/functions.php';

	if ( ! function_exists( 'rsa_load_plugin_for_tests' ) ) {
		function rsa_load_plugin_for_tests() {
			$includes = [
				'class-db',
				'class-bot-detection',
				'class-tracker',
				'class-analytics',
				'class-email',
				'class-admin',
				'class-woocommerce',
				'class-click-tracking',
				'class-heatmap',
				'class-rest-api',
			];
			foreach ( $includes as $cls ) {
				$f = RSA_DIR . 'includes/' . $cls . '.php';
				if ( file_exists( $f ) ) {
					require_once $f;
				}
			}
			// Load CLI class only if WP_CLI is available (not in test environment)
			$cli_path = RSA_DIR . 'cli/class-cli.php';
			if ( file_exists( $cli_path ) && ! class_exists( 'RSA_CLI' ) && class_exists( 'WP_CLI_Command' ) ) {
				require_once $cli_path;
			}
			if ( class_exists( 'RSA_Rest_API' ) ) {
				RSA_Rest_API::init();
			}
			if ( class_exists( 'RSA_DB' ) ) {
				RSA_DB::install();
			}
		}
	}

	tests_add_filter( 'muplugins_loaded', 'rsa_load_plugin_for_tests' );

	// Load WooCommerce if available — needs Action Scheduler and WordPress init to complete first.
	// We hook at 'init' priority 1 to ensure all WP APIs are ready before loading WC.
	tests_add_filter( 'init', function() {
		$wc_path = WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
		$as_path = WP_CONTENT_DIR . '/plugins/woocommerce/packages/action-scheduler/action-scheduler.php';

		if ( ! class_exists( 'WooCommerce' ) ) {
			// Load Action Scheduler first (WooCommerce dependency)
			if ( file_exists( $as_path ) && ! function_exists( 'as_next_scheduled_action' ) ) {
				include_once $as_path;
			}
			// Load WooCommerce
			if ( file_exists( $wc_path ) ) {
				include_once $wc_path;
			}
		}
	}, 1 );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
	return;
}

// -----------------------------------------------------------------------
// Unit (no WordPress) — minimal stubs, load classes per-test
// Brain\Monkey will handle function mocking via Patchwork.
// -----------------------------------------------------------------------

define( 'ABSPATH',        sys_get_temp_dir() . '/' );
define( 'RSA_VERSION',    '1.1.0' );
define( 'RSA_URL',        'http://example.com/wp-content/plugins/rich-statistics/' );
define( 'RSA_ASSETS_URL', RSA_URL . 'assets/' );
define( 'RSA_APP_URL',    'https://rs-app.richardkentgates.com/' );
define( 'RSA_MIN_WP',     '6.0' );
define( 'RSA_MIN_PHP',    '8.0' );

// For unit tests, we intentionally do NOT define WordPress function stubs
// in bootstrap.php. Plugin classes are loaded inside each test file's setUp(),
// after Monkey\setUp() runs, so Brain\Monkey's Patchwork can intercept WP
// function definitions before the class code executes. Stub definitions here
// would cause "DefinedTooEarly" conflicts when Brain\Monkey tries to mock them.
// See: BotDetectionTest, TrackerTest, ClickTrackingTest for the correct pattern.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): bool { return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) { return $default; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $s ): string { return trim( wp_strip_all_tags( $s ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $n ): int { return abs( (int) $n ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string { return $url; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed { return is_string( $value ) ? stripslashes( $value ) : $value; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ) { return gmdate( $type === 'timestamp' ? 'U' : 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool { return false; }
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool { return false; }
}
if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $option, $default = false ) { return $default; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string { return md5( $action . 'nonce' ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string { return 'http://example.com/wp-admin/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null ): void { echo json_encode( [ 'success' => true, 'data' => $data ] ); }
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, int $status = 400 ): void { echo json_encode( [ 'success' => false, 'data' => $data ] ); }
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action = '', $query_arg = false ): bool { return true; }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return new class {
			public $ID = 1;
			public $roles = [ 'administrator' ];
			public $user_email = 'admin@example.com';
		};
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ) { return $single ? '' : []; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, $value ): bool { return true; }
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( int $user_id, string $key ): bool { return true; }
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = [] ): array { return []; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $key = '' ): string {
		$map = [ 'name' => 'Test Site', 'admin_email' => 'admin@test.com', 'url' => 'http://example.com' ];
		return $map[ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value ): bool { return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool { return true; }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $time, string $hook, array $args = [] ): bool { return true; }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook ): void {}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ): int|false { return false; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool { return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool { return true; }
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, string $subject, string $message, array $headers = [], array $attachments = [] ): bool { return true; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ) { return new WP_Error( 'disabled', 'wp_remote_post stub' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int { return 200; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string { return '{}'; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool { return true; }
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		if ( $role === 'rsa_analyst' ) {
			return new class {
				public $capabilities = [ 'rsa_manage_statistics' => true, 'read' => true ];
				public function has_cap( string $cap ): bool { return $this->capabilities[ $cap ] ?? false; }
			};
		}
		return null;
	}
}
if ( ! function_exists( 'add_role' ) ) {
	function add_role( string $role, string $display_name, array $capabilities = [] ) { return null; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return 1; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool { return true; }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302 ): void {}
}
if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( string $redirect = '' ): string { return 'http://example.com/wp-login'; }
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', string $title = '', $args = [] ): void { exit; }
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = [], string $output = 'names', string $operator = 'and' ): array { return [ 'post' => 'Post', 'page' => 'Page' ]; }
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = [] ): array { return []; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ): string { return 'http://example.com/sample-post/'; }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ): string { return 'Sample Post'; }
}
if ( ! function_exists( 'wp_make_link_relative' ) ) {
	function wp_make_link_relative( string $link ): string { return parse_url( $link, PHP_URL_PATH ) ?: '/'; }
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() { return null; }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): void {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], $ver = false, bool $in_footer = false ): void {}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all' ): void {}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { return $text; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url, array $protocols = null ): string { return $url; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ): string {
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url = $args[1] ?? '';
		} else {
			$params = [ $args[0] => $args[1] ];
			$url = $args[2] ?? '';
		}
		return $url . ( strpos( $url, '?' ) !== false ? '&' : '?' ) . http_build_query( $params );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $string ): string { return rtrim( $string, '/\\' ) . '/'; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', string $scheme = null ): string { return 'http://example.com' . $path; }
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url( int $blog_id = null, string $path = '', string $scheme = null ): string { return 'http://example.com'; }
}
if ( ! function_exists( 'file_exists' ) ) {
	function file_exists( string $filename ): bool { return false; }
}
if ( ! function_exists( 'filemtime' ) ) {
	function filemtime( string $filename ): int { return time(); }
}
if ( ! function_exists( 'file_get_contents' ) ) {
	function file_get_contents( string $filename, bool $use_include_path = false, $context = null ) { return ''; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null, $output = 'OBJECT' ) { return null; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ): string { return 'post'; }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post = null ): string { return 'publish'; }
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() {
		return new class {
			public $roles = [
				'administrator' => [ 'name' => 'Administrator', 'capabilities' => [ 'manage_options' => true ] ],
				'editor'        => [ 'name' => 'Editor',        'capabilities' => [ 'edit_posts' => true ] ],
				'subscriber'    => [ 'name' => 'Subscriber',    'capabilities' => [ 'read' => true ] ],
			];
		};
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}

// NOTE: Do NOT call require_once for plugin class files here.
// Load them inside each test file's setUp() so Brain\Monkey's
// Patchwork can intercept function definitions properly.
// See BotDetectionTest, TrackerTest, ClickTrackingTest, TrackerRateLimitTest
// for how to load plugin classes after Monkey\setUp().