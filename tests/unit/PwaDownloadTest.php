<?php
/**
 * Unit tests for RSA_Pwa_Download — method signatures and constants.
 *
 * Pure static analysis + direct invocation; no WordPress functions used.
 * Class loaded in setUp() after Brain\Monkey bootstrap setup.
 *
 * @package RichStatistics\Tests
 */

use PHPUnit\Framework\TestCase;

class PwaDownloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once RSA_DIR . 'includes/class-pwa-download.php';
	}

	public function test_init_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Pwa_Download::class, 'init' ) );
	}

	public function test_init_is_public_static(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'init' );
		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_generate_otp_is_public_static(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'generate_otp' );
		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_generate_otp_accepts_int_user_id_parameter(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'generate_otp' );
		$params = $method->getParameters();
		$this->assertCount( 1, $params );
		$this->assertSame( 'user_id', $params[0]->getName() );
		$this->assertSame( 'int', $params[0]->getType()->getName() );
	}

	public function test_generate_otp_returns_string_type(): void {
		$method      = new ReflectionMethod( RSA_Pwa_Download::class, 'generate_otp' );
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type );
		$this->assertSame( 'string', $return_type->getName() );
	}

	public function test_generate_otp_docblock_indicates_six_digit_return(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'generate_otp' );
		$doc    = $method->getDocComment();
		$this->assertStringContainsString( '6-digit', $doc );
	}

	public function test_handle_generate_otp_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Pwa_Download::class, 'handle_generate_otp' ) );
	}

	public function test_handle_generate_otp_is_public_static(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'handle_generate_otp' );
		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_handle_download_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Pwa_Download::class, 'handle_download' ) );
	}

	public function test_handle_download_is_public_static(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'handle_download' );
		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_stream_zip_is_private(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'stream_zip' );
		$this->assertTrue( $method->isPrivate() );
	}

	public function test_issue_token_is_private(): void {
		$method = new ReflectionMethod( RSA_Pwa_Download::class, 'issue_token' );
		$this->assertTrue( $method->isPrivate() );
	}
}
