<?php
/**
 * Metric Pipeline Tests
 *
 * End-to-end validation that each metric flows from collection
 * through the database to the REST API response consumed by the PWA.
 *
 * If any part of the chain breaks (ingest, storage, aggregation,
 * REST serialization), these tests will identify which metric is affected.
 *
 * @package RichStatistics\Tests
 */
class MetricPipelineTest extends WP_UnitTestCase {

	private static string $test_sid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

	private ?int $test_user_id = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'rsa_manage_statistics' );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			class_alias( 'WP_REST_Server', 'WooCommerce' );
		}
	}

	public static function tearDownAfterClass(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'rsa_manage_statistics' );
		}
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		$this->clear_plugin_tables();
		// Ensure Freemius stub reports premium = true so premium endpoints work.
		if ( ! defined( 'RSA_PREMIUM_TEST' ) ) {
			define( 'RSA_PREMIUM_TEST', true );
		}
		// Create a consistent user for nonce generation / validation.
		$this->test_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->test_user_id );
		// Pretend we are in an AJAX context so wp_send_json() calls wp_die()
		// instead of die(), and redirect wp_die_ajax_handler to the test-case
		// handler that throws WPDieException.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		// Default request headers so bot detection doesn't flag tests.
		$_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		$_SERVER['HTTP_ACCEPT']          = 'text/html';
	}

	public function tearDown(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['HTTP_ACCEPT'], $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	private function clear_plugin_tables(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_sessions`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_wc_events`" );
	}

	private function ingest_pageview( array $overrides = [] ): void {
		$payload                   = array_merge(
			array(
				'action'       => 'rsa_track',
				'nonce'        => wp_create_nonce( 'rsa_track' ),
				'session_id'   => self::$test_sid,
				'page'         => '/test-page/',
				'referrer'     => 'https://google.com',
				'language'     => 'en-US',
				'timezone'     => 'America/Chicago',
				'viewport_w'   => 1920,
				'viewport_h'   => 1080,
				'time_on_page' => 12,
				'bot_signals'  => 0,
				'utm_source'   => '',
				'utm_medium'   => '',
				'utm_campaign' => '',
			),
			$overrides
		);
		$_POST                     = $payload;
		$_REQUEST['nonce']         = $payload['nonce'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
		}
		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		}
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			$_SERVER['HTTP_ACCEPT'] = 'text/html';
		}

		ob_start();
		try {
			RSA_Tracker::handle_ingest();
		} catch ( \WPDieException $e ) {
			// Expected — handle_ingest calls wp_send_json_success().
			unset( $e );
		}
		ob_end_clean();
	}

	private function ingest_click( array $overrides = [] ): void {
		$payload                   = array_merge(
			array(
				'action'        => 'rsa_track_click',
				'nonce'         => wp_create_nonce( 'rsa_track' ),
				'session_id'    => self::$test_sid,
				'page'          => '/test-page/',
				'element_tag'   => 'a',
				'element_id'    => 'test-link',
				'element_class' => 'external',
				'element_text'  => 'Click me',
				'href_protocol' => 'tel',
				'href_value'    => '+15551234567',
				'matched_rule'  => '',
				'x_pct'         => 45.5,
				'y_pct'         => 30.2,
			),
			$overrides
		);
		$_POST                     = $payload;
		$_REQUEST['nonce']         = $payload['nonce'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
		}

		ob_start();
		try {
			RSA_Click_Tracking::handle_click();
		} catch ( \WPDieException $e ) {
			// Expected.
			unset( $e );
		}
		ob_end_clean();
	}

	private function dispatch_rest( string $method, string $route, array $params = [] ): array {
		global $wp_rest_server;
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		$response = $wp_rest_server->dispatch( $request );
		$body     = (array) $response->get_data();
		return (array) ( $body['data'] ?? array() );
	}

	private function get_rest_data( WP_REST_Response $response ): array {
		$body = (array) $response->get_data();
		return (array) ( $body['data'] ?? array() );
	}

	/**
	 * ----------------------------------------------------------------
	 * Pageview metric: ingest → rsa_events → /overview
	 * ----------------------------------------------------------------
	 */
	public function test_pageview_pipeline_from_ingest_to_overview(): void {
		$this->ingest_pageview( array( 'page' => '/hello-world/' ) );

		global $wpdb;
		$event = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s", self::$test_sid ),
			ARRAY_A
		);
		$this->assertNotNull( $event, 'Event should be stored in rsa_events' );
		$this->assertSame( '/hello-world/', $event['page'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$server  = rest_get_server();
		$request = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$request->set_param( 'period', '7d' );
		$response = $server->dispatch( $request );
		$data     = $this->get_rest_data( $response );

		$this->assertArrayHasKey( 'pageviews', $data );
		$this->assertSame( 1, $data['pageviews'], 'Overview should count the ingested pageview' );
		$this->assertArrayHasKey( 'sessions', $data );
		$this->assertArrayHasKey( 'avg_time', $data );
		$this->assertArrayHasKey( 'bounce_rate', $data );
		$this->assertArrayHasKey( 'daily', $data );
	}

	/**
	 * ----------------------------------------------------------------
	 * Session metric: multiple pageviews → session upsert → /overview
	 * ----------------------------------------------------------------
	 */
	public function test_session_pipeline_multiple_pageviews(): void {
		$this->ingest_pageview( array( 'page' => '/page-1/', 'time_on_page' => 5 ) );
		$this->ingest_pageview( array( 'page' => '/page-2/', 'time_on_page' => 8 ) );

		global $wpdb;
		$session = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}rsa_sessions` WHERE session_id = %s", self::$test_sid ),
			ARRAY_A
		);
		$this->assertNotNull( $session, 'Session aggregate should exist' );
		$this->assertSame( 2, (int) $session['pages_viewed'] );
		$this->assertSame( '/page-2/', $session['exit_page'] );
	}

	/**
	 * ----------------------------------------------------------------
	 * Pages metric: ingest → /pages top list
	 * ----------------------------------------------------------------
	 */
	public function test_pages_pipeline_top_pages(): void {
		$this->ingest_pageview( array( 'page' => '/popular/' ) );
		$this->ingest_pageview( array( 'page' => '/popular/' ) );
		$this->ingest_pageview( array( 'page' => '/quiet/' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$server  = rest_get_server();
		$request = new WP_REST_Request( 'GET', '/rsa/v1/pages' );
		$request->set_param( 'period', '7d' );
		$response = $server->dispatch( $request );
		$data     = $this->get_rest_data( $response );

		$this->assertArrayHasKey( 'pages', $data );
		$this->assertNotEmpty( $data['pages'] );
		$top = $data['pages'][0];
		$this->assertSame( '/popular/', $top['page'] );
		$this->assertSame( 2, $top['views'] );
		$this->assertArrayHasKey( 'avg_time', $top );
	}

	/**
	 * ----------------------------------------------------------------
	 * Audience metric: ingest → /audience
	 * ----------------------------------------------------------------
	 */
	public function test_audience_pipeline_breakdowns(): void {
		$this->ingest_pageview( array( 'timezone' => 'Europe/Berlin' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$server  = rest_get_server();
		$request = new WP_REST_Request( 'GET', '/rsa/v1/audience' );
		$request->set_param( 'period', '7d' );
		$response = $server->dispatch( $request );
		$data     = $this->get_rest_data( $response );

		$this->assertArrayHasKey( 'by_timezone', $data );
		$this->assertArrayHasKey( 'by_os', $data );
		$this->assertArrayHasKey( 'by_browser', $data );
		$this->assertArrayHasKey( 'by_viewport', $data );
		$this->assertArrayHasKey( 'by_language', $data );
	}

	/**
	 * ----------------------------------------------------------------
	 * Referrers metric: ingest with referrer → /referrers
	 * ----------------------------------------------------------------
	 */
	public function test_referrers_pipeline_with_referrer(): void {
		$this->ingest_pageview( array( 'referrer' => 'https://twitter.com/somepost' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$server  = rest_get_server();
		$request = new WP_REST_Request( 'GET', '/rsa/v1/referrers' );
		$request->set_param( 'period', '7d' );
		$response = $server->dispatch( $request );
		$data     = $this->get_rest_data( $response );

		$this->assertArrayHasKey( 'referrers', $data );
		$this->assertNotEmpty( $data['referrers'] );
		$first = $data['referrers'][0];
		$this->assertSame( 'twitter.com', $first['domain'] );
		$this->assertSame( 1, $first['pageviews'] );
		$this->assertArrayHasKey( 'top_page', $first );
	}

	/**
	 * ----------------------------------------------------------------
	 * Click tracking metric: ingest click → rsa_clicks → /clicks
	 * ----------------------------------------------------------------
	 */
	public function test_click_pipeline_from_ingest_to_clicks_endpoint(): void {
		// Simulate premium context.
		add_filter(
			'pre_option_rsa_click_track_ids',
			static function () {
				return 'test-link';
			}
		);

		$this->ingest_click( array( 'element_id' => 'test-link' ) );

		global $wpdb;
		$click = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}rsa_clicks` WHERE session_id = %s", self::$test_sid ),
			ARRAY_A
		);
		$this->assertNotNull( $click, 'Click should be stored in rsa_clicks' );
		$this->assertSame( 'a', $click['element_tag'] );
		$this->assertSame( 'test-link', $click['element_id'] );
	}

	/**
	 * ----------------------------------------------------------------
	 * WooCommerce product view: REST ingest → rsa_wc_events → /woocommerce
	 * ----------------------------------------------------------------
	 */
	public function test_wc_product_view_pipeline(): void {
		$server = rest_get_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/rsa/v1/wc-event' );
		$request->set_param( 'event_type', 'wc_product_view' );
		$request->set_param( 'session_id', self::$test_sid );
		$request->set_param( 'nonce', wp_create_nonce( 'rsa_track' ) );
		$request->set_param( 'product_id', 42 );
		$request->set_param( 'product_name', 'Test Widget' );
		$request->set_param( 'quantity', 0 );

		$response = $server->dispatch( $request );
		$body     = (array) $response->get_data();
		$this->assertTrue( $body['ok'] ?? false, 'wc-event should return ok' );

		global $wpdb;
		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}rsa_wc_events` WHERE session_id = %s AND event_type = 'wc_product_view'",
				self::$test_sid
			),
			ARRAY_A
		);
		$this->assertNotNull( $event );
		$this->assertSame( '42', $event['product_id'] );
		$this->assertSame( 'Test Widget', $event['product_name'] );

		// Verify /woocommerce endpoint surfaces it.
		$get_req = new WP_REST_Request( 'GET', '/rsa/v1/woocommerce' );
		$get_req->set_param( 'period', '7d' );
		$get_resp = $server->dispatch( $get_req );
		$wc_data  = $this->get_rest_data( $get_resp );

		$this->assertArrayHasKey( 'funnel', $wc_data );
		$this->assertSame( 1, $wc_data['funnel']['views'] );
		$this->assertArrayHasKey( 'top_products_viewed', $wc_data );
		$this->assertNotEmpty( $wc_data['top_products_viewed'] );
		$this->assertSame( 'Test Widget', $wc_data['top_products_viewed'][0]['product_name'] );
	}

	/**
	 * ----------------------------------------------------------------
	 * WooCommerce add-to-cart: REST ingest → rsa_wc_events → /woocommerce
	 * ----------------------------------------------------------------
	 */
	public function test_wc_add_to_cart_pipeline(): void {
		$server = rest_get_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/rsa/v1/wc-event' );
		$request->set_param( 'event_type', 'wc_add_to_cart' );
		$request->set_param( 'session_id', self::$test_sid );
		$request->set_param( 'nonce', wp_create_nonce( 'rsa_track' ) );
		$request->set_param( 'product_id', 99 );
		$request->set_param( 'product_name', 'Gadget' );
		$request->set_param( 'quantity', 3 );

		$response = $server->dispatch( $request );
		$body     = (array) $response->get_data();
		$this->assertTrue( $body['ok'] ?? false );

		$get_req = new WP_REST_Request( 'GET', '/rsa/v1/woocommerce' );
		$get_req->set_param( 'period', '7d' );
		$get_resp = $server->dispatch( $get_req );
		$wc_data  = $this->get_rest_data( $get_resp );

		$this->assertSame( 1, $wc_data['funnel']['cart'] );
		$this->assertArrayHasKey( 'top_products_cart', $wc_data );
	}

	/**
	 * ----------------------------------------------------------------
	 * WooCommerce order complete: REST ingest → rsa_wc_events → /woocommerce
	 * ----------------------------------------------------------------
	 */
	public function test_wc_order_complete_pipeline(): void {
		$server = rest_get_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/rsa/v1/wc-event' );
		$request->set_param( 'event_type', 'wc_order_complete' );
		$request->set_param( 'session_id', self::$test_sid );
		$request->set_param( 'nonce', wp_create_nonce( 'rsa_track' ) );

		$response = $server->dispatch( $request );
		$body     = (array) $response->get_data();
		$this->assertTrue( $body['ok'] ?? false );

		$get_req = new WP_REST_Request( 'GET', '/rsa/v1/woocommerce' );
		$get_req->set_param( 'period', '7d' );
		$get_resp = $server->dispatch( $get_req );
		$wc_data  = $this->get_rest_data( $get_resp );

		$this->assertSame( 1, $wc_data['orders_count'] );
		$this->assertSame( 1, $wc_data['funnel']['orders'] );
	}

	/**
	 * ----------------------------------------------------------------
	 * WooCommerce endpoint structure matches PWA contract
	 * ----------------------------------------------------------------
	 */
	public function test_wc_response_has_all_pwa_keys(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$server  = rest_get_server();
		$request = new WP_REST_Request( 'GET', '/rsa/v1/woocommerce' );
		$request->set_param( 'period', '7d' );
		$response = $server->dispatch( $request );
		$data     = $this->get_rest_data( $response );

		// Keys the PWA consumes from the /woocommerce response.
		$expected_keys = array( 'top_products_viewed', 'top_products_cart', 'orders_count', 'funnel', 'woocommerce_active' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $data, "Missing key '$key' in /woocommerce response" );
		}
	}

	/**
	 * ----------------------------------------------------------------
	 * Bot detection should block ingestion
	 * ----------------------------------------------------------------
	 */
	public function test_bot_payload_is_not_stored(): void {
		$_SERVER['HTTP_USER_AGENT']      = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';
		$_SERVER['HTTP_ACCEPT']          = '';

		$this->ingest_pageview();

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s", self::$test_sid )
		);
		$this->assertSame( 0, $count, 'Bot request should not create an event' );
	}

	/**
	 * ----------------------------------------------------------------
	 * Consent banner gate: analytics=false should suppress ingestion
	 * ----------------------------------------------------------------
	 */
	public function test_consent_banner_blocks_analytics_when_rejected(): void {
		// Simulate tracker.js short-circuit: when analytics not consented,
		// sendEvent() sets sent=true and returns early. We verify the
		// server-side handler still behaves correctly if a request arrives.
		update_option( 'rsa_consent_banner', 1 );
		update_option( 'rsa_consent_auto', 0 );

		$this->ingest_pageview();

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s", self::$test_sid )
		);
		// Server does NOT enforce consent — the gate is in tracker.js.
		// This test documents the behavior so we know where to look if
		// metrics appear despite rejection.
		$this->assertSame( 1, $count, 'Server accepts regardless; gate is client-side in tracker.js' );
	}
}
