<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$period  = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$rows = RSA_Analytics::get_referrers( $period, 50 );
RSA_Admin::page_header( __( 'Referrers', 'rich-statistics' ), $period );
?>

<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header"><h2><?php esc_html_e( 'Traffic Sources', 'rich-statistics' ); ?></h2></div>
	<div class="rsa-chart-wrap">
		<canvas id="rsa-chart-referrers" height="80"></canvas>
	</div>
</div>

<div class="rsa-card rsa-card-full">
	<?php if ( $rows ) : ?>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Referring Domain', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Visits', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Share', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$total = array_sum( array_column( $rows, 'visits' ) );
			foreach ( $rows as $i => $row ) :
				$share = $total > 0 ? round( ( $row['visits'] / $total ) * 100, 1 ) : 0;
			?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td>
					<span class="rsa-referrer-domain"><?php echo esc_html( $row['domain'] ); ?></span>
				</td>
				<td><?php echo esc_html( number_format( $row['visits'] ) ); ?></td>
				<td>
					<div class="rsa-bar-cell">
						<div class="rsa-bar-fill" style="width: <?php echo esc_attr( $share ); ?>%"></div>
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
