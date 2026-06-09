<?php
/**
 * Admin Page Helpers Test
 *
 * Tests profile_webapp_section(), period_selector(), and other
 * admin rendering helpers that were previously uncovered.
 *
 * @package RichStatistics\Tests
 */
class AdminHelpersTest extends WP_UnitTestCase {

	private static $admin;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
	}

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::$admin->ID );
		RSA_DB::install();
	}

	public function test_profile_webapp_section_outputs_app_section(): void {
		ob_start();
		RSA_Admin::profile_webapp_section( self::$admin );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Rich Statistics App', $html );
		$this->assertStringContainsString( 'rsa-webapp-row', $html );
	}

	public function test_profile_webapp_section_has_button_or_upsell(): void {
		ob_start();
		RSA_Admin::profile_webapp_section( self::$admin );
		$html = ob_get_clean();
		// In premium builds, shows OTP button; in free builds, shows upsell.
		$has_button = strpos( $html, 'Generate App Code' ) !== false || strpos( $html, 'Upgrade to unlock' ) !== false;
		$this->assertTrue( $has_button, 'Profile section should contain either OTP button or upsell link' );
	}

	public function test_period_selector_returns_string(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'period_selector' );
		$method->setAccessible( true );
		$result = $method->invoke( null, '30d' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Last 30 days', $result );
	}

	public function test_period_selector_contains_active_period(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'period_selector' );
		$method->setAccessible( true );
		$result = $method->invoke( null, '7d' );
		$this->assertStringContainsString( 'rsa-period-active', $result );
	}

	public function test_period_selector_includes_custom_range_form(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'period_selector' );
		$method->setAccessible( true );
		$_GET['period']    = 'custom';
		$_GET['date_from'] = '2024-01-01';
		$_GET['date_to']   = '2024-01-31';
		$result            = $method->invoke( null, 'custom' );
		$this->assertStringContainsString( 'custom', $result );
		$this->assertStringContainsString( '2024-01-01', $result );
		$this->assertStringContainsString( '2024-01-31', $result );
	}

	public function test_get_page_data_for_current_screen_campaigns(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'get_page_data_for_current_screen' );
		$method->setAccessible( true );
		$_GET['period'] = '30d';
		$result         = $method->invoke( null, 'rich-statistics_page_rich-statistics-campaigns' );
		$this->assertSame( 'campaigns', $result['view'] );
		$this->assertIsArray( $result['data'] );
	}

	public function test_get_page_data_for_current_screen_behavior(): void {
		$method = new ReflectionMethod( RSA_Admin::class, 'get_page_data_for_current_screen' );
		$method->setAccessible( true );
		$_GET['period'] = '30d';
		$result         = $method->invoke( null, 'rich-statistics_page_rich-statistics-behavior' );
		$this->assertSame( 'behavior', $result['view'] );
		$this->assertIsArray( $result['data'] );
	}
}
