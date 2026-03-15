<?php
/**
 * Premium: Click Map template.
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
if ( ! ( function_exists( 'rsa_fs' ) && rsa_fs()->can_use_premium_code() ) ) {
	wp_die( esc_html__( 'This feature requires Rich Statistics Premium.', 'rich-statistics' ) );
}

$period  = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$page_filter = sanitize_text_field( $_GET['page_filter'] ?? '' );
$rows        = RSA_Analytics::get_click_map( $period, $page_filter );

RSA_Admin::page_header( __( 'Click Map', 'rich-statistics' ), $period );
?>

<div class="rsa-card rsa-card-full">
	<div class="rsa-card-header">
		<h2><?php esc_html_e( 'Click Events', 'rich-statistics' ); ?></h2>
		<form method="get" class="rsa-inline-form">
			<input type="hidden" name="page" value="rich-statistics-click-map">
			<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
			<input type="text" name="page_filter" placeholder="<?php esc_attr_e( 'Filter by page path', 'rich-statistics' ); ?>"
			       value="<?php echo esc_attr( $page_filter ); ?>" class="regular-text">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'rich-statistics' ); ?></button>
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
				<th><?php esc_html_e( 'Tag', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Element ID', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Classes', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Protocol', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Clicks', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $i => $row ) : ?>
			<tr>
				<td class="rsa-td-rank"><?php echo esc_html( $i + 1 ); ?></td>
				<td><code><?php echo esc_html( $row['tag'] ); ?></code></td>
				<td><?php echo $row['id'] ? '<code>' . esc_html( $row['id'] ) . '</code>' : '—'; ?></td>
				<td class="rsa-td-classes"><?php echo esc_html( $row['class'] ?: '—' ); ?></td>
				<td><?php echo esc_html( $row['protocol'] ?: '—' ); ?></td>
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
