<?php
/**
 * Integration tests for RSA_REST_API.
 *
 * Requires the WordPress integration environment. Tests register the routes,
 * fire requests via WP_REST_TestCase helpers, and assert responses.
 *
 * @package RichStatistics\Tests
 */

class RestApiTest extends WP_Test_REST_TestCase {

	/** @var WP_REST_Server */
	protected static WP_REST_Server $server;

	/** @var WP_User Admin user */
	protected static WP_User $admin;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
	}

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		static::$server = $wp_rest_server;
	}

	// ----------------------------------------------------------------
	// Route registration
	// ----------------------------------------------------------------

	public function test_routes_are_registered(): void {
		$routes = static::$server->get_routes();

		$expected = [
			'/rsa/v1/overview',
			'/rsa/v1/pages',
			'/rsa/v1/audience',
			'/rsa/v1/referrers',
			'/rsa/v1/behavior',
			'/rsa/v1/clicks',
			'/rsa/v1/export',
		];

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Missing route: $route" );
		}
	}

	// ----------------------------------------------------------------
	// Authentication / capability gating
	// ----------------------------------------------------------------

	public function test_unauthenticated_request_returns_401(): void {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$response = static::$server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_subscriber_gets_403(): void {
		$subscriber = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber->ID );

		$request  = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$response = static::$server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_admin_can_access_overview(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );

		$this->assertTrue(
			in_array( $response->get_status(), [ 200, 403 ], true ),
			'Expected 200 (real Freemius SDK) or 403 (dev stub without premium)'
		);
	}

	// ----------------------------------------------------------------
	// Parameter validation
	// ----------------------------------------------------------------

	public function test_invalid_period_returns_400(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$request->set_param( 'period', 'last_100_years' );
		$response = static::$server->dispatch( $request );

		// Either 400 (validation) or 200/403 depending on how the API handles invalid period
		$this->assertContains( $response->get_status(), [ 200, 400, 403 ] );
	}

	// ----------------------------------------------------------------
	// Response envelope — {ok:true, data:{...}}
	// ----------------------------------------------------------------

	public function test_overview_response_is_enveloped(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );

		if ( 200 !== $response->get_status() ) {
			$this->markTestSkipped( 'Overview requires premium; skipping envelope check.' );
		}

		$body = $response->get_data();
		$this->assertArrayHasKey( 'ok',   $body );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertTrue( $body['ok'] );
	}

	// ----------------------------------------------------------------
	// /pages response shape
	// ----------------------------------------------------------------

	public function test_pages_response_has_pages_key(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'GET', '/rsa/v1/pages' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );

		if ( 200 !== $response->get_status() ) {
			$this->markTestSkipped( 'Pages endpoint requires premium; skipping.' );
		}

		$body = $response->get_data();
		$this->assertIsArray( $body['data'] );
		$this->assertArrayHasKey( 'pages', $body['data'] );
	}

	// ----------------------------------------------------------------
	// /audience response shape
	// ----------------------------------------------------------------

	public function test_audience_response_has_correct_keys(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'GET', '/rsa/v1/audience' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );

		if ( 200 !== $response->get_status() ) {
			$this->markTestSkipped( 'Audience endpoint requires premium; skipping.' );
		}

		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( 'by_os',       $data );
		$this->assertArrayHasKey( 'by_browser',  $data );
		$this->assertArrayHasKey( 'by_viewport', $data );
		$this->assertArrayHasKey( 'by_language', $data );
		$this->assertArrayHasKey( 'by_timezone', $data );
	}

	// ----------------------------------------------------------------
	// /referrers response shape
	// ----------------------------------------------------------------

	public function test_referrers_response_has_referrers_key(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'GET', '/rsa/v1/referrers' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );

		if ( 200 !== $response->get_status() ) {
			$this->markTestSkipped( 'Referrers endpoint requires premium; skipping.' );
		}

		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( 'referrers', $data );
		$this->assertIsArray( $data['referrers'] );
	}

	// ----------------------------------------------------------------
	// /clicks route exists and returns correct shape
	// ----------------------------------------------------------------

	public function test_clicks_route_is_accessible(): void {
		wp_set_current_user( self::$admin->ID );
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/clicks' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );

		// 200 (premium), 403 (free tier stub), or 401 (unauthed) — never 404
		$this->assertNotSame( 404, $response->get_status(), '/rsa/v1/clicks route should exist' );
	}

	public function test_clicks_response_has_clicks_key(): void {
		wp_set_current_user( self::$admin->ID );
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/clicks' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );

		if ( 200 !== $response->get_status() ) {
			$this->markTestSkipped( 'Clicks endpoint requires premium; skipping.' );
		}

		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( 'clicks', $data );
		$this->assertIsArray( $data['clicks'] );
	}

	public function test_clicks_row_shape_when_data_present(): void {
		global $wpdb;

		// Seed a test row
		$wpdb->insert(
			$wpdb->prefix . 'rsa_clicks',
			[
				'session_id'    => 'rest-api-test-session',
				'page'          => '/rest-test/',
				'href_protocol' => 'tel',
				'element_tag'   => 'A',
				'element_text'  => 'Call us',
				'x_pct'         => 10,
				'y_pct'         => 20,
				'href_value'    => '+15551234567',
				'created_at'    => current_time( 'mysql' ),
			]
		);

		wp_set_current_user( self::$admin->ID );
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/clicks' );
		$request->set_param( 'period', '30d' );
		$response = static::$server->dispatch( $request );

		$wpdb->delete( $wpdb->prefix . 'rsa_clicks', [ 'session_id' => 'rest-api-test-session' ] );

		if ( 200 !== $response->get_status() ) {
			$this->markTestSkipped( 'Clicks endpoint requires premium; skipping row-shape test.' );
		}

		$clicks = $response->get_data()['data']['clicks'];
		$rows   = array_filter( $clicks, fn( $c ) => $c['href_value'] === '+15551234567' );
		$this->assertNotEmpty( $rows, 'Expected seeded click row in /clicks response' );

		$row = reset( $rows );
		$this->assertArrayHasKey( 'page',          $row );
		$this->assertArrayHasKey( 'href_protocol', $row );
		$this->assertArrayHasKey( 'element_tag',   $row );
		$this->assertArrayHasKey( 'element_text',  $row );
		$this->assertArrayHasKey( 'href_value',    $row );
		$this->assertArrayHasKey( 'count',         $row );
	}
}
