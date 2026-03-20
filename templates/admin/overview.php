<?php
/**
 * Overview dashboard template.
 *
 * @var string $period  Current period (set via $_GET['period'] or default).
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Permission denied.', 'rich-statistics' ) );
}

$period  = sanitize_text_field( wp_unslash( $_GET['period'] ?? '30d' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
if ( ! in_array( $period, $allowed, true ) ) {
	$period = '30d';
}

$date_from = $date_to = '';
if ( $period === 'custom' ) {
	$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = date( 'Y-m-d', current_time( 'timestamp' ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
}

$date_filters = [ 'date_from' => $date_from, 'date_to' => $date_to ];
$data = RSA_Analytics::get_overview( $period, $date_filters );

RSA_Admin::page_header( __( 'Overview', 'rich-statistics' ), $period );
?>

<!-- KPI Cards -->
<div class="rsa-kpi-grid">
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Page Views', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $data['pageviews'] ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Sessions', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $data['sessions'] ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Avg. Time on Page', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value">
			<?php
			$secs = (int) $data['avg_time'];
			echo esc_html( $secs >= 60
				? floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's'
				: $secs . 's'
			);
			?>
		</div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Bounce Rate', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( $data['bounce_rate'] . '%' ); ?></div>
	</div>
</div>

<!-- Pageviews over time: sparkline / line chart -->
<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Pageviews Over Time', 'rich-statistics' ); ?></h2>
	</div>
	<div class="rsa-chart-wrap">
		<canvas id="rsa-chart-daily" height="90"></canvas>
	</div>
</div>

<div class="rsa-two-col">
	<!-- Top pages preview -->
	<div class="rsa-card">
		<div class="rsa-card-header">
			<h2><?php esc_html_e( 'Top Pages', 'rich-statistics' ); ?></h2>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rich-statistics-pages&period=' . $period ) ); ?>"
			   class="rsa-see-all"><?php esc_html_e( 'See all', 'rich-statistics' ); ?></a>
		</div>
		<?php
		$top_pages = RSA_Analytics::get_top_pages( $period, 5, $date_filters );
		if ( $top_pages ) :
		?>
		<table class="rsa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Views', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $top_pages as $row ) : ?>
				<tr>
					<td class="rsa-td-page"><?php echo esc_html( $row['page'] ); ?></td>
					<td><?php echo esc_html( number_format( $row['views'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No data yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Top referrers preview -->
	<div class="rsa-card">
		<div class="rsa-card-header">
			<h2><?php esc_html_e( 'Top Referrers', 'rich-statistics' ); ?></h2>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rich-statistics-referrers&period=' . $period ) ); ?>"
			   class="rsa-see-all"><?php esc_html_e( 'See all', 'rich-statistics' ); ?></a>
		</div>
		<?php
		$referrers = RSA_Analytics::get_referrers( $period, 5, $date_filters );
		if ( $referrers ) :
		?>
		<table class="rsa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Domain', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Visits', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $referrers as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['domain'] ); ?></td>
					<td><?php echo esc_html( number_format( $row['visits'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No referral traffic yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php RSA_Admin::page_footer(); ?>
