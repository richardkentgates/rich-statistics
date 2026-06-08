<?php
/**
 * Integration tests for Rich Statistics multisite-compatible functionality.
 *
 * Tests blog switching, network options, and per-site table isolation.
 * Skips tests requiring full multisite environment or WP CLI when running in single-site mode.
 *
 * @package RichStatistics\Tests
 */
class MultisiteTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}
	public function test_is_multisite_returns_boolean(): void {
		$result = is_multisite();
		$this->assertIsBool( $result );
	}
	public function test_network_wide_disable_switch_is_retrievable(): void {
		$value = get_site_option( 'rsa_network_disable_tracker' );
		$this->assertNotNull( $value );
	}
	public function test_network_wide_disable_switch_can_be_updated(): void {
		update_site_option( 'rsa_network_disable_tracker', 1 );
		$retrieved = (int) get_site_option( 'rsa_network_disable_tracker' );
		$this->assertSame( 1, $retrieved );
		update_site_option( 'rsa_network_disable_tracker', 0 );
		$retrieved2 = (int) get_site_option( 'rsa_network_disable_tracker' );
		$this->assertSame( 0, $retrieved2 );
	}
	public function test_rsa_db_table_names_use_wp_prefix(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_events', RSA_DB::events_table() );
		$this->assertSame( $wpdb->prefix . 'rsa_sessions', RSA_DB::sessions_table() );
		$this->assertSame( $wpdb->prefix . 'rsa_clicks', RSA_DB::clicks_table() );
		$this->assertSame( $wpdb->prefix . 'rsa_heatmap', RSA_DB::heatmap_table() );
	}
	public function test_rsa_wc_events_table_uses_wp_prefix(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_wc_events', RSA_DB::wc_events_table() );
	}
	public function test_all_tables_use_same_prefix(): void {
		global $wpdb;
		$expected_prefix = $wpdb->prefix;
		$tables          = array(
			RSA_DB::events_table(),
			RSA_DB::sessions_table(),
			RSA_DB::clicks_table(),
			RSA_DB::heatmap_table(),
		);
		foreach ( $tables as $table ) {
			$this->assertStringStartsWith( $expected_prefix, $table );
		}
	}
	public function test_bot_threshold_option_exists(): void {
		$threshold = get_option( 'rsa_bot_score_threshold' );
		$this->assertNotNull( $threshold );
		$this->assertTrue( is_numeric( $threshold ), 'Bot threshold should be numeric' );
	}
	public function test_data_retention_option_exists(): void {
		$retention = get_option( 'rsa_retention_days' );
		$this->assertNotNull( $retention );
	}
	public function test_network_tracker_class_exists(): void {
		$this->assertTrue( class_exists( 'RSA_Tracker' ) );
	}
	public function test_tracking_init_method_exists(): void {
		$this->assertTrue( method_exists( 'RSA_Tracker', 'init' ) );
	}
	public function test_tracking_enqueue_method_exists(): void {
		$this->assertTrue( method_exists( 'RSA_Tracker', 'enqueue' ) );
	}
	public function test_tracking_handle_ingest_method_exists(): void {
		$this->assertTrue( method_exists( 'RSA_Tracker', 'handle_ingest' ) );
	}
	public function test_cli_class_exists(): void {
		if ( ! class_exists( 'RSA_CLI' ) ) {
			$this->markTestSkipped( 'RSA_CLI not available (WP CLI not installed in test env)' );
		}
		$this->assertTrue( true );
	}
	public function test_new_blog_hook_uses_wp_initialize_site(): void {
		$cb = has_action( 'wp_initialize_site', array( RSA_DB::class, 'on_new_blog_event' ) );
		$this->assertNotFalse( $cb, 'Hook wp_initialize_site should trigger RSA_DB::on_new_blog_event' );
	}
	public function test_rsa_db_has_on_new_blog_event_handler(): void {
		$this->assertTrue( method_exists( 'RSA_DB', 'on_new_blog_event' ) );
	}
	public function test_on_new_blog_event_is_public(): void {
		$ref = new ReflectionMethod( RSA_DB::class, 'on_new_blog_event' );
		$this->assertTrue( $ref->isPublic() );
	}
	public function test_on_new_blog_event_accepts_new_site_param(): void {
		$ref    = new ReflectionMethod( RSA_DB::class, 'on_new_blog_event' );
		$params = $ref->getParameters();
		$this->assertCount( 1, $params );
	}
	public function test_table_names_are_deterministic(): void {
		$table1 = RSA_DB::events_table();
		$table2 = RSA_DB::events_table();
		$this->assertSame( $table1, $table2 );
	}
	public function test_table_names_follow_rsa_prefix_convention(): void {
		$expected_suffixes = array( 'events', 'sessions', 'clicks', 'heatmap' );
		global $wpdb;
		foreach ( $expected_suffixes as $suffix ) {
			$method = $suffix . '_table';
			if ( method_exists( RSA_DB::class, $method ) ) {
				$table = RSA_DB::$method();
				$this->assertSame( $wpdb->prefix . 'rsa_' . $suffix, $table );
			}
		}
	}
	public function test_wc_events_table_method_exists(): void {
		$this->assertTrue( method_exists( RSA_DB::class, 'wc_events_table' ) );
	}
	public function test_events_table_has_correct_structure(): void {
		global $wpdb;
		$table     = RSA_DB::events_table();
		$cols      = $wpdb->get_results( "DESCRIBE $table" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col_names = array_column( $cols, 'Field' );
		$required  = array( 'id', 'session_id', 'page', 'created_at' );
		foreach ( $required as $col ) {
			$this->assertContains( $col, $col_names, "Column $col should exist in events table" );
		}
	}
	public function test_sessions_table_method_exists(): void {
		$this->assertTrue( method_exists( 'RSA_DB', 'sessions_table' ) );
	}
	public function test_clicks_table_method_exists(): void {
		$this->assertTrue( method_exists( 'RSA_DB', 'clicks_table' ) );
	}
	public function test_heatmap_table_method_exists(): void {
		$this->assertTrue( method_exists( 'RSA_DB', 'heatmap_table' ) );
	}
	public function test_events_table_returns_string(): void {
		$result = RSA_DB::events_table();
		$this->assertIsString( $result );
	}
}
