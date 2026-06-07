<?php
/**
 * REST API
 *
 * @package RichStatistics
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// Ensure admin class is loaded for user_can_access_app().
if ( ! class_exists( 'RSA_Admin' ) && file_exists( __DIR__ . '/class-admin.php' ) ) {
	require_once __DIR__ . '/class-admin.php';
}

/**
 * REST API handler class.
 */
class RSA_Rest_API {

	const NS = 'rsa/v1';

	/**
	 * Initialize the REST API.
	 */
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
	 *
	 * @param mixed $result The authentication result.
	 * @return mixed Modified authentication result.
	 */
	public static function remove_cookie_auth( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false === strpos( $route, '/rsa/v1/' ) ) {
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
	 * Known origins allowed to make credentialed CORS requests.
	 * Requests without an Origin header (non-browser) get Access-Control-Allow-Origin: *.
	 * Keep in sync with the PWA's hosted locations.
	 */
	private static function allowed_cors_origins(): array {
		return [
			home_url(),
			'tauri://localhost',
			'https://app.richstatistics.com',
			'https://dev.richstatistics.com',
			'https://test.richstatistics.com',
		];
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
		if ( false === strpos( $route, '/rsa/v1/' ) ) {
			return;
		}

		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed = self::allowed_cors_origins();

		// OPTIONS preflight: answer immediately so WP's serve_request never runs.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			header( 'Vary: Origin' );
			if ( $origin && in_array( $origin, $allowed, true ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Credentials: true' );
			} elseif ( ! $origin ) {
				header( 'Access-Control-Allow-Origin: *' );
			}
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Access-Control-Max-Age: 86400' );
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
		if ( 0 !== strpos( $request->get_route(), '/' . self::NS ) ) {
			return $served;
		}
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed = self::allowed_cors_origins();
		header( 'Vary: Origin' );
		if ( $origin && in_array( $origin, $allowed, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Credentials: true' );
		} elseif ( ! $origin ) {
			header( 'Access-Control-Allow-Origin: *' );
		}
		return $served;
	}

	// ----------------------------------------------------------------
	// Route registration.
	// ----------------------------------------------------------------

	/**
	 * Register REST API routes.
	 */
	public static function register_routes(): void {
		$read_args = [
			'period' => [
				'type'              => 'string',
				'default'           => '30d',
				'enum'              => [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
		];

		$basic   = [ __CLASS__, 'check_basic_auth' ];
		$premium = [ __CLASS__, 'check_premium_auth' ];

		// AI tool endpoint — returns structured JSON for the app to reason over.
		// Free tools are available to all authenticated users;
		// premium tools require an active premium licence.
		$tool_args = [
			'tool'   => [
				'type'              => 'string',
				'required'          => true,
				'enum'              => [ 'overview', 'pages', 'audience', 'referrers', 'behavior', 'campaigns', 'user-flow', 'clicks', 'heatmap', 'woocommerce' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'params' => [
				'type'              => 'object',
				'default'           => [],
				'validate_callback' => function ( $v ) {
					return is_array( $v ); },
			],
		];
		register_rest_route(
			self::NS,
			'/ai/tool',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'ai_tool' ],
				'permission_callback' => [ __CLASS__, 'check_ai_tool_permission' ],
				'args'                => $tool_args,
			]
		);

		// Free tier endpoints — available to authenticated users with the
		// rsa_manage_statistics capability and permitted app access.
		register_rest_route(
			self::NS,
			'/overview',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_overview' ],
				'permission_callback' => $basic,
				'args'                => $read_args,
			]
		);
		register_rest_route(
			self::NS,
			'/pages',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_pages' ],
				'permission_callback' => $basic,
				'args'                => array_merge(
					$read_args,
					[
						'limit'    => [
							'type'    => 'integer',
							'default' => 100,
							'minimum' => 1,
							'maximum' => 100,
						],
						'browser'  => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'os'       => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'path'     => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sort'     => [
							'type'    => 'string',
							'default' => 'views',
							'enum'    => [ 'views', 'avg_time' ],
						],
						'sort_dir' => [
							'type'    => 'string',
							'default' => 'desc',
							'enum'    => [ 'asc', 'desc' ],
						],
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/audience',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_audience' ],
				'permission_callback' => $basic,
				'args'                => $read_args,
			]
		);
		register_rest_route(
			self::NS,
			'/referrers',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_referrers' ],
				'permission_callback' => $basic,
				'args'                => array_merge(
					$read_args,
					[
						'limit'    => [
							'type'    => 'integer',
							'default' => 100,
							'minimum' => 1,
							'maximum' => 100,
						],
						'ref_page' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/behavior',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_behavior' ],
				'permission_callback' => $basic,
				'args'                => $read_args,
			]
		);
		register_rest_route(
			self::NS,
			'/campaigns',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_campaigns' ],
				'permission_callback' => $premium,
				'args'                => array_merge(
					$read_args,
					[
						'limit'  => [
							'type'    => 'integer',
							'default' => 100,
							'minimum' => 1,
							'maximum' => 500,
						],
						'medium' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/filter-options',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_filter_options' ],
				'permission_callback' => $basic,
				'args'                => $read_args,
			]
		);

		// Premium-only endpoints.
		register_rest_route(
			self::NS,
			'/clicks',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_clicks' ],
				'permission_callback' => $premium,
				'args'                => array_merge(
					$read_args,
					[
						'page' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/heatmap',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_heatmap' ],
				'permission_callback' => $premium,
				'args'                => array_merge(
					$read_args,
					[
						'page' => [
							'type'              => 'string',
							'default'           => '/',
							'sanitize_callback' => 'sanitize_text_field',
						],
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/export',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_export' ],
				'permission_callback' => $premium,
				'args'                => array_merge(
					$read_args,
					[
						'format'    => [
							'type'    => 'string',
							'default' => 'json',
							'enum'    => [ 'json', 'csv' ],
						],
						'data_type' => [
							'type'    => 'string',
							'default' => 'pageviews',
							'enum'    => [ 'pageviews', 'sessions', 'clicks', 'referrers' ],
						],
						'date_from' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'date_to'   => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/woocommerce',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_woocommerce' ],
				'permission_callback' => $premium,
				'args'                => $read_args,
			]
		);
		register_rest_route(
			self::NS,
			'/wc-event',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'post_wc_event' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'event_type'   => [
						'type'              => 'string',
						'required'          => true,
						'enum'              => [ 'wc_product_view', 'wc_add_to_cart', 'wc_order_complete' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'session_id'   => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'nonce'        => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'product_id'   => [
						'type'    => 'integer',
						'default' => 0,
					],
					'product_name' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'quantity'     => [
						'type'    => 'integer',
						'default' => 1,
					],
				],
			]
		);
		$flow_args = array_merge(
			$read_args,
			[
				'entry_source' => [
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'focus_page'   => [
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'min_sessions' => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				],
				'steps'        => [
					'type'    => 'integer',
					'default' => 4,
					'minimum' => 2,
					'maximum' => 5,
				],
			]
		);
		register_rest_route(
			self::NS,
			'/user-flow',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_user_flow' ],
				'permission_callback' => $premium,
				'args'                => $flow_args,
			]
		);
		register_rest_route(
			self::NS,
			'/user-flow/journey',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_user_flow_journey' ],
				'permission_callback' => $premium,
				'args'                => array_merge(
					$read_args,
					[
						'from_page' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'to_page'   => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'min_count' => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
						'limit'     => [
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 250,
						],
						'sort'      => [
							'type'              => 'string',
							'default'           => 'count',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sort_dir'  => [
							'type'    => 'string',
							'default' => 'desc',
							'enum'    => [ 'asc', 'desc' ],
						],
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/user-flow/sources',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_user_flow_sources' ],
				'permission_callback' => $premium,
				'args'                => $read_args,
			]
		);

		register_rest_route(
			self::NS,
			'/purge-page',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'purge_page' ],
				'permission_callback' => $premium,
				'args'                => [
					'page' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Plugin info — public, no auth required (version badge + version sync for the PWA).
		register_rest_route(
			self::NS,
			'/info',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_info' ],
				'permission_callback' => '__return_true',
			]
		);

		// User settings — syncs the site list across devices (metadata only, no credentials).
		register_rest_route(
			self::NS,
			'/user-settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'get_user_settings' ],
					'permission_callback' => $basic,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'post_user_settings' ],
					'permission_callback' => $basic,
					'args'                => [
						'sites' => [
							'type'     => 'array',
							'required' => true,
						],
					],
				],
			]
		);

		// Ingest endpoint — public (no auth), nonce verified inside.
		register_rest_route(
			self::NS,
			'/track',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'post_track' ],
				'permission_callback' => '__return_true',
			]
		);

		// OTP site-pairing — public, single-use, rate-limited per IP.
		register_rest_route(
			self::NS,
			'/verify-otp',
			[
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
			]
		);
	}

	// ----------------------------------------------------------------
	// Permission callbacks.
	// ----------------------------------------------------------------

	/**
	 * Basic auth — available to authenticated users with the
	 * rsa_manage_statistics capability and permitted app access.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public static function check_basic_auth( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access Rich Statistics data.', 'rich-statistics' ),
				[ 'status' => 403 ]
			);
		}
		if ( ! RSA_Admin::user_can_access_app() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access Rich Statistics data.', 'rich-statistics' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Premium auth - requires active premium licence
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public static function check_premium_auth( WP_REST_Request $request ): bool|WP_Error {
		// Freemius premium gate — gated features require active premium licence.
		if ( function_exists( 'rs_fs' ) && ! rs_fs()->can_use_premium_code__premium_only() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'This feature requires a premium licence.', 'rich-statistics' ),
				[ 'status' => 403 ]
			);
		}
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access Rich Statistics data.', 'rich-statistics' ),
				[ 'status' => 403 ]
			);
		}
		if ( ! RSA_Admin::user_can_access_app() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access Rich Statistics data.', 'rich-statistics' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Permission callback for /ai/tool — allows free tools for any authenticated
	 * user, premium tools only with active licence.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return bool|WP_Error
	 */
	public static function check_ai_tool_permission( WP_REST_Request $r ): bool|WP_Error {
		$free_tools = [ 'overview', 'pages', 'audience', 'referrers', 'behavior' ];
		$tool       = $r->get_param( 'tool' );
		if ( in_array( $tool, $free_tools, true ) ) {
			return self::check_basic_auth( $r );
		}
		return self::check_premium_auth( $r );
	}

	/**
	 * AI tool endpoint — returns structured analytics data for a given tool.
	 * The app uses this data for LLM reasoning; no LLM call happens server-side.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function ai_tool( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$tool   = $r->get_param( 'tool' );
		$params = $r->get_param( 'params' );
		$period = isset( $params['period'] ) && in_array( $params['period'], [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ], true )
			? $params['period']
			: '30d';
		$limit  = isset( $params['limit'] ) ? min( 100, max( 1, (int) $params['limit'] ) ) : 10;

		$page = sanitize_text_field( $params['page'] ?? '' );

		$data = match ( $tool ) {
			'overview'    => self::strip_pii( RSA_Analytics::get_overview( $period ) ),
			'pages'       => array_map( [ __CLASS__, 'strip_pii' ], RSA_Analytics::get_top_pages( $period, $limit ) ),
			'audience'    => self::strip_pii( RSA_Analytics::get_audience( $period ) ),
			'referrers'   => array_map( [ __CLASS__, 'strip_pii' ], RSA_Analytics::get_referrers( $period, $limit ) ),
			'behavior'    => self::strip_pii( RSA_Analytics::get_behavior( $period ) ),
			'campaigns'   => array_map( [ __CLASS__, 'strip_pii' ], RSA_Analytics::get_campaigns( $period, $limit ) ),
			'user-flow'   => self::strip_pii( RSA_Analytics::get_user_flow( $period ) ),
			'clicks'      => array_map( [ __CLASS__, 'strip_pii' ], RSA_Analytics::get_click_map( $period, $page ) ),
			'heatmap'     => array_map( [ __CLASS__, 'strip_pii' ], RSA_Analytics::get_heatmap( $page, $period ) ),
			'woocommerce' => self::strip_pii( RSA_Analytics::get_woocommerce( $period ) ),
			default       => new WP_Error( 'invalid_tool', __( 'Invalid AI tool requested.', 'rich-statistics' ), [ 'status' => 400 ] ),
		};

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return self::ok(
			[
				'tool'    => $tool,
				'period'  => $period,
				'limit'   => $limit,
				'data'    => $data,
				'premium' => ! in_array( $tool, [ 'overview', 'pages', 'audience', 'referrers', 'behavior' ], true ),
			]
		);
	}

	// ----------------------------------------------------------------
	// Plugin info (public).
	// ----------------------------------------------------------------

	/**
	 * Get plugin info.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_info(): WP_REST_Response {
		return self::ok(
			[
				'version'         => RSA_VERSION,
				'app_version'     => RSA_APP_VERSION,
				'min_app_version' => RSA_MIN_APP_VERSION,
				'app_url'         => RSA_APP_URL,
				'env'             => RSA_APP_ENV,
				'site_name'       => get_bloginfo( 'name' ),
				'site_url'        => get_site_url(),
				'is_premium'      => function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only(),
				'channel'         => get_option( 'rsa_beta_channel' ) ? 'beta' : 'stable',
			]
		);
	}

	// ----------------------------------------------------------------
	// User settings (site list sync — metadata only, no credentials).
	// ----------------------------------------------------------------

	/**
	 * Get user settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_user_settings(): WP_REST_Response {
		$user_id = get_current_user_id();
		$sites   = get_user_meta( $user_id, 'rsa_app_sites', true );
		return self::ok( [ 'sites' => is_array( $sites ) ? $sites : [] ] );
	}

	/**
	 * Save user settings.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_user_settings( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$raw     = $r->get_param( 'sites' );

		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_data', __( 'sites must be an array.', 'rich-statistics' ), [ 'status' => 400 ] );
		}

		// Strip everything except the safe fields we want to persist.
		$sanitized = array_map(
			function ( $site ) {
				return [
					'id'      => sanitize_text_field( (string) ( $site['id'] ?? '' ) ),
					'label'   => sanitize_text_field( (string) ( $site['label'] ?? '' ) ),
					'siteUrl' => esc_url_raw( (string) ( $site['siteUrl'] ?? '' ) ),
					'appUrl'  => esc_url_raw( (string) ( $site['appUrl'] ?? '' ) ),
				];
			},
			$raw
		);

		update_user_meta( $user_id, 'rsa_app_sites', $sanitized );
		return self::ok( [ 'saved' => true ] );
	}

	// ----------------------------------------------------------------
	// Read endpoints.
	// ----------------------------------------------------------------

	/**
	 * Get overview data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_overview( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( RSA_Analytics::get_overview( $r['period'] ) );
	}

	/**
	 * Get pages data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_pages( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'browser'  => (string) ( $r['browser'] ?? '' ),
			'os'       => (string) ( $r['os'] ?? '' ),
			'page'     => (string) ( $r['path'] ?? '' ),
			'sort'     => (string) ( $r['sort'] ?? 'views' ),
			'sort_dir' => (string) ( $r['sort_dir'] ?? 'desc' ),
		];
		return self::ok( [ 'pages' => RSA_Analytics::get_top_pages( $r['period'], (int) $r['limit'], $filters ) ] );
	}

	/**
	 * Get audience data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_audience( WP_REST_Request $r ): WP_REST_Response {
		$d = RSA_Analytics::get_audience( $r['period'] );
		return self::ok(
			[
				'by_os'       => $d['os'],
				'by_browser'  => $d['browser'],
				'by_viewport' => $d['viewport'],
				'by_language' => $d['language'],
				'by_timezone' => $d['timezone'],
			]
		);
	}

	/**
	 * Get referrers data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_referrers( WP_REST_Request $r ): WP_REST_Response {
		$filters = [ 'page' => (string) ( $r['ref_page'] ?? '' ) ];
		$rows    = RSA_Analytics::get_referrers( $r['period'], (int) $r['limit'], $filters );
		return self::ok(
			[
				'referrers' => array_map(
					fn( $row ) => [
						'domain'    => $row['domain'],
						'pageviews' => $row['visits'],
						'top_page'  => $row['top_page'],
					],
					$rows
				),
			]
		);
	}

	/**
	 * Get behavior data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_behavior( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( RSA_Analytics::get_behavior( $r['period'] ) );
	}

	/**
	 * Get clicks data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_clicks( WP_REST_Request $r ): WP_REST_Response {
		$rows = RSA_Analytics::get_click_map( $r['period'], $r['page'] );
		return self::ok(
			[
				'clicks' => array_map(
					fn( $row ) => [
						'href_protocol' => $row['protocol'],
						'element_tag'   => $row['tag'],
						'element_text'  => $row['text'],
						'href_value'    => $row['href_value'],
						'count'         => $row['clicks'],
					],
					$rows
				),
			]
		);
	}

	/**
	 * Get heatmap data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_heatmap( WP_REST_Request $r ): WP_REST_Response {
		$date_from = (string) ( $r['date_from'] ?? '' );
		$date_to   = (string) ( $r['date_to'] ?? '' );
		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = ''; }
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = ''; }
		return self::ok( RSA_Analytics::get_heatmap( $r['page'] ? $r['page'] : '/', $r['period'], $date_from, $date_to ) );
	}

	/**
	 * Get export data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_export( WP_REST_Request $r ): WP_REST_Response {
		$format    = $r['format'];
		$period    = $r['period'];
		$data_type = (string) ( $r['data_type'] ?? 'pageviews' );
		$date_from = (string) ( $r['date_from'] ?? '' );
		$date_to   = (string) ( $r['date_to'] ?? '' );

		// Validate custom date formats.
		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = ''; }
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = ''; }

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

	/**
	 * Get filter options.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_filter_options( WP_REST_Request $r ): WP_REST_Response {
		return self::ok( RSA_Analytics::get_filter_options( $r['period'] ) );
	}

	/**
	 * Purge page data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function purge_page( WP_REST_Request $r ): WP_REST_Response {
		$page    = $r->get_param( 'page' );
		$deleted = RSA_DB::purge_page_data( $page );
		return self::ok(
			[
				'deleted' => $deleted,
				'page'    => $page,
			]
		);
	}

	/**
	 * Get campaigns data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_campaigns( WP_REST_Request $r ): WP_REST_Response {
		$filters = [ 'medium' => (string) ( $r['medium'] ?? '' ) ];
		$rows    = RSA_Analytics::get_campaigns( $r['period'], (int) $r['limit'], $filters );
		return self::ok( [ 'campaigns' => $rows ] );
	}

	/**
	 * Get WooCommerce data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_woocommerce( WP_REST_Request $r ): WP_REST_Response {
		$active = class_exists( 'WooCommerce' );
		if ( ! $active ) {
			return self::ok( [ 'woocommerce_active' => false ] );
		}
		$data = RSA_Analytics::get_woocommerce( $r['period'] );
		return self::ok( array_merge( [ 'woocommerce_active' => true ], $data ) );
	}

	/**
	 * Ingest a WooCommerce event from the frontend tracker.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function post_wc_event( WP_REST_Request $r ): WP_REST_Response {
		$nonce = sanitize_text_field( wp_unslash( $r['nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'rsa_track' ) ) {
			return new WP_REST_Response(
				[
					'ok'    => false,
					'error' => 'invalid_nonce',
				],
				403
			);
		}

		$session_id = sanitize_text_field( wp_unslash( $r['session_id'] ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session_id ) ) {
			return new WP_REST_Response(
				[
					'ok'    => false,
					'error' => 'invalid_session',
				],
				400
			);
		}

		if ( RSA_Bot_Detection::is_bot( RSA_Bot_Detection::score( 0, sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- UA header used for bot detection only
			return self::ok( [ 'recorded' => false, 'reason' => 'bot_detected' ] );
		}

		// Reuse tracker rate-limiting (60 req/min per session).
		$rl_key   = 'rsa_rl_' . substr( md5( $session_id ), 0, 16 );
		$rl_count = (int) get_transient( $rl_key );
		if ( $rl_count >= 60 ) {
			return self::ok( [ 'recorded' => false, 'reason' => 'rate_limited' ] );
		}
		set_transient( $rl_key, $rl_count + 1, 60 );

		// Only ingest when WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::ok( [ 'recorded' => false, 'reason' => 'woocommerce_inactive' ] );
		}

		$meta = [
			'product_id'   => (int) $r['product_id'],
			'product_name' => sanitize_text_field( wp_unslash( $r['product_name'] ) ),
			'product_sku'  => sanitize_text_field( wp_unslash( $r['product_sku'] ) ),
			'quantity'     => (int) $r['quantity'],
		];

		RSA_Woocommerce::insert_event(
			sanitize_text_field( wp_unslash( $r['event_type'] ) ),
			$meta,
			$session_id
		);

		return self::ok( [ 'recorded' => true ] );
	}

	/**
	 * Get user flow data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_user_flow( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'entry_source' => (string) ( $r['entry_source'] ?? '' ),
			'focus_page'   => (string) ( $r['focus_page'] ?? '' ),
			'min_sessions' => (int) ( $r['min_sessions'] ?? 1 ),
			'steps'        => (int) ( $r['steps'] ?? 4 ),
			'date_from'    => (string) ( $r['date_from'] ?? '' ),
			'date_to'      => (string) ( $r['date_to'] ?? '' ),
		];
		return self::ok( RSA_Analytics::get_path_flow( $r['period'], $filters ) );
	}

	/**
	 * Get user flow journey data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_user_flow_journey( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'from_page' => (string) ( $r['from_page'] ?? '' ),
			'to_page'   => (string) ( $r['to_page'] ?? '' ),
			'min_count' => (int) ( $r['min_count'] ?? 1 ),
			'limit'     => (int) ( $r['limit'] ?? 50 ),
			'sort'      => (string) ( $r['sort'] ?? 'count' ),
			'sort_dir'  => (string) ( $r['sort_dir'] ?? 'desc' ),
			'date_from' => (string) ( $r['date_from'] ?? '' ),
			'date_to'   => (string) ( $r['date_to'] ?? '' ),
		];
		return self::ok( [ 'rows' => RSA_Analytics::get_user_flow( $r['period'], $filters ) ] );
	}

	/**
	 * Get user flow sources data.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function get_user_flow_sources( WP_REST_Request $r ): WP_REST_Response {
		$filters = [
			'date_from' => (string) ( $r['date_from'] ?? '' ),
			'date_to'   => (string) ( $r['date_to'] ?? '' ),
		];
		return self::ok( [ 'sources' => RSA_Analytics::get_entry_sources( $r['period'], $filters ) ] );
	}

	// ----------------------------------------------------------------
	// OTP site-pairing  (POST /rsa/v1/verify-otp — public, rate-limited).
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
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_verify_otp( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		// Accept digits only; strip spaces/dashes the user may have typed.
		$otp = preg_replace( '/\D/', '', (string) $r['otp'] );

		if ( 6 !== strlen( $otp ) ) {
			return new WP_Error( 'invalid_otp', __( 'Invalid code format. Please enter the 6-digit code from your profile page.', 'rich-statistics' ), [ 'status' => 400 ] );
		}

		// Per-IP rate-limit — max 5 wrong attempts per 5-minute window.
		$ip_raw = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip_key = 'rsa_otp_fail_' . hash( 'sha256', $ip_raw );
		$fails  = (int) get_transient( $ip_key );

		if ( 5 <= $fails ) {
			return new WP_Error(
				'too_many_attempts',
				__( 'Too many incorrect attempts. Please wait a few minutes before trying again.', 'rich-statistics' ),
				[ 'status' => 429 ]
			);
		}

		$data = get_transient( 'rsa_otp_' . hash( 'sha256', $otp ) );

		if ( ! $data || ! is_array( $data ) ) {
			// Increment fail counter; 5-minute window resets automatically.
			set_transient( $ip_key, $fails + 1, 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'invalid_otp', __( 'Invalid or expired code.', 'rich-statistics' ), [ 'status' => 403 ] );
		}

		// Valid — consume (single-use) and reset IP fail counter.
		delete_transient( 'rsa_otp_' . hash( 'sha256', $otp ) );
		delete_transient( $ip_key );

		return self::ok(
			[
				'verified'   => true,
				'username'   => (string) $data['username'],
				'site_label' => (string) $data['site_label'],
				'site_url'   => (string) $data['site_url'],
			]
		);
	}

	// ----------------------------------------------------------------
	// Ingest endpoint (mirrors the AJAX handler but via REST).
	// ----------------------------------------------------------------

	/**
	 * Track pageview.
	 *
	 * @param WP_REST_Request $r Request object.
	 * @return WP_REST_Response
	 */
	public static function post_track( WP_REST_Request $r ): WP_REST_Response {
		// Save and restore $_POST and $_SERVER to avoid polluting global state.
		$saved_post                = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below before use
		$saved_method              = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$_POST                     = $r->get_params();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$die_handler = function ( $message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal, not rendered.
			throw new Exception( (string) $message );
		};
		$die_wrapper = function () use ( $die_handler ) {
			return $die_handler;
		};
		add_filter( 'wp_die_ajax_handler', $die_wrapper );
		add_filter( 'wp_doing_ajax', '__return_true' );

		try {
			// Verify nonce manually (passed as 'nonce' param).
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'rsa_track' ) ) {
				return new WP_REST_Response(
					[
						'ok'    => false,
						'error' => 'invalid_nonce',
					],
					403
				);
			}

			// Delegate to the tracker's handle_ingest.
			// handle_ingest() calls wp_send_json_success() which calls wp_die().
			// The die handler above converts that to an Exception so we can catch it
			// and still run our finally cleanup.
			ob_start();
			try {
				RSA_Tracker::handle_ingest();
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Exception is expected from wp_die override.
				// Swallow the expected die from wp_send_json_success.
			}
			ob_get_clean();

			return new WP_REST_Response( [ 'ok' => true ], 200 );
		} finally {
			$_POST                     = $saved_post;
			$_SERVER['REQUEST_METHOD'] = $saved_method;
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_wrapper );
		}
	}

	// ----------------------------------------------------------------
	// PII stripping utilities.
	// ----------------------------------------------------------------

	/**
	 * Strip PII from data before returning via the API.
	 *
	 * @param array $data Data to strip.
	 * @return array
	 */
	private static function strip_pii( array $data ): array {
		array_walk_recursive(
			$data,
			function ( &$value, $key ) {
				if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
					$value = '[email-redacted]';
				}
				if ( is_string( $value ) && 32 === strlen( $value ) && ctype_xdigit( $value ) ) {
					$value = substr( $value, 0, 8 ) . '...';
				}
				if ( is_string( $value ) && preg_match( '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $value ) ) {
					$value = '[ip-redacted]';
				}
				// IPv6 addresses (full, compressed, and IPv4-mapped).
				if ( is_string( $value ) && preg_match( '/^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$|^(?:[0-9a-fA-F]{1,4}:){1,7}:$|^(?:[0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}$|^(?:[0-9a-fA-F]{1,4}:){1,5}(?::[0-9a-fA-F]{1,4}){1,2}$|^(?:[0-9a-fA-F]{1,4}:){1,4}(?::[0-9a-fA-F]{1,4}){1,3}$|^(?:[0-9a-fA-F]{1,4}:){1,3}(?::[0-9a-fA-F]{1,4}){1,4}$|^(?:[0-9a-fA-F]{1,4}:){1,2}(?::[0-9a-fA-F]{1,4}){1,5}$|^[0-9a-fA-F]{1,4}:(?::[0-9a-fA-F]{1,4}){1,6}$|^:(?::[0-9a-fA-F]{1,4}){1,7}$|^::$|^(?:[0-9a-fA-F]{1,4}:){6}(?:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$|^::ffff:(?:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $value ) ) {
					$value = '[ip-redacted]';
				}
			}
		);
		return $data;
	}

	// ----------------------------------------------------------------
	// Private helpers.
	// ----------------------------------------------------------------

	/**
	 * Return a standard OK response.
	 *
	 * @param mixed $data Response data.
	 * @return WP_REST_Response
	 */
	private static function ok( mixed $data ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'ok'   => true,
				'data' => $data,
			],
			200
		);
	}
}
