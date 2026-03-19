<?php
/**
 * Unit tests for click-tracking related constants and sanitization logic.
 *
 * These tests do NOT require a WordPress installation because they verify
 * pure PHP behaviour: DB constants and the sanitization/clamping patterns
 * used inside RSA_Click_Tracking::handle_click().
 *
 * @package RichStatistics\Tests
 */

use PHPUnit\Framework\TestCase;

class ClickTrackingTest extends TestCase {

	// ----------------------------------------------------------------
	// RSA_DB::SCHEMA_VERSION — bump this comment when the version changes
	// v8: utm_source, utm_medium, utm_campaign columns on rsa_events
	// v9: woocommerce integration tables (rsa_wc_events)
	// ----------------------------------------------------------------

	public function test_schema_version_constant(): void {
		$this->assertSame( 9, RSA_DB::SCHEMA_VERSION );
	}

	public function test_option_key_constant(): void {
		$this->assertSame( 'rsa_db_version', RSA_DB::OPTION_KEY );
	}

	// ----------------------------------------------------------------
	// href_value sanitization — mirrors handle_click() logic
	// (sanitize_text_field + substr(…, 0, 512))
	// ----------------------------------------------------------------

	/**
	 * Reproduce the sanitization that handle_click() applies to href_value.
	 *
	 * @param string $raw Raw input value
	 * @return string|null Sanitized value, or null when empty
	 */
	private function sanitize_href_value( string $raw ): ?string {
		$v = substr( sanitize_text_field( $raw ), 0, 512 );
		return $v ?: null;
	}

	public function test_plain_phone_number_passes_through(): void {
		$this->assertSame( '+15551234567', $this->sanitize_href_value( '+15551234567' ) );
	}

	public function test_email_address_passes_through(): void {
		$this->assertSame( 'hello@example.com', $this->sanitize_href_value( 'hello@example.com' ) );
	}

	public function test_url_passes_through(): void {
		$url = 'https://example.com/file.pdf';
		$this->assertSame( $url, $this->sanitize_href_value( $url ) );
	}

	public function test_html_tags_are_stripped(): void {
		$result = $this->sanitize_href_value( '<script>alert(1)</script>+15559876543' );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '+15559876543', $result );
	}

	public function test_long_value_is_truncated_to_512_chars(): void {
		$long   = str_repeat( 'a', 600 );
		$result = $this->sanitize_href_value( $long );
		$this->assertSame( 512, strlen( $result ) );
	}

	public function test_exactly_512_chars_is_not_truncated(): void {
		$exact  = str_repeat( 'b', 512 );
		$result = $this->sanitize_href_value( $exact );
		$this->assertSame( 512, strlen( $result ) );
	}

	public function test_empty_string_returns_null(): void {
		$this->assertNull( $this->sanitize_href_value( '' ) );
	}

	public function test_whitespace_only_returns_null(): void {
		// sanitize_text_field trims; empty string after trim → null
		$this->assertNull( $this->sanitize_href_value( '   ' ) );
	}

	// ----------------------------------------------------------------
	// x_pct / y_pct clamping — mirrors handle_click() clamp logic
	// ----------------------------------------------------------------

	/**
	 * Reproduce the x/y percentage clamping from handle_click().
	 */
	private function clamp_pct( mixed $raw ): float {
		return round( min( 100, max( 0, (float) $raw ) ), 2 );
	}

	public function test_pct_normal_value_unchanged(): void {
		$this->assertSame( 42.5, $this->clamp_pct( '42.5' ) );
	}

	public function test_pct_negative_clamped_to_zero(): void {
		$this->assertSame( 0.0, $this->clamp_pct( -10 ) );
	}

	public function test_pct_above_hundred_clamped_to_hundred(): void {
		$this->assertSame( 100.0, $this->clamp_pct( 150 ) );
	}

	public function test_pct_string_zero_is_zero(): void {
		$this->assertSame( 0.0, $this->clamp_pct( '0' ) );
	}

	public function test_pct_non_numeric_string_is_zero(): void {
		$this->assertSame( 0.0, $this->clamp_pct( 'abc' ) );
	}
}
