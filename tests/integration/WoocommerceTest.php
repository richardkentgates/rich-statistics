<?php
/**
 * Integration tests for RSA_Woocommerce.
 *
 * Validates analytics computation from wc_events table and the
 * insert_event() method (called by the REST API ingest endpoint).
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
			'session_id'   => 'wc-test-' . uniqid(),
			'event_type'   => 'wc_product_view',
			'product_id'   => null,
			'product_name' => null,
			'product_sku'  => null,
			'quantity'     => null,
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
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
	 * -------------------------------------------------------------------------
	 */
	public function test_get_woocommerce_returns_empty_when_no_wc(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is installed — this tests the no-WC path' );
		}
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['orders_count'] );
	}
	public function test_get_woocommerce_funnel_keys_exist(): void {
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertArrayHasKey( 'funnel', $result );
		$this->assertArrayHasKey( 'views', $result['funnel'] );
		$this->assertArrayHasKey( 'cart', $result['funnel'] );
		$this->assertArrayHasKey( 'orders', $result['funnel'] );
	}
	public function test_get_woocommerce_orders_zero_when_no_data(): void {
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 0, $result['orders_count'] );
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
				'event_type' => 'wc_order_complete',
				'product_id' => 10,
			)
		);
		$this->seed_wc_event(
			array(
				'event_type' => 'wc_order_complete',
				'product_id' => 20,
			)
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 2, $result['orders_count'] );
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
	public function test_get_woocommerce_excludes_old_events(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available — analytics short-circuits' );
		}
		global $wpdb;
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			RSA_DB::wc_events_table(),
			array(
				'session_id' => 'wc-old-test',
				'event_type' => 'wc_order_complete',
				'created_at' => $old_date,
			),
			array( '%s', '%s', '%s' )
		);
		$result = RSA_Analytics::get_woocommerce( '30d' );
		$this->assertSame( 0, $result['orders_count'] );
		$wpdb->delete( RSA_DB::wc_events_table(), array( 'session_id' => 'wc-old-test' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	public function test_wc_events_table_returns_prefixed_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_wc_events', RSA_DB::wc_events_table() );
	}
	/**
	 * -------------------------------------------------------------------------
	 * RSA_Woocommerce::insert_event()
	 * -------------------------------------------------------------------------
	 */
	public function test_insert_event_writes_to_wc_events_table(): void {
		global $wpdb;
		$sid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
		RSA_Woocommerce::insert_event(
			'wc_add_to_cart',
			array(
				'product_id'   => 99,
				'product_name' => 'Test Product',
				'quantity'     => 2,
			),
			$sid
		);
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, event_type, product_id, quantity FROM ' . RSA_DB::wc_events_table() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				99
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( $sid, $row->session_id );
		$this->assertSame( 'wc_add_to_cart', $row->event_type );
		$this->assertSame( 2, (int) $row->quantity );
		$wpdb->delete( RSA_DB::wc_events_table(), array( 'product_id' => 99 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	public function test_insert_event_ignores_invalid_session_id(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RSA_DB::wc_events_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		RSA_Woocommerce::insert_event(
			'wc_product_view',
			array( 'product_id' => 1 ),
			'not-a-valid-uuid'
		);
		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RSA_DB::wc_events_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( $before, $after );
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
}
