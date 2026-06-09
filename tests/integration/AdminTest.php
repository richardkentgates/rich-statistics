<?php
/**
 * Integration tests for RSA_Admin capabilities.
 *
 * @package RichStatistics\Tests
 */
class AdminTest extends WP_UnitTestCase {

	/** @var WP_User */
	private static $admin;
	/** @var WP_User */
	private static $editor;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin  = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		self::$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );

		if ( ! get_role( 'rsa_analyst' ) ) {
			add_role(
				'rsa_analyst',
				'Statistics Analyst',
				array(
					'rsa_manage_statistics' => true,
					'read'                  => true,
				)
			);
		}

		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( 'rsa_manage_statistics' ) ) {
			$admin_role->add_cap( 'rsa_manage_statistics' );
		}

		$allowed = get_option( 'rsa_allowed_roles', array( 'administrator' ) );
		if ( ! in_array( 'rsa_analyst', $allowed, true ) ) {
			$allowed[] = 'rsa_analyst';
			update_option( 'rsa_allowed_roles', $allowed );
		}
	}

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}

	/**
	 * ----------------------------------------------------------------
	 * user_can_access_app()
	 * ----------------------------------------------------------------
	 */
	public function test_admin_always_has_app_access(): void {
		$this->assertTrue( RSA_Admin::user_can_access_app( self::$admin ) );
	}

	public function test_editor_without_rsa_role_cannot_access_app(): void {
		$this->assertFalse( RSA_Admin::user_can_access_app( self::$editor ) );
	}

	public function test_editor_cannot_access_app_by_default(): void {
		$result = RSA_Admin::user_can_access_app( self::$editor );
		$this->assertFalse( $result );
	}

	public function test_null_user_cannot_access_app(): void {
		$this->assertFalse( RSA_Admin::user_can_access_app( null ) );
	}

	public function test_user_can_access_app_with_current_user(): void {
		wp_set_current_user( self::$admin->ID );
		$this->assertTrue( RSA_Admin::user_can_access_app() );
	}

	public function test_guest_cannot_access_app(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( RSA_Admin::user_can_access_app() );
	}

	public function test_subscriber_not_in_allowed_roles_cannot_access(): void {
		$sub = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_option( 'rsa_allowed_roles', array( 'editor' ) );

		$result = RSA_Admin::user_can_access_app( $sub );

		$this->assertFalse( $result );
	}

	/**
	 * ----------------------------------------------------------------
	 * get_trackable_pages()
	 * ----------------------------------------------------------------
	 */
	public function test_get_trackable_pages_returns_array(): void {
		$result = RSA_Admin::get_trackable_pages();
		$this->assertIsArray( $result );
	}

	public function test_get_trackable_pages_includes_home(): void {
		$result = RSA_Admin::get_trackable_pages();
		$this->assertArrayHasKey( '/', $result );
		$this->assertSame( 'Home', $result['/'] );
	}

	public function test_get_trackable_pages_home_is_first(): void {
		$result = RSA_Admin::get_trackable_pages();
		$keys   = array_keys( $result );
		$this->assertSame( '/', $keys[0] );
	}

	public function test_get_trackable_pages_pagination(): void {
		global $wp_rewrite;
		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->init();
		$wp_rewrite->flush_rules( true );

		// Create two published posts with slugs.
		self::factory()->post->create( [ 'post_title' => 'Alpha Page', 'post_name' => 'alpha-page', 'post_status' => 'publish' ] );
		self::factory()->post->create( [ 'post_title' => 'Beta Page', 'post_name' => 'beta-page', 'post_status' => 'publish' ] );

		// Default limit=-1 returns everything (including home).
		$all       = RSA_Admin::get_trackable_pages();
		$all_count = count( $all );

		// Limit=1 restricts WordPress posts to 1; home is always prepended.
		$limited       = RSA_Admin::get_trackable_pages( 1 );
		$limited_count = count( $limited );

		// Offset with positive limit skips posts correctly.
		$offset0 = RSA_Admin::get_trackable_pages( 1, 0 );
		$offset1 = RSA_Admin::get_trackable_pages( 1, 1 );

		$this->assertGreaterThanOrEqual( 3, $all_count );
		$this->assertLessThan( $all_count, $limited_count );
		$this->assertGreaterThan( 0, $limited_count );
		$this->assertArrayHasKey( '/', $limited );

		// Offset should change which post is returned (home is always prepended).
		$this->assertNotSame( array_values( $offset0 ), array_values( $offset1 ) );
		$this->assertArrayHasKey( '/', $offset0 );
		$this->assertArrayHasKey( '/', $offset1 );
	}

	/**
	 * ----------------------------------------------------------------
	 * role setup — rsa_analyst role
	 * ----------------------------------------------------------------
	 */
	public function test_rsa_analyst_role_has_correct_capability(): void {
		$role = get_role( 'rsa_analyst' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'rsa_manage_statistics' ) );
		$this->assertTrue( $role->has_cap( 'read' ) );
	}

	public function test_admin_role_has_rsa_manage_statistics(): void {
		$admin = get_role( 'administrator' );
		$this->assertTrue( $admin->has_cap( 'rsa_manage_statistics' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * RSA_DB table helpers
	 * ----------------------------------------------------------------
	 */
	public function test_events_table_helper(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_events', RSA_DB::events_table() );
	}

	public function test_sessions_table_helper(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_sessions', RSA_DB::sessions_table() );
	}

	public function test_clicks_table_helper(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_clicks', RSA_DB::clicks_table() );
	}

	public function test_heatmap_table_helper(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_heatmap', RSA_DB::heatmap_table() );
	}

	public function test_wc_events_table_helper(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_wc_events', RSA_DB::wc_events_table() );
	}

	/**
	 * ----------------------------------------------------------------
	 * Freemius sync — non-blocking cron
	 * ----------------------------------------------------------------
	 */
	public function test_schedule_freemius_sync_creates_cron_event(): void {
		// Clear any existing cron event.
		$timestamp = wp_next_scheduled( 'rsa_freemius_sync_beta' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'rsa_freemius_sync_beta' );
		}
		$this->assertFalse( wp_next_scheduled( 'rsa_freemius_sync_beta' ) );

		RSA_Admin::schedule_freemius_sync();
		$this->assertNotFalse( wp_next_scheduled( 'rsa_freemius_sync_beta' ) );

		// Clean up.
		wp_unschedule_event( wp_next_scheduled( 'rsa_freemius_sync_beta' ), 'rsa_freemius_sync_beta' );
	}

	public function test_run_freemius_sync_does_not_fatal_when_freemius_missing(): void {
		// rs_fs() is not available in tests — verify no fatal error.
		$this->assertNull( RSA_Admin::run_freemius_sync() );
	}

	/**
	 * ----------------------------------------------------------------
	 * Admin page data — get_page_data_for_current_screen via reflection
	 * ----------------------------------------------------------------
	 */
	public function test_get_page_data_for_current_screen_returns_array(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'get_page_data_for_current_screen' );
		$method->setAccessible( true );
		$_GET['period'] = '30d';
		$result         = $method->invoke( null, 'toplevel_page_rich-statistics' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'view', $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'period', $result );
	}

	public function test_get_page_data_for_current_screen_pages_view(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'get_page_data_for_current_screen' );
		$method->setAccessible( true );
		$_GET['period'] = '30d';
		$_GET['path']   = '/test/';
		$result         = $method->invoke( null, 'rich-statistics_page_rich-statistics-pages' );
		$this->assertSame( 'pages', $result['view'] );
		$this->assertIsArray( $result['data'] );
	}

	public function test_get_page_data_for_current_screen_audience_view(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'get_page_data_for_current_screen' );
		$method->setAccessible( true );
		$_GET['period'] = '30d';
		$result         = $method->invoke( null, 'rich-statistics_page_rich-statistics-audience' );
		$this->assertSame( 'audience', $result['view'] );
		$this->assertIsArray( $result['data'] );
	}
}
