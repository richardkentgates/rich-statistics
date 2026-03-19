<?php
/**
 * Premium: Export — download stats as CSV.
 *
 * @fs_premium_only
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
	RSA_Admin::page_header( __( 'Export', 'rich-statistics' ) );
	?>
	<div class="rsa-upsell-notice">
		<h2><?php esc_html_e( 'Export is a Premium Feature', 'rich-statistics' ); ?></h2>
		<p><?php esc_html_e( 'Download your analytics data as CSV for use in spreadsheets or external tools.', 'rich-statistics' ); ?></p>
		<?php if ( function_exists( 'rs_fs' ) ) : ?>
		<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary button-hero">
			<?php esc_html_e( 'Upgrade to Unlock Export', 'rich-statistics' ); ?>
		</a>
		<?php endif; ?>
	</div>
	<?php
	RSA_Admin::page_footer();
	return;
}

RSA_Admin::page_header( __( 'Export', 'rich-statistics' ) );
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rsa-settings-form">
	<?php wp_nonce_field( 'rsa_export_csv' ); ?>
	<input type="hidden" name="action" value="rsa_export_csv">

	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Export Data as CSV', 'rich-statistics' ); ?></h2></div>
		<table class="form-table">
			<tr>
				<th><label for="rsa_export_data_type"><?php esc_html_e( 'Data type', 'rich-statistics' ); ?></label></th>
				<td>
					<select id="rsa_export_data_type" name="data_type">
						<option value="pageviews"><?php esc_html_e( 'Pageviews (events)', 'rich-statistics' ); ?></option>
						<option value="sessions"><?php esc_html_e( 'Sessions', 'rich-statistics' ); ?></option>
						<option value="clicks"><?php esc_html_e( 'Click events', 'rich-statistics' ); ?></option>
						<option value="referrers"><?php esc_html_e( 'Referrers (aggregated)', 'rich-statistics' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Date range', 'rich-statistics' ); ?></th>
				<td>
					<?php
					$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- export form display; GET param populates pre-selected period only
				$period  = in_array( $_GET['period'] ?? '30d', $allowed, true ) ? sanitize_text_field( wp_unslash( $_GET['period'] ?? '30d' ) ) : '30d';
					$labels  = [
						'7d'        => __( 'Last 7 days',  'rich-statistics' ),
						'30d'       => __( 'Last 30 days', 'rich-statistics' ),
						'90d'       => __( 'Last 90 days', 'rich-statistics' ),
						'thismonth' => __( 'This month',   'rich-statistics' ),
						'lastmonth' => __( 'Last month',   'rich-statistics' ),
						'custom'    => __( 'Custom range', 'rich-statistics' ),
					];
					?>
					<select name="period" id="rsa_export_period" onchange="document.getElementById('rsa-custom-dates').style.display=this.value==='custom'?'':'none'">
						<?php foreach ( $labels as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $period, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span id="rsa-custom-dates" style="display:<?php echo $period === 'custom' ? '' : 'none'; ?>;margin-left:8px;">
					<input type="date" name="date_from" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
					<span style="margin:0 4px"><?php esc_html_e( 'to', 'rich-statistics' ); ?></span>
					<input type="date" name="date_to" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
					</span>
				</td>
			</tr>
		</table>
		<p style="padding:0 1.5em 1.5em">
			<?php submit_button( __( 'Download CSV', 'rich-statistics' ), 'primary', '', false ); ?>
		</p>
	</div>
</form>

<?php RSA_Admin::page_footer(); ?>
