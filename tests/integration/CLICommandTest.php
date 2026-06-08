<?php
/**
 * Integration tests for RSA_CLI — basic command invocation.
 *
 * WP_CLI functions are stubbed in tests/bootstrap.php for integration mode.
 *
 * @package RichStatistics\Tests
 */
class CLICommandTest extends WP_UnitTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'WP_CLI' ) ) {
			// Define minimal WP_CLI stubs if not already present.
			class_alias( 'WP_CLI_Stub', 'WP_CLI' );
		}
	}

	public function test_cli_class_exists(): void {
		$this->assertTrue( class_exists( 'RSA_CLI' ) );
	}

	public function test_validate_period_returns_default(): void {
		$cli = new RSA_CLI();
		$method = new ReflectionMethod( $cli, 'validate_period' );
		$method->setAccessible( true );

		$this->assertSame( '30d', $method->invoke( $cli, '' ) );
		$this->assertSame( '7d', $method->invoke( $cli, '7d' ) );
		$this->assertSame( '90d', $method->invoke( $cli, '90d' ) );
		$this->assertSame( '30d', $method->invoke( $cli, 'invalid' ) );
	}

	public function test_format_seconds_boundaries(): void {
		$cli = new RSA_CLI();
		$method = new ReflectionMethod( $cli, 'format_seconds' );
		$method->setAccessible( true );

		$this->assertSame( '0s', $method->invoke( $cli, 0 ) );
		$this->assertSame( '1m 0s', $method->invoke( $cli, 60 ) );
		$this->assertSame( '60m 0s', $method->invoke( $cli, 3600 ) );
		$this->assertSame( '1440m 0s', $method->invoke( $cli, 86400 ) );
	}
}
