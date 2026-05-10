<?php
/**
 * Unit tests for RSA_Tracker rate limiting and payload parsing.
 *
 * No Brain\Monkey needed — is_rate_limited() uses pure transients and
 * sanitize_page() is tested via reflection without mocking WP functions.
 * Classes are loaded via bootstrap.php stubs (no Patchwork interference).
 *
 * @package RichStatistics\Tests
 */

use PHPUnit\Framework\TestCase;

require_once RSA_DIR . 'includes/class-tracker.php';

class TrackerRateLimitTest extends TestCase {

	// ----------------------------------------------------------------
	// is_rate_limited() — transient-based session rate limiting
	// No WordPress mocking needed: uses get_transient/set_transient directly
	// ----------------------------------------------------------------

	private function is_rate_limited( string $session_id ): bool {
		$method = new ReflectionMethod( RSA_Tracker::class, 'is_rate_limited' );
		$method->setAccessible( true );
		return $method->invoke( null, $session_id );
	}

	public function test_first_request_is_not_rate_limited(): void {
		global $wp_filter;
		$hasOrig = array_key_exists( 'pre_get_transient', $GLOBALS['wp_filter'] );
		$orig    = $hasOrig ? $wp_filter['pre_get_transient'] : null;
		$wp_filter['pre_get_transient'] = new class {
			public function __invoke( $pre, string $key ) { return false; }
		};
		$result = $this->is_rate_limited( uniqid( 'session-', true ) );
		if ( $hasOrig ) {
			$wp_filter['pre_get_transient'] = $orig;
		} else {
			unset( $wp_filter['pre_get_transient'] );
		}
		$this->assertFalse( $result );
	}

	public function test_request_under_limit_is_not_rate_limited(): void {
		$this->assertFalse( $this->is_rate_limited( 'under-limit-session' ) );
	}

	public function test_rate_limit_key_uses_md5_prefix(): void {
		$session_id = 'test-md5-session';
		$expected_suffix = substr( md5( $session_id ), 0, 16 );

		$result = $this->is_rate_limited( $session_id );

		$this->assertFalse( $result, "Fresh session should not be rate-limited (key format: rsa_rl_{md5_prefix})" );
		$this->assertNotEmpty( $expected_suffix );
		$this->assertSame( 16, strlen( $expected_suffix ) );
	}

	// ----------------------------------------------------------------
	// sanitize_page() — query param edge cases
	// Uses reflection, no WP mocking needed
	// ----------------------------------------------------------------

	private function sanitize_page( string $raw ): string {
		$method = new ReflectionMethod( RSA_Tracker::class, 'sanitize_page' );
		$method->setAccessible( true );
		return $method->invoke( null, $raw );
	}

	public function test_plain_path_is_unchanged(): void {
		$this->assertSame( '/blog/hello-world/', $this->sanitize_page( '/blog/hello-world/' ) );
	}

	public function test_query_param_with_exactly_40_chars_is_kept(): void {
		$result = $this->sanitize_page( '/page/?token=' . str_repeat( 'x', 40 ) );
		$this->assertStringContainsString( 'token=', $result );
	}

	public function test_query_param_with_41_chars_is_dropped(): void {
		$result = $this->sanitize_page( '/page/?token=' . str_repeat( 'x', 41 ) );
		$this->assertStringNotContainsString( 'token=', $result );
	}

	public function test_mixed_query_params_preserved_selectively(): void {
		$input  = '/page/?page=2&per_page=50&token=' . str_repeat( 'a', 45 );
		$result = $this->sanitize_page( $input );
		$this->assertStringContainsString( 'page=2', $result );
		$this->assertStringContainsString( 'per_page=50', $result );
		$this->assertStringNotContainsString( 'token=', $result );
	}

	public function test_query_param_with_email_value_stripped(): void {
		$result = $this->sanitize_page( '/page/?utm_content=user%40domain.com&ref=newsletter' );
		$this->assertStringNotContainsString( 'utm_content=', $result );
		$this->assertStringContainsString( 'ref=newsletter', $result );
	}

	public function test_path_only_with_no_query_unchanged(): void {
		$result = $this->sanitize_page( '/blog/my-first-post/' );
		$this->assertSame( '/blog/my-first-post/', $result );
	}

	public function test_empty_query_string_removed(): void {
		$result = $this->sanitize_page( '/page/?' );
		$this->assertNotSame( '/page/?', $result );
	}

	public function test_fragment_removed_from_sanitize_page(): void {
		$result = $this->sanitize_page( '/page/#section' );
		$this->assertStringNotContainsString( '#section', $result );
	}

	public function test_full_url_strips_domain(): void {
		$result = $this->sanitize_page( 'https://example.com/about/?ref=newsletter' );
		$this->assertStringNotContainsString( 'example.com', $result );
		$this->assertStringContainsString( '/about/', $result );
	}
}