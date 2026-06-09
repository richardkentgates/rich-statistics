<?php
/**
 * REST CORS Origin Test
 *
 * Tests CORS origin validation and fix_cors_origin filter.
 *
 * @package RichStatistics\Tests
 */
class RestCorsTest extends WP_UnitTestCase {

	public function test_allowed_cors_origins_list(): void {
		$method = new ReflectionMethod( RSA_Rest_API::class, 'allowed_cors_origins' );
		$method->setAccessible( true );
		$origins = $method->invoke( null );
		$this->assertIsArray( $origins );
		$this->assertContains( home_url(), $origins );
		$this->assertContains( 'https://app.richstatistics.com', $origins );
		$this->assertContains( 'https://dev.richstatistics.com', $origins );
		$this->assertContains( 'https://test.richstatistics.com', $origins );
		$this->assertContains( 'tauri://localhost', $origins );
	}

	public function test_fix_cors_origin_adds_header_for_allowed_origin(): void {
		$method = new ReflectionMethod( RSA_Rest_API::class, 'fix_cors_origin' );
		$method->setAccessible( true );

		$_SERVER['HTTP_ORIGIN'] = 'https://app.richstatistics.com';

		$request  = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$response = new WP_REST_Response( [] );
		$server   = new WP_REST_Server();

		// The method should return null (unchanged $served).
		// Suppress header warnings — PHPUnit has already started output.
		$result = @$method->invoke( null, null, $response, $request, $server );
		$this->assertNull( $result );
	}

	public function test_fix_cors_origin_skips_non_rsa_routes(): void {
		$method = new ReflectionMethod( RSA_Rest_API::class, 'fix_cors_origin' );
		$method->setAccessible( true );

		$_SERVER['HTTP_ORIGIN'] = 'https://app.richstatistics.com';

		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = new WP_REST_Response( [] );
		$server   = new WP_REST_Server();

		$result = $method->invoke( null, null, $response, $request, $server );
		$this->assertNull( $result );
	}

	public function test_fix_cors_origin_allows_tauri_scheme(): void {
		$method = new ReflectionMethod( RSA_Rest_API::class, 'fix_cors_origin' );
		$method->setAccessible( true );

		$_SERVER['HTTP_ORIGIN'] = 'tauri://localhost';

		$request  = new WP_REST_Request( 'GET', '/rsa/v1/overview' );
		$response = new WP_REST_Response( [] );
		$server   = new WP_REST_Server();

		// Suppress header warnings — PHPUnit has already started output.
		$result = @$method->invoke( null, null, $response, $request, $server );
		$this->assertNull( $result );
	}

	public function test_add_cors_headers_skips_non_rsa_routes(): void {
		$method = new ReflectionMethod( RSA_Rest_API::class, 'add_cors_headers' );
		$method->setAccessible( true );

		$_SERVER['REQUEST_URI'] = '/wp/v2/posts';
		$_SERVER['HTTP_ORIGIN'] = 'https://app.richstatistics.com';

		// Should return without adding headers (route doesn't match rsa/v1).
		$result = $method->invoke( null );
		$this->assertNull( $result );
	}
}
