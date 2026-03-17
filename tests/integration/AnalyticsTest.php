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
	// fill_date_gaps()
	// ----------------------------------------------------------------

	public function test_fill_date_gaps_inserts_missing_days(): void {
		$input = [
			[ 'date' => '2025-01-01', 'pageviews' => 5, 'sessions' => 3 ],
			[ 'date' => '2025-01-05', 'pageviews' => 8, 'sessions' => 6 ],
		];
		$filled = RSA_Analytics::fill_date_gaps( $input, '2025-01-01', '2025-01-05' );

		// Should have an entry for each of the 5 days
		$this->assertCount( 5, $filled );
		$dates = array_column( $filled, 'date' );
		$this->assertContains( '2025-01-01', $dates );
		$this->assertContains( '2025-01-02', $dates );
		$this->assertContains( '2025-01-03', $dates );
		$this->assertContains( '2025-01-04', $dates );
		$this->assertContains( '2025-01-05', $dates );
	}

	public function test_fill_date_gaps_preserves_existing_data(): void {
		$input = [
			[ 'date' => '2025-06-01', 'pageviews' => 100, 'sessions' => 50 ],
				[ 'date' => '2025-06-03', 'pageviews' => 200, 'sessions' => 80 ],
		];
		$filled = RSA_Analytics::fill_date_gaps( $input, '2025-06-01', '2025-06-03' );
		$by_date = array_column( $filled, null, 'date' );

		$this->assertSame( 100, (int) $by_date['2025-06-01']['pageviews'] );
		$this->assertSame( 200, (int) $by_date['2025-06-03']['pageviews'] );
		$this->assertSame( 0,   (int) $by_date['2025-06-02']['pageviews'] );
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

		$this->assertArrayHasKey( 'by_os',       $result );
		$this->assertArrayHasKey( 'by_browser',  $result );
		$this->assertArrayHasKey( 'by_viewport', $result );
		$this->assertArrayHasKey( 'by_language', $result );
		$this->assertArrayHasKey( 'by_timezone', $result );
	}

	public function test_get_top_pages_returns_array(): void {
		$result = RSA_Analytics::get_top_pages( '30d' );
		$this->assertArrayHasKey( 'pages', $result );
		$this->assertIsArray( $result['pages'] );
	}

	public function test_get_referrers_returns_array(): void {
		$result = RSA_Analytics::get_referrers( '30d' );
		$this->assertArrayHasKey( 'referrers', $result );
		$this->assertIsArray( $result['referrers'] );
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
