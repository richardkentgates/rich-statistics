<?php
/**
 * WooCommerce integration — writes events into rsa_wc_events.
 *
 * Events are ingested via the REST API (POST /rsa/v1/wc-event)
 * from the frontend tracker. No WooCommerce hooks are used.
 *
 * @package RichStatistics
 * @fs_premium_only
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class RSA_Woocommerce {

	/**
	 * Insert a WooCommerce event into rsa_wc_events.
	 *
	 * @param string $event_type The event type.
	 * @param array  $meta       Event metadata.
	 * @param string $session_id Validated UUIDv4 session ID.
	 */
	public static function insert_event( string $event_type, array $meta, string $session_id ): void {
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session_id ) ) {
			return;
		}

		global $wpdb;

		$data    = [
			'session_id' => $session_id,
			'event_type' => $event_type,
			'created_at' => current_time( 'mysql', true ),
		];
		$formats = [ '%s', '%s', '%s' ];

		if ( isset( $meta['product_id'] ) && $meta['product_id'] ) {
			$data['product_id'] = (int) $meta['product_id'];
			$formats[]          = '%d';
		}
		if ( isset( $meta['product_name'] ) && '' !== $meta['product_name'] ) {
			$data['product_name'] = substr( $meta['product_name'], 0, 255 );
			$formats[]            = '%s';
		}
		if ( isset( $meta['product_sku'] ) && '' !== $meta['product_sku'] ) {
			$data['product_sku'] = substr( $meta['product_sku'], 0, 100 );
			$formats[]           = '%s';
		}
		if ( isset( $meta['quantity'] ) && $meta['quantity'] ) {
			$data['quantity'] = (int) $meta['quantity'];
			$formats[]        = '%d';
		}

		$wpdb->insert( RSA_DB::wc_events_table(), $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- commerce event insert
	}
}
