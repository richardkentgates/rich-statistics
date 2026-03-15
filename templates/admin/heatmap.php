<?php
/**
 * Premium: Heatmap template.
 * Renders a live page in an iframe and overlays canvas heatmap via JS.
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
if ( ! ( function_exists( 'rsa_fs' ) && rsa_fs()->can_use_premium_code() ) ) {
	wp_die( esc_html__( 'This feature requires Rich Statistics Premium.', 'rich-statistics' ) );
}

$period  = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$page_path = sanitize_text_field( $_GET['hm_page'] ?? '/' );
if ( ! preg_match( '#^/#', $page_path ) ) {
	$page_path = '/';
}

$heatmap_data = RSA_Analytics::get_heatmap( $page_path, $period );
$preview_url  = home_url( $page_path );

RSA_Admin::page_header( __( 'Heatmap', 'rich-statistics' ), $period );
?>

<!-- Page selector -->
<div class="rsa-card rsa-card-full rsa-heatmap-controls">
	<form method="get" class="rsa-inline-form">
		<input type="hidden" name="page"   value="rich-statistics-heatmap">
		<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
		<label for="hm_page"><?php esc_html_e( 'Page path:', 'rich-statistics' ); ?></label>
		<input type="text" id="hm_page" name="hm_page"
		       value="<?php echo esc_attr( $page_path ); ?>"
		       placeholder="/example-page/"
		       class="regular-text">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Load Heatmap', 'rich-statistics' ); ?></button>
	</form>
</div>

<!-- Heatmap viewer -->
<div class="rsa-card rsa-card-full">
	<?php if ( ! empty( $heatmap_data ) ) : ?>
	<p class="rsa-heatmap-info">
		<?php printf(
			/* translators: %1$s: number of data points, %2$s: page path */
			esc_html__( 'Showing %1$s data points for %2$s', 'rich-statistics' ),
			'<strong>' . esc_html( number_format( count( $heatmap_data ) ) ) . '</strong>',
			'<code>' . esc_html( $page_path ) . '</code>'
		); ?>
	</p>

	<div class="rsa-heatmap-container" id="rsa-heatmap-container">
		<iframe id="rsa-heatmap-iframe"
		        src="<?php echo esc_url( $preview_url ); ?>"
		        class="rsa-heatmap-iframe"
		        scrolling="no"
		        sandbox="allow-same-origin allow-scripts"
		        title="<?php esc_attr_e( 'Page preview for heatmap', 'rich-statistics' ); ?>">
		</iframe>
		<canvas id="rsa-heatmap-canvas" class="rsa-heatmap-canvas"></canvas>
	</div>

	<script>
		window.RSA_HEATMAP = <?php echo wp_json_encode( array_values( $heatmap_data ) ); ?>;
	</script>

	<?php else : ?>
	<p class="rsa-empty">
		<?php
		printf(
			/* translators: %s: page path */
			esc_html__( 'No heatmap data yet for %s. Data is aggregated nightly from click events.', 'rich-statistics' ),
			'<code>' . esc_html( $page_path ) . '</code>'
		);
		?>
	</p>
	<?php endif; ?>
</div>

<?php RSA_Admin::page_footer(); ?>
