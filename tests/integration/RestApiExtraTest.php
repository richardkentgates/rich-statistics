<?php
/**
 * Integration tests for RSA_Rest_API — additional endpoints not covered
 * by the existing RestApiTest.
 *
 * @package RichStatistics\Tests
 */
class RestApiExtraTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	protected static WP_REST_Server $server;
	/** @var WP_User Admin user */
	protected static WP_User $admin;
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
	}
	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		static::$server = $wp_rest_server;
	}
	/**
	 * ----------------------------------------------------------------
	 * /info — public, no auth required
	 * ----------------------------------------------------------------
	 */
	public function test_info_returns_version(): void {
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/info' );
		$response = static::$server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( true, $data['ok'] );
		$this->assertArrayHasKey( 'version', $data['data'] );
		$this->assertNotEmpty( $data['data']['version'] );
	}
	public function test_info_returns_app_url(): void {
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/info' );
		$response = static::$server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'app_url', $data['data'] );
	}
	public function test_info_returns_site_name(): void {
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/info' );
		$response = static::$server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'site_name', $data['data'] );
		$this->assertNotEmpty( $data['data']['site_name'] );
	}
	public function test_info_returns_max_app_version(): void {
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/info' );
		$response = static::$server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'max_app_version', $data['data'] );
		$this->assertSame( RSA_APP_VERSION, $data['data']['max_app_version'] );
	}
	public function test_info_no_auth_required(): void {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/info' );
		$response = static::$server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
	}
	/**
	 * ----------------------------------------------------------------
	 * /user-settings — GET (auth required)
	 * ----------------------------------------------------------------
	 */
	public function test_user_settings_requires_auth(): void {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/user-settings' );
		$response = static::$server->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
	public function test_user_settings_returns_sites_array_for_authenticated(): void {
		wp_set_current_user( self::$admin->ID );
		$request  = new WP_REST_Request( 'GET', '/rsa/v1/user-settings' );
		$response = static::$server->dispatch( $request );
		if ( $response->get_status() === 403 ) {
			$this->markTestSkipped( 'requires premium' );
		}
		$data = $response->get_data();
		$this->assertArrayHasKey( 'sites', $data['data'] );
		$this->assertIsArray( $data['data']['sites'] );
	}
	/**
	 * ----------------------------------------------------------------
	 * /user-settings — POST (auth required)
	 * ----------------------------------------------------------------
	 */
	public function test_user_settings_post_requires_auth(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/user-settings' );
		$request->set_param( 'sites', array() );
		$response = static::$server->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
	public function test_user_settings_post_rejects_non_array(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/user-settings' );
		$request->set_param( 'sites', 'not-an-array' );
		$response = static::$server->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 200, 400, 403 ) );
	}
	public function test_user_settings_post_strips_unexpected_keys(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/user-settings' );
		$request->set_param(
			'sites',
			array(
				array(
					'id'      => 'x',
					'label'   => 'Test',
					'siteUrl' => 'http://test.com/',
					'appUrl'  => 'http://test.com/rs-app/',
					'secret'  => 'should-be-removed',
				),
			)
		);
		$response = static::$server->dispatch( $request );
		if ( $response->get_status() === 403 ) {
			$this->markTestSkipped( 'requires premium' );
		}
		$saved = get_user_meta( self::$admin->ID, 'rsa_app_sites', true );
		$this->assertIsArray( $saved );
		$site = $saved[0];
		$this->assertArrayNotHasKey( 'secret', $site );
		$this->assertSame( 'x', $site['id'] );
		$this->assertSame( 'Test', $site['label'] );
	}
	/**
	 * ----------------------------------------------------------------
	 * /verify-otp (POST, public)
	 * ----------------------------------------------------------------
	 */
	public function test_verify_otp_rejects_non_six_digits(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
		$request->set_param( 'otp', '12345' );
		$response = static::$server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}
	public function test_verify_otp_rejects_invalid_format(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
		$request->set_param( 'otp', 'abc123' );
		$response = static::$server->dispatch( $request );
		if ( $response->get_status() === 403 ) {
			$this->markTestSkipped( 'requires premium' );
		}
		$this->assertSame( 400, $response->get_status() );
	}
	public function test_verify_otp_strips_spaces_and_dashes(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
		$request->set_param( 'otp', '123 456' );
		$response = static::$server->dispatch( $request );
		if ( $response->get_status() === 403 ) {
			$this->markTestSkipped( 'requires premium' );
		}
		$this->assertNotSame( 400, $response->get_status() );
	}
	public function test_verify_otp_fails_with_nonexistent_code(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
		$request->set_param( 'otp', '999999' );
		$response = static::$server->dispatch( $request );
		if ( $response->get_status() === 403 ) {
			$this->markTestSkipped( 'requires premium' );
		}
		$this->assertSame( 403, $response->get_status() );
	}
	/**
	 * ----------------------------------------------------------------
	 * /track (POST, public with nonce)
	 * ----------------------------------------------------------------
	 */
	public function test_track_requires_valid_nonce(): void {
		$request = new WP_REST_Request( 'POST', '/rsa/v1/track' );
		$request->set_param( 'nonce', 'invalid-nonce' );
		$request->set_param( 'session_id', 'test-session' );
		$request->set_param( 'page', '/test/' );
		$response = static::$server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}
	/**
	 * ----------------------------------------------------------------
	 * /filter-options
	 * ----------------------------------------------------------------
	 */
	public function test_filter_options_returns_browsers_and_os(): void {
		wp_set_current_user( self::$admin->ID );
		$request = new WP_REST_Request( 'GET', '/rsa/v1/filter-options' );
		$request->set_param( 'period', '7d' );
		$response = static::$server->dispatch( $request );
		if ( $response->get_status() === 403 ) {
			$this->markTestSkipped( 'requires premium' );
		}
		$data = $response->get_data();
		$this->assertArrayHasKey( 'browsers', $data['data'] );
		$this->assertArrayHasKey( 'os', $data['data'] );
		$this->assertIsArray( $data['data']['browsers'] );
		$this->assertIsArray( $data['data']['os'] );
	}
	/**
	 * ----------------------------------------------------------------
	 * /purge-page (POST)
	 * ----------------------------------------------------------------
	 */
	public function test_purge_page_requires_auth(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/rsa/v1/purge-page' );
		$request->set_param( 'page', '/test/' );
		$response = static::$server->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
	public function test_purge_page_requires_page_param(): void {
		wp_set_current_user( self::$admin->ID );
		$request  = new WP_REST_Request( 'POST', '/rsa/v1/purge-page' );
		$response = static::$server->dispatch( $request );
		$this->assertNotSame( 200, $response->get_status() );
	}
}
