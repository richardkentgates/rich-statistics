<?php
/**
 * Integration tests for RSA_Analytics export methods.
 *
 * @package RichStatistics\Tests
 */
class AnalyticsExportTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}

	/**
	 * ----------------------------------------------------------------
	 * export_events() backward compat — delegates to export_data('pageviews')
	 * ----------------------------------------------------------------
	 */
	public function test_export_events_delegates_to_export_data(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$result = RSA_Analytics::export_events( '7d', 'json' );
		$this->assertSame( '[]', $result );
	}

	/**
	 * ----------------------------------------------------------------
	 * export_data() — empty data
	 * ----------------------------------------------------------------
	 */
	public function test_export_data_pageviews_json_empty(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$result = RSA_Analytics::export_data( 'pageviews', '7d', 'json' );
		$this->assertSame( '[]', $result );
	}

	public function test_export_data_sessions_csv_empty(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_sessions`" );
		$result = RSA_Analytics::export_data( 'sessions', '7d', 'csv' );
		// CSV returns BOM + headers even when there are no rows.
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $result );
		$this->assertStringContainsString( 'session_id', $result );
		$this->assertStringContainsString( 'entry_page', $result );
	}

	/**
	 * ----------------------------------------------------------------
	 * export_data() — with seeded data
	 * ----------------------------------------------------------------
	 */
	public function test_export_data_pageviews_json_with_data(): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_events',
			array(
				'session_id' => 'test-export-session',
				'page'       => '/export-test/',
				'bot_score'  => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$result = RSA_Analytics::export_data( 'pageviews', '7d', 'json' );
		$data   = json_decode( $result, true );
		$this->assertIsArray( $data );
		$this->assertCount( 1, $data );
		$this->assertSame( '/export-test/', $data[0]['page'] );
		$wpdb->delete( $wpdb->prefix . 'rsa_events', array( 'session_id' => 'test-export-session' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_export_data_sessions_csv_with_data(): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_sessions',
			array(
				'session_id'   => 'test-export-session',
				'entry_page'   => '/entry/',
				'exit_page'    => '/exit/',
				'pages_viewed' => 3,
				'total_time'   => 120,
				'browser'      => 'TestBrowser',
				'os'           => 'TestOS',
				'language'     => 'en',
				'timezone'     => 'UTC',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$result = RSA_Analytics::export_data( 'sessions', '7d', 'csv' );
		$this->assertStringContainsString( 'session_id', $result );
		$this->assertStringContainsString( 'TestBrowser', $result );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $result );
		$wpdb->delete( $wpdb->prefix . 'rsa_sessions', array( 'session_id' => 'test-export-session' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
