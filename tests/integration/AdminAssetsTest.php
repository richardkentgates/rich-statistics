<?php
/**
 * Integration tests for RSA_Admin asset enqueueing.
 *
 * @package RichStatistics\Tests
 */
class AdminAssetsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wp_scripts, $wp_styles;
		$wp_scripts = new WP_Scripts();
		$wp_styles  = new WP_Styles();
	}

	public function tearDown(): void {
		global $wp_scripts, $wp_styles;
		$wp_scripts = null;
		$wp_styles  = null;
		parent::tearDown();
	}

	/**
	 * ----------------------------------------------------------------
	 * enqueue_assets() — valid hook
	 * ----------------------------------------------------------------
	 */
	public function test_enqueue_assets_on_valid_hook_loads_chartjs(): void {
		RSA_Admin::enqueue_assets( 'toplevel_page_rich-statistics' );
		$this->assertTrue( wp_script_is( 'rsa-chartjs', 'enqueued' ) );
	}

	public function test_enqueue_assets_on_valid_hook_loads_admin_css(): void {
		RSA_Admin::enqueue_assets( 'toplevel_page_rich-statistics' );
		$this->assertTrue( wp_style_is( 'rsa-admin', 'enqueued' ) );
	}

	public function test_enqueue_assets_on_valid_hook_loads_admin_charts_js(): void {
		RSA_Admin::enqueue_assets( 'toplevel_page_rich-statistics' );
		$this->assertTrue( wp_script_is( 'rsa-admin-charts', 'enqueued' ) );
	}

	public function test_enqueue_assets_localizes_rsa_data(): void {
		RSA_Admin::enqueue_assets( 'toplevel_page_rich-statistics' );
		global $wp_scripts;
		$data = $wp_scripts->get_data( 'rsa-admin-charts', 'data' );
		$this->assertNotEmpty( $data );
		$this->assertStringContainsString( 'RSA_DATA', $data );
	}

	/**
	 * ----------------------------------------------------------------
	 * enqueue_assets() — invalid hook (no-op)
	 * ----------------------------------------------------------------
	 */
	public function test_enqueue_assets_on_unrelated_hook_does_nothing(): void {
		RSA_Admin::enqueue_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( 'rsa-chartjs', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'rsa-admin', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'rsa-admin-charts', 'enqueued' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * enqueue_profile_assets()
	 * ----------------------------------------------------------------
	 */
	public function test_enqueue_profile_assets_on_profile_page(): void {
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		RSA_Admin::enqueue_profile_assets( 'profile.php' );
		$this->assertTrue( wp_script_is( 'rsa-profile-otp', 'enqueued' ) );
	}

	public function test_enqueue_profile_assets_skips_without_cap(): void {
		$sub = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub->ID );
		RSA_Admin::enqueue_profile_assets( 'profile.php' );
		$this->assertFalse( wp_script_is( 'rsa-profile-otp', 'enqueued' ) );
	}

	public function test_enqueue_profile_assets_skips_on_wrong_hook(): void {
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		RSA_Admin::enqueue_profile_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( 'rsa-profile-otp', 'enqueued' ) );
	}

	public function test_enqueue_profile_assets_localizes_rsa_otp(): void {
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		RSA_Admin::enqueue_profile_assets( 'profile.php' );
		global $wp_scripts;
		$data = $wp_scripts->get_data( 'rsa-profile-otp', 'data' );
		$this->assertNotEmpty( $data );
		$this->assertStringContainsString( 'rsaOtp', $data );
	}
}
