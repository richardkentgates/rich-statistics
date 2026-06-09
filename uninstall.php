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

// Load only the DB class — avoids bootstrapping Freemius and the full plugin
// stack during uninstall, which can white-screen on servers with strict
// opcache or when the Freemius SDK is missing.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-db.php';

// Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'rsa_daily_maintenance' );
wp_clear_scheduled_hook( 'rsa_send_digest' );

// Flush rewrite rules to remove stale rs-app rules left by older versions.
flush_rewrite_rules();

// Remove data (tables, options) using the existing DB class.
RSA_DB::maybe_remove_data();
