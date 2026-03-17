<?php
/**
 * Integration tests for RSA_Analytics.
 *
 * These tests require the WordPress integration environment
 * (WP_TESTS_DIR set) because they interact with $wpdb.
 *
 * @package RichStatistics\Tests
 */

class AnalyticsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install(); // ensure plugin tables exist after WP test bootstrap resets
	}

	// ----------------------------------------------------------------
	// period_range()
	// ----------------------------------------------------------------

	public function test_period_range_7d_spans_seven_days(): void {
		$range = RSA_Analytics::period_range( '7d' );
		$start = new DateTimeImmutable( $range['start'] );
		$end   = new DateTimeImmutable( $range['end'] );
		$diff  = $end->diff( $start );

		$this->assertLessThanOrEqual( 7, $diff->days );
		$this->assertGreaterThanOrEqual( 6, $diff->days );
	}

	public function test_period_range_30d_spans_thirty_days(): void {
		$range = RSA_Analytics::period_range( '30d' );
		$start = new DateTimeImmutable( $range['start'] );
		$end   = new DateTimeImmutable( $range['end'] );
		$diff  = $end->diff( $start );

		$this->assertGreaterThanOrEqual( 29, $diff->days );
		$this->assertLessThanOrEqual( 30, $diff->days );
	}

	public function test_period_range_returns_strings(): void {
		$range = RSA_Analytics::period_range( '90d' );
		$this->assertIsString( $range['start'] );
		$this->assertIsString( $range['end'] );
	}

	public function test_invalid_period_falls_back_to_30d(): void {
		$range = RSA_Analytics::period_range( 'invalid' );
		$start = new DateTimeImmutable( $range['start'] );
		$end   = new DateTimeImmutable( $range['end'] );
		$diff  = $end->diff( $start );
		$this->assertLessThanOrEqual( 30, $diff->days );
	}

	// ----------------------------------------------------------------
	// get_overview() — DB integration
	// ----------------------------------------------------------------

	public function test_get_overview_returns_expected_keys(): void {
		$result = RSA_Analytics::get_overview( '7d' );

		$this->assertArrayHasKey( 'pageviews',    $result );
		$this->assertArrayHasKey( 'sessions',     $result );
		$this->assertArrayHasKey( 'avg_time',     $result );
		$this->assertArrayHasKey( 'bounce_rate',  $result );
		$this->assertArrayHasKey( 'daily',        $result );
	}

	public function test_get_overview_returns_zero_with_no_data(): void {
		$result = RSA_Analytics::get_overview( '7d' );

		$this->assertSame( 0, (int) $result['pageviews'] );
		$this->assertSame( 0, (int) $result['sessions'] );
	}

	public function test_get_audience_returns_expected_keys(): void {
		$result = RSA_Analytics::get_audience( '7d' );

		$this->assertArrayHasKey( 'os',       $result );
		$this->assertArrayHasKey( 'browser',  $result );
		$this->assertArrayHasKey( 'viewport', $result );
		$this->assertArrayHasKey( 'language', $result );
		$this->assertArrayHasKey( 'timezone', $result );
	}

	public function test_get_top_pages_returns_array(): void {
		$result = RSA_Analytics::get_top_pages( '30d' );
		$this->assertIsArray( $result );
	}

	public function test_get_referrers_returns_array(): void {
		$result = RSA_Analytics::get_referrers( '30d' );
		$this->assertIsArray( $result );
	}

	public function test_get_behavior_returns_expected_keys(): void {
		$result = RSA_Analytics::get_behavior( '30d' );
		$this->assertArrayHasKey( 'time_histogram', $result );
		$this->assertArrayHasKey( 'session_depth',  $result );
		$this->assertArrayHasKey( 'entry_pages',    $result );
	}

	// ----------------------------------------------------------------
	// get_click_map() — structure and href_value field
	// ----------------------------------------------------------------

	public function test_get_click_map_returns_array(): void {
		$result = RSA_Analytics::get_click_map( '30d' );
		$this->assertIsArray( $result );
	}

	public function test_get_click_map_rows_have_expected_keys(): void {
		global $wpdb;

		// Insert a test click row with href_value populated
		$wpdb->insert(
			$wpdb->prefix . 'rsa_clicks',
			[
				'session_id'   => 'test-session-clickmap',
				'page'         => '/test-page/',
				'href_protocol' => 'mailto',
				'element_tag'  => 'A',
				'element_text' => 'Contact',
				'x_pct'        => 50,
				'y_pct'        => 25,
				'href_value'   => 'hello@example.com',
				'created_at'   => current_time( 'mysql' ),
			]
		);

		$result = RSA_Analytics::get_click_map( '30d' );

		// Filter to our test row
		$rows = array_filter( $result, fn( $r ) => $r['href_value'] === 'hello@example.com' );
		$this->assertNotEmpty( $rows, 'Expected to find row with href_value=hello@example.com' );

		$row = reset( $rows );
		$this->assertArrayHasKey( 'tag',        $row );
		$this->assertArrayHasKey( 'protocol',   $row );
		$this->assertArrayHasKey( 'text',       $row );
		$this->assertArrayHasKey( 'clicks',     $row );
		$this->assertArrayHasKey( 'href_value', $row );
		$this->assertSame( 'hello@example.com', $row['href_value'] );

		// Cleanup
		$wpdb->delete( $wpdb->prefix . 'rsa_clicks', [ 'session_id' => 'test-session-clickmap' ] );
	}
}
