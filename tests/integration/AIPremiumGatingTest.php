<?php
/**
 * AI Tool Endpoint Tests
 *
 * Covers free and premium tool responses, invalid tool rejection,
 * and data-shape validation for key tools.
 *
 * @package RichStatistics\Tests
 */
class AIPremiumGatingTest extends WP_UnitTestCase {

	private ?int $admin_id = null;

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

		if ( ! defined( 'RSA_PREMIUM_TEST' ) ) {
			define( 'RSA_PREMIUM_TEST', true );
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	private function dispatch( WP_REST_Request $request ): WP_REST_Response {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		return $wp_rest_server->dispatch( $request );
	}

	/**
	 * Free tools
	 */
	public function test_overview_tool_returns_kpis(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'overview' );
		$request->set_param( 'params', array( 'period' => '7d' ) );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$tool_data = $data['data']['data'];
		$this->assertArrayHasKey( 'pageviews', $tool_data );
		$this->assertArrayHasKey( 'sessions', $tool_data );
		$this->assertArrayHasKey( 'bounce_rate', $tool_data );
	}

	public function test_audience_tool_returns_os_breakdown(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'audience' );
		$request->set_param( 'params', array( 'period' => '7d' ) );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$tool_data = $data['data']['data'];
		$this->assertArrayHasKey( 'os', $tool_data );
		$this->assertArrayHasKey( 'browser', $tool_data );
		$this->assertArrayHasKey( 'language', $tool_data );
		$this->assertArrayHasKey( 'viewport', $tool_data );
	}

	/**
	 * Premium tools
	 */
	public function test_campaigns_tool_returns_data_when_premium(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'campaigns' );
		$request->set_param( 'params', array( 'period' => '7d' ) );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertIsArray( $data['data'] );
	}

	public function test_user_flow_tool_returns_data_when_premium(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'user-flow' );
		$request->set_param( 'params', array( 'period' => '7d' ) );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertIsArray( $data['data'] );
	}

	/**
	 * Invalid params
	 */
	public function test_invalid_tool_returns_400(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'nonexistent_tool' );
		$request->set_param( 'params', array() );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_missing_tool_param_returns_400(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'params', array() );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
