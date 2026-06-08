<?php
/**
 * Integration tests for REST /track endpoint happy path.
 *
 * @package RichStatistics\Tests
 */
class RestTrackTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	protected static WP_REST_Server $server;

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		static::$server = $wp_rest_server;
	}

	public function test_track_endpoint_accepts_valid_nonce(): void {
		$nonce = wp_create_nonce( 'rsa_track' );

		$request = new WP_REST_Request( 'POST', '/rsa/v1/track' );
		$request->set_param( 'nonce', $nonce );
		$request->set_param( 'session_id', 'test-session-123' );
		$request->set_param( 'page', '/test-page/' );
		$request->set_param( 'referrer', '' );
		$request->set_param( 'browser', 'TestBrowser' );
		$request->set_param( 'os', 'TestOS' );
		$request->set_param( 'browser_version', '1.0' );
		$request->set_param( 'language', 'en' );
		$request->set_param( 'timezone', 'UTC' );
		$request->set_param( 'viewport_w', 1920 );
		$request->set_param( 'viewport_h', 1080 );
		$request->set_param( 'time_on_page', 5 );
		$request->set_param( 'bot_score', 0 );

		$response = static::$server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
	}
}
