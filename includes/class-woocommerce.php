<?php
/**
 * WooCommerce integration — tracks product views, add-to-cart, and orders
 * as events in the rsa_events table, gated on WooCommerce being active.
 */
defined( 'ABSPATH' ) || exit;

class RSA_Woocommerce {

	public static function init(): void {
		if ( ! get_option( 'rsa_woocommerce_enabled', 1 ) ) {
			return;
		}

		// Product page view — fires after WC product is set up on single-product pages
		add_action( 'woocommerce_before_single_product', [ __CLASS__, 'track_product_view' ] );

		// Add to cart — fires server-side for classic forms (non-AJAX)
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'track_add_to_cart' ], 10, 6 );

		// AJAX add to cart — fires after WC processes the request
		add_action( 'woocommerce_ajax_added_to_cart', [ __CLASS__, 'track_add_to_cart_ajax' ] );

		// Order completed (payment received)
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'track_order_complete' ] );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'track_order_complete' ] );
	}

	// ----------------------------------------------------------------
	// Track product page view
	// ----------------------------------------------------------------

	public static function track_product_view(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		self::insert_event( 'wc_product_view', [
			'product_id'   => $product->get_id(),
			'product_name' => $product->get_name(),
			'product_sku'  => $product->get_sku(),
		] );
	}

	// ----------------------------------------------------------------
	// Track add-to-cart (classic form)
	// ----------------------------------------------------------------

	public static function track_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) {
			return;
		}

		self::insert_event( 'wc_add_to_cart', [
			'product_id'   => $product->get_id(),
			'product_name' => $product->get_name(),
			'product_sku'  => $product->get_sku(),
			'quantity'     => $quantity,
		] );
	}

	// ----------------------------------------------------------------
	// Track add-to-cart (AJAX)
	// ----------------------------------------------------------------

	public static function track_add_to_cart_ajax( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		self::insert_event( 'wc_add_to_cart', [
			'product_id'   => $product->get_id(),
			'product_name' => $product->get_name(),
			'product_sku'  => $product->get_sku(),
			'quantity'     => 1,
		] );
	}

	// ----------------------------------------------------------------
	// Track order completion
	// ----------------------------------------------------------------

	public static function track_order_complete( int $order_id ): void {
		// Avoid double-tracking if both hooks fire for the same order
		if ( get_post_meta( $order_id, '_rsa_tracked', true ) ) {
			return;
		}
		update_post_meta( $order_id, '_rsa_tracked', '1' );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' x' . $item->get_quantity();
		}

		self::insert_event( 'wc_order_complete', [
			'order_id' => $order_id,
			'total'    => (float) $order->get_total(),
			'items'    => implode( '; ', $items ),
			'currency' => $order->get_currency(),
		] );
	}

	// ----------------------------------------------------------------
	// Internal: insert a WooCommerce event into rsa_wc_events
	// ----------------------------------------------------------------

	private static function insert_event( string $event_type, array $meta ): void {
		global $wpdb;

		$data    = [
			'session_id' => self::session_id(),
			'event_type' => $event_type,
			'created_at' => current_time( 'mysql' ),
		];
		$formats = [ '%s', '%s', '%s' ];

		if ( isset( $meta['product_id'] ) ) {
			$data['product_id'] = (int) $meta['product_id'];
			$formats[]          = '%d';
		}
		if ( isset( $meta['product_name'] ) ) {
			$data['product_name'] = substr( $meta['product_name'], 0, 255 );
			$formats[]            = '%s';
		}
		if ( isset( $meta['product_sku'] ) ) {
			$data['product_sku'] = substr( $meta['product_sku'], 0, 100 );
			$formats[]           = '%s';
		}
		if ( isset( $meta['quantity'] ) ) {
			$data['quantity'] = (int) $meta['quantity'];
			$formats[]        = '%d';
		}
		if ( isset( $meta['total'] ) ) {
			$data['order_total']    = round( (float) $meta['total'], 2 );
			$data['order_currency'] = isset( $meta['currency'] ) ? substr( $meta['currency'], 0, 8 ) : '';
			$formats[]              = '%f';
			$formats[]              = '%s';
		}

		$wpdb->insert( RSA_DB::wc_events_table(), $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- commerce event insert
	}

	/**
	 * Returns the RSA session ID from the cookie/header that the tracker.js
	 * sets.  If unavailable (e.g. server-side order hook) returns a placeholder.
	 */
	private static function session_id(): string {
		$sid = sanitize_text_field( wp_unslash( $_COOKIE['rsa_sid'] ?? '' ) );
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid ) ) {
			return $sid;
		}
		return '00000000-0000-4000-8000-000000000000';
	}
}
