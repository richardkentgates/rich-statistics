<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$period   = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed  = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$date_from = $date_to = '';
if ( $period === 'custom' ) {
	$date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
	$date_to   = sanitize_text_field( $_GET['date_to']   ?? '' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) ); }
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = gmdate( 'Y-m-d' ); }
}

$f_browser  = sanitize_text_field( $_GET['browser']  ?? '' );
$f_os       = sanitize_text_field( $_GET['os']       ?? '' );
$f_page     = sanitize_text_field( $_GET['path']     ?? '' );
$sort       = in_array( $_GET['sort'] ?? '', [ 'views', 'avg_time' ], true ) ? $_GET['sort'] : 'views';
$sort_dir   = ( ( $_GET['sort_dir'] ?? 'desc' ) === 'asc' ) ? 'asc' : 'desc';

$filters    = [ 'browser' => $f_browser, 'os' => $f_os, 'page' => $f_page, 'sort' => $sort, 'sort_dir' => $sort_dir, 'date_from' => $date_from, 'date_to' => $date_to ];
$rows       = RSA_Analytics::get_top_pages( $period, 100, $filters );
$opts       = RSA_Analytics::get_filter_options( $period, $filters );

RSA_Admin::page_header( __( 'Pages', 'rich-statistics' ), $period );

$base      = admin_url( 'admin.php' );
$keep      = array_filter( [ 'page' => 'rich-statistics-pages', 'period' => $period, 'browser' => $f_browser, 'os' => $f_os, 'path' => $f_page, 'date_from' => $date_from, 'date_to' => $date_to ] );
$sort_link = function ( $field, $label ) use ( $sort, $sort_dir, $keep, $base ) {
	$new_dir = ( $sort === $field && $sort_dir === 'desc' ) ? 'asc' : 'desc';
	$url     = add_query_arg( array_merge( $keep, [ 'sort' => $field, 'sort_dir' => $new_dir ] ), $base );
	$arrow   = $sort === $field ? ( $sort_dir === 'desc' ? ' &#8595;' : ' &#8593;' ) : '';
	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $arrow . '</a>';
};
?>

<!-- Filter bar -->
<form method="get" action="<?php echo esc_url( $base ); ?>" class="rsa-filter-bar">
	<input type="hidden" name="page"     value="rich-statistics-pages">
	<input type="hidden" name="period"   value="<?php echo esc_attr( $period ); ?>">
	<input type="hidden" name="sort"     value="<?php echo esc_attr( $sort ); ?>">
	<input type="hidden" name="sort_dir" value="<?php echo esc_attr( $sort_dir ); ?>">
	<?php if ( $period === 'custom' ) : ?>
	<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
	<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>">
	<?php endif; ?>

	<?php if ( $opts['pages'] ) : ?>
	<select name="path">
		<option value=""><?php esc_html_e( 'All Pages', 'rich-statistics' ); ?></option>
		<?php foreach ( $opts['pages'] as $p ) : ?>
		<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $f_page, $p ); ?>><?php echo esc_html( $p ); ?></option>
		<?php endforeach; ?>
	</select>
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
	<?php if ( $f_browser || $f_os || $f_page ) : ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'rich-statistics-pages', 'period' => $period ], $base ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rich-statistics' ); ?></a>
	<?php endif; ?>
</form>

<!-- Chart -->
<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Top Pages by Views', 'rich-statistics' ); ?></h2>
	</div>
	<div class="rsa-chart-wrap rsa-chart-wrap--bar">
		<canvas id="rsa-chart-pages" height="80"></canvas>
	</div>
</div>

<!-- Table -->
<div class="rsa-card rsa-card-full">
	<?php if ( $rows ) : ?>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Page', 'rich-statistics' ); ?></th>
				<th><?php echo wp_kses_post( $sort_link( 'views', __( 'Views', 'rich-statistics' ) ) ); ?></th>
				<th><?php echo wp_kses_post( $sort_link( 'avg_time', __( 'Avg. Time', 'rich-statistics' ) ) ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $i => $row ) :
				$secs     = (int) $row['avg_time'];
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
	<p class="rsa-empty"><?php esc_html_e( 'No page view data for the selected filters.', 'rich-statistics' ); ?></p>
	<?php endif; ?>
</div>

<?php RSA_Admin::page_footer(); ?>
