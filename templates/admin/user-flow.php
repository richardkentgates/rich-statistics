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

// Unified filters — shared by Path Explorer and Journey Table
$f_source  = sanitize_text_field( wp_unslash( $_GET['entry_source'] ?? '' ) );
$f_focus   = sanitize_text_field( wp_unslash( $_GET['focus_page']   ?? '' ) );
$f_min_s   = max( 1, absint( $_GET['min_sessions'] ?? 1 ) );
$f_steps   = min( 5, max( 2, absint( $_GET['steps'] ?? 4 ) ) );
$view_type = in_array( $_GET['view_type'] ?? 'explorer', [ 'explorer', 'table' ], true )
	? sanitize_key( $_GET['view_type'] ?? 'explorer' ) : 'explorer';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$path_flow = RSA_Analytics::get_path_flow( $period, [
	'date_from'    => $date_from,
	'date_to'      => $date_to,
	'entry_source' => $f_source,
	'focus_page'   => $f_focus,
	'min_sessions' => $f_min_s,
	'steps'        => $f_steps,
] );
$flow         = RSA_Analytics::get_user_flow( $period, [
	'date_from' => $date_from,
	'date_to'   => $date_to,
	'from_page' => $f_focus,
	'min_count' => $f_min_s,
	'sort'      => 'count',
	'sort_dir'  => 'desc',
	'limit'     => 250,
] );
$sources      = RSA_Analytics::get_entry_sources( $period, [ 'date_from' => $date_from, 'date_to' => $date_to ] );
$pages        = RSA_Admin::get_trackable_pages();
$grouped_flow = [];
foreach ( $flow as $row ) {
	$grouped_flow[ $row['from_page'] ][] = $row;
}

RSA_Admin::page_header( __( 'User Flow', 'rich-statistics' ), $period );

$base      = admin_url( 'admin.php' );
$page_slug = 'rich-statistics-user-flow';

// Helper: build filter-preserving URLs for toggle + clear links
$common_params = array_filter( [
	'page'         => $page_slug,
	'period'       => $period,
	'date_from'    => $period === 'custom' ? $date_from : '',
	'date_to'      => $period === 'custom' ? $date_to   : '',
	'entry_source' => $f_source,
	'focus_page'   => $f_focus,
	'min_sessions' => $f_min_s > 1 ? (string) $f_min_s : '',
	'steps'        => $f_steps !== 4 ? (string) $f_steps : '',
] );
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

	<?php if ( $sources ) : ?>
	<select name="entry_source">
		<option value=""><?php esc_html_e( 'All entry sources', 'rich-statistics' ); ?></option>
		<option value="(direct)" <?php selected( $f_source, '(direct)' ); ?>><?php esc_html_e( '(direct)', 'rich-statistics' ); ?></option>
		<?php foreach ( $sources as $src ) : ?>
		<option value="<?php echo esc_attr( $src ); ?>" <?php selected( $f_source, $src ); ?>><?php echo esc_html( $src ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<select name="focus_page">
		<option value=""><?php esc_html_e( 'Any page', 'rich-statistics' ); ?></option>
		<?php foreach ( $pages as $path => $plabel ) : ?>
		<option value="<?php echo esc_attr( $path ); ?>" <?php selected( $f_focus, $path ); ?>><?php echo esc_html( $plabel ); ?></option>
		<?php endforeach; ?>
	</select>

	<label class="rsa-filter-inline-label">
		<?php esc_html_e( 'Min. sessions', 'rich-statistics' ); ?>
		<input type="number" name="min_sessions" value="<?php echo esc_attr( $f_min_s ); ?>" min="1" max="9999" style="width:64px">
	</label>

	<label class="rsa-filter-inline-label">
		<?php esc_html_e( 'Steps', 'rich-statistics' ); ?>
		<select name="steps">
			<?php foreach ( [ 2, 3, 4, 5 ] as $s ) : ?>
			<option value="<?php echo absint( $s ); ?>" <?php selected( $f_steps, $s ); ?>><?php echo absint( $s ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>

	<?php submit_button( __( 'Filter', 'rich-statistics' ), 'secondary', '', false ); ?>
	<?php if ( $f_source || $f_focus || $f_min_s > 1 || $f_steps !== 4 ) : ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => $page_slug, 'period' => $period, 'view_type' => $view_type ], $base ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rich-statistics' ); ?></a>
	<?php endif; ?>
</form>

<!-- View toggle -->
<div class="rsa-view-toggle" style="margin-bottom:16px">
	<?php
	$explorer_url = add_query_arg( array_merge( $common_params, [ 'view_type' => 'explorer' ] ), $base );
	$table_url    = add_query_arg( array_merge( $common_params, [ 'view_type' => 'table'    ] ), $base );
	?>
	<a href="<?php echo esc_url( $explorer_url ); ?>" class="button <?php echo $view_type === 'explorer' ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Path Explorer', 'rich-statistics' ); ?></a>
	<a href="<?php echo esc_url( $table_url ); ?>" class="button <?php echo $view_type === 'table' ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Journey Table', 'rich-statistics' ); ?></a>
</div>

<?php
$pf_sessions  = (int) ( $path_flow['total_sessions'] ?? 0 );
$pf_steps_cnt = count( $path_flow['steps'] ?? [] );
$pf_entry_pgs = count( array_filter( $path_flow['steps'][1] ?? [], fn( $p ) => $p['page'] !== '(exit)' ) );
$pf_exits     = array_sum( array_column( array_filter( $path_flow['links'] ?? [], fn( $l ) => $l['to'] === '(exit)' ), 'count' ) );
$pf_exit_rate = $pf_sessions > 0 ? round( $pf_exits / $pf_sessions * 100, 1 ) : 0;
if ( $pf_sessions > 0 ) :
?>
<!-- Metrics summary -->
<div class="rsa-kpi-row">
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Sessions Tracked', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $pf_sessions ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Entry Pages', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( number_format( $pf_entry_pgs ) ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Steps in Flow', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( $pf_steps_cnt ); ?></div>
	</div>
	<div class="rsa-kpi-card">
		<div class="rsa-kpi-label"><?php esc_html_e( 'Exit Rate', 'rich-statistics' ); ?></div>
		<div class="rsa-kpi-value"><?php echo esc_html( $pf_exit_rate ) . '%'; ?></div>
	</div>
</div>
<?php endif; ?>

<?php if ( $view_type === 'explorer' ) : ?>
<!-- Path Explorer (rendered by admin-charts.js) -->
<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Path Explorer', 'rich-statistics' ); ?></h2>
		<p class="rsa-card-desc"><?php esc_html_e( 'Each column shows where visitors went at that step. Click any page to drill forward.', 'rich-statistics' ); ?></p>
	</div>
	<div id="rsa-flow-chart">
		<?php if ( empty( $path_flow['steps'] ) ) : ?>
		<p class="rsa-empty"><?php esc_html_e( 'No path data for this period. Try a wider date range or lower the minimum sessions threshold.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php else : ?>
<!-- Journey Table: grouped by from_page -->
<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Journey Table', 'rich-statistics' ); ?></h2>
		<p class="rsa-card-desc"><?php esc_html_e( 'Where visitors went next from each page. Percentage is share of outbound transitions from that page.', 'rich-statistics' ); ?></p>
	</div>
	<?php if ( ! empty( $grouped_flow ) ) : ?>
	<table class="rsa-table rsa-table--full rsa-table--grouped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'From Page', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'To Page', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Transitions', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( '% From Page', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $grouped_flow as $from_page => $destinations ) :
				$group_total = array_sum( array_column( $destinations, 'count' ) );
			?>
			<?php foreach ( $destinations as $di => $row ) :
				$pct = $group_total > 0 ? round( $row['count'] / $group_total * 100, 1 ) : 0;
			?>
			<tr<?php echo $di === 0 ? ' class="rsa-group-first"' : ''; ?>>
				<td class="rsa-td-group-label"><?php echo $di === 0 ? esc_html( $from_page ) : ''; ?></td>
				<td class="rsa-td-page"><?php echo esc_html( $row['to_page'] ); ?></td>
				<td><?php echo esc_html( number_format( $row['count'] ) ); ?></td>
				<td class="rsa-td-bar">
					<div class="rsa-bar-wrap">
						<div class="rsa-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
						<span class="rsa-bar-label"><?php echo esc_html( number_format( $pct, 1 ) ) . '%'; ?></span>
					</div>
				</td>
			</tr>
			<?php endforeach; ?>
			<tr class="rsa-group-spacer"><td colspan="4"></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<p class="rsa-empty"><?php esc_html_e( 'No transition data for this period and filter.', 'rich-statistics' ); ?></p>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php RSA_Admin::page_footer(); ?>
