<?php
/**
 * [PREMIUM] PWA download handlers.
 *
 * Two download actions:
 *
 *   rsa_download_pwa  – Generic app ZIP.  Download once, install once on any
 *                       device.  No site data baked in.
 *
 *   rsa_site_config   – Per-site .rsasite JSON file.  Import this into an
 *                       already-installed app to add the site without
 *                       creating a second PWA instance on the device.
 *
 * Security properties:
 *   • Both endpoints require manage_options + a valid WP nonce (~24 h TTL).
 *   • siteToken in the .rsasite file is per-user, HMAC-signed, single-use,
 *     and expires in 30 days.  Verification via POST /wp-json/rsa/v1/verify-install
 *     uses hash_equals() to prevent timing side-channels.
 *   • The generic ZIP contains no credentials, no site URL, and no token.
 *
 * @fs_premium_only
 */
defined( 'ABSPATH' ) || exit;

class RSA_Pwa_Download {

	public static function init(): void {
		add_action( 'wp_ajax_rsa_download_pwa', [ __CLASS__, 'handle_download'    ] );
		add_action( 'wp_ajax_rsa_site_config',  [ __CLASS__, 'handle_site_config' ] );
	}

	// ----------------------------------------------------------------
	// Generic app ZIP (download once, install once)
	// ----------------------------------------------------------------

	public static function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download the Rich Statistics Web App.', 'rich-statistics' ), 403 );
		}
		check_ajax_referer( 'rsa_download_pwa' );
		self::stream_zip();
	}

	// ----------------------------------------------------------------
	// Per-site .rsasite config file
	// ----------------------------------------------------------------

	public static function handle_site_config(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'rich-statistics' ), 403 );
		}
		check_ajax_referer( 'rsa_site_config' );

		$user     = wp_get_current_user();
		$site_url = rtrim( get_site_url(), '/' );
		$token    = self::issue_token( $user->ID, $site_url );

		$payload = wp_json_encode( [
			'rsaVersion' => 1,
			'siteLabel'  => wp_parse_url( $site_url, PHP_URL_HOST ),
			'siteUrl'    => $site_url,
			'username'   => $user->user_login,
			'siteToken'  => $token,
			'generated'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$host     = wp_parse_url( $site_url, PHP_URL_HOST ) ?? 'site';
		$filename = sanitize_file_name( $host . '.rsasite' );

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $payload ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $payload;
		exit;
	}

	// ----------------------------------------------------------------
	// Token helpers
	// ----------------------------------------------------------------

	private static function issue_token( int $user_id, string $site_url ): string {
		$token = hash_hmac(
			'sha256',
			$site_url . '|' . $user_id . '|' . time(),
			wp_salt( 'auth' )
		);

		update_user_meta( $user_id, 'rsa_install_token', [
			'token'   => $token,
			'expires' => time() + ( 30 * DAY_IN_SECONDS ),
		] );

		return $token;
	}

	// ----------------------------------------------------------------
	// Generic ZIP builder + streamer
	// ----------------------------------------------------------------

	private static function stream_zip(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The ZipArchive PHP extension is required to generate the download. Please ask your host to enable it.', 'rich-statistics' ) );
		}

		$webapp_dir = RSA_DIR . 'webapp/';
		$tmp_file   = wp_tempnam( 'rsa-app' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_file, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create the download package. Please try again.', 'rich-statistics' ) );
		}

		foreach ( [ 'index.html', 'config.js', 'app.js', 'app.css', 'sw.js', 'manifest.json' ] as $file ) {
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
		@unlink( $tmp_file );
		exit;
	}
}
