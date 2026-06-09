<?php
/**
 * Integration tests for RSA CLI analytics functions.
 *
 * RSA_CLI extends WP_CLI_Command which is only available when WP-CLI is installed.
 * These tests validate the analytics functions that RSA_CLI commands call,
 * ensuring the CLI commands will work correctly when WP-CLI is available.
 *
 * @package RichStatistics\Tests
 */
class CLITest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}
	private function seed_event( array $overrides = array() ): int {
		global $wpdb;
		$sid      = 'cli-test-' . uniqid();
		$defaults = array(
			'session_id'      => $sid,
			'page'            => '/test-page/',
			'referrer_domain' => 'google.com',
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'os'              => 'Linux',
			'browser'         => 'Chrome',
			'browser_version' => '124.0',
			'language'        => 'en-US',
			'timezone'        => 'America/New_York',
			'viewport_w'      => 1920,
			'viewport_h'      => 963,
			'time_on_page'    => 30,
			'bot_score'       => 0,
			'utm_source'      => '',
			'utm_medium'      => '',
			'utm_campaign'    => '',
		);
		$data     = array_merge( $defaults, $overrides );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			RSA_DB::events_table(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}
	private function seed_session( string $sid = '', array $overrides = array() ): string {
		global $wpdb;
		$sid      = $sid ? $sid : 'cli-session-' . uniqid();
		$defaults = array(
			'session_id'   => $sid,
			'pages_viewed' => 1,
			'entry_page'   => '/test/',
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'os'           => 'Linux',
			'browser'      => 'Chrome',
			'language'     => 'en-US',
			'timezone'     => 'America/New_York',
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			RSA_DB::sessions_table(),
			array_merge( $defaults, $overrides ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $sid;
	}
	/**
	 * -------------------------------------------------------------------------
	 * Tests for analytics functions used by CLI commands
	 * -------------------------------------------------------------------------
	 */
	public function test_get_overview_returns_required_keys(): void {
		$data = RSA_Analytics::get_overview( '30d' );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'pageviews', $data );
		$this->assertArrayHasKey( 'sessions', $data );
		$this->assertArrayHasKey( 'avg_time', $data );
		$this->assertArrayHasKey( 'bounce_rate', $data );
	}
	public function test_get_overview_counts_events_and_sessions(): void {
		$sid = $this->seed_session();
		$this->seed_event( array( 'session_id' => $sid ) );
		$this->seed_event( array( 'session_id' => $sid ) );
		$this->seed_event( array( 'session_id' => $sid ) );
		$data = RSA_Analytics::get_overview( '30d' );
		$this->assertSame( 3, $data['pageviews'] );
		$this->assertGreaterThanOrEqual( 1, $data['sessions'] );
	}
	public function test_get_top_pages_returns_page_data(): void {
		$this->seed_event( array( 'page' => '/page-1/' ) );
		$this->seed_event( array( 'page' => '/page-1/' ) );
		$this->seed_event( array( 'page' => '/page-2/' ) );
		$rows = RSA_Analytics::get_top_pages( '30d', 10 );
		$this->assertNotEmpty( $rows );
		$this->assertArrayHasKey( 'page', $rows[0] );
		$this->assertArrayHasKey( 'views', $rows[0] );
	}
	public function test_get_top_pages_respects_limit(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed_event( array( 'page' => "/page-{$i}/" ) );
		}
		$rows = RSA_Analytics::get_top_pages( '30d', 3 );
		$this->assertCount( 3, $rows );
	}
	public function test_get_audience_returns_os_browser_language(): void {
		$this->seed_event(
			array(
				'os'       => 'Windows',
				'browser'  => 'Firefox',
				'language' => 'en-US',
			)
		);
		$this->seed_event(
			array(
				'os'       => 'Mac',
				'browser'  => 'Safari',
				'language' => 'de-DE',
			)
		);
		$data = RSA_Analytics::get_audience( '30d' );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'os', $data );
		$this->assertArrayHasKey( 'browser', $data );
		$this->assertArrayHasKey( 'language', $data );
	}
	public function test_export_data_pageviews_returns_valid_json(): void {
		$this->seed_event();
		$data = RSA_Analytics::export_data( 'pageviews', '30d', 'json' );
		$this->assertIsString( $data );
		$decoded = json_decode( $data, true );
		$this->assertNotNull( $decoded );
		$this->assertIsArray( $decoded );
	}
	public function test_export_data_pageviews_returns_valid_csv(): void {
		$this->seed_event();
		$data = RSA_Analytics::export_data( 'pageviews', '30d', 'csv' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'session_id', $data );
		$this->assertStringContainsString( 'page', $data );
	}
	public function test_export_data_pageviews_includes_all_columns(): void {
		$this->seed_event();
		$data          = RSA_Analytics::export_data( 'pageviews', '30d', 'csv' );
		$expected_cols = array( 'session_id', 'page', 'referrer_domain', 'os', 'browser', 'created_at' );
		foreach ( $expected_cols as $col ) {
			$this->assertStringContainsString( $col, $data, "CSV should include '$col' column" );
		}
	}
	public function test_prune_removes_old_events(): void {
		global $wpdb;
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) );
		$this->seed_event(
			array(
				'created_at' => $old_date,
				'session_id' => 'old-event-001',
			)
		);
		$et     = RSA_DB::events_table();
		$before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$et}` WHERE session_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'old-event-001'
			)
		);
		$this->assertSame( 1, $before );
		$deleted = RSA_DB::prune_old_data( 90 );
		$after   = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$et}` WHERE session_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'old-event-001'
			)
		);
		$this->assertSame( 0, $after );
	}
	public function test_prune_respects_custom_retention_days(): void {
		global $wpdb;
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$this->seed_event(
			array(
				'created_at' => $old_date,
				'session_id' => 'recent-event-001',
			)
		);
		$et = RSA_DB::events_table();
		RSA_DB::prune_old_data( 30 );
		$after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$et}` WHERE session_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'recent-event-001'
			)
		);
		$this->assertSame( 1, $after );
	}
	public function test_email_send_digest_succeeds(): void {
		$result = RSA_Email::send_digest( '30d' );
		$this->assertTrue( $result );
	}
	public function test_period_range_validates_custom_dates(): void {
		$range = RSA_Analytics::period_range( 'custom', '2024-01-01', '2024-01-31' );
		$this->assertArrayHasKey( 'start', $range );
		$this->assertArrayHasKey( 'end', $range );
		$this->assertSame( '2024-01-01 00:00:00', $range['start'] );
		$this->assertSame( '2024-01-31 23:59:59', $range['end'] );
	}
	public function test_period_range_defaults_to_30d(): void {
		$range = RSA_Analytics::period_range( 'invalid' );
		$this->assertArrayHasKey( 'start', $range );
		$this->assertArrayHasKey( 'end', $range );
	}
	public function test_cli_status_info_is_available(): void {
		$this->assertNotEmpty( RSA_VERSION );
		$this->assertNotEmpty( RSA_MIN_WP );
		$this->assertNotEmpty( RSA_MIN_PHP );
	}
	/**
	 * -------------------------------------------------------------------------
	 * Verify RSA_CLI class exists and has expected methods (if WP_CLI available)
	 * -------------------------------------------------------------------------
	 */
	public function test_cli_class_available_when_wp_cli_present(): void {
		if ( ! class_exists( 'WP_CLI_Command' ) ) {
			$this->markTestSkipped( 'WP_CLI_Command not available in test environment' );
		}
		$this->assertTrue( class_exists( 'RSA_CLI' ) );
	}
}
