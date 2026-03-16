<?php
/**
 * Premium: Click Map template.
 *
 * @fs_premium_only
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
	RSA_Admin::page_header( __( 'Click Map', 'rich-statistics' ) );
	?>
	<div class="rsa-upsell-notice">
		<h2><?php esc_html_e( 'Click Map is a Premium Feature', 'rich-statistics' ); ?></h2>
		<p><?php esc_html_e( 'See exactly where visitors click on every page. Identify high-interest areas, dead zones, and navigation patterns at a glance.', 'rich-statistics' ); ?></p>
		<?php if ( function_exists( 'rs_fs' ) ) : ?>
		<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary button-hero">
			<?php esc_html_e( 'Upgrade to Unlock Click Map', 'rich-statistics' ); ?>
		</a>
		<?php endif; ?>
	</div>
	<?php
	return;
}

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

$page_filter = sanitize_text_field( $_GET['page_filter'] ?? '' );
$opts        = RSA_Analytics::get_filter_options( $period, [ 'date_from' => $date_from, 'date_to' => $date_to ] );
$rows        = RSA_Analytics::get_click_map( $period, $page_filter );

RSA_Admin::page_header( __( 'Click Map', 'rich-statistics' ), $period );
$base = admin_url( 'admin.php' );
?>

<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Click Events', 'rich-statistics' ); ?></h2>
		<form method="get" action="<?php echo esc_url( $base ); ?>" class="rsa-inline-form">
			<input type="hidden" name="page" value="rich-statistics-click-map">
			<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
			<?php if ( $period === 'custom' ) : ?>
			<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
			<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>">
			<?php endif; ?>
			<?php if ( $opts['pages'] ) : ?>
			<select name="page_filter">
				<option value=""><?php esc_html_e( 'All Pages', 'rich-statistics' ); ?></option>
				<?php foreach ( $opts['pages'] as $p ) : ?>
				<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $page_filter, $p ); ?>><?php echo esc_html( $p ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php else : ?>
			<input type="text" name="page_filter" placeholder="<?php esc_attr_e( 'Filter by page path', 'rich-statistics' ); ?>"
			       value="<?php echo esc_attr( $page_filter ); ?>">
			<?php endif; ?>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'rich-statistics' ); ?></button>
			<?php if ( $page_filter ) : ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'rich-statistics-click-map', 'period' => $period ], $base ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rich-statistics' ); ?></a>
			<?php endif; ?>
		</form>
	</div>

	<?php if ( $rows ) : ?>
	<div class="rsa-chart-wrap">
		<canvas id="rsa-chart-clicks" height="80"></canvas>
	</div>
	<table class="rsa-table rsa-table--full">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Element', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Trigger', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Text / Label', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Clicks', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $i => $row ) :
				// Build a readable element descriptor
				$el_label = '<code>' . esc_html( $row['tag'] ) . '</code>';
				if ( $row['id'] )    { $el_label .= ' <code class="rsa-el-id">#' . esc_html( $row['id'] ) . '</code>'; }
				if ( $row['class'] ) { $el_label .= ' <span class="rsa-el-class">' . esc_html( $row['class'] ) . '</span>'; }
				// Trigger: what caused this click to be tracked
				if ( $row['matched_rule'] ) {
					$trigger = '<code class="rsa-trigger-rule">' . esc_html( $row['matched_rule'] ) . '</code>';
				} elseif ( $row['protocol'] ) {
					$trigger = '<span class="rsa-trigger-protocol">' . esc_html( $row['protocol'] ) . ':</span>';
				} else {
					$trigger = '—';
				}
			?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td><?php echo wp_kses( $el_label, [ 'code' => [ 'class' => [] ], 'span' => [ 'class' => [] ] ] ); ?></td>
				<td><?php echo wp_kses( $trigger, [ 'code' => [ 'class' => [] ], 'span' => [ 'class' => [] ] ] ); ?></td>
				<td class="rsa-td-text"><?php echo esc_html( $row['text'] ?: '—' ); ?></td>
				<td><strong><?php echo esc_html( number_format( $row['clicks'] ) ); ?></strong></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<p class="rsa-empty"><?php esc_html_e( 'No click data yet. Make sure click tracking is active in Data Settings.', 'rich-statistics' ); ?></p>
	<?php endif; ?>
</div>

<?php RSA_Admin::page_footer(); ?>
