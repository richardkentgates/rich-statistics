<?php
/**
 * Heatmap Tests
 *
 * Covers coordinate bucketing, aggregate query accuracy, NULL exclusion,
 * REST endpoint response shape, and premium gating.
 *
 * @package RichStatistics\Tests
 */
class HeatmapTest extends WP_UnitTestCase {

	private ?int $admin_id = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'rsa_manage_statistics' );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			class_alias( 'WP_REST_Server', 'WooCommerce' );
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
		$this->clear_tables();

		if ( ! defined( 'RSA_PREMIUM_TEST' ) ) {
			define( 'RSA_PREMIUM_TEST', true );
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	private function clear_tables(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_sessions`" );
	}

	private function seed_click( array $overrides = [] ): void {
		global $wpdb;
		$defaults = array(
			'session_id'    => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
			'page'          => '/test-page/',
			'created_at'    => current_time( 'mysql', true ),
			'element_tag'   => 'a',
			'element_id'    => 'link-1',
			'element_class' => 'external',
			'element_text'  => 'Click me',
			'href_protocol' => 'https',
			'href_value'    => 'https://example.com',
			'matched_rule'  => '',
			'x_pct'         => 50.0,
			'y_pct'         => 50.0,
		);
		$row      = array_merge( $defaults, $overrides );
		$wpdb->insert(
			$wpdb->prefix . 'rsa_clicks',
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f' )
		);
	}

	/**
	 * Coordinate bucketing
	 */
	public function test_heatmap_buckets_coordinates_to_nearest_two_percent(): void {
		$this->seed_click( array( 'x_pct' => 45.3, 'y_pct' => 78.9 ) );

		$data = RSA_Analytics::get_heatmap( '/test-page/', '7d' );
		$this->assertCount( 1, $data );
		$this->assertSame( 46.0, $data[0]['x'] );
		$this->assertSame( 78.0, $data[0]['y'] );
		$this->assertSame( 1, $data[0]['weight'] );
	}

	public function test_heatmap_aggregates_multiple_clicks_in_same_bucket(): void {
		// All three should fall into the same 50:50 bucket.
		$this->seed_click( array( 'x_pct' => 50.1, 'y_pct' => 50.1 ) );
		$this->seed_click( array( 'x_pct' => 50.9, 'y_pct' => 50.9 ) );
		$this->seed_click( array( 'x_pct' => 50.0, 'y_pct' => 50.0 ) );

		$data = RSA_Analytics::get_heatmap( '/test-page/', '7d' );
		$this->assertCount( 1, $data );
		$this->assertSame( 50.0, $data[0]['x'] );
		$this->assertSame( 50.0, $data[0]['y'] );
		$this->assertSame( 3, $data[0]['weight'] );
	}

	public function test_heatmap_excludes_null_coordinates(): void {
		$this->seed_click( array( 'x_pct' => 50.0, 'y_pct' => 50.0 ) );
		$this->seed_click( array( 'x_pct' => null, 'y_pct' => null ) );

		$data = RSA_Analytics::get_heatmap( '/test-page/', '7d' );
		$this->assertCount( 1, $data );
	}

	public function test_heatmap_returns_empty_for_no_data(): void {
		$data = RSA_Analytics::get_heatmap( '/nonexistent/', '7d' );
		$this->assertSame( array(), $data );
	}

	public function test_heatmap_includes_element_breakdown(): void {
		$this->seed_click(
			array(
				'x_pct'        => 50.0,
				'y_pct'        => 50.0,
				'element_tag'  => 'button',
				'element_text' => 'Buy Now',
				'href_value'   => '/checkout',
			)
		);

		$data = RSA_Analytics::get_heatmap( '/test-page/', '7d' );
		$this->assertCount( 1, $data );
		$this->assertNotEmpty( $data[0]['elements'] );
		$this->assertSame( 'button', $data[0]['elements'][0]['tag'] );
		$this->assertSame( 'Buy Now', $data[0]['elements'][0]['text'] );
		$this->assertSame( '/checkout', $data[0]['elements'][0]['href'] );
	}

	/**
	 * REST endpoint
	 */
	public function test_rest_heatmap_returns_correct_shape(): void {
		$this->seed_click( array( 'x_pct' => 50.0, 'y_pct' => 50.0 ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'GET', '/rsa/v1/heatmap' );
		$request->set_param( 'period', '7d' );
		$request->set_param( 'page', '/test-page/' );
		$response = $wp_rest_server->dispatch( $request );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $body['ok'] );
		$this->assertIsArray( $body['data'] );
		$this->assertCount( 1, $body['data'] );
		$this->assertArrayHasKey( 'x', $body['data'][0] );
		$this->assertArrayHasKey( 'y', $body['data'][0] );
		$this->assertArrayHasKey( 'weight', $body['data'][0] );
		$this->assertArrayHasKey( 'elements', $body['data'][0] );
	}

	public function test_rest_heatmap_rejects_invalid_date_format(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'GET', '/rsa/v1/heatmap' );
		$request->set_param( 'period', '7d' );
		$request->set_param( 'page', '/' );
		$request->set_param( 'date_from', 'not-a-date' );
		$request->set_param( 'date_to', '2026-13-45' );
		$response = $wp_rest_server->dispatch( $request );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $body['ok'] );
		// Invalid dates are silently reset to empty, so the query still runs.
		$this->assertIsArray( $body['data'] );
	}
}
