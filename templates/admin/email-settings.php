<?php
/**
 * Email settings & digest configuration template.
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
RSA_Admin::page_header( __( 'Email Reports', 'rich-statistics' ) );
?>

<?php if ( $saved ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'rich-statistics' ); ?></p></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rsa-settings-form">
	<?php wp_nonce_field( 'rsa_settings_save' ); ?>
	<input type="hidden" name="action" value="rsa_save_settings">

	<div class="rsa-card rsa-card-full">
		<div class="rsa-card-header"><h2><?php esc_html_e( 'Digest Email', 'rich-statistics' ); ?></h2></div>

		<table class="form-table">
			<tr>
				<th><label for="rsa_email_digest_enabled"><?php esc_html_e( 'Enable Digest', 'rich-statistics' ); ?></label></th>
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
						<option value="<?php echo esc_attr( $val ); ?>"
						        <?php selected( $current_freq, $val ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
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

		<p class="rsa-card-footer">
			<?php submit_button( __( 'Save Email Settings', 'rich-statistics' ), 'primary', 'submit', false ); ?>
			&nbsp;
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rsa_send_test_email' ), 'rsa_test_email' ) ); ?>"
			   class="button">
				<?php esc_html_e( 'Send Test Email', 'rich-statistics' ); ?>
			</a>
		</p>
	</div>

</form>

<?php RSA_Admin::page_footer(); ?>
