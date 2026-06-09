<?php
/**
 * Integration tests for RSA_Pwa_Download — OTP generation and verification.
 *
 * @package RichStatistics\Tests
 */
class PwaDownloadTest extends WP_UnitTestCase {

	/** @var WP_User */
	protected static WP_User $admin;
	/** @var WP_User */
	protected static WP_User $subscriber;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin      = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
		self::$subscriber = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
	}

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}

	public function test_generate_otp_creates_valid_code(): void {
		wp_set_current_user( self::$admin->ID );
		$otp = RSA_Pwa_Download::generate_otp( self::$admin->ID );

		$this->assertSame( 6, strlen( $otp ) );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $otp );
	}

	public function test_generate_otp_stores_transient(): void {
		wp_set_current_user( self::$admin->ID );
		$otp = RSA_Pwa_Download::generate_otp( self::$admin->ID );

		$transient = get_transient( 'rsa_otp_' . hash( 'sha256', $otp ) );
		$this->assertIsArray( $transient );
		$this->assertSame( self::$admin->ID, $transient['user_id'] );
		$this->assertNotEmpty( $transient['username'] );
		$this->assertNotEmpty( $transient['site_url'] );
	}

	public function test_verify_otp_returns_site_data(): void {
		wp_set_current_user( self::$admin->ID );
		$otp = RSA_Pwa_Download::generate_otp( self::$admin->ID );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
		$request->set_param( 'otp', $otp );
		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'site_url', $data['data'] );
		$this->assertArrayHasKey( 'username', $data['data'] );
	}

	public function test_verify_otp_consumes_code(): void {
		wp_set_current_user( self::$admin->ID );
		$otp = RSA_Pwa_Download::generate_otp( self::$admin->ID );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		// First verification succeeds.
		$request = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
		$request->set_param( 'otp', $otp );
		$wp_rest_server->dispatch( $request );

		// Second verification fails (consumed).
		$request2 = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
		$request2->set_param( 'otp', $otp );
		$response2 = $wp_rest_server->dispatch( $request2 );

		$this->assertSame( 403, $response2->get_status() );
	}

	public function test_verify_otp_rate_limits_wrong_attempts(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		// 6 wrong attempts.
		for ( $i = 0; $i < 6; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/rsa/v1/verify-otp' );
			$request->set_param( 'otp', '000000' );
			$response = $wp_rest_server->dispatch( $request );
		}

		$this->assertSame( 429, $response->get_status() );
	}
}
