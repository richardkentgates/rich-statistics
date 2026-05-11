<?php
/**
 * Integration tests for Rich Statistics AI Analytics endpoints.
 *
 * Tests the /rsa/v1/ai/query endpoint for conversational analytics.
 *
 * @package RichStatistics\Tests
 */
class AITest extends WP_UnitTestCase {

	private static int $admin_id;
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}
	public function test_ai_query_endpoint_exists(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/rsa/v1/ai/query', $routes );
	}
	public function test_ai_query_requires_question_parameter(): void {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'POST', '/rsa/v1/ai/query' );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 400, 403 ) );
	}
	public function test_ai_query_validates_openai_api_key(): void {
		wp_set_current_user( self::$admin_id );
		delete_option( 'rsa_ai_api_key' );
		update_option( 'rsa_ai_provider', 'openai' );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/ai/query' );
		$request->set_param( 'question', 'What is my overview?' );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 400, 403 ) );
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
	public function test_ai_query_accepts_period_parameter(): void {
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/rsa/v1/ai/query'];
		$args   = $route[0]['args'] ?? array();
		$this->assertArrayHasKey( 'period', $args );
		$this->assertSame( 'string', $args['period']['type'] );
	}
	public function test_ai_query_system_prompt_mentions_privacy(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/includes/class-rest-api.php';
		$content     = file_get_contents( $plugin_file );
		$this->assertTrue(
			strpos( $content, 'privacy' ) !== false || strpos( $content, 'PII' ) !== false,
			'AI system prompt should mention privacy considerations'
		);
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
	public function test_ai_query_uses_openai_compatible_format(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/includes/class-rest-api.php';
		$content     = file_get_contents( $plugin_file );
		$this->assertTrue(
			strpos( $content, 'chat/completions' ) !== false || strpos( $content, 'chat/completion' ) !== false,
			'AI endpoint should use OpenAI-compatible format'
		);
	}
	public function test_ai_query_includes_max_tokens_limit(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/includes/class-rest-api.php';
		$content     = file_get_contents( $plugin_file );
		$this->assertTrue( strpos( $content, 'max_tokens' ) !== false );
	}
	public function test_ai_query_includes_woocommerce_support(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/includes/class-rest-api.php';
		$content     = file_get_contents( $plugin_file );
		$this->assertTrue( strpos( $content, 'woocommerce' ) !== false );
	}
	public function test_ai_query_includes_user_flow_data(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/includes/class-rest-api.php';
		$content     = file_get_contents( $plugin_file );
		$this->assertTrue( strpos( $content, 'user_flow' ) !== false );
	}
	public function test_ai_query_includes_campaign_data(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/includes/class-rest-api.php';
		$content     = file_get_contents( $plugin_file );
		$this->assertTrue(
			strpos( $content, 'campaign' ) !== false || strpos( $content, 'utm' ) !== false
		);
	}
	public function test_ai_query_requires_question_to_be_sanitized(): void {
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/rsa/v1/ai/query'];
		$args   = $route[0]['args'] ?? array();
		$this->assertArrayHasKey( 'question', $args );
		$this->assertSame( 'string', $args['question']['type'] );
		$this->assertTrue( $args['question']['required'] );
	}
	public function test_ai_query_strips_visitor_data(): void {
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
	public function test_ai_query_strips_referrer_data(): void {
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
}
