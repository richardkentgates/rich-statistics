<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- admin display template; GET params control display filters only
$period  = sanitize_text_field( wp_unslash( $_GET['period'] ?? '30d' ) );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$date_from = $date_to = '';
if ( $period === 'custom' ) {
	$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
	$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = date( 'Y-m-d', current_time( 'timestamp' ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
}

$f_page  = sanitize_text_field( wp_unslash( $_GET['ref_page'] ?? '' ) );
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$filters = [ 'page' => $f_page, 'date_from' => $date_from, 'date_to' => $date_to ];
$rows    = RSA_Analytics::get_referrers( $period, 100, $filters );
$opts    = RSA_Analytics::get_filter_options( $period, $filters );

RSA_Admin::page_header( __( 'Referrers', 'rich-statistics' ), $period );

$base    = admin_url( 'admin.php' );
?>

<!-- Filter bar -->
<form method="get" action="<?php echo esc_url( $base ); ?>" class="rsa-filter-bar">
	<input type="hidden" name="page"   value="rich-statistics-referrers">
	<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
	<?php if ( $period === 'custom' ) : ?>
	<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
	<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>">
	<?php endif; ?>

	<?php if ( $opts['pages'] ) : ?>
	<select name="ref_page">
		<option value=""><?php esc_html_e( 'All landing pages', 'rich-statistics' ); ?></option>
		<?php foreach ( $opts['pages'] as $p ) : ?>
		<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $f_page, $p ); ?>><?php echo esc_html( $p ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<?php submit_button( __( 'Filter', 'rich-statistics' ), 'secondary', '', false ); ?>
	<?php if ( $f_page ) : ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'rich-statistics-referrers', 'period' => $period ], $base ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rich-statistics' ); ?></a>
	<?php endif; ?>
</form>

<!-- Chart -->
<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header"><h2><?php esc_html_e( 'Traffic Sources', 'rich-statistics' ); ?></h2></div>
	<div class="rsa-chart-wrap">
		<canvas id="rsa-chart-referrers" height="80"></canvas>
	</div>
</div>

<!-- Table -->
<div class="rsa-card rsa-card-full">
	<?php if ( $rows ) :
		$total = array_sum( array_column( $rows, 'visits' ) );
	?>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Referring Domain', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Top Landing Page', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Visits', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Share', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $i => $row ) :
				$share = $total > 0 ? round( ( $row['visits'] / $total ) * 100, 1 ) : 0;
			?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td><span class="rsa-referrer-domain"><?php echo esc_html( $row['domain'] ); ?></span></td>
				<td class="rsa-td-page"><?php echo esc_html( $row['top_page'] ?: '—' ); ?></td>
				<td><?php echo esc_html( number_format( $row['visits'] ) ); ?></td>
				<td>
					<div class="rsa-bar-cell">
						<div class="rsa-bar-fill" style="width:<?php echo esc_attr( $share ); ?>%"></div>
						<span><?php echo esc_html( $share . '%' ); ?></span>
					</div>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<p class="rsa-empty"><?php esc_html_e( 'No referral traffic recorded yet.', 'rich-statistics' ); ?></p>
	<?php endif; ?>
</div>

<?php RSA_Admin::page_footer(); ?>
