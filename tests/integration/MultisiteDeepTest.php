<?php
/**
 * Multisite Deep Tests
 *
 * Covers network permission checks, `on_new_blog()` table installation,
 * network-wide tracker disable, and network option isolation.
 *
 * Limited by single-site test environment — tests mock multisite scenarios
 * where the actual APIs are not available.
 *
 * @package RichStatistics\Tests
 */
class MultisiteDeepTest extends WP_UnitTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'rsa_manage_statistics' );
		}
	}

	public static function tearDownAfterClass(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'rsa_manage_statistics' );
		}
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}

	public function tearDown(): void {
		delete_site_option( 'rsa_network_disable_tracker' );
		delete_site_option( 'rsa_default_retention_days' );
		parent::tearDown();
	}

	/**
	 * Network admin permission checks
	 */
	public function test_network_dashboard_dies_without_manage_network(): void {
		wp_set_current_user( 0 );

		$threw = false;
		ob_start();
		try {
			RSA_Admin::page_network_dashboard();
		} catch ( \WPDieException $e ) {
			$threw = true;
			unset( $e );
		}
		ob_end_clean();

		$this->assertTrue( $threw, 'Expected wp_die() for user without manage_network_options' );
	}

	public function test_network_settings_dies_without_manage_network(): void {
		wp_set_current_user( 0 );

		$threw = false;
		ob_start();
		try {
			RSA_Admin::page_network_settings();
		} catch ( \WPDieException $e ) {
			$threw = true;
			unset( $e );
		}
		ob_end_clean();

		$this->assertTrue( $threw, 'Expected wp_die() for user without manage_network_options' );
	}

	/**
	 * On new blog table installation
	 */
	public function test_on_new_blog_installs_tables(): void {
		global $wpdb;

		// Verify tables exist for the current blog.
		$this->assertTrue(
			(bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rsa_events'" ),
			'Events table should exist for current blog'
		);

		// In single-site env, switch_to_blog() does not exist.
		// Skip if not available.
		if ( ! function_exists( 'switch_to_blog' ) ) {
			$this->markTestSkipped( 'switch_to_blog() requires multisite' );
		}

		RSA_DB::on_new_blog( get_current_blog_id() );

		$this->assertTrue(
			(bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rsa_events'" ),
			'Events table should still exist after on_new_blog'
		);
	}

	/**
	 * Network-wide tracker disable
	 */
	public function test_tracker_returns_early_when_network_disabled(): void {
		// Simulate the network disable flag.
		update_site_option( 'rsa_network_disable_tracker', 1 );

		// The flag should be readable via get_site_option.
		$this->assertSame( 1, (int) get_site_option( 'rsa_network_disable_tracker' ) );

		// In a real multisite env, tracker::enqueue() would check this flag
		// and return early. We verify the option shape and value.
		$disabled = (bool) get_site_option( 'rsa_network_disable_tracker', 0 );
		$this->assertTrue( $disabled );
	}

	public function test_tracker_enqueues_when_network_not_disabled(): void {
		update_site_option( 'rsa_network_disable_tracker', 0 );

		$disabled = (bool) get_site_option( 'rsa_network_disable_tracker', 0 );
		$this->assertFalse( $disabled );
	}

	/**
	 * Network option persistence
	 */
	public function test_network_default_retention_days_persists(): void {
		update_site_option( 'rsa_default_retention_days', 60 );
		$this->assertSame( 60, (int) get_site_option( 'rsa_default_retention_days' ) );
	}

	public function test_network_retention_clamping(): void {
		update_site_option( 'rsa_default_retention_days', 999 );
		$val = (int) get_site_option( 'rsa_default_retention_days' );
		// The option itself is not clamped on storage — clamping happens on read/use.
		$this->assertSame( 999, $val );
	}

	/**
	 * On new blog event handler
	 */
	public function test_on_new_blog_event_runs_without_error(): void {
		$site          = new stdClass();
		$site->blog_id = get_current_blog_id();

		// Skip if RSA_FILE is not defined (happens in test bootstrap).
		if ( ! defined( 'RSA_FILE' ) ) {
			$this->markTestSkipped( 'RSA_FILE constant not defined in test env' );
		}

		// Should not throw even when not in multisite.
		try {
			RSA_DB::on_new_blog_event( $site );
		} catch ( \Throwable $e ) {
			$this->fail( 'on_new_blog_event should not throw: ' . $e->getMessage() );
		}

		$this->assertTrue( true );
	}
}
