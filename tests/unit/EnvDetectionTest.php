<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class EnvDetectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function detect_env( string $site_url ): string {
		$host = wp_parse_url( $site_url, PHP_URL_HOST );
		if ( ! $host ) {
			return 'production';
		}
		if ( str_contains( $host, 'rs-dev.' ) || str_contains( $host, 'localhost' ) || str_contains( $host, '127.0.0.1' ) ) {
			return 'development';
		}
		if ( str_contains( $host, 'rs-test.' ) ) {
			return 'test';
		}
		return 'production';
	}

	private function detect_url( string $site_url ): string {
		$env = $this->detect_env( $site_url );
		if ( 'development' === $env ) {
			return 'https://rs-dev.richardkentgates.com/';
		}
		if ( 'test' === $env ) {
			return 'https://rs-test.richardkentgates.com/';
		}
		return 'https://rs-app.richardkentgates.com/';
	}

	public function test_production_site_returns_production_env(): void {
		$this->assertSame( 'production', $this->detect_env( 'https://example.com' ) );
	}

	public function test_production_pwa_domain_returns_production_env(): void {
		$this->assertSame( 'production', $this->detect_env( 'https://rs-app.richardkentgates.com' ) );
	}

	public function test_dev_subdomain_returns_development_env(): void {
		$this->assertSame( 'development', $this->detect_env( 'https://rs-dev.richardkentgates.com' ) );
	}

	public function test_localhost_returns_development_env(): void {
		$this->assertSame( 'development', $this->detect_env( 'http://localhost' ) );
	}

	public function test_local_ip_returns_development_env(): void {
		$this->assertSame( 'development', $this->detect_env( 'http://127.0.0.1' ) );
	}

	public function test_test_subdomain_returns_test_env(): void {
		$this->assertSame( 'test', $this->detect_env( 'https://rs-test.richardkentgates.com' ) );
	}

	public function test_empty_host_returns_production(): void {
		$this->assertSame( 'production', $this->detect_env( '' ) );
	}

	public function test_app_url_matches_env_production(): void {
		$url = $this->detect_url( 'https://example.com' );
		$this->assertSame( 'https://rs-app.richardkentgates.com/', $url );
	}

	public function test_app_url_matches_env_development(): void {
		$url = $this->detect_url( 'https://rs-dev.richardkentgates.com' );
		$this->assertSame( 'https://rs-dev.richardkentgates.com/', $url );
	}

	public function test_app_url_matches_env_test(): void {
		$url = $this->detect_url( 'https://rs-test.richardkentgates.com' );
		$this->assertSame( 'https://rs-test.richardkentgates.com/', $url );
	}
}
