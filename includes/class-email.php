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
		$recipients     = array_filter(
			array_map( 'sanitize_email', array_map( 'trim', explode( ',', $recipients_raw ) ) )
		);

		if ( empty( $recipients ) ) {
			return false;
		}

		$overview = RSA_Analytics::get_overview( $period );
		$pages    = RSA_Analytics::get_top_pages( $period, 10 );
		$subject  = sprintf(
			/* translators: %s: site name */
			__( '[%s] Analytics Digest', 'rich-statistics' ),
			get_bloginfo( 'name' )
		);

		$body = self::build_html( $overview, $pages, $period );

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

	private static function build_html( array $overview, array $pages, string $period ): string {
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
			'{{YEAR}}'         => esc_html( gmdate( 'Y' ) ),
		];

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $html );
	}

	private static function build_pages_rows( array $pages ): string {
		if ( empty( $pages ) ) {
			return '<tr><td colspan="2" style="padding:12px 0;color:#94a3b8;text-align:center;">No page data yet.</td></tr>';
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
}
