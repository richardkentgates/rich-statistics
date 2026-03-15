<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$period  = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$rows = RSA_Analytics::get_top_pages( $period, 50 );

RSA_Admin::page_header( __( 'Pages', 'rich-statistics' ), $period );
?>

<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Top Pages by Views', 'rich-statistics' ); ?></h2>
	</div>
	<div class="rsa-chart-wrap rsa-chart-wrap--bar">
		<canvas id="rsa-chart-pages" height="80"></canvas>
	</div>
</div>

<div class="rsa-card rsa-card-full">
	<?php if ( $rows ) : ?>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Page', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Views', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Avg. Time', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $i => $row ) :
				$secs = (int) $row['avg_time'];
				$time_fmt = $secs >= 60 ? floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's' : $secs . 's';
			?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td class="rsa-td-page"><?php echo esc_html( $row['page'] ); ?></td>
				<td><?php echo esc_html( number_format( $row['views'] ) ); ?></td>
				<td><?php echo esc_html( $time_fmt ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<p class="rsa-empty"><?php esc_html_e( 'No page view data yet.', 'rich-statistics' ); ?></p>
	<?php endif; ?>
</div>

<?php RSA_Admin::page_footer(); ?>
