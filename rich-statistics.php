<?php
/**
 * Plugin Name:       Rich Statistics
 * Plugin URI:        https://richstatistics.com
 * Description:       Privacy-first analytics for WordPress publishers. No PII, no consent banners required.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Rich Statistics
 * Author URI:        https://richstatistics.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rich-statistics
 * Domain Path:       /languages
 * Network:           true
 */

defined( 'ABSPATH' ) || exit;

// --------------------------------------------------------------------
// Constants
// --------------------------------------------------------------------
define( 'RSA_VERSION',     '1.0.0' );
define( 'RSA_FILE',        __FILE__ );
define( 'RSA_DIR',         plugin_dir_path( __FILE__ ) );
define( 'RSA_URL',         plugin_dir_url( __FILE__ ) );
define( 'RSA_ASSETS_URL',  RSA_URL . 'assets/' );
define( 'RSA_MIN_WP',      '6.0' );
define( 'RSA_MIN_PHP',     '8.0' );

// --------------------------------------------------------------------
// Freemius SDK bootstrap
//
// Production: freemius/start.php is downloaded by build.sh and included
//             in the distributable ZIP uploaded to Freemius.
//
// Development / CI: when freemius/ is absent a lightweight stub is used
//             so that the free tier loads correctly without the SDK.
//
// To configure for your Freemius account, replace the two placeholder
// values below with your product ID and public key from:
// https://dashboard.freemius.com → My Plugins → Your Plugin → Integration
// --------------------------------------------------------------------
if ( ! function_exists( 'rsa_fs' ) ) {
	function rsa_fs(): object {
		global $rsa_fs;
		if ( ! isset( $rsa_fs ) ) {

			$sdk = RSA_DIR . 'freemius/start.php';

			if ( file_exists( $sdk ) ) {
				// Production path — real Freemius SDK
				require_once $sdk;
				$rsa_fs = fs_dynamic_init( [
					// -------------------------------------------------------
					// ⚠️  FILL IN BEFORE UPLOADING TO FREEMIUS
					// -------------------------------------------------------
					'id'                          => '0000',                          // Your Freemius plugin ID
					'public_key'                  => 'pk_REPLACE_WITH_YOUR_KEY',      // Your Freemius public key
					// -------------------------------------------------------
					'slug'                        => 'rich-statistics',
					'type'                        => 'plugin',
					'is_premium'                  => true,
					'can_be_deactivated_for_free' => true,
					'has_addons'                  => false,
					'has_paid_plans'              => true,
					'trial'                       => [
						'days'               => 14,
						'is_require_payment' => false,
					],
					'menu'                        => [
						'slug'    => 'rich-statistics',
						'network' => true,
					],
					'is_org_compliant'            => true,
					'navigation'                  => 'menu',
				] );
			} else {
				// Development / CI stub — premium gates return false
				require_once RSA_DIR . 'includes/class-freemius-stub.php';
				$rsa_fs = RSA_Freemius_Stub::instance();
			}
		}
		return $rsa_fs;
	}
	rsa_fs();
	do_action( 'rsa_fs_loaded' );
}

// --------------------------------------------------------------------
// Autoload core classes
// --------------------------------------------------------------------
$rsa_classes = [
	'RSA_DB',
	'RSA_Bot_Detection',
	'RSA_Tracker',
	'RSA_Analytics',
	'RSA_Admin',
	'RSA_Email',
];

foreach ( $rsa_classes as $class ) {
	$file = RSA_DIR . 'includes/class-' . strtolower( str_replace( [ 'RSA_', '_' ], [ '', '-' ], $class ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

// WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once RSA_DIR . 'cli/class-cli.php';
	WP_CLI::add_command( 'rich-stats', 'RSA_CLI' );
}

// Premium-only classes (gated by Freemius)
if ( function_exists( 'rsa_fs' ) && rsa_fs()->can_use_premium_code() ) {
	$rsa_premium = [
		'RSA_Click_Tracking',
		'RSA_Heatmap',
		'RSA_Rest_API',
	];
	foreach ( $rsa_premium as $class ) {
		$file = RSA_DIR . 'includes/class-' . strtolower( str_replace( [ 'RSA_', '_' ], [ '', '-' ], $class ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// --------------------------------------------------------------------
// Activation / Deactivation / Uninstall hooks
// --------------------------------------------------------------------
register_activation_hook( RSA_FILE, [ 'RSA_DB', 'activate' ] );
register_deactivation_hook( RSA_FILE, [ 'RSA_DB', 'deactivate' ] );

// Uninstall is handled via uninstall.php
// (WordPress calls uninstall.php automatically when uninstall hook is absent and file exists)

// --------------------------------------------------------------------
// Bootstrap
// --------------------------------------------------------------------
add_action( 'plugins_loaded', 'rsa_init', 10 );

function rsa_init() {
	// Version gate
	if ( version_compare( $GLOBALS['wp_version'], RSA_MIN_WP, '<' ) ||
	     version_compare( PHP_VERSION, RSA_MIN_PHP, '<' ) ) {
		add_action( 'admin_notices', 'rsa_version_notice' );
		return;
	}

	// Load text domain
	load_plugin_textdomain( 'rich-statistics', false, dirname( plugin_basename( RSA_FILE ) ) . '/languages' );

	// Boot core
	RSA_Tracker::init();
	RSA_Admin::init();
	RSA_Email::init();

	// Boot premium
	if ( function_exists( 'rsa_fs' ) && rsa_fs()->can_use_premium_code() ) {
		if ( class_exists( 'RSA_Click_Tracking' ) ) {
			RSA_Click_Tracking::init();
		}
		if ( class_exists( 'RSA_Heatmap' ) ) {
			RSA_Heatmap::init();
		}
		if ( class_exists( 'RSA_Rest_API' ) ) {
			RSA_Rest_API::init();
		}
	}
}

function rsa_version_notice() {
	$msg = sprintf(
		/* translators: 1: minimum WP version, 2: minimum PHP version */
		__( 'Rich Statistics requires WordPress %1$s and PHP %2$s or higher.', 'rich-statistics' ),
		RSA_MIN_WP,
		RSA_MIN_PHP
	);
	echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
}
