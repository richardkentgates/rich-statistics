<?php
/**
 * [PREMIUM] PWA download handlers and OTP-based site-pairing.
 *
 * Download actions:
 *   rsa_download_pwa  – Generic app ZIP. Download once, install once on any
 *                       device. No site data baked in.
 *
 * OTP site-pairing (replaces .rsasite file):
 *   rsa_generate_otp  – AJAX: admin generates a 6-digit, single-use code
 *                       (15 min TTL). User types the code into the app to
 *                       add the site — no file download or import required.
 *   POST /rsa/v1/verify-otp – public REST endpoint that exchanges the OTP
 *                       for site URL + username so the app can proceed to
 *                       the Application Password step.
 *
 * Security properties:
 *   • rsa_generate_otp requires a valid WP nonce + user_can_access_app() (role-based).
 *   • OTPs are stored hashed (SHA-256) as transients; the plain code is never
 *     persisted. Transients auto-expire after 15 minutes.
 *   • verify-otp applies per-IP rate-limiting (max 5 wrong attempts / 5 min)
 *     to prevent brute-force of the 6-digit code space.
 *   • Successful verification consumes the OTP (single-use) and resets the
 *     fail counter for that IP.
 *   • The generic ZIP contains no credentials, no site URL, and no token.
 *
 * @package RichStatistics
 * @fs_premium_only
 */

defined( 'ABSPATH' ) || exit;

class RSA_Pwa_Download {

	public static function init(): void {
		add_action( 'wp_ajax_rsa_download_pwa', [ __CLASS__, 'handle_download' ] );
		add_action( 'wp_ajax_rsa_generate_otp', [ __CLASS__, 'handle_generate_otp' ] );
	}

	/**
	 * AJAX handler — generates a 6-digit OTP for the current user
	 * (if permitted by app access role settings).
	 * Returns JSON: { otp: "482391", expires_in: 900 }.
	 */
	public static function handle_generate_otp(): void {
		check_ajax_referer( 'rsa_generate_otp' );
		if ( ! RSA_Admin::user_can_access_app() ) {
			wp_send_json_error( __( 'You do not have permission.', 'rich-statistics' ), 403 );
		}

		$otp = self::generate_otp( get_current_user_id() );
		wp_send_json_success(
			[
				'otp'        => $otp,
				'expires_in' => 15 * MINUTE_IN_SECONDS,
			]
		);
	}

	/**
	 * Generate a cryptographically-random 6-digit OTP and store its hash
	 * as a transient that auto-expires after 15 minutes.
	 *
	 * @param int $user_id The user ID for whom the OTP is generated.
	 * @return string 6-digit zero-padded code (plain, never stored).
	 */
	public static function generate_otp( int $user_id ): string {
		$otp      = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$user     = get_userdata( $user_id );
		$site_url = rtrim( get_site_url(), '/' );

		set_transient(
			'rsa_otp_' . hash( 'sha256', $otp ),
			[
				'user_id'    => $user_id,
				'username'   => $user ? $user->user_login : '',
				'site_label' => (string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				'site_url'   => $site_url,
			],
			15 * MINUTE_IN_SECONDS
		);

		return $otp;
	}

	/**
	 * Handle the PWA ZIP download request.
	 */
	public static function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download the Rich Statistics Web App.', 'rich-statistics' ), 403 );
		}
		check_ajax_referer( 'rsa_download_pwa' );
		self::stream_zip();
	}

	/**
	 * Build and stream a generic app ZIP.
	 */
	private static function stream_zip(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The ZipArchive PHP extension is required to generate the download. Please ask your host to enable it.', 'rich-statistics' ) );
		}

		$webapp_dir = RSA_DIR . 'docs/app/';
		$tmp_file   = wp_tempnam( 'rsa-app' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_file, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create the download package. Please try again.', 'rich-statistics' ) );
		}

		foreach ( [ 'index.html', 'config.js', 'app.js', 'app.css', 'sw.js', 'manifest.json', 'chart.min.js' ] as $file ) {
			$path = $webapp_dir . $file;
			if ( file_exists( $path ) ) {
				$zip->addFile( $path, 'rich-statistics-app/' . $file );
			}
		}

		foreach ( [ 'icons/icon-192.png', 'icons/icon-512.png' ] as $icon ) {
			$path = $webapp_dir . $icon;
			if ( file_exists( $path ) ) {
				$zip->addFile( $path, 'rich-statistics-app/' . $icon );
			}
		}

		$zip->close();

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="rich-statistics-app.zip"' );
		header( 'Content-Length: ' . filesize( $tmp_file ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $tmp_file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( file_exists( $tmp_file ) ) {
			unlink( $tmp_file );
		}
		exit;
	}
}
