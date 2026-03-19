<?php
/**
 * Premium: Heatmap template.
 * Dark canvas heatmap with hotspot tooltips and click-element table — no iframe.
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
<p><?php esc_html_e( 'Visualise scroll depth and engagement intensity across any page with a real-time heatmap — no external service required.', 'rich-statistics' ); ?></p>
<?php if ( function_exists( 'rs_fs' ) ) : ?>
<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary button-hero">
<?php esc_html_e( 'Upgrade to Unlock Heatmap', 'rich-statistics' ); ?>
</a>
<?php endif; ?>
</div>
<?php
return;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display filter only
$period  = sanitize_text_field( wp_unslash( $_GET['period'] ?? '30d' ) );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

$date_from = $date_to = '';
if ( $period === 'custom' ) {
$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) );
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = ''; }
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to   ) ) { $date_to   = ''; }
}

$page_path = sanitize_text_field( wp_unslash( $_GET['hm_page'] ?? '/' ) );
if ( ! preg_match( '#^/#', $page_path ) ) {
$page_path = '/';
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$heatmap_data = RSA_Analytics::get_heatmap( $page_path, $period, $date_from, $date_to );
$click_data   = RSA_Analytics::get_click_map( $period, $page_path );

RSA_Admin::page_header( __( 'Heatmap', 'rich-statistics' ), $period );
?>

<!-- Page selector -->
<div class="rsa-card rsa-card-full rsa-heatmap-controls">
<form method="get" class="rsa-inline-form">
<input type="hidden" name="page"   value="rich-statistics-heatmap">
<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
<?php if ( $period === 'custom' && $date_from ) : ?>
<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to   ); ?>">
<?php endif; ?>
<label for="hm_page"><?php esc_html_e( 'Page:', 'rich-statistics' ); ?></label>
<?php $trackable = RSA_Admin::get_trackable_pages(); ?>
<?php if ( $trackable ) : ?>
<select id="hm_page" name="hm_page">
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
<?php if ( ! empty( $heatmap_data ) || ! empty( $click_data ) ) : ?>

<div class="rsa-hm-admin-head">
<span class="rsa-hm-admin-path"><?php echo esc_html( $page_path ); ?></span>
<span class="rsa-hm-admin-meta">
<?php
$total = (int) array_sum( array_column( $heatmap_data, 'weight' ) );
printf( esc_html__( '%s interaction%s', 'rich-statistics' ), number_format( $total ), 1 !== $total ? 's' : '' );
?>
</span>
</div>

<div class="rsa-hm-admin-body">
<div class="rsa-hm-admin-canvas-wrap" id="rsa-heatmap-container">
<canvas id="rsa-heatmap-canvas"></canvas>
<div id="rsa-hm-admin-tip" class="rsa-hm-tip" hidden></div>
<div class="rsa-hm-legend">
<span><?php esc_html_e( 'Low', 'rich-statistics' ); ?></span>
<div class="rsa-hm-legend-bar"></div>
<span><?php esc_html_e( 'High', 'rich-statistics' ); ?></span>
</div>
</div>
<div class="rsa-hm-admin-table-wrap" id="rsa-hm-admin-table"></div>
</div>

<script>
window.RSA_HEATMAP = <?php echo wp_json_encode( array_values( $heatmap_data ) ); ?>;
window.RSA_CLICKS  = <?php echo wp_json_encode( array_values( $click_data  ) ); ?>;
</script>

<?php else : ?>
<p class="rsa-empty">
<?php printf(
/* translators: %s: page path */
esc_html__( 'No heatmap data yet for %s. Data is aggregated nightly from click events.', 'rich-statistics' ),
'<code>' . esc_html( $page_path ) . '</code>'
); ?>
</p>
<?php endif; ?>
</div>

<?php RSA_Admin::page_footer(); ?>
