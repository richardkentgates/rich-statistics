<?php
/**
 * Uninstall Rich Statistics
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes all custom tables, options, transients, and scheduled cron hooks.
 *
 * @package Rich_Statistics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Freemius requires this hook to capture uninstall feedback.
 * Without it, Freemius rejects the plugin upload.
 *
 * @see https://freemius.com/help/documentation/wordpress-sdk/uninstall-feedback/
 */
if ( ! function_exists( 'rsa_after_uninstall' ) ) {
	/**
	 * Freemius uninstall feedback callback.
	 *
	 * @param array $all_plugin_slugs Array of plugin slugs being uninstalled.
	 */
	function rsa_after_uninstall( $all_plugin_slugs ) {
		// Freemius handles the feedback form automatically.
		// No custom action needed — the hook just needs to exist.
	}
}
add_action( 'after_uninstall', 'rsa_after_uninstall' );

global $wpdb;

// ── Clear scheduled cron hooks ─────────────────────────────────────
wp_clear_scheduled_hook( 'rsa_daily_maintenance' );
wp_clear_scheduled_hook( 'rsa_send_digest' );

// ── Drop custom tables ─────────────────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_events`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_sessions`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_clicks`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_heatmap`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_wc_events`" );

// ── Delete options ──────────────────────────────────────────────────
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'rsa_%' ) );

// ── Delete user meta ────────────────────────────────────────────────
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", 'rsa_%' ) );

// ── Multisite: clean all sites ──────────────────────────────────────
if ( is_multisite() ) {
	$site_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );

		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_sessions`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_heatmap`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_wc_events`" );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'rsa_%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", 'rsa_%' ) );

		restore_current_blog();
	}

	delete_site_option( 'rsa_network_settings' );
}
