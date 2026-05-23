<?php
/**
 * WooCommerce integration — tracks product views, add-to-cart, and orders
 * as events in the rsa_wc_events table, gated on the rsa_woocommerce_enabled option.
 *
 * @package RichStatistics
 * @fs_premium_only
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class RSA_Woocommerce {

	public static function init(): void {
		if ( ! get_option( 'rsa_woocommerce_enabled', 1 ) ) {
			return;
		}

		add_action( 'woocommerce_before_single_product', [ __CLASS__, 'track_product_view' ] );

		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'track_add_to_cart' ], 10, 6 );

		add_action( 'woocommerce_ajax_added_to_cart', [ __CLASS__, 'track_add_to_cart_ajax' ] );

		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'track_order_complete' ] );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'track_order_complete' ] );
	}

	/**
	 * Track a product page view.
	 */
	public static function track_product_view(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		self::insert_event(
			'wc_product_view',
			[
				'product_id'   => $product->get_id(),
				'product_name' => $product->get_name(),
				'product_sku'  => $product->get_sku(),
			]
		);
	}

	/**
	 * Track an add-to-cart event from a classic form.
	 *
	 * @param string $cart_item_key   Cart item key.
	 * @param int    $product_id      Product ID.
	 * @param int    $quantity        Quantity added.
	 * @param int    $variation_id    Variation ID.
	 * @param array  $variation       Variation attributes.
	 * @param array  $cart_item_data  Cart item data.
	 */
	public static function track_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return;
		}

		self::insert_event(
			'wc_add_to_cart',
			[
				'product_id'   => $product->get_id(),
				'product_name' => $product->get_name(),
				'product_sku'  => $product->get_sku(),
				'quantity'     => $quantity,
			]
		);
	}

	/**
	 * Track an add-to-cart event from AJAX.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function track_add_to_cart_ajax( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		self::insert_event(
			'wc_add_to_cart',
			[
				'product_id'   => $product->get_id(),
				'product_name' => $product->get_name(),
				'product_sku'  => $product->get_sku(),
				'quantity'     => 1,
			]
		);
	}

	/**
	 * Track an order completion.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function track_order_complete( int $order_id ): void {
		// Use add_post_meta with unique=true to prevent race condition where
		// both woocommerce_payment_complete and woocommerce_order_status_processing
		// fire simultaneously and both read _rsa_tracked as empty.
		if ( ! add_post_meta( $order_id, '_rsa_tracked', '1', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' x' . $item->get_quantity();
		}

		self::insert_event(
			'wc_order_complete',
			[
				'order_id' => $order_id,
				'total'    => (float) $order->get_total(),
				'items'    => implode( '; ', $items ),
				'currency' => $order->get_currency(),
			]
		);
	}

	/**
	 * Insert a WooCommerce event into rsa_wc_events.
	 *
	 * @param string $event_type The event type.
	 * @param array  $meta       Event metadata.
	 */
	private static function insert_event( string $event_type, array $meta ): void {
		global $wpdb;

		$data    = [
			'session_id' => self::session_id(),
			'event_type' => $event_type,
			'created_at' => current_time( 'mysql', true ),
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
	 * Returns the RSA session ID for the current visitor.
	 *
	 * Source: $_POST['rsa_sid'] — set by tracker.js via sendBeacon/ajax
	 * on every page view.  WC hooks fire after the tracker has already
	 * sent the event (pagehide fires after WC hooks), so the session ID
	 * is reliably available in $_POST.
	 *
	 * If unavailable (e.g. direct order hook without a prior page view),
	 * a fresh UUIDv4 is generated. This ensures every visitor has a
	 * session ID and allows WC events to be correlated with tracker events.
	 *
	 * No cookies — session ID originates from sessionStorage (JS only).
	 */
	private static function session_id(): string {
		$sid = sanitize_text_field( wp_unslash( $_POST['rsa_sid'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified upstream
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sid ) ) {
			return $sid;
		}
		return self::generate_uuid();
	}

	private static function generate_uuid(): string {
		$hex      = bin2hex( random_bytes( 16 ) );
		$hex      = substr( $hex, 0, 12 ) . '4' . substr( $hex, 13 );
		$variants = [ '8', '9', 'a', 'b' ];
		$hex      = substr( $hex, 0, 16 ) . $variants[ array_rand( $variants ) ] . substr( $hex, 17 );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
