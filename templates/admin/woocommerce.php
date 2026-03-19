<?php
/**
 * WooCommerce analytics — product views, add-to-cart events, orders, and revenue.
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'rich-statistics' ) ); }

RSA_Admin::page_header( __( 'WooCommerce', 'rich-statistics' ) );

if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
	?>
	<div class="rsa-card rsa-card-full" style="padding:24px;">
		<p><?php esc_html_e( 'WooCommerce Analytics tracks product views, add-to-cart events, and completed orders in a dedicated dashboard without storing any customer data.', 'rich-statistics' ); ?></p>
		<?php if ( function_exists( 'rs_fs' ) ) : ?>
		<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary">
			<?php esc_html_e( 'Upgrade to Unlock WooCommerce Analytics', 'rich-statistics' ); ?>
		</a>
		<?php endif; ?>
	</div>
	<?php
	RSA_Admin::page_footer();
	return;
}

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

$data = RSA_Analytics::get_woocommerce( $period, [ 'date_from' => $date_from, 'date_to' => $date_to ] );
?>

<!-- KPI Cards -->
<div class="rsa-kpi-grid">
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Product Views', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $data['funnel']['views'] ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Add to Carts', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $data['funnel']['cart'] ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Orders', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $data['orders_count'] ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Revenue', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $data['revenue_total'], 2 ) ); ?></div>
	</div>
</div>

<!-- Funnel + Revenue chart -->
<div class="rsa-two-col">

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Conversion Funnel', 'rich-statistics' ); ?></h2></div>
		<?php
		$funnel_steps = [
			__( 'Product Views', 'rich-statistics' ) => $data['funnel']['views'],
			__( 'Add to Cart',   'rich-statistics' ) => $data['funnel']['cart'],
			__( 'Orders',        'rich-statistics' ) => $data['funnel']['orders'],
		];
		?>
		<table class="widefat striped rsa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Stage', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Count', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Conversion', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$prev = null;
			foreach ( $funnel_steps as $label => $count ) :
				if ( $prev === null ) {
					$rate_str = '—';
				} else {
					$rate_str = $prev > 0 ? round( ( $count / $prev ) * 100, 1 ) . '%' : '0%';
				}
				$prev = $count;
			?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td><?php echo esc_html( number_format( $count ) ); ?></td>
				<td><?php echo esc_html( $rate_str ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Revenue Over Time', 'rich-statistics' ); ?></h2></div>
		<?php if ( $data['revenue_by_day'] ) : ?>
		<div class="rsa-chart-wrap">
			<canvas id="rsa-chart-wc-revenue" height="160"></canvas>
		</div>
		<?php else : ?>
		<p class="description" style="padding:16px 0;"><?php esc_html_e( 'No order data in the selected period.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

</div>

<!-- Top products by views / by add-to-cart -->
<div class="rsa-two-col">

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Top Products — Views', 'rich-statistics' ); ?></h2></div>
		<?php if ( $data['top_products_viewed'] ) : ?>
		<table class="widefat striped rsa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Views', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $data['top_products_viewed'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['product_name'] ?: '#' . $row['product_id'] ); ?></td>
				<td><?php echo esc_html( number_format( (int) $row['views'] ) ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="description" style="padding:16px 0;"><?php esc_html_e( 'No product views recorded yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Top Products — Add to Cart', 'rich-statistics' ); ?></h2></div>
		<?php if ( $data['top_products_cart'] ) : ?>
		<table class="widefat striped rsa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Cart Events', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Total Qty', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $data['top_products_cart'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['product_name'] ?: '#' . $row['product_id'] ); ?></td>
				<td><?php echo esc_html( number_format( (int) $row['events'] ) ); ?></td>
				<td><?php echo esc_html( number_format( (int) $row['total_qty'] ) ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="description" style="padding:16px 0;"><?php esc_html_e( 'No add-to-cart events recorded yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

</div>

<?php RSA_Admin::page_footer(); ?>
