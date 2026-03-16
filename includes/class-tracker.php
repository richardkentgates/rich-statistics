<?php
/**
 * Handles enqueueing the tracker script and processing ingest requests.
 *
 * Ingest route: wp_ajax_nopriv_rsa_track + wp_ajax_rsa_track
 * (admin-ajax.php — avoids REST bootstrap cost on every pageview)
 */
defined( 'ABSPATH' ) || exit;

class RSA_Tracker {

	// Rate-limit: max events per session per minute
	const RATE_LIMIT_PER_MIN = 60;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_ajax_nopriv_rsa_track', [ __CLASS__, 'handle_ingest' ] );
		add_action( 'wp_ajax_rsa_track',        [ __CLASS__, 'handle_ingest' ] );
	}

	// ----------------------------------------------------------------
	// Script enqueueing
	// ----------------------------------------------------------------

	public static function enqueue(): void {
		// Do not track admin pages, login page, robots
		if ( is_admin() ) {
			return;
		}

		// Respect network-wide disable switch (multisite)
		if ( is_multisite() && get_site_option( 'rsa_network_disable_tracker', 0 ) ) {
			return;
		}

		$js_file = RSA_DIR . 'assets/js/tracker.js';
		$version = file_exists( $js_file )
			? filemtime( $js_file )
			: RSA_VERSION;

		wp_enqueue_script(
			'rsa-tracker',
			RSA_ASSETS_URL . 'js/tracker.js',
			[ 'jquery' ],
			(string) $version,
			true  // footer
		);

		// Build the protocol-tracking options bitmask from settings
		$protocols = self::get_protocol_options();

		// Premium: click tracking config
		$premium_config = [];
		if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) {
			$premium_config = [
				'clickEnabled'  => true,
				'trackIds'      => array_filter( array_map( 'trim', explode( ',', get_option( 'rsa_click_track_ids', '' ) ) ) ),
				'trackClasses'  => array_filter( array_map( 'trim', explode( ',', get_option( 'rsa_click_track_classes', '' ) ) ) ),
			];
		}

		wp_localize_script( 'rsa-tracker', 'RSA', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'rsa_track' ),
			'protocols' => $protocols,
			'premium'   => $premium_config,
		] );

		// Enqueue heatmap overlay if premium
		if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) {
			$hm_file    = RSA_DIR . 'assets/js/heatmap-overlay.js';
			$hm_version = file_exists( $hm_file ) ? filemtime( $hm_file ) : RSA_VERSION;
			wp_enqueue_script(
				'rsa-heatmap',
				RSA_ASSETS_URL . 'js/heatmap-overlay.js',
				[ 'rsa-tracker' ],
				(string) $hm_version,
				true
			);
		}
	}

	private static function get_protocol_options(): array {
		return [
			'http'     => (bool) get_option( 'rsa_track_protocol_http',     1 ),
			'tel'      => (bool) get_option( 'rsa_track_protocol_tel',      1 ),
			'mailto'   => (bool) get_option( 'rsa_track_protocol_mailto',   1 ),
			'geo'      => (bool) get_option( 'rsa_track_protocol_geo',      1 ),
			'sms'      => (bool) get_option( 'rsa_track_protocol_sms',      1 ),
			'download' => (bool) get_option( 'rsa_track_protocol_download', 1 ),
		];
	}

	// ----------------------------------------------------------------
	// Ingest handler
	// ----------------------------------------------------------------

	public static function handle_ingest(): void {
		// Respect network-wide disable switch (multisite)
		if ( is_multisite() && get_site_option( 'rsa_network_disable_tracker', 0 ) ) {
			wp_send_json_success( 'disabled' );
		}

		// Verify nonce
		if ( ! check_ajax_referer( 'rsa_track', 'nonce', false ) ) {
			wp_send_json_error( 'invalid_nonce', 403 );
		}

		$payload = self::parse_payload();
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( $payload->get_error_message(), 400 );
		}

		// Bot detection — pass only the two headers the scorer reads.
		// NO IP address (REMOTE_ADDR) is ever passed or stored.
		$bot_score = RSA_Bot_Detection::score(
			$payload['bot_signals'],
			$_SERVER['HTTP_USER_AGENT'] ?? '',
			[
				'HTTP_ACCEPT_LANGUAGE' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
				'HTTP_ACCEPT'          => $_SERVER['HTTP_ACCEPT'] ?? '',
			]
		);

		if ( RSA_Bot_Detection::is_bot( $bot_score ) ) {
			// Silently discard — never tell bots they were detected
			wp_send_json_success( [ 'ok' => true ] );
		}

		// Parse UA server-side
		$ua_data = RSA_Bot_Detection::parse_ua( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		// Rate-limit check
		if ( self::is_rate_limited( $payload['session_id'] ) ) {
			wp_send_json_success( [ 'ok' => true ] );
		}

		// Strip referrer to domain-only
		$referrer_domain = '';
		if ( ! empty( $payload['referrer'] ) ) {
			$parsed = wp_parse_url( $payload['referrer'] );
			$referrer_domain = $parsed['host'] ?? '';
			// Strip www.
			$referrer_domain = preg_replace( '/^www\./i', '', $referrer_domain );
		}

		// Sanitize page path
		$page = self::sanitize_page( $payload['page'] );

		global $wpdb;

		// Upsert session
		$sessions_table = RSA_DB::sessions_table();
		$existing       = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, pages_viewed FROM `{$sessions_table}` WHERE session_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$payload['session_id']
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$sessions_table,
				[
					'pages_viewed' => (int) $existing->pages_viewed + 1,
					'exit_page'    => $page,
					'total_time'   => $payload['time_on_page'] > 0
						? (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT total_time FROM `{$sessions_table}` WHERE session_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$payload['session_id']
						) ) + (int) $payload['time_on_page']
						: null,
				],
				[ 'session_id' => $payload['session_id'] ],
				[ '%d', '%s', '%d' ],
				[ '%s' ]
			);
		} else {
			$wpdb->insert(
				$sessions_table,
				[
					'session_id'  => $payload['session_id'],
					'pages_viewed'=> 1,
					'entry_page'  => $page,
					'os'          => $ua_data['os'],
					'browser'     => $ua_data['browser'],
					'language'    => $payload['language'],
					'timezone'    => $payload['timezone'],
				],
				[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		// Insert event row
		$wpdb->insert(
			RSA_DB::events_table(),
			[
				'session_id'      => $payload['session_id'],
				'page'            => $page,
				'referrer_domain' => $referrer_domain,
				'os'              => $ua_data['os'],
				'browser'         => $ua_data['browser'],
				'browser_version' => $ua_data['browser_version'],
				'language'        => $payload['language'],
				'timezone'        => $payload['timezone'],
				'viewport_w'      => $payload['viewport_w'],
				'viewport_h'      => $payload['viewport_h'],
				'time_on_page'    => $payload['time_on_page'],
				'bot_score'       => $bot_score,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ]
		);

		wp_send_json_success( [ 'ok' => true ] );
	}

	// ----------------------------------------------------------------
	// Payload parsing + validation
	// ----------------------------------------------------------------

	private static function parse_payload(): array|WP_Error {
		// Only accept POST
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return new WP_Error( 'method', 'POST required' );
		}

		$raw = file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );

		// Fall back to $_POST
		if ( ! is_array( $data ) ) {
			$data = $_POST;
		}

		$session_id = sanitize_text_field( $data['session_id'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session_id ) ) {
			return new WP_Error( 'session', 'Invalid session_id' );
		}

		$page = sanitize_text_field( $data['page'] ?? '/' );
		if ( strlen( $page ) > 512 ) {
			$page = substr( $page, 0, 512 );
		}

		return [
			'session_id'   => $session_id,
			'page'         => $page,
			'referrer'     => sanitize_text_field( $data['referrer'] ?? '' ),
			'language'     => substr( sanitize_text_field( $data['language'] ?? '' ), 0, 10 ),
			'timezone'     => substr( sanitize_text_field( $data['timezone'] ?? '' ), 0, 64 ),
			'viewport_w'   => min( absint( $data['viewport_w'] ?? 0 ), 65535 ),
			'viewport_h'   => min( absint( $data['viewport_h'] ?? 0 ), 65535 ),
			'time_on_page' => min( absint( $data['time_on_page'] ?? 0 ), 32767 ),
			'bot_signals'  => absint( $data['bot_signals'] ?? 0 ),
		];
	}

	// ----------------------------------------------------------------
	// Rate limiting via transients
	// ----------------------------------------------------------------

	private static function is_rate_limited( string $session_id ): bool {
		$key   = 'rsa_rl_' . substr( md5( $session_id ), 0, 16 );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MIN ) {
			return true;
		}
		set_transient( $key, $count + 1, 60 );
		return false;
	}

	// ----------------------------------------------------------------
	// Page sanitization
	// ----------------------------------------------------------------

	private static function sanitize_page( string $page ): string {
		// Keep only path + query, strip fragment and domain
		$parsed = wp_parse_url( $page );
		$path   = $parsed['path'] ?? '/';
		// Strip any query params that look like tokens or emails
		$query  = $parsed['query'] ?? '';
		// Remove any key=value pairs where value looks like an email or token (>20 chars hex)
		if ( $query ) {
			parse_str( $query, $params );
			$clean = [];
			foreach ( $params as $k => $v ) {
				if ( strlen( $v ) > 40 || filter_var( $v, FILTER_VALIDATE_EMAIL ) ) {
					continue; // drop suspicious params
				}
				$clean[ $k ] = $v;
			}
			$query = http_build_query( $clean );
		}
		return $path . ( $query ? '?' . $query : '' );
	}
}
