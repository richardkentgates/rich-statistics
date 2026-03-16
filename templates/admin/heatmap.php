<?php
/**
 * Premium: Heatmap template.
 * Renders a live page in an iframe and overlays canvas heatmap via JS.
 *
 * @fs_premium_only
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
	RSA_Admin::page_header( __( 'Heatmap', 'rich-statistics' ) );
	?>
	<div class="rsa-upsell-notice">
		<h2><?php esc_html_e( 'Heatmap is a Premium Feature', 'rich-statistics' ); ?></h2>
		<p><?php esc_html_e( 'Visualise scroll depth and engagement intensity across any page with a real-time heatmap overlay — no external service required.', 'rich-statistics' ); ?></p>
		<?php if ( function_exists( 'rs_fs' ) ) : ?>
		<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary button-hero">
			<?php esc_html_e( 'Upgrade to Unlock Heatmap', 'rich-statistics' ); ?>
		</a>
		<?php endif; ?>
	</div>
	<?php
	return;
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
		<label for="hm_page"><?php esc_html_e( 'Page:', 'rich-statistics' ); ?></label>
		<?php $trackable = RSA_Admin::get_trackable_pages(); ?>
		<?php if ( $trackable ) : ?>
		<select id="hm_page" name="hm_page">
			<option value="/"><?php esc_html_e( '/ (Home)', 'rich-statistics' ); ?></option>
			<?php foreach ( $trackable as $path => $label ) : ?>
			<option value="<?php echo esc_attr( $path ); ?>" <?php selected( $page_path, $path ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php else : ?>
		<input type="text" id="hm_page" name="hm_page"
		       value="<?php echo esc_attr( $page_path ); ?>"
		       placeholder="/example-page/"
		       class="regular-text">
		<?php endif; ?>
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
