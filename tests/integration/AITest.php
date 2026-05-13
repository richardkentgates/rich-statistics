<?php
/**
 * Integration tests for /rsa/v1/ai/tool endpoint.
 *
 * @package RichStatistics\Tests
 */
class AITest extends WP_UnitTestCase {

	private static int $admin_id;
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}
	public function test_ai_tool_endpoint_exists(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/rsa/v1/ai/tool', $routes );
	}
	public function test_ai_tool_requires_tool_parameter(): void {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 400, 403 ) );
	}
	public function test_ai_tool_accepts_valid_tool(): void {
		wp_set_current_user( self::$admin_id );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'overview' );
		$request->set_param( 'params', array( 'period' => '30d' ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'overview', $data['data']['tool'] );
		$this->assertArrayHasKey( 'data', $data['data'] );
	}
	public function test_ai_tool_rejects_invalid_tool(): void {
		wp_set_current_user( self::$admin_id );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'nonexistent' );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 400, 403 ) );
	}
	public function test_ai_tool_returns_structured_json(): void {
		wp_set_current_user( self::$admin_id );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
		$request->set_param( 'tool', 'pages' );
		$request->set_param( 'params', array( 'period' => '7d', 'limit' => 5 ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'pages', $data['data']['tool'] );
		$this->assertSame( 5, $data['data']['limit'] );
	}
	public function test_ai_tool_free_tools_accessible(): void {
		wp_set_current_user( self::$admin_id );
		$free_tools = array( 'overview', 'pages', 'audience', 'referrers', 'behavior' );
		foreach ( $free_tools as $tool ) {
			$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
			$request->set_param( 'tool', $tool );
			$request->set_param( 'params', array( 'period' => '30d' ) );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			$this->assertSame( 200, $response->get_status(), "Tool $tool should be accessible" );
			$this->assertFalse( $data['data']['premium'], "Tool $tool should not be marked premium" );
			$this->assertArrayHasKey( 'data', $data['data'], "Tool $tool should return data" );
		}
	}
	public function test_ai_tool_route_has_tool_param_defined(): void {
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/rsa/v1/ai/tool'];
		$args   = $route[0]['args'] ?? array();
		$this->assertArrayHasKey( 'tool', $args );
		$this->assertSame( 'string', $args['tool']['type'] );
		$this->assertTrue( $args['tool']['required'] );
	}
	public function test_ai_tool_route_has_params_param(): void {
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/rsa/v1/ai/tool'];
		$args   = $route[0]['args'] ?? array();
		$this->assertArrayHasKey( 'params', $args );
		$this->assertSame( 'object', $args['params']['type'] );
	}
	public function test_ai_tool_has_tool_enum_values(): void {
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/rsa/v1/ai/tool'];
		$args   = $route[0]['args'] ?? array();
		$enum   = $args['tool']['enum'] ?? array();
		$this->assertContains( 'overview', $enum );
		$this->assertContains( 'pages', $enum );
		$this->assertContains( 'audience', $enum );
		$this->assertContains( 'referrers', $enum );
		$this->assertContains( 'behavior', $enum );
		$this->assertContains( 'campaigns', $enum );
		$this->assertContains( 'user-flow', $enum );
		$this->assertContains( 'clicks', $enum );
		$this->assertContains( 'heatmap', $enum );
		$this->assertContains( 'woocommerce', $enum );
	}
	public function test_strip_pii_method_exists(): void {
		$this->assertTrue( method_exists( 'RSA_REST_API', 'strip_pii' ) );
	}
	public function test_strip_pii_is_private(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$this->assertTrue( $ref->isPrivate() );
	}
	public function test_strip_pii_strips_email_addresses(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'user_email' => 'admin@my-site.com',
			'user_login' => 'admin',
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( '[email-redacted]', $result['user_email'] );
		$this->assertSame( 'admin', $result['user_login'] );
	}
	public function test_strip_pii_strips_ipv4_addresses(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'ip1' => '10.0.0.1',
			'ip2' => '192.168.1.100',
			'ip3' => '8.8.8.8',
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( '[ip-redacted]', $result['ip1'] );
		$this->assertSame( '[ip-redacted]', $result['ip2'] );
		$this->assertSame( '[ip-redacted]', $result['ip3'] );
	}
	public function test_strip_pii_truncates_32_char_hex_session_ids(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'session_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( 'a1b2c3d4...', $result['session_id'] );
	}
	public function test_strip_pii_does_not_truncate_non_hex_32_strings(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'page' => '/blog/my-post',
			'uuid' => '550e8400-e29b-41d4-a716-446655440000',
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( '/blog/my-post', $result['page'] );
		$this->assertSame( '550e8400-e29b-41d4-a716-446655440000', $result['uuid'] );
	}
	public function test_strip_pii_handles_nested_arrays(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'data' => array(
				'users' => array(
					array(
						'email' => 'user1@test.com',
						'name'  => 'User 1',
					),
					array(
						'email' => 'user2@test.com',
						'name'  => 'User 2',
					),
				),
			),
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( '[email-redacted]', $result['data']['users'][0]['email'] );
		$this->assertSame( '[email-redacted]', $result['data']['users'][1]['email'] );
		$this->assertSame( 'User 1', $result['data']['users'][0]['name'] );
	}
	public function test_strip_pii_preserves_numeric_values(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'pageviews'   => 12345,
			'sessions'    => 6789,
			'bounce_rate' => 45.6,
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( 12345, $result['pageviews'] );
		$this->assertSame( 6789, $result['sessions'] );
		$this->assertSame( 45.6, $result['bounce_rate'] );
	}
	public function test_ai_tool_strips_visitor_data(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'visitors' => array(
				array(
					'email' => 'visitor@test.com',
					'page'  => '/home',
				),
			),
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( '[email-redacted]', $result['visitors'][0]['email'] );
		$this->assertSame( '/home', $result['visitors'][0]['page'] );
	}
	public function test_ai_tool_strips_referrer_data(): void {
		$ref = new ReflectionMethod( 'RSA_REST_API', 'strip_pii' );
		$ref->setAccessible( true );
		$test_data = array(
			'referrers' => array(
				array(
					'email'  => 'referrer@example.com',
					'domain' => 'google.com',
				),
			),
		);
		$result    = $ref->invoke( null, $test_data );
		$this->assertSame( '[email-redacted]', $result['referrers'][0]['email'] );
		$this->assertSame( 'google.com', $result['referrers'][0]['domain'] );
	}
	public function test_ai_tool_returns_premium_flag_for_premium_tools(): void {
		wp_set_current_user( self::$admin_id );
		$premium_tools = array( 'campaigns', 'user-flow', 'clicks', 'heatmap', 'woocommerce' );
		foreach ( $premium_tools as $tool ) {
			$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/tool' );
			$request->set_param( 'tool', $tool );
			$request->set_param( 'params', array( 'period' => '30d' ) );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			if ( 200 === $response->get_status() ) {
				$this->assertTrue( $data['data']['premium'], "Tool $tool should be marked premium" );
			} else {
				$this->assertSame( 403, $response->get_status(), "Tool $tool should require premium auth" );
			}
		}
	}
	public function test_ai_tool_no_llm_config_in_plugin(): void {
		$this->assertFalse( get_option( 'rsa_ai_api_key' ), 'AI API key should not be stored in plugin options' );
		$this->assertEmpty( get_option( 'rsa_ai_provider', '' ), 'AI provider should not be stored in plugin options' );
		$this->assertEmpty( get_option( 'rsa_ai_endpoint', '' ), 'AI endpoint should not be stored in plugin options' );
	}
}
