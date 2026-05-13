<?php
/**
 * Install page — desktop app downloads and setup instructions.
 *
 * @package RichStatistics
 */

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
	wp_die();
}

RSA_Admin::page_header( __( 'Install Desktop App', 'rich-statistics' ) );
?>
<div class="rsa-install-page" style="max-width:800px;">
	<p><?php esc_html_e( 'Access your analytics from your desktop — no browser required. The Rich Statistics desktop app wraps the same dashboard in a lightweight native window.', 'rich-statistics' ); ?></p>

	<div class="rsa-card" style="margin-bottom:16px;">
		<div class="rsa-card-header"><h2 style="margin:0;font-size:16px;"><?php esc_html_e( 'Linux', 'rich-statistics' ); ?></h2></div>
		<div style="padding:16px;">
			<p><strong><?php esc_html_e( 'Install via APT (recommended)', 'rich-statistics' ); ?></strong></p>
			<pre style="background:#f5f5f5;padding:12px;border-radius:4px;overflow-x:auto;font-size:12px;">curl -fsSL https://app.richstatistics.com/apt/public.gpg \
	| sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/rich-statistics.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/rich-statistics.gpg] \
	https://app.richstatistics.com/apt stable main" \
	| sudo tee /etc/apt/sources.list.d/rich-statistics.list

sudo apt update && sudo apt install rich-statistics</pre>
			<p><strong><?php esc_html_e( 'Or download .deb directly:', 'rich-statistics' ); ?></strong></p>
			<p>
				<a href="https://app.richstatistics.com/dist/rich-statistics-linux-amd64.deb" class="button"><?php esc_html_e( 'x86-64 .deb', 'rich-statistics' ); ?></a>
				<a href="https://app.richstatistics.com/dist/rich-statistics-linux-arm64.deb" class="button" style="margin-left:8px;"><?php esc_html_e( 'ARM64 .deb', 'rich-statistics' ); ?></a>
			</p>
		</div>
	</div>

	<div class="rsa-card" style="margin-bottom:16px;">
		<div class="rsa-card-header"><h2 style="margin:0;font-size:16px;"><?php esc_html_e( 'Windows', 'rich-statistics' ); ?></h2></div>
		<div style="padding:16px;">
			<p><?php esc_html_e( 'Download the installer (.exe) and run it:', 'rich-statistics' ); ?></p>
			<p><a href="https://app.richstatistics.com/dist/rich-statistics-windows.exe" class="button button-primary"><?php esc_html_e( 'Download Windows .exe', 'rich-statistics' ); ?></a></p>
		</div>
	</div>

	<div class="rsa-card">
		<div class="rsa-card-header"><h2 style="margin:0;font-size:16px;"><?php esc_html_e( 'Dev / Test Tracks', 'rich-statistics' ); ?></h2></div>
		<div style="padding:16px;">
			<p><?php esc_html_e( 'Install instructions for pre-release builds are available in the wiki.', 'rich-statistics' ); ?></p>
			<p><a href="https://github.com/richardkentgates/rich-statistics/wiki/Release-Tracks" target="_blank" rel="noopener" class="button"><?php esc_html_e( 'View Dev/Test Install Guide', 'rich-statistics' ); ?></a></p>
		</div>
	</div>
</div>
<?php
RSA_Admin::page_footer();
