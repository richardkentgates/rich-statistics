<?php
/**
 * Plugin Name:       Rich Statistics
 * Plugin URI:        https://statistics.richardkentgates.com
 * Description:       Privacy-first analytics for WordPress publishers. No PII, no consent banners required.
 * Version:           1.4.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Rich Statistics
 * Author URI:        https://richardkentgates.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rich-statistics
 * Network:           true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'rs_fs' ) ) {
	rs_fs()->set_basename( true, __FILE__ );
} else {
	// --------------------------------------------------------------------
	// Constants
	// --------------------------------------------------------------------
	define( 'RSA_VERSION',     '1.4.2' );
	define( 'RSA_FILE',        __FILE__ );
	define( 'RSA_DIR',         plugin_dir_path( __FILE__ ) );
	define( 'RSA_URL',         plugin_dir_url( __FILE__ ) );
	define( 'RSA_ASSETS_URL',  RSA_URL . 'assets/' );
	define( 'RSA_MIN_WP',      '6.0' );
	define( 'RSA_MIN_PHP',     '8.0' );
define( 'RSA_APP_URL',     'https://rs-app.richardkentgates.com/app/' );

	/**
	 * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
	 * `function_exists` CALL ABOVE TO PROPERLY WORK.
	 */
	if ( ! function_exists( 'rs_fs' ) ) {
		// Create a helper function for easy SDK access.
		function rs_fs() {
			global $rs_fs;

			if ( ! isset( $rs_fs ) ) {
				// Activate multisite network integration.
				if ( ! defined( 'WP_FS__PRODUCT_25954_MULTISITE' ) ) {
					define( 'WP_FS__PRODUCT_25954_MULTISITE', true );
				}

				// Include Freemius SDK.
				require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

				$rs_fs = fs_dynamic_init( array(
					'id'                  => '25954',
					'slug'                => 'rich-statistics',
					'type'                => 'plugin',
					'public_key'          => 'pk_ebd3048f311ce1adcbdb6246fc1e5',
					'is_premium'          => true,
					'premium_suffix'      => 'Publisher',
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
					'trial'               => array(
						'days'               => 30,
						'is_require_payment' => true,
					),
					'menu'                => array(
						'slug'           => 'rich-statistics',
						'support'        => false,
						'network'        => true,
					),
				) );
			}

			return $rs_fs;
		}

		// Init Freemius.
		rs_fs();
		// Signal that SDK was initiated.
		do_action( 'rs_fs_loaded' );
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

// Premium-only classes (gated by Freemius — entire block stripped from free version)
if ( function_exists( 'rs_fs' ) && rs_fs()->is__premium_only() ) {
	$rsa_premium = [
		'RSA_Click_Tracking',
		'RSA_Heatmap',
		'RSA_Rest_API',
		'RSA_Pwa_Download',
		'RSA_Woocommerce',
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
register_activation_hook( RSA_FILE, function() { RSA_Admin::register_app_rewrite(); flush_rewrite_rules(); } );
register_deactivation_hook( RSA_FILE, [ 'RSA_DB', 'deactivate' ] );

// Uninstall — hooked via Freemius so the uninstall event + user feedback
// is reported to Freemius before our cleanup runs.
rs_fs()->add_action( 'after_uninstall', 'rs_fs_uninstall_cleanup' );

function rs_fs_uninstall_cleanup() {
	if ( is_multisite() ) {
		$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			RSA_DB::maybe_remove_data();
			restore_current_blog();
		}
		// Remove network-level options
		delete_site_option( 'rsa_network_settings' );
	} else {
		RSA_DB::maybe_remove_data();
	}
}

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

	// Boot core
	RSA_Tracker::init();
	RSA_Admin::init();
	RSA_Email::init();

	// WooCommerce integration (premium feature — gated via Freemius)
	if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) {
		if ( class_exists( 'WooCommerce' ) ) {
			RSA_Woocommerce::init();
		}
	}

	// Boot premium
	if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) {
		if ( class_exists( 'RSA_Click_Tracking' ) ) {
			RSA_Click_Tracking::init();
		}
		if ( class_exists( 'RSA_Heatmap' ) ) {
			RSA_Heatmap::init();
		}
		if ( class_exists( 'RSA_Rest_API' ) ) {
			RSA_Rest_API::init();
		}
		if ( class_exists( 'RSA_Pwa_Download' ) ) {
			RSA_Pwa_Download::init();
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
} // end else
