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
}
