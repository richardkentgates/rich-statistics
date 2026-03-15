<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Respects the admin's choice to keep or remove all data.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-db.php';

if ( is_multisite() ) {
	$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
	foreach ( $sites as $blog_id ) {
		switch_to_blog( $blog_id );
		RSA_DB::maybe_remove_data();
		restore_current_blog();
	}
	// Also remove network-level options
	delete_site_option( 'rsa_network_settings' );
} else {
	RSA_DB::maybe_remove_data();
}
