<?php
/**
 * Network Dashboard — cross-site analytics for multisite networks.
 *
 * @package RichStatistics
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_network_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ) );
}
?>
<div class="wrap rsa-wrap">
	<h1>
		<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
		<?php esc_html_e( 'Rich Statistics — Network Dashboard', 'rich-statistics' ); ?>
	</h1>

	<!-- Sub-site Overview Table -->
	<h2><?php esc_html_e( 'Sub-site Overview', 'rich-statistics' ); ?></h2>
	<p><?php esc_html_e( 'Per-site analytics for the last 30 days. Click a site name to view its detailed dashboard.', 'rich-statistics' ); ?></p>

	<?php
	$sites = get_sites( array( 'number' => 100, 'orderby' => 'id', 'order' => 'ASC' ) );
	if ( $sites ) :
		global $wpdb;
		$now             = current_time( 'mysql' );
		$start           = date( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$network_disable = (int) get_site_option( 'rsa_network_disable_tracker', 0 );
		?>
		<table class="wp-list-table widefat fixed striped" id="rsa-network-sites-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Pageviews (30d)', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Sessions (30d)', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Bounce Rate', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Tracker', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $sites as $site ) :
				switch_to_blog( $site->blog_id );
				$has_table  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'rsa_events' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$bt         = (int) get_option( 'rsa_bot_score_threshold', 5 );
				$tracker_on = ! $network_disable && ! (bool) get_option( 'rsa_network_disable_tracker', 0 );

				$pageviews = 0;
				$sessions  = 0;
				$bounce    = 0;
				if ( $has_table ) {
					$pageviews = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rsa_events WHERE created_at BETWEEN %s AND %s AND bot_score < %d", $start, $now, $bt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$sessions  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rsa_sessions WHERE created_at BETWEEN %s AND %s", $start, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( $sessions > 0 ) {
						$single = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rsa_sessions WHERE created_at BETWEEN %s AND %s AND pages_viewed <= 1", $start, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$bounce = round( ( $single / $sessions ) * 100 );
					}
				}
				$site_details  = get_site( $site->blog_id );
				$dashboard_url = get_admin_url( $site->blog_id, 'admin.php?page=rich-statistics' );
				restore_current_blog();
				?>
		<tr>
			<td><a href="<?php echo esc_url( $dashboard_url ); ?>"><?php echo esc_html( $site_details->blogname ); ?></a></td>
			<td><?php echo $has_table ? esc_html( number_format( $pageviews ) ) : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
			<td><?php echo $has_table ? esc_html( number_format( $sessions ) ) : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
			<td><?php echo $has_table ? esc_html( $bounce . '%' ) : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
			<td><?php echo $tracker_on && $has_table ? '<span style="color:#10b981">&#10003;</span>' : '<span style="color:#ef4444">&#10007;</span>'; ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php else : ?>
	<p><?php esc_html_e( 'No sites found.', 'rich-statistics' ); ?></p>
<?php endif; ?>
</div>
<?php
