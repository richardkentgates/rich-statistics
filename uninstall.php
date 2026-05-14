<?php
/**
 * Uninstall handler for Rich Statistics.
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Respects the rsa_remove_data_on_uninstall option — if enabled,
 * all database tables and options are removed.
 *
 * Also called by the Freemius after_uninstall hook, which is the
 * primary uninstall path for premium users. This file is the
 * standard WordPress uninstall.php entry point for free users.
 *
 * @package RichStatistics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clear scheduled cron hooks to prevent orphaned cron entries.
wp_clear_scheduled_hook( 'rsa_daily_maintenance' );
wp_clear_scheduled_hook( 'rsa_send_digest' );

global $wpdb;

$remove = get_option( 'rsa_remove_data_on_uninstall', 0 );
if ( ! $remove ) {
	return;
}

if ( is_multisite() ) {
	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sites as $blog_id ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional multisite iteration
		switch_to_blog( $blog_id );
		drop_tables();
		restore_current_blog();
	}
	// Clean network-level options.
	delete_site_option( 'rsa_network_settings' );
	delete_site_option( 'rsa_network_disable_tracker' );
} else {
	drop_tables();
}

/**
 * Drop all plugin tables and remove all options for the current site.
 */
function drop_tables() {
	global $wpdb;

	$tables = array(
		"{$wpdb->prefix}rsa_events",
		"{$wpdb->prefix}rsa_sessions",
		"{$wpdb->prefix}rsa_clicks",
		"{$wpdb->prefix}rsa_heatmap",
		"{$wpdb->prefix}rsa_wc_events",
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore
	}

	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'rsa_%' )
	);
}
