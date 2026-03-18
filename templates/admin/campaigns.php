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

$f_medium = sanitize_text_field( wp_unslash( $_GET['utm_medium'] ?? '' ) );
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$filters  = [ 'medium' => $f_medium, 'date_from' => $date_from, 'date_to' => $date_to ];
$rows     = RSA_Analytics::get_campaigns( $period, 100, $filters );
$mediums  = RSA_Analytics::get_utm_mediums( $period );

RSA_Admin::page_header( __( 'Campaigns', 'rich-statistics' ), $period );

$base = admin_url( 'admin.php' );
?>

<!-- Filter bar -->
<form method="get" action="<?php echo esc_url( $base ); ?>" class="rsa-filter-bar">
	<input type="hidden" name="page"   value="rich-statistics-campaigns">
	<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
	<?php if ( $period === 'custom' ) : ?>
	<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
	<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>">
	<?php endif; ?>

	<?php if ( $mediums ) : ?>
	<select name="utm_medium">
		<option value=""><?php esc_html_e( 'All mediums', 'rich-statistics' ); ?></option>
		<?php foreach ( $mediums as $m ) : ?>
		<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $f_medium, $m ); ?>><?php echo esc_html( $m ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<?php submit_button( __( 'Filter', 'rich-statistics' ), 'secondary', '', false ); ?>
	<?php if ( $f_medium ) : ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'rich-statistics-campaigns', 'period' => $period ], $base ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rich-statistics' ); ?></a>
	<?php endif; ?>
</form>

<!-- Table -->
<div class="rsa-card rsa-card-full">
	<?php if ( $rows ) :
		$total_sessions = array_sum( array_column( $rows, 'sessions' ) );
	?>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Campaign', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Source', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Medium', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Sessions', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Pageviews', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Share', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $i => $row ) :
				$share = $total_sessions > 0 ? round( ( $row['sessions'] / $total_sessions ) * 100, 1 ) : 0;
			?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td><strong><?php echo esc_html( $row['campaign'] ?: '—' ); ?></strong></td>
				<td><?php echo esc_html( $row['source'] ?: '—' ); ?></td>
				<td><?php echo esc_html( $row['medium'] ?: '—' ); ?></td>
				<td><?php echo esc_html( number_format( $row['sessions'] ) ); ?></td>
				<td><?php echo esc_html( number_format( $row['pageviews'] ) ); ?></td>
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
	<p class="rsa-empty"><?php esc_html_e( 'No campaign traffic recorded yet. Add utm_source, utm_medium, and utm_campaign parameters to your links to start tracking.', 'rich-statistics' ); ?></p>
	<?php endif; ?>
</div>

<?php RSA_Admin::page_footer(); ?>
