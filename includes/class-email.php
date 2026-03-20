<?php
/**
 * Email digest — scheduling, composition, and delivery.
 * Uses wp_mail exclusively. No SMTP panel — site owners can
 * use WP Mail SMTP or similar if needed.
 */
defined( 'ABSPATH' ) || exit;

class RSA_Email {

	public static function init(): void {
		add_action( 'rsa_send_digest',        [ __CLASS__, 'send_digest' ] );
		add_action( 'admin_post_rsa_save_settings', [ __CLASS__, 'reschedule_on_save' ] );
		add_action( 'admin_post_rsa_send_test_email', [ __CLASS__, 'handle_test_send' ] );

		// Schedule if not yet scheduled and digest is enabled
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
	}

	// ----------------------------------------------------------------
	// Scheduling
	// ----------------------------------------------------------------

	public static function maybe_schedule(): void {
		if ( ! get_option( 'rsa_email_digest_enabled' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'rsa_send_digest' ) ) {
			self::schedule_next();
		}
	}

	/**
	 * Schedule the next digest according to the configured frequency.
	 */
	public static function schedule_next(): void {
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		if ( ! get_option( 'rsa_email_digest_enabled' ) ) {
			return;
		}

		$freq   = get_option( 'rsa_email_digest_frequency', 'weekly' );
		$offset = match ( $freq ) {
			'daily'   => DAY_IN_SECONDS,
			'monthly' => 30 * DAY_IN_SECONDS,
			default   => WEEK_IN_SECONDS,  // weekly
		};

		wp_schedule_single_event( time() + $offset, 'rsa_send_digest' );
	}

	/**
	 * Re-schedule after settings are saved (frequency may have changed).
	 */
	public static function reschedule_on_save(): void {
		self::schedule_next();
	}

	// ----------------------------------------------------------------
	// Digest sending
	// ----------------------------------------------------------------

	/**
	 * Builds and sends the digest email to all configured recipients.
	 */
	public static function send_digest( string $period = '' ): bool {
		if ( ! $period ) {
			$freq   = get_option( 'rsa_email_digest_frequency', 'weekly' );
			$period = match ( $freq ) {
				'daily'   => '7d',
				'monthly' => 'lastmonth',
				default   => '30d',
			};
		}

		$recipients_raw = get_option( 'rsa_email_digest_recipients', get_option( 'admin_email' ) );

		// Role-based recipients: find all WP users with an allowed role.
		if ( get_option( 'rsa_email_digest_use_roles' ) ) {
			$allowed_roles = (array) get_option( 'rsa_allowed_roles', [ 'administrator' ] );
			$role_users    = get_users( [ 'role__in' => $allowed_roles, 'fields' => [ 'user_email' ] ] );
			$recipients    = array_values( array_unique( array_filter(
				array_map( fn( $u ) => sanitize_email( $u->user_email ), $role_users )
			) ) );
		} else {
			$recipients = array_filter(
				array_map( 'sanitize_email', array_map( 'trim', explode( ',', $recipients_raw ) ) )
			);
		}

		if ( empty( $recipients ) ) {
			return false;
		}

		$overview  = RSA_Analytics::get_overview( $period );
		$pages     = RSA_Analytics::get_top_pages( $period, 10 );
		$referrers = RSA_Analytics::get_referrers( $period, 5 );

		$is_premium = function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only();
		$wc_data    = ( $is_premium && class_exists( 'WooCommerce' ) && get_option( 'rsa_woocommerce_enabled', 1 ) )
			? RSA_Analytics::get_woocommerce( $period )
			: null;

		$subject  = sprintf(
			/* translators: %s: site name */
			__( '[%s] Analytics Digest', 'rich-statistics' ),
			get_bloginfo( 'name' )
		);

		$body = self::build_html( $overview, $pages, $referrers, $wc_data, $period );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		];

		$result = wp_mail( $recipients, $subject, $body, $headers );

		// Re-schedule next send
		self::schedule_next();

		return (bool) $result;
	}

	// ----------------------------------------------------------------
	// Test email handler
	// ----------------------------------------------------------------

	public static function handle_test_send(): void {
		check_admin_referer( 'rsa_test_email' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'rich-statistics' ) );
		}
		$sent = self::send_digest( '30d' );
		$msg  = $sent ? 'test_sent' : 'test_failed';
		wp_safe_redirect( add_query_arg( [ 'page' => 'rich-statistics-email-settings', $msg => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ----------------------------------------------------------------
	// HTML email builder
	// ----------------------------------------------------------------

	private static function build_html( array $overview, array $pages, array $referrers, ?array $wc_data, string $period ): string {
		$period_labels = [
			'7d'        => __( 'last 7 days',   'rich-statistics' ),
			'30d'       => __( 'last 30 days',  'rich-statistics' ),
			'90d'       => __( 'last 90 days',  'rich-statistics' ),
			'thismonth' => __( 'this month',    'rich-statistics' ),
			'lastmonth' => __( 'last month',    'rich-statistics' ),
		];
		$period_label = $period_labels[ $period ] ?? $period;
		$site_name    = esc_html( get_bloginfo( 'name' ) );
		$site_url     = esc_url( home_url() );
		$dash_url     = esc_url( admin_url( 'admin.php?page=rich-statistics' ) );

		$secs     = (int) $overview['avg_time'];
		$avg_fmt  = $secs >= 60
			? floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's'
			: $secs . 's';

		ob_start();
		include RSA_DIR . 'templates/email/digest.php';
		$html = ob_get_clean();

		// Inject dynamic values
		$replacements = [
			'{{SITE_NAME}}'    => $site_name,
			'{{SITE_URL}}'     => $site_url,
			'{{DASH_URL}}'     => $dash_url,
			'{{PERIOD_LABEL}}' => esc_html( $period_label ),
			'{{PAGEVIEWS}}'    => esc_html( number_format( $overview['pageviews'] ) ),
			'{{SESSIONS}}'     => esc_html( number_format( $overview['sessions'] ) ),
			'{{AVG_TIME}}'     => esc_html( $avg_fmt ),
			'{{BOUNCE_RATE}}'  => esc_html( $overview['bounce_rate'] . '%' ),
			'{{TOP_PAGES}}'    => self::build_pages_rows( $pages ),
			'{{REFERRERS}}'   => self::build_referrers_section( $referrers ),
			'{{WC_SECTION}}'  => self::build_wc_section( $wc_data ),
			'{{YEAR}}'         => esc_html( gmdate( 'Y' ) ),
		];

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $html );
	}

	private static function build_pages_rows( array $pages ): string {
		if ( empty( $pages ) ) {
			return '<tr><td colspan="3" style="padding:12px 0;color:#94a3b8;text-align:center;">No page data yet.</td></tr>';
		}
		$rows = '';
		foreach ( $pages as $i => $row ) {
			$bg     = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
			$secs   = (int) $row['avg_time'];
			$time   = $secs >= 60 ? floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's' : $secs . 's';
			$rows  .= '<tr style="background:' . $bg . '">'
				. '<td style="padding:8px 12px;font-family:monospace;font-size:12px;color:#334155;">' . esc_html( $row['page'] ) . '</td>'
				. '<td style="padding:8px 12px;text-align:center;color:#1e293b;">' . esc_html( number_format( $row['views'] ) ) . '</td>'
				. '<td style="padding:8px 12px;text-align:center;color:#64748b;">' . esc_html( $time ) . '</td>'
				. '</tr>';
		}
		return $rows;
	}

	private static function build_referrers_section( array $referrers ): string {
		if ( empty( $referrers ) ) {
			return '';
		}
		$rows = '';
		foreach ( $referrers as $i => $row ) {
			$bg    = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
			$rows .= '<tr style="background:' . $bg . '">'
				. '<td style="padding:8px 12px;font-family:monospace;font-size:12px;color:#334155;">' . esc_html( $row['domain'] ) . '</td>'
				. '<td style="padding:8px 12px;text-align:center;color:#1e293b;">' . esc_html( number_format( $row['visits'] ) ) . '</td>'
				. '</tr>';
		}
		return '<tr><td style="padding:28px 36px 0;" colspan="1">'
			. '<h2 style="margin:0 0 16px;font-size:16px;font-weight:700;color:#1e293b;">Top Referrers</h2>'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">'
			. '<thead><tr style="background:#f8fafc;">'
			. '<th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;">Domain</th>'
			. '<th style="padding:10px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;">Visits</th>'
			. '</tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '</table></td></tr>';
	}

	private static function build_wc_section( ?array $wc ): string {
		if ( ! $wc ) {
			return '';
		}
		$currency = get_woocommerce_currency_symbol();
		$revenue  = $currency . number_format( $wc['revenue_total'], 2 );
		$top_rows = '';
		foreach ( array_slice( $wc['top_products_viewed'], 0, 5 ) as $i => $p ) {
			$bg        = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
			$top_rows .= '<tr style="background:' . $bg . '">'
				. '<td style="padding:8px 12px;font-size:12px;color:#334155;">' . esc_html( $p['product_name'] ?? '' ) . '</td>'
				. '<td style="padding:8px 12px;text-align:center;color:#1e293b;">' . esc_html( number_format( (int) $p['views'] ) ) . '</td>'
				. '</tr>';
		}
		return '<tr><td style="padding:28px 36px 0;">'
			. '<h2 style="margin:0 0 16px;font-size:16px;font-weight:700;color:#1e293b;">WooCommerce</h2>'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">'
			. '<tr>'
			. '<td width="33%" style="text-align:center;padding:0 8px;">'
			.   '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Orders</div>'
			.   '<div style="font-size:24px;font-weight:800;color:#6366f1;">' . esc_html( number_format( $wc['orders_count'] ) ) . '</div>'
			. '</td>'
			. '<td width="33%" style="text-align:center;padding:0 8px;border-left:1px solid #e2e8f0;">'
			.   '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Revenue</div>'
			.   '<div style="font-size:24px;font-weight:800;color:#1e293b;">' . esc_html( $revenue ) . '</div>'
			. '</td>'
			. '<td width="33%" style="text-align:center;padding:0 8px;border-left:1px solid #e2e8f0;">'
			.   '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Add to Cart</div>'
			.   '<div style="font-size:24px;font-weight:800;color:#1e293b;">' . esc_html( number_format( $wc['funnel']['cart'] ) ) . '</div>'
			. '</td>'
			. '</tr></table>'
			. ( $top_rows
				? '<table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">'
				  . '<thead><tr style="background:#f8fafc;">'
				  . '<th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;">Top Product</th>'
				  . '<th style="padding:10px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;">Views</th>'
				  . '</tr></thead><tbody>' . $top_rows . '</tbody></table>'
				: '' )
			. '</td></tr>';
	}
}
