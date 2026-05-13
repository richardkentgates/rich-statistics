<?php
/**
 * Overview dashboard template.
 *
 * @var string $period  Current period (set via $_GET['period'] or default).
 *
 * @package RichStatistics
 */

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
	wp_die( esc_html__( 'Permission denied.', 'rich-statistics' ) );
}

$period  = sanitize_text_field( wp_unslash( $_GET['period'] ?? '30d' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
$allowed = array( '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' );
if ( ! in_array( $period, $allowed, true ) ) {
	$period = '30d';
}

$date_from = $date_to = '';
if ( $period === 'custom' ) {
	$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
		$date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
		$date_to = date( 'Y-m-d', current_time( 'timestamp' ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
}

$date_filters = array(
	'date_from' => $date_from,
	'date_to'   => $date_to,
);
$data         = RSA_Analytics::get_overview( $period, $date_filters );

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
			echo esc_html(
				$secs >= 60
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

<!-- AI Insights (free, no LLM needed) -->
<?php
$insights = [];

if ( $data['pageviews'] > 0 ) {
	$top_pages = RSA_Analytics::get_top_pages( $period, 1, $date_filters );
	$top_page  = $top_pages ? $top_pages[0]['page'] : null;
	$top_views = $top_pages ? $top_pages[0]['views'] : 0;
	$top_share = $data['pageviews'] > 0 ? round( ( $top_views / $data['pageviews'] ) * 100, 1 ) : 0;
	if ( $top_page ) {
		$insights[] = sprintf(
			/* translators: 1: page path, 2: view count, 3: percentage share */
			__( 'Top page: <strong>%1$s</strong> with %2$s views (%3$s%% of total traffic).', 'rich-statistics' ),
			esc_html( $top_page ),
			esc_html( number_format( $top_views ) ),
			esc_html( $top_share )
		);
	}
}

// Bounce rate assessment.
$bounce = (float) $data['bounce_rate'];
if ( $bounce < 40 ) {
	$insights[] = __( 'Bounce rate is low — visitors are engaging with multiple pages.', 'rich-statistics' );
} elseif ( $bounce > 70 ) {
	$insights[] = __( 'Bounce rate is high — consider reviewing page content and load times.', 'rich-statistics' );
} else {
	$insights[] = __( 'Bounce rate is within a typical range.', 'rich-statistics' );
}

// Avg time assessment.
$avg_time = (int) $data['avg_time'];
if ( $avg_time > 120 ) {
	$insights[] = __( 'Visitors spend over 2 minutes on average — content is engaging.', 'rich-statistics' );
} elseif ( $avg_time < 30 ) {
	$insights[] = __( 'Average time on page is under 30 seconds — content may need improvement.', 'rich-statistics' );
}

// Sessions-to-views ratio.
if ( $data['sessions'] > 0 ) {
	$ratio = round( $data['pageviews'] / $data['sessions'], 1 );
	if ( $ratio >= 3 ) {
		$insights[] = sprintf(
			/* translators: %s: pages-per-session ratio */
			__( 'Visitors view %s pages per session on average — strong engagement.', 'rich-statistics' ),
			esc_html( $ratio )
		);
	}
}

// Top referrer.
$referrers = RSA_Analytics::get_referrers( $period, 1, $date_filters );
if ( ! empty( $referrers ) ) {
	$top_ref    = $referrers[0]['domain'];
	$ref_visits = $referrers[0]['visits'];
	$insights[] = sprintf(
		/* translators: 1: referrer domain, 2: visit count */
		__( 'Top referrer: <strong>%1$s</strong> (%2$s visits).', 'rich-statistics' ),
		esc_html( $top_ref ),
		esc_html( number_format( $ref_visits ) )
	);
}

// Campaign insight (premium only).
if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) {
	$campaigns = RSA_Analytics::get_campaigns( $period, 1 );
	if ( ! empty( $campaigns ) ) {
		$top_camp   = $campaigns[0]['campaign'] ?? $campaigns[0]['source'];
		$insights[] = sprintf(
			/* translators: 1: campaign name or source, 2: session count */
			__( 'Top campaign: <strong>%1$s</strong> (%2$s sessions).', 'rich-statistics' ),
			esc_html( $top_camp ),
			esc_html( number_format( $campaigns[0]['sessions'] ) )
		);
	}
}
?>
<?php if ( $insights ) : ?>
<div class="rsa-card rsa-card-full" style="margin-bottom:16px;">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Insights', 'rich-statistics' ); ?></h2>
		<span style="font-size:12px;color:#888;"><?php esc_html_e( 'Derived from your analytics data', 'rich-statistics' ); ?></span>
	</div>
	<div style="padding:8px 16px 16px;">
		<ul style="margin:0;padding:0;list-style:none;">
			<?php foreach ( $insights as $i ) : ?>
			<li style="padding:6px 0;font-size:13px;line-height:1.6;"><?php echo wp_kses_post( $i ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
<?php endif; ?>

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

<script>
(function() {
	'use strict';
	setTimeout( function() {
		var url = new URL( window.location.href );
		url.searchParams.delete( 'rsa_refresh' );
		window.location.href = url.toString();
	}, 30000 );
})();
</script>
