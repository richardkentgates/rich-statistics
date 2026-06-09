<?php
/**
 * Integration tests for RSA_Tracker — init, enqueue, and localize script.
 *
 * @package RichStatistics\Tests
 */
class TrackerInitTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Ensure clean script state — $wp_scripts persists across WP unit tests.
		global $wp_scripts;
		$wp_scripts = new WP_Scripts();
	}

	public function test_init_registers_hooks(): void {
		$this->assertTrue( has_action( 'wp_enqueue_scripts', [ 'RSA_Tracker', 'enqueue' ] ) > 0 );
		$this->assertTrue( has_action( 'wp_ajax_nopriv_rsa_track', [ 'RSA_Tracker', 'handle_ingest' ] ) > 0 );
		$this->assertTrue( has_action( 'wp_ajax_rsa_track', [ 'RSA_Tracker', 'handle_ingest' ] ) > 0 );
	}

	public function test_enqueue_localizes_script(): void {
		wp_enqueue_script( 'rsa-tracker', RSA_ASSETS_URL . 'js/tracker.js', [], RSA_VERSION, true );
		RSA_Tracker::enqueue();

		global $wp_scripts;
		$localized = $wp_scripts->get_data( 'rsa-tracker', 'data' );
		$this->assertNotEmpty( $localized );
		$this->assertStringContainsString( 'RSA', $localized );
		$this->assertStringContainsString( 'sessionId', $localized );
	}

	/**
	 * @group multisite
	 */
	public function test_multisite_disable_flag_prevents_enqueue(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite environment.' );
		}

		// Reset script state — other tests may have enqueued this.
		wp_dequeue_script( 'rsa-tracker' );
		wp_deregister_script( 'rsa-tracker' );

		update_site_option( 'rsa_network_disable_tracker', 1 );

		RSA_Tracker::enqueue();

		$this->assertFalse( wp_script_is( 'rsa-tracker', 'enqueued' ) );

		// Cleanup.
		delete_site_option( 'rsa_network_disable_tracker' );
	}
}
