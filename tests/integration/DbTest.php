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
		RSA_DB::install();
	}

	public function tearDown(): void {
		global $wpdb;
		// Clean up test data (tables remain; WordPress test suite handles teardown)
		$wpdb->query( "DELETE FROM {$wpdb->prefix}rsa_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}rsa_sessions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		parent::tearDown();
	}

	// ----------------------------------------------------------------
	// Table existence
	// ----------------------------------------------------------------

	public function test_events_table_exists(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rsa_events';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	public function test_sessions_table_exists(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rsa_sessions';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	public function test_clicks_table_exists(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rsa_clicks';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	public function test_heatmap_table_exists(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rsa_heatmap';
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $result );
	}

	// ----------------------------------------------------------------
	// Default options seeded
	// ----------------------------------------------------------------

	public function test_retention_days_default_is_ninety(): void {
		$this->assertSame( 90, (int) get_option( 'rsa_retention_days' ) );
	}

	public function test_bot_threshold_default_is_five(): void {
		$this->assertSame( 5, (int) get_option( 'rsa_bot_score_threshold' ) );
	}

	public function test_email_digest_disabled_by_default(): void {
		$this->assertSame( 0, (int) get_option( 'rsa_email_digest_enabled' ) );
	}

	// ----------------------------------------------------------------
	// table() helper
	// ----------------------------------------------------------------

	public function test_table_helper_returns_prefixed_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_events', RSA_DB::table( 'events' ) );
	}

	// ----------------------------------------------------------------
	// prune_old_data() does not error on empty table
	// ----------------------------------------------------------------

	public function test_prune_runs_without_error_on_empty_tables(): void {
		$this->expectNotToPerformAssertions();
		RSA_DB::prune_old_data();
	}

	// ----------------------------------------------------------------
	// Schema version
	// ----------------------------------------------------------------

	public function test_schema_version_is_nine(): void {
		$this->assertSame( 9, RSA_DB::SCHEMA_VERSION );
	}

	// ----------------------------------------------------------------
	// href_value column exists in clicks table
	// ----------------------------------------------------------------

	public function test_clicks_table_has_href_value_column(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'rsa_clicks';
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}rsa_clicks`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'href_value', $columns, "Expected href_value column in {$table}" );
	}
}
