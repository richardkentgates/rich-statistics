<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$period  = sanitize_text_field( $_GET['period'] ?? '30d' );
$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

// Data is loaded via RSA_DATA JS object (see class-admin.php enqueue)
RSA_Admin::page_header( __( 'Audience', 'rich-statistics' ), $period );
?>

<div class="rsa-audience-grid">

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Operating System', 'rich-statistics' ); ?></h2></div>
		<div class="rsa-chart-wrap rsa-chart-wrap--doughnut">
			<canvas id="rsa-chart-os"></canvas>
		</div>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Browser', 'rich-statistics' ); ?></h2></div>
		<div class="rsa-chart-wrap rsa-chart-wrap--doughnut">
			<canvas id="rsa-chart-browser"></canvas>
		</div>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Viewport / Device Size', 'rich-statistics' ); ?></h2></div>
		<div class="rsa-chart-wrap rsa-chart-wrap--doughnut">
			<canvas id="rsa-chart-viewport"></canvas>
		</div>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Language', 'rich-statistics' ); ?></h2></div>
		<div class="rsa-chart-wrap rsa-chart-wrap--doughnut">
			<canvas id="rsa-chart-language"></canvas>
		</div>
	</div>

	<div class="rsa-card rsa-card-wide">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Timezone Distribution', 'rich-statistics' ); ?></h2></div>
		<div class="rsa-chart-wrap">
			<canvas id="rsa-chart-timezone" height="80"></canvas>
		</div>
	</div>

</div>

<?php RSA_Admin::page_footer(); ?>
