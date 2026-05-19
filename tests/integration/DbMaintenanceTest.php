<?php
/**
 * Integration tests for RSA_DB maintenance methods.
 *
 * @package RichStatistics\Tests
 */
class DbMaintenanceTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'rsa_retention_days' );
		delete_option( 'rsa_bot_score_threshold' );
		RSA_DB::install();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	private function insert_event( string $session_id, string $page, ?string $created_at = null ): void {
		global $wpdb;
		$created_at = $created_at ? $created_at : gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_events',
			array(
				'session_id' => $session_id,
				'page'       => $page,
				'bot_score'  => 0,
				'created_at' => $created_at,
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	private function insert_session( string $session_id, ?string $created_at = null ): void {
		global $wpdb;
		$created_at = $created_at ? $created_at : gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_sessions',
			array(
				'session_id' => $session_id,
				'entry_page' => '/',
				'created_at' => $created_at,
			),
			array( '%s', '%s', '%s' )
		);
	}

	private function insert_click( string $session_id, string $page, ?string $created_at = null ): void {
		global $wpdb;
		$created_at = $created_at ? $created_at : gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_clicks',
			array(
				'session_id' => $session_id,
				'page'       => $page,
				'created_at' => $created_at,
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * ----------------------------------------------------------------
	 * purge_page_data()
	 * ----------------------------------------------------------------
	 */
	public function test_purge_page_data_returns_zero_for_nonexistent_page(): void {
		$deleted = RSA_DB::purge_page_data( '/nonexistent-page/' );
		$this->assertSame( 0, $deleted );
	}

	public function test_purge_page_data_deletes_events(): void {
		$this->insert_event( 'purge-events-test', '/about/', current_time( 'mysql' ) );
		$this->insert_event( 'purge-events-test', '/about/', current_time( 'mysql' ) );

		$deleted = RSA_DB::purge_page_data( '/about/' );

		$this->assertSame( 2, $deleted );
		$this->assertSame( 0, $this->count_events_for_session( 'purge-events-test' ) );
	}

	public function test_purge_page_data_deletes_clicks(): void {
		$this->insert_click( 'purge-clicks-test', '/contact/', current_time( 'mysql' ) );

		$deleted = RSA_DB::purge_page_data( '/contact/' );

		$this->assertSame( 1, $deleted );
	}

	public function test_purge_page_data_deletes_across_tables(): void {
		$this->insert_event( 'purge-multi-test', '/blog/', current_time( 'mysql' ) );
		$this->insert_click( 'purge-multi-test', '/blog/', current_time( 'mysql' ) );

		$deleted = RSA_DB::purge_page_data( '/blog/' );

		$this->assertSame( 2, $deleted );
	}

	public function test_purge_page_data_does_not_delete_other_pages(): void {
		$this->insert_event( 'keep-event-test', '/about/', current_time( 'mysql' ) );
		$this->insert_event( 'keep-event-test', '/contact/', current_time( 'mysql' ) );

		RSA_DB::purge_page_data( '/about/' );

		$this->assertSame( 1, $this->count_events_for_session( 'keep-event-test' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * prune_old_data()
	 * ----------------------------------------------------------------
	 */
	public function test_prune_old_data_respects_custom_retention_days(): void {
		update_option( 'rsa_retention_days', 7 );
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$this->insert_event( 'prune-old-test', '/old/', $old_date );
		$this->insert_event( 'prune-recent-test', '/recent/', current_time( 'mysql' ) );

		RSA_DB::prune_old_data();

		$this->assertSame( 0, $this->count_events_for_session( 'prune-old-test' ) );
		$this->assertSame( 1, $this->count_events_for_session( 'prune-recent-test' ) );
	}

	public function test_prune_old_data_deletes_from_all_tables(): void {
		update_option( 'rsa_retention_days', 1 );
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) );
		$this->insert_event( 'prune-all-test', '/page/', $old_date );
		$this->insert_session( 'prune-all-sess-test', $old_date );
		$this->insert_click( 'prune-all-click-test', '/page/', $old_date );

		RSA_DB::prune_old_data();

		$this->assertSame( 0, $this->count_events_for_session( 'prune-all-test' ) );
		$this->assertSame( 0, $this->count_sessions_for_session( 'prune-all-sess-test' ) );
		$this->assertSame( 0, $this->count_clicks_for_session( 'prune-all-click-test' ) );
	}

	public function test_prune_old_data_respects_default_retention(): void {
		update_option( 'rsa_retention_days', 1 );
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-50 days' ) );
		$this->insert_event( 'prune-default-test', '/test/', $old_date );
		$recent_date = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$this->insert_event( 'prune-default-recent', '/test/', $recent_date );

		RSA_DB::prune_old_data( 40 );

		$this->assertSame( 0, $this->count_events_for_session( 'prune-default-test' ) );
		$this->assertSame( 1, $this->count_events_for_session( 'prune-default-recent' ) );
	}

	public function test_prune_old_data_uses_limit_batch(): void {
		update_option( 'rsa_retention_days', 1 );
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->insert_event( "prune-batch-{$i}", '/batch/', $old_date );
		}

		RSA_DB::prune_old_data();

		$this->assertSame( 0, $this->count_events_for_session( 'prune-batch-0' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * aggregate_heatmap() — no-op when no data
	 * ----------------------------------------------------------------
	 */
	public function test_aggregate_heatmap_does_nothing_when_no_clicks(): void {
		$this->expectNotToPerformAssertions();
		RSA_DB::aggregate_heatmap();
	}

	public function test_aggregate_heatmap_aggregates_raw_clicks(): void {
		global $wpdb;
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_clicks',
			array(
				'session_id' => 'hm-agg-test',
				'page'       => '/test/',
				'x_pct'      => 10.4,
				'y_pct'      => 20.8,
				'created_at' => $yesterday . ' 12:00:00',
			),
			array( '%s', '%s', '%f', '%f', '%s' )
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_clicks',
			array(
				'session_id' => 'hm-agg-test',
				'page'       => '/test/',
				'x_pct'      => 10.6,
				'y_pct'      => 20.2,
				'created_at' => $yesterday . ' 14:00:00',
			),
			array( '%s', '%s', '%f', '%f', '%s' )
		);

		RSA_DB::aggregate_heatmap();

		$bucket = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT weight FROM {$wpdb->prefix}rsa_heatmap WHERE page = %s AND x_pct = 10.0 AND y_pct = 20.0",
				'/test/'
			)
		);
		$this->assertNotNull( $bucket );
		$this->assertSame( 2, (int) $bucket->weight );

		$wpdb->delete( $wpdb->prefix . 'rsa_clicks', array( 'session_id' => 'hm-agg-test' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'rsa_heatmap', array( 'page' => '/test/' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * ----------------------------------------------------------------
	 * aggregate_heatmap() — NULL x_pct/y_pct are excluded (L32)
	 * ----------------------------------------------------------------
	 */
	public function test_aggregate_heatmap_excludes_null_coordinates(): void {
		global $wpdb;
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// Insert a click with NULL coordinates
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_clicks',
			array(
				'session_id' => 'hm-null-test',
				'page'       => '/null-test/',
				'x_pct'      => null,
				'y_pct'      => null,
				'created_at' => $yesterday . ' 12:00:00',
			),
			array( '%s', '%s', '%f', '%f', '%s' )
		);

		// Insert a valid click on the same page
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'rsa_clicks',
			array(
				'session_id' => 'hm-valid-test',
				'page'       => '/null-test/',
				'x_pct'      => 50.0,
				'y_pct'      => 50.0,
				'created_at' => $yesterday . ' 13:00:00',
			),
			array( '%s', '%s', '%f', '%f', '%s' )
		);

		RSA_DB::aggregate_heatmap();

		// Only the valid click should be aggregated
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->prefix}rsa_heatmap WHERE page = %s",
				'/null-test/'
			)
		);
		$this->assertSame( 1, $count, 'Only clicks with valid coordinates should be aggregated' );

		$weight = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT weight FROM {$wpdb->prefix}rsa_heatmap WHERE page = %s",
				'/null-test/'
			)
		);
		$this->assertSame( 1, $weight, 'Weight should be 1 (only the valid click)' );

		$wpdb->delete( $wpdb->prefix . 'rsa_clicks', array( 'page' => '/null-test/' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'rsa_heatmap', array( 'page' => '/null-test/' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * ----------------------------------------------------------------
	 * prune_old_data() — 0 days retention deletes everything (L33)
	 * ----------------------------------------------------------------
	 */
	public function test_prune_old_data_with_zero_days_deletes_all(): void {
		// With 0 days retention, cutoff is "now". Use data from 1 second ago
		// to ensure it's older than the cutoff (prune uses < not <=).
		$one_sec_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-1 second' ) );
		$this->insert_event( 'prune-zero-test', '/test/', $one_sec_ago );
		$this->insert_session( 'prune-zero-sess', $one_sec_ago );

		$deleted = RSA_DB::prune_old_data( 0 );

		// With 0 days retention, even recent data is "old"
		$this->assertGreaterThan( 0, $deleted, 'prune with 0 days should delete rows' );
		$this->assertSame( 0, $this->count_events_for_session( 'prune-zero-test' ) );
		$this->assertSame( 0, $this->count_sessions_for_session( 'prune-zero-sess' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * prune_old_data() — returns early on timeout (L34)
	 * ----------------------------------------------------------------
	 * The 55-second timeout is a safety valve for large sites. We verify
	 * the method completes without error and returns an integer count.
	 */
	public function test_prune_old_data_returns_integer_count(): void {
		$result = RSA_DB::prune_old_data();
		$this->assertIsInt( $result, 'prune_old_data() should return an integer count' );
		$this->assertGreaterThanOrEqual( 0, $result, 'Delete count should be non-negative' );
	}

	/**
	 * ----------------------------------------------------------------
	 * daily_maintenance() runs both prune and aggregate
	 * ----------------------------------------------------------------
	 */
	public function test_daily_maintenance_does_not_error(): void {
		update_option( 'rsa_retention_days', 90 );
		$this->expectNotToPerformAssertions();
		RSA_DB::daily_maintenance();
	}

	/**
	 * ----------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------
	 *
	 * @param string $session_id The session ID to count.
	 * @return int Event count.
	 */
	private function count_events_for_session( string $session_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->prefix}rsa_events WHERE session_id = %s",
				$session_id
			)
		);
	}

	private function count_sessions_for_session( string $session_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->prefix}rsa_sessions WHERE session_id = %s",
				$session_id
			)
		);
	}

	private function count_clicks_for_session( string $session_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->prefix}rsa_clicks WHERE session_id = %s",
				$session_id
			)
		);
	}
}
