<?php
/**
 * Privacy Disclosure Shortcode
 *
 * Outputs a visitor-facing disclosure of what data this site collects via
 * Rich Statistics analytics, mapped against applicable EU and US privacy
 * regulations. Intended to be placed on a public privacy policy page.
 *
 * Usage: [rich_statistics_privacy_disclosure]
 *
 * @package RichStatistics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the privacy disclosure shortcode.
 *
 * @return string HTML output.
 */
function rsa_privacy_disclosure_shortcode(): string {
	ob_start();
	$site_name  = get_bloginfo( 'name' );
	$is_premium = function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only();

	/* translators: %s: Site name */
	$title          = sprintf( __( 'Analytics Data Collected by %s', 'rich-statistics' ), $site_name );
	/* translators: %s: Plugin version */
	$footer_version = sprintf( __( 'This disclosure was generated automatically by Rich Statistics v%s.', 'rich-statistics' ), defined( 'RSA_VERSION' ) ? RSA_VERSION : '' );
	/* translators: %s: Site name */
	$footer_contact = sprintf( __( 'For questions about data practices on %s, contact the site administrator.', 'rich-statistics' ), $site_name );
	?>
<div class="rsa-privacy-disclosure">
	<h2><?php echo esc_html( $title ); ?></h2>
	<p><?php esc_html_e( 'This site uses a self-hosted analytics system to understand how visitors use the site. Below is a complete list of what data is collected when you visit, how it is used, and your rights under applicable privacy laws.', 'rich-statistics' ); ?></p>

	<h3><?php esc_html_e( 'What We Collect', 'rich-statistics' ); ?></h3>
	<table class="rsa-privacy-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Data', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'What It Is', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Why', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Session ID', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'A random identifier stored in your browser\'s sessionStorage. It is deleted when you close the tab. No cookies are set.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To group pageviews into a single visit so we can count sessions and measure time on site.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Pages Viewed', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'The URL path of each page you visit. Email addresses and long query strings are stripped before storage.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To see which content is most popular.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Referrer Domain', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'The website you came from (e.g., google.com). Only the domain is stored, not the full URL.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To understand how visitors find this site.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Operating System', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Derived from your browser\'s User-Agent header (e.g., Windows, macOS, Android). The raw User-Agent string is not stored.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To ensure the site works on the browsers our visitors use.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Browser', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Name and version derived from your User-Agent header (e.g., Chrome 125).', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To ensure compatibility and prioritize fixes.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Language', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Your browser\'s language preference (e.g., en-US).', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To understand our audience.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Timezone', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Your browser\'s timezone setting (e.g., America/New_York).', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To display daily traffic in local time.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Viewport Size', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Your browser window width and height in pixels.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To optimize layout for common screen sizes.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Time on Page', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'How many seconds you spent on each page.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To measure engagement.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Campaign Parameters', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'utm_source, utm_medium, and utm_campaign from the URL if present.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To measure the effectiveness of marketing campaigns.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'IP Address', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Hashed with SHA-256 and stored temporarily (60 seconds) to prevent abuse. The raw IP is never written to the database.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Rate limiting only.', 'rich-statistics' ); ?></td>
			</tr>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'What We Do Not Collect', 'rich-statistics' ); ?></h3>
	<ul>
		<li><?php esc_html_e( 'No cookies', 'rich-statistics' ); ?></li>
		<li><?php esc_html_e( 'No name, email, phone number, or address', 'rich-statistics' ); ?></li>
		<li><?php esc_html_e( 'No tracking across other websites', 'rich-statistics' ); ?></li>
		<li><?php esc_html_e( 'No data shared with or sold to third parties', 'rich-statistics' ); ?></li>
		<li><?php esc_html_e( 'No GPS or precise location', 'rich-statistics' ); ?></li>
		<li><?php esc_html_e( 'No form inputs, audio, video, or biometric data', 'rich-statistics' ); ?></li>
	</ul>

	<?php if ( $is_premium ) : ?>
	<h3><?php esc_html_e( 'Additional Interactions (on some pages)', 'rich-statistics' ); ?></h3>
	<table class="rsa-privacy-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Data', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'What It Is', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'Why', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Clicks', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'When you click a phone link, email link, map link, or download link, the destination is recorded along with where on the screen you clicked.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To see which links visitors use most.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Mouse Position', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'Aggregated grid coordinates of where visitors move their mouse or touch the screen. Not tied to any individual.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To improve page layout.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Purchase Events', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'If this site has a store, product views, cart additions, and completed orders are recorded. No customer name, email, or shipping address is stored.', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'To understand the checkout funnel.', 'rich-statistics' ); ?></td>
			</tr>
		</tbody>
	</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Your Rights', 'rich-statistics' ); ?></h3>
	<table class="rsa-privacy-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Law', 'rich-statistics' ); ?></th>
				<th><?php esc_html_e( 'What It Means for You', 'rich-statistics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'GDPR (EU)', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'This site processes data under legitimate interest (Art. 6(1)(f)). No personal identifiers are stored, so this analytics does not require your consent under the ePrivacy Directive.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'CCPA / CPRA (California)', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'The data collected does not meet the definition of "personal information" under CCPA because it cannot identify you. No data is sold or shared.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'VCDPA (Virginia) / CPA (Colorado) / CTDPA (Connecticut) / UCPA (Utah) / TDPSA (Texas)', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'The data collected is pseudonymous and used only for internal analytics. It is exempt from opt-out requirements under these state laws.', 'rich-statistics' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'COPPA (US)', 'rich-statistics' ); ?></td>
				<td><?php esc_html_e( 'No personal information is collected from any visitor, including children.', 'rich-statistics' ); ?></td>
			</tr>
		</tbody>
	</table>

	<p class="rsa-privacy-footer">
		<?php echo esc_html( $footer_version ); ?>
		<?php echo esc_html( $footer_contact ); ?>
	</p>
</div>

<style>
.rsa-privacy-disclosure {
	max-width: 100%;
	margin: 2em 0;
	padding: 1.5em;
	border: 1px solid #ddd;
	border-radius: 4px;
	background: #fafafa;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.rsa-privacy-disclosure h2 {
	margin-top: 0;
	border-bottom: 2px solid #2271b1;
	padding-bottom: 0.5em;
}
.rsa-privacy-disclosure h3 {
	margin-top: 1.5em;
	color: #1d2327;
}
.rsa-privacy-table {
	width: 100%;
	border-collapse: collapse;
	margin: 1em 0;
	font-size: 0.9em;
}
.rsa-privacy-table th,
.rsa-privacy-table td {
	border: 1px solid #ddd;
	padding: 8px 10px;
	text-align: left;
	vertical-align: top;
}
.rsa-privacy-table th {
	background: #2271b1;
	color: #fff;
	font-weight: 600;
}
.rsa-privacy-table tr:nth-child(even) {
	background: #f0f0f1;
}
.rsa-privacy-disclosure ul {
	padding-left: 1.5em;
}
.rsa-privacy-disclosure li {
	margin-bottom: 0.4em;
}
.rsa-privacy-footer {
	margin-top: 2em;
	padding-top: 1em;
	border-top: 1px solid #ddd;
	font-size: 0.85em;
	color: #666;
}
</style>
	<?php
	return ob_get_clean();
}
add_shortcode( 'rich_statistics_privacy_disclosure', 'rsa_privacy_disclosure_shortcode' );
