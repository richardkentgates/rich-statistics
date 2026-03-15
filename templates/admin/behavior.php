<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$period  = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

RSA_Admin::page_header( __( 'Behavior', 'rich-statistics' ), $period );
?>

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

<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header"><h2><?php esc_html_e( 'Top Entry Pages', 'rich-statistics' ); ?></h2></div>
	<?php
	$data = RSA_Analytics::get_behavior( $period );
	if ( ! empty( $data['entry_pages'] ) ) :
	?>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Entry Page', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Sessions Started', 'rich-statistics' ); ?></th>
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

<?php RSA_Admin::page_footer(); ?>
