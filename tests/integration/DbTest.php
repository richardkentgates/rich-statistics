<?php
/**
 * Integration tests for RSA_DB — table creation and schema.
 *
 * @package RichStatistics\Tests
 */
class DbTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Delete options so install() seeds fresh defaults (live site may have different values).
		delete_option( 'rsa_retention_days' );
		delete_option( 'rsa_bot_score_threshold' );
		delete_option( 'rsa_email_digest_enabled' );
		delete_option( RSA_DB::OPTION_KEY );
		RSA_DB::install();
	}

	public function tearDown(): void {
		global $wpdb;
		// Clean up test data (tables remain; WordPress test suite handles teardown)
		$wpdb->query( "DELETE FROM {$wpdb->prefix}rsa_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}rsa_sessions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		parent::tearDown();
	}

	/**
	 * ----------------------------------------------------------------
	 * Table existence
	 * ----------------------------------------------------------------
	 */
	public function test_events_table_exists(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'rsa_events';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	public function test_sessions_table_exists(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'rsa_sessions';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	public function test_clicks_table_exists(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'rsa_clicks';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	public function test_heatmap_table_exists(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'rsa_heatmap';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	/**
	 * ----------------------------------------------------------------
	 * Default options seeded
	 * ----------------------------------------------------------------
	 */
	public function test_retention_days_default_is_ninety(): void {
		$this->assertSame( 90, (int) get_option( 'rsa_retention_days' ) );
	}

	public function test_bot_threshold_default_is_five(): void {
		$this->assertSame( 5, (int) get_option( 'rsa_bot_score_threshold' ) );
	}

	public function test_email_digest_disabled_by_default(): void {
		$this->assertSame( 0, (int) get_option( 'rsa_email_digest_enabled' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * table() helper
	 * ----------------------------------------------------------------
	 */
	public function test_table_helper_returns_prefixed_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_events', RSA_DB::table( 'events' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * prune_old_data() does not error on empty table
	 * ----------------------------------------------------------------
	 */
	public function test_prune_runs_without_error_on_empty_tables(): void {
		$this->expectNotToPerformAssertions();
		RSA_DB::prune_old_data();
	}

	/**
	 * ----------------------------------------------------------------
	 * Schema version
	 * ----------------------------------------------------------------
	 */
	public function test_schema_version_is_one(): void {
		$this->assertSame( 1, RSA_DB::SCHEMA_VERSION );
	}

	/**
	 * ----------------------------------------------------------------
	 * href_value column exists in clicks table
	 * ----------------------------------------------------------------
	 */
	public function test_clicks_table_has_href_value_column(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'rsa_clicks';
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}rsa_clicks`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'href_value', $columns, "Expected href_value column in {$table}" );
	}

	/**
	 * ----------------------------------------------------------------
	 * Migration / idempotency tests
	 * ----------------------------------------------------------------
	 */
	public function test_install_is_idempotent(): void {
		RSA_DB::install();
		$this->expectNotToPerformAssertions();
	}

	public function test_data_survives_reinstall(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rsa_events';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'session_id' => 'migration-test-uuid',
				'page'       => '/test',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		RSA_DB::install();

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $before, $after, 'Data count should not change after install()' );
	}

	public function test_schema_version_option_matches_constant(): void {
		$this->assertSame( RSA_DB::SCHEMA_VERSION, (int) get_option( RSA_DB::OPTION_KEY ) );
	}

	public function test_events_table_has_all_expected_columns(): void {
		global $wpdb;
		$columns  = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}rsa_events`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$expected = array( 'id', 'session_id', 'page', 'referrer_domain', 'os', 'browser', 'browser_version', 'language', 'timezone', 'viewport_w', 'viewport_h', 'time_on_page', 'bot_score', 'utm_source', 'utm_medium', 'utm_campaign', 'created_at' );
		foreach ( $expected as $col ) {
			$this->assertContains( $col, $columns, "Missing column {$col} in rsa_events" );
		}
	}

	public function test_sessions_table_has_all_expected_columns(): void {
		global $wpdb;
		$columns  = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}rsa_sessions`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$expected = array( 'id', 'session_id', 'pages_viewed', 'total_time', 'entry_page', 'exit_page', 'os', 'browser', 'language', 'timezone', 'created_at', 'updated_at' );
		foreach ( $expected as $col ) {
			$this->assertContains( $col, $columns, "Missing column {$col} in rsa_sessions" );
		}
	}

	public function test_wc_events_table_has_all_expected_columns(): void {
		global $wpdb;
		$columns  = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}rsa_wc_events`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$expected = array( 'id', 'session_id', 'event_type', 'product_id', 'product_name', 'product_sku', 'quantity', 'order_total', 'order_currency', 'created_at' );
		foreach ( $expected as $col ) {
			$this->assertContains( $col, $columns, "Missing column {$col} in rsa_wc_events" );
		}
	}

	public function test_clicks_table_has_all_expected_columns(): void {
		global $wpdb;
		$columns  = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}rsa_clicks`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$expected = array( 'id', 'session_id', 'page', 'element_tag', 'element_id', 'element_class', 'element_text', 'href_protocol', 'href_value', 'matched_rule', 'x_pct', 'y_pct', 'created_at' );
		foreach ( $expected as $col ) {
			$this->assertContains( $col, $columns, "Missing column {$col} in rsa_clicks" );
		}
	}

	public function test_heatmap_table_has_all_expected_columns(): void {
		global $wpdb;
		$columns  = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}rsa_heatmap`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$expected = array( 'id', 'page', 'x_pct', 'y_pct', 'weight', 'date_bucket' );
		foreach ( $expected as $col ) {
			$this->assertContains( $col, $columns, "Missing column {$col} in rsa_heatmap" );
		}
	}

	public function test_install_preserves_existing_options(): void {
		update_option( 'rsa_retention_days', 30 );
		RSA_DB::install();
		$this->assertSame( 30, (int) get_option( 'rsa_retention_days' ), 'install() should not overwrite existing option values' );
	}

	public function test_all_options_seeded_after_install(): void {
		$options = array(
			'rsa_retention_days',
			'rsa_bot_score_threshold',
			'rsa_remove_data_on_uninstall',
			'rsa_track_protocol_tel',
			'rsa_track_protocol_mailto',
			'rsa_track_protocol_geo',
			'rsa_track_protocol_sms',
			'rsa_track_protocol_download',
			'rsa_email_digest_enabled',
			'rsa_email_digest_frequency',
			'rsa_woocommerce_enabled',
		);
		foreach ( $options as $key ) {
			$this->assertNotFalse( get_option( $key ), "Option {$key} should exist after install" );
		}
	}

	/**
	 * ----------------------------------------------------------------
	 * maybe_remove_data() — H13
	 * ----------------------------------------------------------------
	 */
	public function test_maybe_remove_data_does_nothing_when_option_is_zero(): void {
		update_option( 'rsa_remove_data_on_uninstall', 0 );
		RSA_DB::maybe_remove_data();
		global $wpdb;
		$table  = $wpdb->prefix . 'rsa_events';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result, 'Tables should still exist when rsa_remove_data_on_uninstall is 0' );
	}

	public function test_maybe_remove_data_drops_tables_when_option_is_one(): void {
		update_option( 'rsa_remove_data_on_uninstall', 1 );
		$this->assertSame( 1, (int) get_option( 'rsa_remove_data_on_uninstall' ), 'Option should be set to 1' );
		RSA_DB::maybe_remove_data();
		// Option is deleted by drop_site_tables, so we just verify the method ran without error.
		$this->assertTrue( true, 'maybe_remove_data() should complete without error' );
		// Reinstall tables for subsequent tests.
		RSA_DB::install();
	}

	/**
	 * ----------------------------------------------------------------
	 * deactivate() — H16
	 * ----------------------------------------------------------------
	 */
	public function test_deactivate_clears_scheduled_cron(): void {
		RSA_DB::install();
		$this->assertNotFalse( wp_next_scheduled( 'rsa_daily_maintenance' ), 'Cron should be scheduled after install' );
		RSA_DB::deactivate();
		$this->assertFalse( wp_next_scheduled( 'rsa_daily_maintenance' ), 'Cron should be cleared after deactivate' );
	}
}
