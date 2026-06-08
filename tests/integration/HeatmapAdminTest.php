<?php
/**
 * Integration tests for RSA_Heatmap — admin asset enqueueing.
 *
 * @package RichStatistics\Tests
 */
class HeatmapAdminTest extends WP_UnitTestCase {

	/** @var WP_User */
	protected static WP_User $admin;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$admin = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
	}

	public function test_heatmap_init_registers_admin_hook(): void {
		$this->assertTrue( has_action( 'admin_enqueue_scripts', [ 'RSA_Heatmap', 'enqueue_heatmap_assets' ] ) > 0 );
	}

	public function test_heatmap_class_has_enqueue_method(): void {
		$this->assertTrue( method_exists( 'RSA_Heatmap', 'enqueue_heatmap_assets' ) );
	}
}
