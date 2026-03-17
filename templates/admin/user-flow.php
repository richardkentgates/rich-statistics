<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$period  = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$date_from = $date_to = '';
if ( $period === 'custom' ) {
	$date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
	$date_to   = sanitize_text_field( $_GET['date_to']   ?? '' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) ); }
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = gmdate( 'Y-m-d' ); }
}

$f_from    = sanitize_text_field( $_GET['from_page'] ?? '' );
$f_to      = sanitize_text_field( $_GET['to_page']   ?? '' );
$f_min     = max( 1, (int) ( $_GET['min_count'] ?? 1 ) );
$view_type = in_array( $_GET['view_type'] ?? 'chart', [ 'chart', 'table' ], true ) ? ( $_GET['view_type'] ?? 'chart' ) : 'chart';
$sort      = in_array( $_GET['sort'] ?? 'count', [ 'count', 'from_page', 'to_page' ], true ) ? ( $_GET['sort'] ?? 'count' ) : 'count';
$sort_dir  = ( ( $_GET['sort_dir'] ?? 'desc' ) === 'asc' ) ? 'asc' : 'desc';

$filters = [
	'date_from' => $date_from,
	'date_to'   => $date_to,
	'from_page' => $f_from,
	'to_page'   => $f_to,
	'min_count' => $f_min,
	'sort'      => $sort,
	'sort_dir'  => $sort_dir,
	'limit'     => $view_type === 'table' ? 250 : 30,
];

$flow       = RSA_Analytics::get_user_flow( $period, $filters );
$flow_stats = RSA_Analytics::get_flow_stats( $period, [ 'date_from' => $date_from, 'date_to' => $date_to ] );
$pages      = RSA_Admin::get_trackable_pages();
$total     = array_sum( array_column( $flow, 'count' ) );
$uniq_src  = count( array_unique( array_column( $flow, 'from_page' ) ) );
$uniq_dst  = count( array_unique( array_column( $flow, 'to_page' ) ) );

RSA_Admin::page_header( __( 'User Flow', 'rich-statistics' ), $period );

$base      = admin_url( 'admin.php' );
$page_slug = 'rich-statistics-user-flow';

// Helper: build URLs preserving current filters
$common_params = array_filter( [
	'page'      => $page_slug,
	'period'    => $period,
	'from_page' => $f_from,
	'to_page'   => $f_to,
	'min_count' => $f_min > 1 ? $f_min : '',
	'date_from' => $period === 'custom' ? $date_from : '',
	'date_to'   => $period === 'custom' ? $date_to   : '',
] );

// Sort link closure (used in table view)
$sort_link = static function ( string $col, string $label ) use ( $sort, $sort_dir, $base, $common_params, $view_type ): string {
	$is_active = ( $sort === $col );
	$new_dir   = ( $is_active && $sort_dir === 'desc' ) ? 'asc' : 'desc';
	$indicator = $is_active ? ( $sort_dir === 'asc' ? ' ▲' : ' ▼' ) : '';
	$url       = add_query_arg( array_merge( $common_params, [ 'view_type' => $view_type, 'sort' => $col, 'sort_dir' => $new_dir ] ), $base );
	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label . $indicator ) . '</a>';
};
?>

<!-- Filter bar -->
<form method="get" action="<?php echo esc_url( $base ); ?>" class="rsa-filter-bar">
	<input type="hidden" name="page"      value="<?php echo esc_attr( $page_slug ); ?>">
	<input type="hidden" name="period"    value="<?php echo esc_attr( $period ); ?>">
	<input type="hidden" name="view_type" value="<?php echo esc_attr( $view_type ); ?>">
	<?php if ( $period === 'custom' ) : ?>
	<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
	<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>">
	<?php endif; ?>

	<select name="from_page">
		<option value=""><?php esc_html_e( 'Any entry page', 'rich-statistics' ); ?></option>
		<?php foreach ( $pages as $path => $label ) : ?>
		<option value="<?php echo esc_attr( $path ); ?>" <?php selected( $f_from, $path ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>

	<select name="to_page">
		<option value=""><?php esc_html_e( 'Any destination page', 'rich-statistics' ); ?></option>
		<?php foreach ( $pages as $path => $label ) : ?>
		<option value="<?php echo esc_attr( $path ); ?>" <?php selected( $f_to, $path ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>

	<label class="rsa-filter-inline-label">
		<?php esc_html_e( 'Min. transitions', 'rich-statistics' ); ?>
		<input type="number" name="min_count" value="<?php echo esc_attr( $f_min ); ?>" min="1" max="9999" style="width:72px">
	</label>

	<?php submit_button( __( 'Filter', 'rich-statistics' ), 'secondary', '', false ); ?>

	<?php if ( $f_from || $f_to || $f_min > 1 ) : ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => $page_slug, 'period' => $period, 'view_type' => $view_type ], $base ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rich-statistics' ); ?></a>
	<?php endif; ?>
</form>

<!-- View toggle -->
<div class="rsa-view-toggle" style="margin-bottom:16px">
	<?php
	$chart_url = add_query_arg( array_merge( $common_params, [ 'view_type' => 'chart' ] ), $base );
	$table_url = add_query_arg( array_merge( $common_params, [ 'view_type' => 'table' ] ), $base );
	?>
	<a href="<?php echo esc_url( $chart_url ); ?>" class="button <?php echo $view_type === 'chart' ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Flow Chart', 'rich-statistics' ); ?></a>
	<a href="<?php echo esc_url( $table_url ); ?>" class="button <?php echo $view_type === 'table' ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Transitions Table', 'rich-statistics' ); ?></a>
</div>

<?php if ( ! empty( $flow ) ) : ?>
<!-- Metrics summary -->
<div class="rsa-kpi-row">
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Total Transitions', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $total ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Unique From Pages', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $uniq_src ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Unique To Pages', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $uniq_dst ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Page Pairs', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( count( $flow ) ) ); ?></div>
	</div>
</div>
<?php endif; ?>

<?php if ( $view_type === 'chart' ) : ?>
<!-- Sankey diagram (rendered by admin-charts.js) -->
<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Navigation Flow', 'rich-statistics' ); ?></h2>
	</div>
	<div class="rsa-chart-wrap" id="rsa-flow-chart">
		<?php if ( empty( $flow ) ) : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No navigation flow data for this period and filter. Flow tracking requires multi-page sessions.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php else : ?>
<!-- Transitions table -->
<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'All Transitions', 'rich-statistics' ); ?></h2>
	</div>
	<?php if ( ! empty( $flow ) ) : ?>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php echo $sort_link( 'from_page', __( 'From Page', 'rich-statistics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
				<th><?php echo $sort_link( 'to_page',   __( 'To Page',   'rich-statistics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
				<th><?php echo $sort_link( 'count',     __( 'Transitions', 'rich-statistics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
				<th><?php esc_html_e( '% of Total', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $flow as $i => $row ) : ?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td class="rsa-td-page"><?php echo esc_html( $row['from_page'] ); ?></td>
				<td class="rsa-td-page"><?php echo esc_html( $row['to_page'] ); ?></td>
				<td><?php echo esc_html( number_format( $row['count'] ) ); ?></td>
				<td><?php echo $total > 0 ? esc_html( number_format( $row['count'] / $total * 100, 1 ) ) . '%' : '&mdash;'; ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<p class="rsa-empty"><?php esc_html_e( 'No transition data for this period and filter.', 'rich-statistics' ); ?></p>
	<?php endif; ?>
</div>
<?php endif; ?>

<!-- Inbound / Outbound page stats -->
<?php if ( ! empty( $flow_stats['outbound'] ) || ! empty( $flow_stats['inbound'] ) ) : ?>
<div class="rsa-two-col">

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Top Outbound Pages', 'rich-statistics' ); ?></h2><span class="rsa-card-header__sub"><?php esc_html_e( 'Pages that send visitors to other pages', 'rich-statistics' ); ?></span></div>
		<?php if ( ! empty( $flow_stats['outbound'] ) ) : ?>
		<table class="rsa-table rsa-table--full">
			<thead><tr>
				<th>#</th>
				<th><?php esc_html_e( 'Page', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Transitions Out', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Unique Destinations', 'rich-statistics' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $flow_stats['outbound'] as $i => $row ) : ?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td class="rsa-td-page"><?php echo esc_html( $row['page'] ); ?></td>
				<td><?php echo esc_html( number_format( $row['transitions_out'] ) ); ?></td>
				<td><?php echo esc_html( number_format( $row['unique_destinations'] ) ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No data yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Top Inbound Pages', 'rich-statistics' ); ?></h2><span class="rsa-card-header__sub"><?php esc_html_e( 'Pages that receive visitors from other pages', 'rich-statistics' ); ?></span></div>
		<?php if ( ! empty( $flow_stats['inbound'] ) ) : ?>
		<table class="rsa-table rsa-table--full">
			<thead><tr>
				<th>#</th>
				<th><?php esc_html_e( 'Page', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Transitions In', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Unique Sources', 'rich-statistics' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $flow_stats['inbound'] as $i => $row ) : ?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td class="rsa-td-page"><?php echo esc_html( $row['page'] ); ?></td>
				<td><?php echo esc_html( number_format( $row['transitions_in'] ) ); ?></td>
				<td><?php echo esc_html( number_format( $row['unique_sources'] ) ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No data yet.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

</div>
<?php endif; ?>

<?php RSA_Admin::page_footer(); ?>
