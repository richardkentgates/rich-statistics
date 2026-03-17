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
			'limit' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
		] ) ] );
		register_rest_route( self::NS, '/audience',  [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_audience'  ], 'permission_callback' => $auth, 'args' => $read_args ] );
		register_rest_route( self::NS, '/referrers', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_referrers' ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'limit' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
		] ) ] );
		register_rest_route( self::NS, '/behavior',  [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_behavior'  ], 'permission_callback' => $auth, 'args' => $read_args ] );
		register_rest_route( self::NS, '/clicks',    [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_clicks'    ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'page' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/heatmap',   [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_heatmap'   ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'page' => [ 'type' => 'string', 'default' => '/', 'sanitize_callback' => 'sanitize_text_field' ],
		] ) ] );
		register_rest_route( self::NS, '/export',    [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_export'    ], 'permission_callback' => $auth, 'args' => array_merge( $read_args, [
			'format' => [ 'type' => 'string', 'default' => 'json', 'enum' => [ 'json', 'csv' ] ],
		] ) ] );

		// Ingest endpoint — public (no auth), nonce verified inside
		register_rest_route( self::NS, '/track', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'post_track' ],
			'permission_callback' => '__return_true',
		] );

		// Install token verification — authenticated, single-use
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
	}

	// ----------------------------------------------------------------
	// Permission callback
	// ----------------------------------------------------------------

	public static function check_auth( WP_REST_Request $request ): bool|WP_Error {
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
	// Read endpoints
	// ----------------------------------------------------------------

	public static function get_overview( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( RSA_Analytics::get_overview( $r['period'] ) );
	}

	public static function get_pages( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( [ 'pages' => RSA_Analytics::get_top_pages( $r['period'], (int) $r['limit'] ) ] );
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
		$rows = RSA_Analytics::get_referrers( $r['period'], (int) $r['limit'] );
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
		return self::ok( RSA_Analytics::get_heatmap( $r['page'] ?: '/', $r['period'] ) );
	}

	public static function get_export( WP_REST_Request $r ): WP_REST_Response {
		$data = RSA_Analytics::export_events( $r['period'], $r['format'] );

		if ( $r['format'] === 'csv' ) {
			// Return as CSV download
			return new WP_REST_Response( $data, 200, [
				'Content-Type'        => 'text/csv',
				'Content-Disposition' => 'attachment; filename="rsa-export.csv"',
			] );
		}

		return self::ok( json_decode( $data, true ) );
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
			return new WP_Error( 'no_token', 'No pending install token for this user.', [ 'status' => 404 ] );
		}

		if ( time() > (int) $stored['expires'] ) {
			delete_user_meta( $user_id, 'rsa_install_token' );
			return new WP_Error( 'token_expired', 'Install token has expired. Download a fresh copy from your profile.', [ 'status' => 410 ] );
		}

		// Constant-time comparison prevents timing side-channel
		if ( ! hash_equals( (string) $stored['token'], (string) $r['site_token'] ) ) {
			return new WP_Error( 'invalid_token', 'Invalid install token.', [ 'status' => 403 ] );
		}

		// Token consumed — delete so replay on a second device fails
		delete_user_meta( $user_id, 'rsa_install_token' );

		return self::ok( [ 'verified' => true ] );
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
		if ( ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ?? '' ), 'rsa_track' ) ) {
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
