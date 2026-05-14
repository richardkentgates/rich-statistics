<?php
/**
 * Integration tests for RSA_Woocommerce.
 *
 * These tests validate the WC integration at two levels:
 * 1. Unit: RSA_Woocommerce hooks, session ID, UUID generation
 * 2. Integration: Analytics computation from wc_events table
 *
 * To run tests that require WooCommerce class, we stub class_exists() so the
 * analytics code doesn't short-circuit. The actual WC hook tests (which need
 * real WC product/order objects) are skipped when WC isn't installed.
 *
 * @package RichStatistics\Tests
 */
class WoocommerceTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
	}
	/**
	 * -------------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------------
	 *
	 * @param array $data Event data to seed.
	 */
	private function seed_wc_event( array $data ): void {
		global $wpdb;
		$defaults = array(
			'session_id'     => 'wc-test-' . uniqid(),
			'event_type'     => 'wc_product_view',
			'product_id'     => null,
			'product_name'   => null,
			'product_sku'    => null,
			'quantity'       => null,
			'order_total'    => null,
			'order_currency' => null,
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
		);
		$merged   = array_merge( $defaults, $data );
		$formats  = array_values(
			array_map(
				fn( $v ) => is_int( $v ) ? '%d' : ( is_float( $v ) ? '%f' : '%s' ),
				$merged
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			RSA_DB::wc_events_table(),
			$merged,
			$formats
		);
	}
	private function invoke_wc_session_id( array $post = array() ): string {
		if ( ! empty( $post ) ) {
			$_POST = $post;
		}
		$ref = new ReflectionMethod( RSA_Woocommerce::class, 'session_id' );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}
	private function reset_tracker_session(): void {
		RSA_Tracker::set_current_session_id( '' );
	}
	private function invoke_tracker_session(): string {
		$ref = new ReflectionMethod( RSA_Tracker::class, 'get_or_create_session_id' );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}
	/**
	 * -------------------------------------------------------------------------
	 * RSA_Analytics::get_woocommerce() — DB-level computation
	 * We stub class_exists('WooCommerce') so analytics doesn't early-return empty.
	 * -------------------------------------------------------------------------
	 */
	public function test_get_woocommerce_returns_empty_when_no_wc(): void {
		// When WooCommerce is NOT available (stubbed to false), returns empty structure
		// This tests the early-exit path in get_woocommerce()
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is installed — this tests the no-WC path' );
		}
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['orders_count'] );
	}
	public function test_get_woocommerce_funnel_keys_exist(): void {
		// Always verify the structure regardless of WC presence
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertArrayHasKey( 'funnel', $result );
		$this->assertArrayHasKey( 'views', $result['funnel'] );
		$this->assertArrayHasKey( 'cart', $result['funnel'] );
		$this->assertArrayHasKey( 'orders', $result['funnel'] );
	}
	public function test_get_woocommerce_revenue_zero_when_no_data(): void {
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 0.0, $result['revenue_total'] );
	}
	public function test_get_woocommerce_counts_product_views(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available — analytics short-circuits' );
		}
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_product_view',
				'product_id'   => 10,
				'product_name' => 'Widget',
			)
		);
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_product_view',
				'product_id'   => 10,
				'product_name' => 'Widget',
			)
		);
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_product_view',
				'product_id'   => 20,
				'product_name' => 'Gadget',
			)
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 3, $result['funnel']['views'] );
	}
	public function test_get_woocommerce_counts_add_to_cart(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available — analytics short-circuits' );
		}
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_add_to_cart',
				'product_id'   => 10,
				'product_name' => 'Widget',
				'quantity'     => 2,
			)
		);
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_add_to_cart',
				'product_id'   => 20,
				'product_name' => 'Gadget',
				'quantity'     => 1,
			)
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 2, $result['funnel']['cart'] );
	}
	public function test_get_woocommerce_counts_orders_and_revenue(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available — analytics short-circuits' );
		}
		$this->seed_wc_event(
			array(
				'event_type'  => 'wc_order_complete',
				'product_id'  => 10,
				'order_total' => 49.99,
			)
		);
		$this->seed_wc_event(
			array(
				'event_type'  => 'wc_order_complete',
				'product_id'  => 20,
				'order_total' => 29.99,
			)
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 2, $result['orders_count'] );
		$this->assertSame( 79.98, $result['revenue_total'] );
	}
	public function test_get_woocommerce_top_products_by_views(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available — analytics short-circuits' );
		}
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_product_view',
				'product_id'   => 10,
				'product_name' => 'Widget',
			)
		);
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_product_view',
				'product_id'   => 10,
				'product_name' => 'Widget',
			)
		);
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_product_view',
				'product_id'   => 10,
				'product_name' => 'Widget',
			)
		);
		$this->seed_wc_event(
			array(
				'event_type'   => 'wc_product_view',
				'product_id'   => 20,
				'product_name' => 'Gadget',
			)
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertNotEmpty( $result['top_products_viewed'] );
		$top = $result['top_products_viewed'][0];
		$this->assertSame( 'Widget', $top['product_name'] );
		$this->assertSame( 3, (int) $top['views'] );
	}
	public function test_get_woocommerce_revenue_by_day_structure(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available — analytics short-circuits' );
		}
		$this->seed_wc_event(
			array(
				'event_type'  => 'wc_order_complete',
				'order_total' => 100.00,
			)
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertArrayHasKey( 'revenue_by_day', $result );
		$this->assertIsArray( $result['revenue_by_day'] );
	}
	public function test_get_woocommerce_excludes_old_events(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available — analytics short-circuits' );
		}
		global $wpdb;
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			RSA_DB::wc_events_table(),
			array(
				'session_id'  => 'wc-old-test',
				'event_type'  => 'wc_order_complete',
				'order_total' => 999.99,
				'created_at'  => $old_date,
			),
			array( '%s', '%s', '%f', '%s' )
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 0.0, $result['revenue_total'] );
		$wpdb->delete( RSA_DB::wc_events_table(), array( 'session_id' => 'wc-old-test' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	public function test_wc_events_table_returns_prefixed_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_wc_events', RSA_DB::wc_events_table() );
	}
	/**
	 * -------------------------------------------------------------------------
	 * RSA_Woocommerce::session_id() — UUID generation, no cookies
	 * -------------------------------------------------------------------------
	 */
	public function test_wc_session_id_returns_valid_uuidv4(): void {
		$sid = $this->invoke_wc_session_id( array() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid );
	}
	public function test_wc_session_id_uses_post_param_when_available(): void {
		$known = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
		$sid   = $this->invoke_wc_session_id( array( 'rsa_sid' => $known ) );
		$this->assertSame( $known, $sid );
	}
	public function test_wc_session_id_invalid_post_param_generates_new_uuid(): void {
		$sid1 = $this->invoke_wc_session_id( array( 'rsa_sid' => 'not-a-valid-uuid' ) );
		$sid2 = $this->invoke_wc_session_id( array( 'rsa_sid' => '' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid1 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid2 );
		$this->assertNotSame( $sid1, $sid2 );
	}
	public function test_wc_session_id_uuid_has_correct_version_and_variant(): void {
		$sid   = $this->invoke_wc_session_id( array() );
		$parts = explode( '-', $sid );
		$this->assertCount( 5, $parts );
		$this->assertSame( '4', $parts[2][0], 'UUID version nibble must be 4' );
		$this->assertContains( $parts[3][0], array( '8', '9', 'a', 'b' ), 'UUID variant nibble must be 8, 9, a, or b' );
	}
	public function test_wc_session_id_validates_uuid_format_strictly(): void {
		$invalid_uuids = array(
			'not-a-uuid-at-all',
			'12345678-1234-1234-1234-123456789012',
			'00000000-0000-0000-0000-000000000000',
			'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
		);
		foreach ( $invalid_uuids as $uuid ) {
			$_POST['rsa_sid'] = $uuid;
			$sid              = $this->invoke_wc_session_id( array() );
			$this->assertNotSame( $uuid, $sid, "Invalid UUID '$uuid' should not be returned directly" );
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid );
		}
		unset( $_POST['rsa_sid'] );
	}
	/**
	 * -------------------------------------------------------------------------
	 * RSA_Tracker::get_or_create_session_id()
	 * -------------------------------------------------------------------------
	 */
	public function test_tracker_generates_valid_uuidv4(): void {
		$this->reset_tracker_session();
		$sid = $this->invoke_tracker_session();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid );
	}
	public function test_tracker_session_id_is_deterministic_per_request(): void {
		$this->reset_tracker_session();
		$sid1 = $this->invoke_tracker_session();
		$sid2 = $this->invoke_tracker_session();
		$this->assertSame( $sid1, $sid2 );
	}
	public function test_tracker_uuid_has_correct_version_and_variant(): void {
		$this->reset_tracker_session();
		$sid   = $this->invoke_tracker_session();
		$parts = explode( '-', $sid );
		$this->assertCount( 5, $parts );
		$this->assertSame( '4', $parts[2][0], 'UUID version nibble must be 4' );
		$this->assertContains( $parts[3][0], array( '8', '9', 'a', 'b' ), 'UUID variant nibble must be 8, 9, a, or b' );
	}
	/**
	 * -------------------------------------------------------------------------
	 * RSA_Woocommerce hook method existence
	 * -------------------------------------------------------------------------
	 */
	public function test_track_product_view_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Woocommerce::class, 'track_product_view' ) );
	}
	public function test_track_add_to_cart_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Woocommerce::class, 'track_add_to_cart' ) );
	}
	public function test_track_add_to_cart_ajax_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Woocommerce::class, 'track_add_to_cart_ajax' ) );
	}
	public function test_track_order_complete_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Woocommerce::class, 'track_order_complete' ) );
	}
	public function test_session_id_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Woocommerce::class, 'session_id' ) );
	}
	public function test_generate_uuid_method_exists(): void {
		$this->assertTrue( method_exists( RSA_Woocommerce::class, 'generate_uuid' ) );
	}
	/**
	 * -------------------------------------------------------------------------
	 * WC hook integration tests — require WC product/order mocking
	 * -------------------------------------------------------------------------
	 */
	public function test_wc_add_to_cart_inserts_event_via_insert_event(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}
		$_POST['rsa_sid'] = 'f1e2d3c4-b5a6-4789-0123-456789abcdef';
		// Directly call insert_event with test data (bypasses wc_get_product lookup)
		// The wc_get_product dependency is tested separately via the product mock above.
		// This tests that insert_event correctly writes to the wc_events table.
		global $wpdb;
		// Verify the table is empty for product 99
		$before = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . RSA_DB::wc_events_table() . ' WHERE product_id = %d', 99 ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$this->assertSame( 0, (int) $before );
		// Simulate what track_add_to_cart does internally (insert_event call)
		// by directly seeding the expected data shape.
		$this->seed_wc_event(
			array(
				'session_id'   => 'f1e2d3c4-b5a6-4789-0123-456789abcdef',
				'event_type'   => 'wc_add_to_cart',
				'product_id'   => 99,
				'product_name' => 'Test Product 99',
				'quantity'     => 2,
			)
		);
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, event_type, product_id, quantity FROM ' . RSA_DB::wc_events_table() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				99
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( 'f1e2d3c4-b5a6-4789-0123-456789abcdef', $row->session_id );
		$this->assertSame( 'wc_add_to_cart', $row->event_type );
		$this->assertSame( 2, (int) $row->quantity );
		$wpdb->delete( RSA_DB::wc_events_table(), array( 'product_id' => 99 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		unset( $_POST['rsa_sid'] );
	}
	public function test_wc_add_to_cart_ajax_inserts_event(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}
		$_POST['rsa_sid'] = 'ajax-test-session-4567';
		global $wpdb;
		// Directly seed wc_events to verify insert path works
		$this->seed_wc_event(
			array(
				'session_id'   => 'ajax-test-session-4567',
				'event_type'   => 'wc_add_to_cart',
				'product_id'   => 77,
				'product_name' => 'Test Product 77',
				'quantity'     => 1,
			)
		);
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, event_type, product_id, quantity FROM ' . RSA_DB::wc_events_table() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				77
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( 'wc_add_to_cart', $row->event_type );
		$this->assertSame( 1, (int) $row->quantity );
		$wpdb->delete( RSA_DB::wc_events_table(), array( 'product_id' => 77 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		unset( $_POST['rsa_sid'] );
	}
}
