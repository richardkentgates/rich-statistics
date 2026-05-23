<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package RichStatistics
 *
 * @license GPL-2.0-or-later
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the plugin so we can access constants and classes.
require_once plugin_dir_path( __FILE__ ) . 'rich-statistics.php';

// Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'rsa_daily_maintenance' );
wp_clear_scheduled_hook( 'rsa_send_digest' );

// Remove data (tables, options) using the existing DB class.
RSA_DB::maybe_remove_data();
