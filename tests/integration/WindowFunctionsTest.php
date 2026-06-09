<?php
/**
 * Window Functions Test
 *
 * Tests mysql_supports_window_functions() detection and graceful fallback.
 *
 * @package RichStatistics\Tests
 */
class WindowFunctionsTest extends WP_UnitTestCase {

	public function test_mysql_supports_window_functions_returns_bool(): void {
		$method = new ReflectionMethod( RSA_Analytics::class, 'mysql_supports_window_functions' );
		$method->setAccessible( true );
		$result = $method->invoke( null );
		$this->assertIsBool( $result );
	}

	public function test_get_user_flow_returns_error_on_old_mysql(): void {
		// If window functions are not supported, get_user_flow returns an error array.
		$method = new ReflectionMethod( RSA_Analytics::class, 'mysql_supports_window_functions' );
		$method->setAccessible( true );
		$supported = $method->invoke( null );

		if ( ! $supported ) {
			$result = RSA_Analytics::get_user_flow( '30d' );
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'error', $result );
		} else {
			$this->markTestSkipped( 'Window functions are supported on this environment — skip negative test.' );
		}
	}

	public function test_get_path_flow_returns_error_on_old_mysql(): void {
		$method = new ReflectionMethod( RSA_Analytics::class, 'mysql_supports_window_functions' );
		$method->setAccessible( true );
		$supported = $method->invoke( null );

		if ( ! $supported ) {
			$result = RSA_Analytics::get_path_flow( '30d' );
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'error', $result );
		} else {
			$this->markTestSkipped( 'Window functions are supported — skip negative test.' );
		}
	}

	public function test_get_user_flow_returns_array_on_supported_mysql(): void {
		$method = new ReflectionMethod( RSA_Analytics::class, 'mysql_supports_window_functions' );
		$method->setAccessible( true );
		$supported = $method->invoke( null );

		if ( $supported ) {
			RSA_DB::install();
			global $wpdb;
			// Seed two events with same session_id for flow detection.
			$sid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				$wpdb->prefix . 'rsa_events',
				[
					'session_id' => $sid,
					'page'       => '/page-a/',
					'created_at' => $now,
				],
				[ '%s', '%s', '%s' ]
			);
			$wpdb->insert(
				$wpdb->prefix . 'rsa_events',
				[
					'session_id' => $sid,
					'page'       => '/page-b/',
					'created_at' => $now,
				],
				[ '%s', '%s', '%s' ]
			);
			$result = RSA_Analytics::get_user_flow( '30d' );
			$this->assertIsArray( $result );
			// get_user_flow returns array of row objects directly (not wrapped).
			// If no transitions are found, returns empty array.
			$this->assertIsArray( $result );
		} else {
			$this->markTestSkipped( 'Window functions not supported — skip positive test.' );
		}
	}
}
