<?php
/**
 * Integration tests for RSA_Woocommerce.
 *
 * @package RichStatistics\Tests
 */

class WoocommerceTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce class not available' );
		}
	}

	private function seed_wc_event( array $data ): void {
		global $wpdb;
		$defaults = [
			'session_id'  => 'wc-test-' . uniqid(),
			'event_type'  => 'wc_product_view',
			'product_id'  => null,
			'product_name' => null,
			'product_sku' => null,
			'quantity'    => null,
			'order_total' => null,
			'order_currency' => null,
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		];
		$merged = array_merge( $defaults, $data );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			RSA_DB::wc_events_table(),
			$merged,
			array_values( array_map(
				fn( $v ) => is_int( $v ) ? '%d' : ( is_float( $v ) ? '%f' : '%s' ),
				$merged
			) )
		);
	}

	// ----------------------------------------------------------------
	// get_woocommerce() structure — no WC class
	// ----------------------------------------------------------------

	public function test_get_woocommerce_returns_empty_when_no_wc(): void {
		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['orders_count'] );
		$this->assertEmpty( $result['top_products_viewed'] );
		$this->assertEmpty( $result['top_products_cart'] );
	}

	public function test_get_woocommerce_funnel_keys_exist(): void {
		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertArrayHasKey( 'funnel', $result );
		$this->assertArrayHasKey( 'views',  $result['funnel'] );
		$this->assertArrayHasKey( 'cart',   $result['funnel'] );
		$this->assertArrayHasKey( 'orders', $result['funnel'] );
	}

	public function test_get_woocommerce_revenue_zero_when_no_data(): void {
		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertSame( 0.0, $result['revenue_total'] );
	}

	// ----------------------------------------------------------------
	// get_woocommerce() with seeded WC events
	// ----------------------------------------------------------------

	public function test_get_woocommerce_counts_product_views(): void {
		$this->seed_wc_event( [ 'event_type' => 'wc_product_view', 'product_id' => 10, 'product_name' => 'Widget' ] );
		$this->seed_wc_event( [ 'event_type' => 'wc_product_view', 'product_id' => 10, 'product_name' => 'Widget' ] );
		$this->seed_wc_event( [ 'event_type' => 'wc_product_view', 'product_id' => 20, 'product_name' => 'Gadget' ] );

		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertSame( 3, $result['funnel']['views'] );
	}

	public function test_get_woocommerce_counts_add_to_cart(): void {
		$this->seed_wc_event( [ 'event_type' => 'wc_add_to_cart', 'product_id' => 10, 'product_name' => 'Widget', 'quantity' => 2 ] );
		$this->seed_wc_event( [ 'event_type' => 'wc_add_to_cart', 'product_id' => 20, 'product_name' => 'Gadget', 'quantity' => 1 ] );

		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertSame( 2, $result['funnel']['cart'] );
	}

	public function test_get_woocommerce_counts_orders_and_revenue(): void {
		$this->seed_wc_event( [ 'event_type' => 'wc_order_complete', 'product_id' => 10, 'order_total' => 49.99 ] );
		$this->seed_wc_event( [ 'event_type' => 'wc_order_complete', 'product_id' => 20, 'order_total' => 29.99 ] );

		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertSame( 2, $result['orders_count'] );
		$this->assertSame( 79.98, $result['revenue_total'] );
	}

	public function test_get_woocommerce_top_products_by_views(): void {
		$this->seed_wc_event( [ 'event_type' => 'wc_product_view', 'product_id' => 10, 'product_name' => 'Widget' ] );
		$this->seed_wc_event( [ 'event_type' => 'wc_product_view', 'product_id' => 10, 'product_name' => 'Widget' ] );
		$this->seed_wc_event( [ 'event_type' => 'wc_product_view', 'product_id' => 10, 'product_name' => 'Widget' ] );
		$this->seed_wc_event( [ 'event_type' => 'wc_product_view', 'product_id' => 20, 'product_name' => 'Gadget' ] );

		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertNotEmpty( $result['top_products_viewed'] );
		$top = $result['top_products_viewed'][0];
		$this->assertSame( 'Widget', $top['product_name'] );
		$this->assertSame( 3, (int) $top['views'] );
	}

	public function test_get_woocommerce_revenue_by_day_structure(): void {
		$this->seed_wc_event( [ 'event_type' => 'wc_order_complete', 'order_total' => 100.00 ] );

		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertArrayHasKey( 'revenue_by_day', $result );
		$this->assertIsArray( $result['revenue_by_day'] );
	}

	// ----------------------------------------------------------------
	// period filter — events outside range excluded
	// ----------------------------------------------------------------

	public function test_get_woocommerce_excludes_old_events(): void {
		global $wpdb;
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			RSA_DB::wc_events_table(),
			[
				'session_id'  => 'wc-old-test',
				'event_type'  => 'wc_order_complete',
				'order_total' => 999.99,
				'created_at'  => $old_date,
			],
			[ '%s', '%s', '%f', '%s' ]
		);

		$result = RSA_Analytics::get_woocommerce( '30d' );

		$this->assertSame( 0.0, $result['revenue_total'] );

		$wpdb->delete( RSA_DB::wc_events_table(), [ 'session_id' => 'wc-old-test' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// ----------------------------------------------------------------
	// RSA_DB wc_events_table() helper
	// ----------------------------------------------------------------

	public function test_wc_events_table_returns_prefixed_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rsa_wc_events', RSA_DB::wc_events_table() );
	}

	// ----------------------------------------------------------------
	// session_id() — UUID generation, no cookies
	// ----------------------------------------------------------------

	public function test_wc_session_id_returns_valid_uuidv4(): void {
		$sid = $this->invoke_wc_session_id( [] );
		$this->assertMatchesRegularQuery( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid );
	}

	public function test_wc_session_id_uses_post_param_when_available(): void {
		$known = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
		$sid   = $this->invoke_wc_session_id( [ 'rsa_sid' => $known ] );
		$this->assertSame( $known, $sid );
	}

	public function test_wc_session_id_invalid_post_param_generates_new_uuid(): void {
		$sid1 = $this->invoke_wc_session_id( [ 'rsa_sid' => 'not-a-valid-uuid' ] );
		$sid2 = $this->invoke_wc_session_id( [ 'rsa_sid' => '' ] );
		$this->assertMatchesRegularQuery( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid1 );
		$this->assertMatchesRegularQuery( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid2 );
		$this->assertNotSame( $sid1, $sid2 );
	}

	// ----------------------------------------------------------------
	// RSA_Tracker session ID generation
	// ----------------------------------------------------------------

	public function test_tracker_generates_valid_uuidv4(): void {
		$sid = RSA_Tracker::get_or_create_session_id();
		$this->assertMatchesRegularQuery( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid );
	}

	public function test_tracker_session_id_is_deterministic_per_request(): void {
		$sid1 = RSA_Tracker::get_or_create_session_id();
		$sid2 = RSA_Tracker::get_or_create_session_id();
		$this->assertSame( $sid1, $sid2 );
	}

	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	private function invoke_wc_session_id( array $post ): string {
		if ( ! empty( $post ) ) {
			$_POST = $post;
		}
		$ref = new ReflectionMethod( RSA_Woocommerce::class, 'session_id' );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}
}