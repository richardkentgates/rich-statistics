<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$period  = sanitize_text_field( wp_unslash( $_GET['period'] ?? '30d' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$date_from = $date_to = '';
if ( $period === 'custom' ) {
	$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = date( 'Y-m-d', current_time( 'timestamp' ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
}

$f_browser = sanitize_text_field( wp_unslash( $_GET['browser'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$f_os      = sanitize_text_field( wp_unslash( $_GET['os']      ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filters   = [ 'browser' => $f_browser, 'os' => $f_os, 'date_from' => $date_from, 'date_to' => $date_to ];
$data      = RSA_Analytics::get_behavior( $period, $filters );
$opts      = RSA_Analytics::get_filter_options( $period, $filters );

RSA_Admin::page_header( __( 'Behavior', 'rich-statistics' ), $period );

$base = admin_url( 'admin.php' );
?>

<!-- Filter bar -->
<form method="get" action="<?php echo esc_url( $base ); ?>" class="rsa-filter-bar">
	<input type="hidden" name="page"   value="rich-statistics-behavior">
	<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
	<?php if ( $period === 'custom' ) : ?>
	<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
	<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>">
	<?php endif; ?>

	<?php if ( $opts['browsers'] ) : ?>
	<select name="browser">
		<option value=""><?php esc_html_e( 'All Browsers', 'rich-statistics' ); ?></option>
		<?php foreach ( $opts['browsers'] as $b ) : ?>
		<option value="<?php echo esc_attr( $b ); ?>" <?php selected( $f_browser, $b ); ?>><?php echo esc_html( $b ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<?php if ( $opts['os'] ) : ?>
	<select name="os">
		<option value=""><?php esc_html_e( 'All OS', 'rich-statistics' ); ?></option>
		<?php foreach ( $opts['os'] as $o ) : ?>
		<option value="<?php echo esc_attr( $o ); ?>" <?php selected( $f_os, $o ); ?>><?php echo esc_html( $o ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<?php submit_button( __( 'Filter', 'rich-statistics' ), 'secondary', '', false ); ?>
	<?php if ( $f_browser || $f_os ) : ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'rich-statistics-behavior', 'period' => $period ], $base ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rich-statistics' ); ?></a>
	<?php endif; ?>
</form>

<!-- Time on page + Session depth charts -->
<div class="rsa-two-col">
	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Time on Page', 'rich-statistics' ); ?></h2></div>
		<div class="rsa-chart-wrap">
			<canvas id="rsa-chart-time-hist" height="120"></canvas>
		</div>
	</div>
	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Session Depth (Pages Viewed)', 'rich-statistics' ); ?></h2></div>
		<div class="rsa-chart-wrap">
			<canvas id="rsa-chart-session-depth" height="120"></canvas>
		</div>
	</div>
</div>

<!-- Entry pages + Exit pages side by side -->
<div class="rsa-two-col">

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Top Entry Pages', 'rich-statistics' ); ?></h2></div>
		<?php if ( ! empty( $data['entry_pages'] ) ) : ?>
		<table class="rsa-table rsa-table--full">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Page', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Sessions', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['entry_pages'] as $i => $row ) : ?>
				<tr>
					<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
					<td class="rsa-td-page"><?php echo esc_html( $row['page'] ); ?></td>
					<td><?php echo esc_html( number_format( $row['count'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No data yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Top Exit Pages', 'rich-statistics' ); ?></h2></div>
		<?php if ( ! empty( $data['exit_pages'] ) ) : ?>
		<table class="rsa-table rsa-table--full">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Page', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Sessions', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['exit_pages'] as $i => $row ) : ?>
				<tr>
					<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
					<td class="rsa-td-page"><?php echo esc_html( $row['page'] ); ?></td>
					<td><?php echo esc_html( number_format( $row['count'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No data yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

</div>

<?php RSA_Admin::page_footer(); ?>
