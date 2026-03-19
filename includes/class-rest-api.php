<?php
/**
 * [PREMIUM] REST API
 *
 * Namespace    : rsa/v1
 * Auth         : WordPress Application Passwords (core, WP 5.6+)
 *                No custom token system — use a WP user account with
 *                the Application Password generated in their profile.
 *
 * @fs_premium_only
 *
 * Notes on security:
 * - All read endpoints require 'manage_options' (admin-level).
 * - The ingest POST endpoint (/track) is public but nonce-protected.
 * - Rate-limiting on /track mirrors the AJAX handler.
 * - All outputs are wp_json_encode'd via WP_REST_Response; no raw echo.
 */
defined( 'ABSPATH' ) || exit;

class RSA_Rest_API {

	const NS = 'rsa/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'add_cors_headers' ] );

		// When the PWA is served on the same origin as the WP site, the browser
		// sends session cookies with every fetch().  WP's cookie-nonce check
		// (priority 100) sets a WP_Error when those cookies carry no nonce —
		// even when a valid Authorization: Basic (Application Password) header
		// is also present.  We run at priority 200 (after the cookie check) and
		// clear that error when an Authorization header is present, allowing
		// Application Password auth to succeed.
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'remove_cookie_auth' ], 200 );
	}

	/**
	 * Clear cookie-auth errors for rsa/v1 requests that carry an Authorization
	 * header so Application Password authentication is not blocked.
	 */
	public static function remove_cookie_auth( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( strpos( $route, '/rsa/v1/' ) === false ) {
			return $result;
		}
		// Only clear the error if the client is actually providing credentials
		// via an Authorization header (Application Password).
		$has_auth = ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		if ( $has_auth ) {
			return null;
		}
		return $result;
	}

	/**
	 * Add CORS headers for rsa/v1 routes so the PWA / desktop app (served from
	 * a different origin, including tauri://localhost) can reach the REST API.
	 *
	 * WordPress's own REST server calls esc_url_raw() on the Origin header, which
	 * strips custom schemes like tauri:// and writes an empty
	 * Access-Control-Allow-Origin header — AFTER rest_api_init runs.  We handle
	 * two cases separately:
	 *
	 *   OPTIONS preflight  – respond immediately (before WP's serve_request runs).
	 *   All other methods  – register a rest_pre_serve_request filter that fires
	 *                        after WP sets its (broken) ACAO header so we can
	 *                        override it with the correct value.
	 */
	public static function add_cors_headers(): void {
		// Only act on our own namespace.
		$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( strpos( $route, '/rsa/v1/' ) === false ) {
			return;
		}

		// OPTIONS preflight: answer immediately so WP's serve_request never runs.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			header( 'Access-Control-Allow-Origin: ' . ( $origin ?: '*' ) );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Access-Control-Max-Age: 86400' );
			header( 'Vary: Origin' );
			status_header( 204 );
			exit;
		}

		// For all other methods: fix ACAO after WP's serve_request overwrites it.
		add_filter( 'rest_pre_serve_request', [ __CLASS__, 'fix_cors_origin' ], 999, 4 );
	}

	/**
	 * Re-apply Access-Control-Allow-Origin after WordPress's REST server has
	 * overwritten it with an empty string (because tauri:// fails esc_url_raw).
	 * Runs as a rest_pre_serve_request filter at priority 999, after WP's own
	 * CORS code, but before the response body is output.
	 *
	 * @param bool|null        $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  The response object.
	 * @param WP_REST_Request  $request The request object.
	 * @param WP_REST_Server   $server  The REST server instance.
	 * @return bool|null Unchanged $served value.
	 */
	public static function fix_cors_origin( $served, $result, $request, $server ) {
		if ( strpos( $request->get_route(), '/' . self::NS ) !== 0 ) {
			return $served;
		}
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		header( 'Access-Control-Allow-Origin: ' . ( $origin ?: '*' ) );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin' );
		return $served;
	}

	// ----------------------------------------------------------------
	// Route registration
	// ----------------------------------------------------------------

	public static function register_routes(): void {
		$read_args = [
			'period' => [
				'type'              => 'string',
				'default'           => '30d',
				'enum'              => [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
		];

		$auth = [ __CLASS__, 'check_auth' ];

		register_rest_route( self::NS, '/overview',  [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_overview'  ], 'permission_callback' => $auth, 'args' => $read_args ] );
		register_rest_route( self::NS, '/pages',     [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_pages'     ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'limit'    => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 100 ],
			'browser'  => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'os'       => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'path'     => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'sort'     => [ 'type' => 'string',  'default' => 'views', 'enum' => [ 'views', 'avg_time' ] ],
			'sort_dir' => [ 'type' => 'string',  'default' => 'desc',  'enum' => [ 'asc', 'desc' ] ],
		] ) ] );
		register_rest_route( self::NS, '/audience',  [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_audience'  ], 'permission_callback' => $auth, 'args' => $read_args ] );
		register_rest_route( self::NS, '/referrers', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_referrers' ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'limit'    => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 100 ],
			'ref_page' => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/behavior',  [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_behavior'  ], 'permission_callback' => $auth, 'args' => $read_args ] );
		register_rest_route( self::NS, '/clicks',    [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_clicks'    ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'page' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/heatmap',   [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_heatmap'   ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'page' => [ 'type' => 'string', 'default' => '/', 'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/export',    [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_export'    ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'format'    => [ 'type' => 'string', 'default' => 'json',      'enum' => [ 'json', 'csv' ] ],
			'data_type' => [ 'type' => 'string', 'default' => 'pageviews', 'enum' => [ 'pageviews', 'sessions', 'clicks', 'referrers' ] ],
			'date_from' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'date_to'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/campaigns', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_campaigns' ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'limit'  => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ],
			'medium' => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/woocommerce', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_woocommerce' ], 'permission_callback' => $auth, 'args' => $read_args ] );
		$flow_args = array_merge( $read_args, [
			'entry_source' => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'focus_page'   => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'min_sessions' => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
			'steps'        => [ 'type' => 'integer', 'default' => 4,  'minimum' => 2, 'maximum' => 5 ],
		] );
		register_rest_route( self::NS, '/user-flow',         [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_user_flow'         ], 'permission_callback' => $auth, 'args' => $flow_args ] );
		register_rest_route( self::NS, '/user-flow/journey', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_user_flow_journey' ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'from_page' => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'to_page'   => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'min_count' => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
			'limit'     => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 250 ],
			'sort'      => [ 'type' => 'string',  'default' => 'count', 'sanitize_callback' => 'sanitize_text_field' ],
			'sort_dir'  => [ 'type' => 'string',  'default' => 'desc',  'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/user-flow/sources', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_user_flow_sources' ], 'permission_callback' => $auth, 'args' => $read_args ] );
		register_rest_route( self::NS, '/filter-options', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_filter_options' ], 'permission_callback' => $auth, 'args' => $read_args ] );

		register_rest_route( self::NS, '/purge-page', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'purge_page' ],
			'permission_callback' => $auth,
			'args'                => [
				'page' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// Plugin info — public, no auth required (version badge + version sync for the PWA)
		register_rest_route( self::NS, '/info', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_info' ], 'permission_callback' => '__return_true' ] );

		// User settings — syncs the site list across devices (metadata only, no credentials)
		register_rest_route( self::NS, '/user-settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_user_settings' ],
				'permission_callback' => $auth,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'post_user_settings' ],
				'permission_callback' => $auth,
				'args'                => [
					'sites' => [ 'type' => 'array', 'required' => true ],
				],
			],
		] );

		// Ingest endpoint — public (no auth), nonce verified inside
		register_rest_route( self::NS, '/track', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'post_track' ],
			'permission_callback' => '__return_true',
		] );

		// Install token verification — authenticated, single-use (legacy)
		register_rest_route( self::NS, '/verify-install', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'post_verify_install' ],
			'permission_callback' => $auth,
			'args'                => [
				'site_token' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// OTP site-pairing — public, single-use, rate-limited per IP
		register_rest_route( self::NS, '/verify-otp', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'post_verify_otp' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'otp' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	// ----------------------------------------------------------------
	// Permission callback
	// ----------------------------------------------------------------

	public static function check_auth( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access Rich Statistics data.', 'rich-statistics' ),
				[ 'status' => 401 ]
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access Rich Statistics data.', 'rich-statistics' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	// ----------------------------------------------------------------
	// Plugin info (public)
	// ----------------------------------------------------------------

	public static function get_info(): WP_REST_Response {
		return self::ok( [
			'version'   => RSA_VERSION,
			'app_url'   => RSA_APP_URL,
			'site_name' => get_bloginfo( 'name' ),
			'site_url'  => get_site_url(),
		] );
	}

	// ----------------------------------------------------------------
	// User settings (site list sync — metadata only, no credentials)
	// ----------------------------------------------------------------

	public static function get_user_settings(): WP_REST_Response {
		$user_id = get_current_user_id();
		$sites   = get_user_meta( $user_id, 'rsa_app_sites', true );
		return self::ok( [ 'sites' => is_array( $sites ) ? $sites : [] ] );
	}

	public static function post_user_settings( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$raw     = $r->get_param( 'sites' );

		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_data', __( 'sites must be an array.', 'rich-statistics' ), [ 'status' => 400 ] );
		}

		// Strip everything except the three safe fields we want to persist.
		$sanitized = array_map(
			function ( $site ) {
				return [
					'id'      => sanitize_text_field( (string) ( $site['id']      ?? '' ) ),
					'label'   => sanitize_text_field( (string) ( $site['label']   ?? '' ) ),
					'siteUrl' => esc_url_raw(          (string) ( $site['siteUrl'] ?? '' ) ),
					'appUrl'  => esc_url_raw(          (string) ( $site['appUrl']  ?? '' ) ),
				];
			},
			$raw
		);

		update_user_meta( $user_id, 'rsa_app_sites', $sanitized );
		return self::ok( [ 'saved' => true ] );
	}

	// ----------------------------------------------------------------
	// Read endpoints
	// ----------------------------------------------------------------

	public static function get_overview( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( RSA_Analytics::get_overview( $r['period'] ) );
	}

	public static function get_pages( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'browser'  => (string) ( $r['browser']  ?? '' ),
			'os'       => (string) ( $r['os']       ?? '' ),
			'page'     => (string) ( $r['path']     ?? '' ),
			'sort'     => (string) ( $r['sort']     ?? 'views' ),
			'sort_dir' => (string) ( $r['sort_dir'] ?? 'desc' ),
		];
		return self::ok( [ 'pages' => RSA_Analytics::get_top_pages( $r['period'], (int) $r['limit'], $filters ) ] );
	}

	public static function get_audience( WP_REST_Request $r ): WP_REST_Response {
		$d = RSA_Analytics::get_audience( $r['period'] );
		return self::ok( [
			'by_os'       => $d['os'],
			'by_browser'  => $d['browser'],
			'by_viewport' => $d['viewport'],
			'by_language' => $d['language'],
			'by_timezone' => $d['timezone'],
		] );
	}

	public static function get_referrers( WP_REST_Request $r ): WP_REST_Response {
		$filters = [ 'page' => (string) ( $r['ref_page'] ?? '' ) ];
		$rows = RSA_Analytics::get_referrers( $r['period'], (int) $r['limit'], $filters );
		return self::ok( [ 'referrers' => array_map( fn( $row ) => [
			'domain'    => $row['domain'],
			'pageviews' => $row['visits'],
			'top_page'  => $row['top_page'],
		], $rows ) ] );
	}

	public static function get_behavior( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( RSA_Analytics::get_behavior( $r['period'] ) );
	}

	public static function get_clicks( WP_REST_Request $r ): WP_REST_Response {
		$rows = RSA_Analytics::get_click_map( $r['period'], $r['page'] );
		return self::ok( [ 'clicks' => array_map( fn( $row ) => [
			'href_protocol' => $row['protocol'],
			'element_tag'   => $row['tag'],
			'element_text'  => $row['text'],
			'href_value'    => $row['href_value'],
			'count'         => $row['clicks'],
		], $rows ) ] );
	}

	public static function get_heatmap( WP_REST_Request $r ): WP_REST_Response {
		$date_from = (string) ( $r['date_from'] ?? '' );
		$date_to   = (string) ( $r['date_to']   ?? '' );
		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = ''; }
		if ( $date_to   && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to   ) ) { $date_to   = ''; }
		return self::ok( RSA_Analytics::get_heatmap( $r['page'] ?: '/', $r['period'], $date_from, $date_to ) );
	}

	public static function get_export( WP_REST_Request $r ): WP_REST_Response {
		$format    = $r['format'];
		$period    = $r['period'];
		$data_type = (string) ( $r['data_type'] ?? 'pageviews' );
		$date_from = (string) ( $r['date_from'] ?? '' );
		$date_to   = (string) ( $r['date_to']   ?? '' );

		// Validate custom date formats
		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = ''; }
		if ( $date_to   && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to   ) ) { $date_to   = ''; }

		$data = RSA_Analytics::export_data( $data_type, $period, $format, $date_from, $date_to );

		if ( 'csv' === $format ) {
			add_filter(
				'rest_pre_serve_request',
				static function ( $served ) use ( $data, $period, $data_type ) {
					if ( $served ) {
						return $served;
					}
					$filename = 'rsa-' . sanitize_file_name( $data_type ) . '-' . sanitize_file_name( $period ) . '.csv';
					header( 'Content-Type: text/csv; charset=UTF-8' );
					header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
					header( 'Pragma: no-cache' );
					echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV
					return true;
				},
				10,
				1
			);
			return new WP_REST_Response( null, 200 );
		}

		return self::ok( json_decode( $data, true ) );
	}

	public static function get_filter_options( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( RSA_Analytics::get_filter_options( $r['period'] ) );
	}

	public static function purge_page( WP_REST_Request $r ): WP_REST_Response {
		$page    = $r->get_param( 'page' );
		$deleted = RSA_DB::purge_page_data( $page );
		return self::ok( [ 'deleted' => $deleted, 'page' => $page ] );
	}

	public static function get_campaigns( WP_REST_Request $r ): WP_REST_Response {
		$filters = [ 'medium' => (string) ( $r['medium'] ?? '' ) ];
		$rows    = RSA_Analytics::get_campaigns( $r['period'], (int) $r['limit'], $filters );
		return self::ok( [ 'campaigns' => $rows ] );
	}

	public static function get_woocommerce( WP_REST_Request $r ): WP_REST_Response {
		$active = class_exists( 'WooCommerce' );
		if ( ! $active ) {
			return self::ok( [ 'woocommerce_active' => false ] );
		}
		$data = RSA_Analytics::get_woocommerce( $r['period'] );
		return self::ok( array_merge( [ 'woocommerce_active' => true ], $data ) );
	}

	public static function get_user_flow( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'entry_source' => (string) ( $r['entry_source'] ?? '' ),
			'focus_page'   => (string) ( $r['focus_page']   ?? '' ),
			'min_sessions' => (int)    ( $r['min_sessions'] ?? 1  ),
			'steps'        => (int)    ( $r['steps']        ?? 4  ),
			'date_from'    => (string) ( $r['date_from']    ?? '' ),
			'date_to'      => (string) ( $r['date_to']      ?? '' ),
		];
		return self::ok( RSA_Analytics::get_path_flow( $r['period'], $filters ) );
	}

	public static function get_user_flow_journey( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'from_page' => (string) ( $r['from_page'] ?? '' ),
			'to_page'   => (string) ( $r['to_page']   ?? '' ),
			'min_count' => (int)    ( $r['min_count'] ?? 1  ),
			'limit'     => (int)    ( $r['limit']     ?? 50 ),
			'sort'      => (string) ( $r['sort']      ?? 'count' ),
			'sort_dir'  => (string) ( $r['sort_dir']  ?? 'desc'  ),
			'date_from' => (string) ( $r['date_from'] ?? '' ),
			'date_to'   => (string) ( $r['date_to']   ?? '' ),
		];
		return self::ok( [ 'rows' => RSA_Analytics::get_user_flow( $r['period'], $filters ) ] );
	}

	public static function get_user_flow_sources( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'date_from' => (string) ( $r['date_from'] ?? '' ),
			'date_to'   => (string) ( $r['date_to']   ?? '' ),
		];
		return self::ok( [ 'sources' => RSA_Analytics::get_entry_sources( $r['period'], $filters ) ] );
	}

	// ----------------------------------------------------------------
	// Install token verification
	// ----------------------------------------------------------------

	/**
	 * Consume the single-use install token embedded in a personalised PWA
	 * download.  Called on the first successful login from that device.
	 * Fails silently in the app — app functionality is never gated on this.
	 */
	public static function post_verify_install( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$stored  = get_user_meta( $user_id, 'rsa_install_token', true );

		if ( empty( $stored ) || ! is_array( $stored ) ) {
			return new WP_Error( 'no_token', __( 'No pending install token for this user.', 'rich-statistics' ), [ 'status' => 404 ] );
		}

		if ( time() > (int) $stored['expires'] ) {
			delete_user_meta( $user_id, 'rsa_install_token' );
			return new WP_Error( 'token_expired', __( 'Install token has expired. Download a fresh copy from your profile.', 'rich-statistics' ), [ 'status' => 410 ] );
		}

		// Constant-time comparison prevents timing side-channel
		if ( ! hash_equals( (string) $stored['token'], (string) $r['site_token'] ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid install token.', 'rich-statistics' ), [ 'status' => 403 ] );
		}

		// Token consumed — delete so replay on a second device fails
		delete_user_meta( $user_id, 'rsa_install_token' );

		return self::ok( [ 'verified' => true ] );
	}

	// ----------------------------------------------------------------
	// OTP site-pairing  (POST /rsa/v1/verify-otp — public, rate-limited)
	// ----------------------------------------------------------------

	/**
	 * Exchange a 6-digit OTP (generated in the WP admin profile page) for the
	 * site URL and username needed by the PWA to complete its "Add Site" flow.
	 *
	 * Security:
	 *   - OTPs are stored hashed (SHA-256); the plain code is never persisted.
	 *   - Per-IP rate-limiting caps incorrect attempts at 5 per 5 minutes.
	 *   - The OTP is single-use: consumed (deleted) on first successful call.
	 *   - On success the IP fail-counter is reset.
	 */
	public static function post_verify_otp( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		// Accept digits only; strip spaces/dashes the user may have typed
		$otp = preg_replace( '/\D/', '', (string) $r['otp'] );

		if ( strlen( $otp ) !== 6 ) {
			return new WP_Error( 'invalid_otp', __( 'Invalid code format. Please enter the 6-digit code from your profile page.', 'rich-statistics' ), [ 'status' => 400 ] );
		}

		// Per-IP rate-limit — max 5 wrong attempts per 5-minute window
		$ip_raw = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip_key = 'rsa_otp_fail_' . hash( 'sha256', $ip_raw );
		$fails  = (int) get_transient( $ip_key );

		if ( $fails >= 5 ) {
			return new WP_Error(
				'too_many_attempts',
				__( 'Too many incorrect attempts. Please wait a few minutes before trying again.', 'rich-statistics' ),
				[ 'status' => 429 ]
			);
		}

		$data = get_transient( 'rsa_otp_' . hash( 'sha256', $otp ) );

		if ( ! $data || ! is_array( $data ) ) {
			// Increment fail counter; 5-minute window resets automatically
			set_transient( $ip_key, $fails + 1, 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'invalid_otp', __( 'Invalid or expired code.', 'rich-statistics' ), [ 'status' => 403 ] );
		}

		// Valid — consume (single-use) and reset IP fail counter
		delete_transient( 'rsa_otp_' . hash( 'sha256', $otp ) );
		delete_transient( $ip_key );

		return self::ok( [
			'verified'   => true,
			'username'   => (string) $data['username'],
			'site_label' => (string) $data['site_label'],
			'site_url'   => (string) $data['site_url'],
		] );
	}

	// ----------------------------------------------------------------
	// Ingest endpoint (mirrors the AJAX handler but via REST)
	// ----------------------------------------------------------------

	public static function post_track( WP_REST_Request $r ): WP_REST_Response {
		// Delegates fully to RSA_Tracker which is AJAX-handler based.
		// For REST clients we run the same logic but without wp_send_json.
		// We simply re-use the AJAX action via do_action().
		$_POST  = $r->get_params();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Verify nonce manually (passed as 'nonce' param)
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'rsa_track' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_nonce' ], 403 );
		}

		// Delegate to the tracker's handle_ingest — capture wp_send_json call
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		RSA_Tracker::handle_ingest();
		ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	// ----------------------------------------------------------------
	// Response helper
	// ----------------------------------------------------------------

	private static function ok( mixed $data ): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => true, 'data' => $data ], 200 );
	}
}
