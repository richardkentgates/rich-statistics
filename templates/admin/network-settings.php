<?php
/**
 * Network Settings page — shown in the Network Admin for multisite.
 */
defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_network_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ) );
}

// Handle save
$saved   = false;
$updated = [];

if ( isset( $_POST['rsa_network_save'] ) ) {
	check_admin_referer( 'rsa_network_settings_save' );

	$per_site_default_retention = absint( $_POST['rsa_default_retention_days'] ?? 90 );
	$per_site_default_retention = max( 1, min( 730, $per_site_default_retention ) );

	update_site_option( 'rsa_default_retention_days', $per_site_default_retention );
	update_site_option( 'rsa_network_disable_tracker', absint( $_POST['rsa_network_disable_tracker'] ?? 0 ) );

	$saved = true;
}

$default_retention     = (int) get_site_option( 'rsa_default_retention_days', 90 );
$network_disable       = (int) get_site_option( 'rsa_network_disable_tracker', 0 );
?>
<div class="wrap rsa-wrap">
	<h1>
		<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
		<?php esc_html_e( 'Rich Statistics — Network Settings', 'rich-statistics' ); ?>
	</h1>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Network settings saved.', 'rich-statistics' ); ?></p>
		</div>
	<?php endif; ?>

	<p><?php esc_html_e( 'These settings apply network-wide and set defaults for all sub-sites. Individual sites can override their own retention and bot threshold settings in their own Data Settings page.', 'rich-statistics' ); ?></p>

	<form method="post" action="">
		<?php wp_nonce_field( 'rsa_network_settings_save' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="rsa-default-retention">
						<?php esc_html_e( 'Default Retention (days)', 'rich-statistics' ); ?>
					</label>
				</th>
				<td>
					<input type="number"
					       id="rsa-default-retention"
					       name="rsa_default_retention_days"
					       value="<?php echo esc_attr( $default_retention ); ?>"
					       min="1"
					       max="730"
					       class="small-text">
					<p class="description">
						<?php esc_html_e( 'Applied to new sites. Existing sites keep their own setting.', 'rich-statistics' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Disable Tracker Network-wide', 'rich-statistics' ); ?>
				</th>
				<td>
					<label>
						<input type="checkbox"
						       name="rsa_network_disable_tracker"
						       value="1"
						       <?php checked( $network_disable, 1 ); ?>>
						<?php esc_html_e( 'Stop collecting analytics data on all sub-sites', 'rich-statistics' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Useful during maintenance or to temporarily pause data collection across all sites.', 'rich-statistics' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Network Settings', 'rich-statistics' ), 'primary', 'rsa_network_save' ); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Sub-site Status', 'rich-statistics' ); ?></h2>
	<p><?php esc_html_e( 'Per-site analytics for the last 30 days. Click the site name to view its full dashboard.', 'rich-statistics' ); ?></p>

	<?php
	$sites = get_sites( [ 'number' => 100, 'orderby' => 'id', 'order' => 'ASC' ] );
	if ( $sites ) :
		global $wpdb;
		$now   = current_time( 'mysql' );
		$start = date( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'ID', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Pageviews (30d)', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Sessions (30d)', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Retention (days)', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Tracker active?', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $sites as $site ) :
				switch_to_blog( $site->blog_id );
				$prefix     = $wpdb->prefix;
				$et         = $prefix . 'rsa_events';
				$st         = $prefix . 'rsa_sessions';
				$has_table  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $et ) );
				$retention  = (int) get_option( 'rsa_retention_days', $default_retention );
				$bt         = (int) get_option( 'rsa_bot_score_threshold', 5 );
				$tracker_on = ! (bool) get_option( 'rsa_network_disable_tracker', 0 );

				$pageviews = 0;
				$sessions  = 0;
				if ( $has_table ) {
					$pageviews = (int) $wpdb->get_var(
						$wpdb->prepare( "SELECT COUNT(*) FROM `{$et}` WHERE created_at BETWEEN %s AND %s AND bot_score < %d", $start, $now, $bt ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					);
					$sessions = (int) $wpdb->get_var(
						$wpdb->prepare( "SELECT COUNT(*) FROM `{$st}` WHERE created_at BETWEEN %s AND %s", $start, $now ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					);
				}

				$site_details  = get_blog_details( $site->blog_id );
				$dashboard_url = get_admin_url( $site->blog_id, 'admin.php?page=rich-statistics' );
				restore_current_blog();
				?>
				<tr>
					<td><a href="<?php echo esc_url( $dashboard_url ); ?>"><?php echo esc_html( $site_details->blogname ); ?></a></td>
					<td><?php echo (int) $site->blog_id; ?></td>
					<td><?php echo $has_table ? esc_html( number_format( $pageviews ) ) : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
					<td><?php echo $has_table ? esc_html( number_format( $sessions ) )  : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
					<td><?php echo (int) $retention; ?></td>
					<td><?php echo $tracker_on && $has_table
						? '<span style="color:#10b981">&#10003; ' . esc_html__( 'Yes', 'rich-statistics' ) . '</span>'
						: '<span style="color:#ef4444">&#10007; ' . esc_html__( 'No', 'rich-statistics' ) . '</span>';
					?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
