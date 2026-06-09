<?php
/**
 * Integration tests for RSA_Admin menu registration.
 *
 * @package RichStatistics\Tests
 */
class AdminMenusTest extends WP_UnitTestCase {

	/** @var WP_User */
	private static $admin;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		// Add manage_network_options to the *user* (not the role) so other tests
		// that rely on the default single-site administrator role are not affected.
		self::$admin->add_cap( 'manage_network_options' );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$admin ) {
			self::$admin->remove_cap( 'manage_network_options' );
		}
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::$admin->ID );
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();
		parent::tearDown();
	}

	/**
	 * ----------------------------------------------------------------
	 * register_menus()
	 * ----------------------------------------------------------------
	 */
	public function test_register_menus_creates_top_level_menu(): void {
		RSA_Admin::register_menus();
		global $menu;
		$slugs = wp_list_pluck( $menu, 2 );
		$this->assertContains( 'rich-statistics', $slugs );
	}

	public function test_register_menus_creates_overview_submenu(): void {
		RSA_Admin::register_menus();
		global $submenu;
		$this->assertArrayHasKey( 'rich-statistics', $submenu );
		$slugs = wp_list_pluck( $submenu['rich-statistics'], 2 );
		$this->assertContains( 'rich-statistics', $slugs );
	}

	public function test_register_menus_creates_expected_submenus(): void {
		RSA_Admin::register_menus();
		global $submenu;
		$this->assertArrayHasKey( 'rich-statistics', $submenu );
		$slugs    = wp_list_pluck( $submenu['rich-statistics'], 2 );
		$expected = array(
			'rich-statistics',
			'rich-statistics-pages',
			'rich-statistics-audience',
			'rich-statistics-referrers',
			'rich-statistics-behavior',
		);
		foreach ( $expected as $slug ) {
			$this->assertContains( $slug, $slugs, "Missing submenu: {$slug}" );
		}
	}

	public function test_register_menus_requires_rsa_manage_statistics(): void {
		RSA_Admin::register_menus();
		global $menu;
		$rich_stats_menu = array_filter( $menu, fn( $m ) => $m[2] === 'rich-statistics' );
		$this->assertNotEmpty( $rich_stats_menu );
		$first = reset( $rich_stats_menu );
		$this->assertSame( 'rsa_manage_statistics', $first[1] );
	}

	/**
	 * ----------------------------------------------------------------
	 * register_network_menus()
	 * ----------------------------------------------------------------
	 */
	public function test_register_network_menus_creates_top_level_menu(): void {
		RSA_Admin::register_network_menus();
		global $menu;
		$slugs = wp_list_pluck( $menu, 2 );
		$this->assertContains( 'rich-statistics-network', $slugs );
	}

	public function test_register_network_menus_creates_network_settings_submenu(): void {
		RSA_Admin::register_network_menus();
		global $submenu;
		$this->assertArrayHasKey( 'rich-statistics-network', $submenu );
		$slugs = wp_list_pluck( $submenu['rich-statistics-network'], 2 );
		$this->assertContains( 'rich-statistics-network-settings', $slugs );
	}

	public function test_register_network_menus_requires_manage_network_options(): void {
		RSA_Admin::register_network_menus();
		global $menu;
		$network_menu = array_filter( $menu, fn( $m ) => $m[2] === 'rich-statistics-network' );
		$this->assertNotEmpty( $network_menu );
		$first = reset( $network_menu );
		$this->assertSame( 'manage_network_options', $first[1] );
	}
}
