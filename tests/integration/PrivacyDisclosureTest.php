<?php
/**
 * Integration tests for RSA_Privacy_Disclosure shortcode.
 *
 * @package RichStatistics\Tests
 */
class PrivacyDisclosureTest extends WP_UnitTestCase {

	public function test_shortcode_returns_html(): void {
		$output = do_shortcode( '[rich_statistics_privacy_disclosure]' );
		$this->assertStringContainsString( 'rsa-privacy-disclosure', $output );
	}

	public function test_shortcode_contains_site_name(): void {
		$output = do_shortcode( '[rich_statistics_privacy_disclosure]' );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $output );
	}

	public function test_shortcode_contains_data_table(): void {
		$output = do_shortcode( '[rich_statistics_privacy_disclosure]' );
		$this->assertStringContainsString( 'Session ID', $output );
		$this->assertStringContainsString( 'Pages Viewed', $output );
	}

	public function test_shortcode_contains_rights_section(): void {
		$output = do_shortcode( '[rich_statistics_privacy_disclosure]' );
		$this->assertStringContainsString( 'Your Rights', $output );
	}
}
