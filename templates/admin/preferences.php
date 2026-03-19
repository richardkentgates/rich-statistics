<?php
/**
 * Preferences — combined tracking, retention, bot detection, email & data settings.
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag after POST redirect
RSA_Admin::page_header( __( 'Preferences', 'rich-statistics' ) );
?>

<?php if ( $saved ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Preferences saved.', 'rich-statistics' ); ?></p></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rsa-settings-form">
	<?php wp_nonce_field( 'rsa_settings_save' ); ?>
	<input type="hidden" name="action" value="rsa_save_settings">

	<!-- Tracking -->
	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Tracking', 'rich-statistics' ); ?></h2></div>
		<table class="form-table">
			<tr>
				<th><label for="rsa_bot_score_threshold"><?php esc_html_e( 'Bot score threshold', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="number" id="rsa_bot_score_threshold" name="rsa_bot_score_threshold"
					       min="1" max="10" class="small-text"
					       value="<?php echo esc_attr( (int) get_option( 'rsa_bot_score_threshold', 5 ) ); ?>">
					<p class="description"><?php esc_html_e( 'Events with a bot score at or above this value are discarded. Range 1–10. Higher = less aggressive filtering. Default: 5.', 'rich-statistics' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<?php if ( class_exists( 'WooCommerce' ) ) : ?>
	<!-- WooCommerce Integration -->
	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'WooCommerce', 'rich-statistics' ); ?></h2></div>
		<?php if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) : ?>
		<table class="form-table">
			<tr>
				<th><label for="rsa_woocommerce_enabled"><?php esc_html_e( 'Enable WooCommerce tracking', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="checkbox" id="rsa_woocommerce_enabled" name="rsa_woocommerce_enabled" value="1"
					       <?php checked( get_option( 'rsa_woocommerce_enabled', 1 ), 1 ); ?>>
					<label for="rsa_woocommerce_enabled">
						<?php esc_html_e( 'Record product views, add-to-cart events, and order completions.', 'rich-statistics' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'No customer data is stored. Events are linked to anonymous session IDs only.', 'rich-statistics' ); ?></p>
				</td>
			</tr>
		</table>
		<?php else : ?>
		<div style="padding:16px 0;">
			<p><?php esc_html_e( 'Track product views, add-to-cart events, and completed orders in a dedicated analytics panel.', 'rich-statistics' ); ?></p>
			<?php if ( function_exists( 'rs_fs' ) ) : ?>
			<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary">
				<?php esc_html_e( 'Upgrade to Unlock WooCommerce Analytics', 'rich-statistics' ); ?>
			</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- Click Tracking (premium only) -->
	<?php if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) : ?>
	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header">
			<h2><?php esc_html_e( 'Click Tracking', 'rich-statistics' ); ?></h2>
		</div>
		<table class="form-table">
			<?php
			$protocols = [
			'tel'      => __( 'tel: (phone number links)', 'rich-statistics' ),
			'sms'      => __( 'sms: (text message links)', 'rich-statistics' ),
			'mailto'   => __( 'mailto: (email links)', 'rich-statistics' ),
			'geo'      => __( 'geo: (map coordinate links)', 'rich-statistics' ),
			'download' => __( 'download attribute (file downloads)', 'rich-statistics' ),
			];
			foreach ( $protocols as $key => $label ) :
			?>
			<tr>
				<th><label for="rsa_track_protocol_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input type="checkbox" id="rsa_track_protocol_<?php echo esc_attr( $key ); ?>"
					       name="rsa_track_protocol_<?php echo esc_attr( $key ); ?>"
					       value="1"
					       <?php checked( get_option( 'rsa_track_protocol_' . $key, 1 ), 1 ); ?>>
				</td>
			</tr>
			<?php endforeach; ?>
			<tr>
				<th><label for="rsa_click_track_ids"><?php esc_html_e( 'Track by Element ID', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="text" id="rsa_click_track_ids" name="rsa_click_track_ids"
					       class="regular-text"
					       value="<?php echo esc_attr( get_option( 'rsa_click_track_ids', '' ) ); ?>"
					       placeholder="my-cta-button, signup-link">
					<p class="description"><?php esc_html_e( 'Comma-separated element IDs.', 'rich-statistics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rsa_click_track_classes"><?php esc_html_e( 'Track by CSS Class', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="text" id="rsa_click_track_classes" name="rsa_click_track_classes"
					       class="regular-text"
					       value="<?php echo esc_attr( get_option( 'rsa_click_track_classes', '' ) ); ?>"
					       placeholder="btn-primary, cta">
					<p class="description"><?php esc_html_e( 'Comma-separated class names.', 'rich-statistics' ); ?></p>
				</td>
			</tr>
		</table>
	</div>
	<?php else : ?>
	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header">
			<h2><?php esc_html_e( 'Click Tracking', 'rich-statistics' ); ?></h2>
		</div>
		<div style="padding:16px 0;">
			<p><?php esc_html_e( 'Record clicks on links, buttons, and elements across your site.', 'rich-statistics' ); ?></p>
			<?php if ( function_exists( 'rs_fs' ) ) : ?>
			<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary">
				<?php esc_html_e( 'Upgrade to Unlock Click Tracking', 'rich-statistics' ); ?>
			</a>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- Custom Post Types -->
	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Custom Post Types in Page Dropdowns', 'rich-statistics' ); ?></h2></div>
		<?php
		$all_cpts = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
		$enabled  = (array) get_option( 'rsa_enabled_post_types', [] );
		?>
		<?php if ( $all_cpts ) : ?>
		<table class="form-table">
			<?php foreach ( $all_cpts as $cpt ) : ?>
			<tr>
				<th><label for="rsa_cpt_<?php echo esc_attr( $cpt->name ); ?>"><?php echo esc_html( $cpt->label ); ?></label></th>
				<td>
					<input type="checkbox"
					       id="rsa_cpt_<?php echo esc_attr( $cpt->name ); ?>"
					       name="rsa_enabled_post_types[]"
					       value="<?php echo esc_attr( $cpt->name ); ?>"
					       <?php checked( in_array( $cpt->name, $enabled, true ) ); ?>>
					<label for="rsa_cpt_<?php echo esc_attr( $cpt->name ); ?>">
						<?php
						printf(
							/* translators: %s: post type slug */
							esc_html__( 'Include %s in page filter dropdowns', 'rich-statistics' ),
							'<code>' . esc_html( $cpt->name ) . '</code>'
						);
						?>
					</label>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>
		<?php else : ?>
		<p class="description" style="padding:8px 0;"><?php esc_html_e( 'No public custom post types detected.', 'rich-statistics' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Data Retention -->
	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Data Retention', 'rich-statistics' ); ?></h2></div>
		<table class="form-table">
			<tr>
				<th><label for="rsa_retention_days"><?php esc_html_e( 'Keep data for', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="number" id="rsa_retention_days" name="rsa_retention_days"
					       min="1" max="730" class="small-text"
					       value="<?php echo esc_attr( (int) get_option( 'rsa_retention_days', 90 ) ); ?>">
					<span><?php esc_html_e( 'days', 'rich-statistics' ); ?></span>
					<p class="description"><?php esc_html_e( 'Data older than this is pruned automatically each night. Minimum 1, maximum 730.', 'rich-statistics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Remove data on uninstall', 'rich-statistics' ); ?></th>
				<td>
					<input type="checkbox" id="rsa_remove_data_on_uninstall" name="rsa_remove_data_on_uninstall" value="1"
					       <?php checked( get_option( 'rsa_remove_data_on_uninstall' ), 1 ); ?>>
					<label for="rsa_remove_data_on_uninstall">
						<?php esc_html_e( 'Drop all Rich Statistics tables and options when the plugin is deleted.', 'rich-statistics' ); ?>
					</label>
					<p class="description rsa-warning">
						<?php esc_html_e( '⚠ This cannot be undone.', 'rich-statistics' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Email Reports -->
	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Email Reports', 'rich-statistics' ); ?></h2></div>
		<table class="form-table">
			<tr>
				<th><label for="rsa_email_digest_enabled"><?php esc_html_e( 'Enable digest', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="checkbox" id="rsa_email_digest_enabled" name="rsa_email_digest_enabled" value="1"
					       <?php checked( get_option( 'rsa_email_digest_enabled' ), 1 ); ?>>
					<label for="rsa_email_digest_enabled"><?php esc_html_e( 'Send periodic analytics digest emails', 'rich-statistics' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="rsa_email_digest_frequency"><?php esc_html_e( 'Frequency', 'rich-statistics' ); ?></label></th>
				<td>
					<select id="rsa_email_digest_frequency" name="rsa_email_digest_frequency">
						<?php
						$freq_options = [
							'daily'   => __( 'Daily',   'rich-statistics' ),
							'weekly'  => __( 'Weekly',  'rich-statistics' ),
							'monthly' => __( 'Monthly', 'rich-statistics' ),
						];
						$current_freq = get_option( 'rsa_email_digest_frequency', 'weekly' );
						foreach ( $freq_options as $val => $label ) :
						?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_freq, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rsa_email_digest_recipients"><?php esc_html_e( 'Recipients', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="text" id="rsa_email_digest_recipients" name="rsa_email_digest_recipients" class="regular-text"
					       value="<?php echo esc_attr( get_option( 'rsa_email_digest_recipients', get_option( 'admin_email' ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated list of email addresses.', 'rich-statistics' ); ?></p>
				</td>
			</tr>
		</table>
		<p style="padding:0 0 16px;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rsa_send_test_email' ), 'rsa_test_email' ) ); ?>"
			   class="button">
				<?php esc_html_e( 'Send Test Email', 'rich-statistics' ); ?>
			</a>
		</p>
	</div>

	<?php submit_button( __( 'Save Preferences', 'rich-statistics' ), 'primary', 'submit', true ); ?>

</form>

<?php RSA_Admin::page_footer(); ?>
