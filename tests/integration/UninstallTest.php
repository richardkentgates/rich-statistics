<?php
/**
 * Uninstall Tests
 *
 * Covers single-site and multisite data cleanup, edge cases, and option deletion.
 *
 * @package RichStatistics\Tests
 * @group ddl
 */
class UninstallTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}

	public function tearDown(): void {
		// Re-install tables so other tests are not affected.
		// Force install() to run by clearing the version option first.
		delete_option( 'rsa_db_version' );
		// Bypass WP test framework filters that convert CREATE/DROP to TEMPORARY.
		// We need permanent tables so subsequent test classes are not affected.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		RSA_DB::install();
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		parent::tearDown();
	}

	private function seed_options(): void {
		update_option( 'rsa_remove_data_on_uninstall', 1 );
		update_option( 'rsa_retention_days', 30 );
		update_option( 'rsa_bot_score_threshold', 5 );
		update_option( 'rsa_consent_banner', 1 );
		update_option( 'rsa_consent_auto', 0 );
		update_option( 'rsa_consent_styles', '{}' );
		update_option( 'rsa_consent_banner_text', 'Test' );
		update_option( 'rsa_enabled_post_types', array( 'post', 'page' ) );
		update_option( 'rsa_allowed_roles', array( 'editor' ) );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		$name = $wpdb->prefix . $table;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
	}

	private function option_exists( string $option ): bool {
		global $wpdb;
		// Bypass WordPress cache by querying directly.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
	}

	/**
	 * Remove the WP test framework filter that converts DROP TABLE to DROP TEMPORARY TABLE.
	 * Our tables are permanent (created during bootstrap before filters were active).
	 */
	private function allow_real_drops(): void {
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	private function restore_temporary_filter(): void {
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Single-site uninstall
	 */
	public function test_maybe_remove_data_returns_early_when_flag_disabled(): void {
		update_option( 'rsa_remove_data_on_uninstall', 0 );
		update_option( 'rsa_retention_days', 30 );

		RSA_DB::maybe_remove_data();

		$this->assertSame( 30, (int) get_option( 'rsa_retention_days' ) );
		$this->assertTrue( $this->table_exists( 'rsa_events' ) );
	}

	public function test_single_site_uninstall_drops_tables(): void {
		$this->seed_options();
		global $wpdb;

		$this->allow_real_drops();
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_sessions`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_wc_events`" );
		$this->restore_temporary_filter();

		$this->assertFalse( $this->table_exists( 'rsa_events' ) );
		$this->assertFalse( $this->table_exists( 'rsa_sessions' ) );
		$this->assertFalse( $this->table_exists( 'rsa_clicks' ) );
		$this->assertFalse( $this->table_exists( 'rsa_wc_events' ) );
	}

	public function test_single_site_uninstall_deletes_options(): void {
		$this->seed_options();
		RSA_DB::maybe_remove_data();

		// Direct DB queries because drop_site_tables() deletes via $wpdb->query()
		// which bypasses WordPress option cache.
		$this->assertFalse( $this->option_exists( 'rsa_retention_days' ) );
		$this->assertFalse( $this->option_exists( 'rsa_bot_score_threshold' ) );
		$this->assertFalse( $this->option_exists( 'rsa_consent_banner' ) );
		$this->assertFalse( $this->option_exists( 'rsa_consent_auto' ) );
		$this->assertFalse( $this->option_exists( 'rsa_consent_styles' ) );
		$this->assertFalse( $this->option_exists( 'rsa_consent_banner_text' ) );
		$this->assertFalse( $this->option_exists( 'rsa_enabled_post_types' ) );
		$this->assertFalse( $this->option_exists( 'rsa_allowed_roles' ) );
	}

	/**
	 * Edge cases
	 */
	public function test_drop_site_tables_safe_when_tables_missing(): void {
		global $wpdb;
		$this->allow_real_drops();
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_sessions`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_wc_events`" );
		$this->restore_temporary_filter();

		$this->seed_options();
		// Should not throw — DROP TABLE IF EXISTS is safe.
		RSA_DB::maybe_remove_data();

		$this->assertFalse( $this->table_exists( 'rsa_events' ) );
	}
}
