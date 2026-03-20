<?php
/**
 * WP-CLI seed script — insert realistic WooCommerce analytics sample data.
 *
 * Usage (from WordPress root):
 *   wp eval-file /path/to/rich-statistics/bin/seed-woocommerce-data.php
 *
 * Inserts 90 days of wc_product_view, wc_add_to_cart, and wc_order_complete
 * events into {prefix}rsa_wc_events so the WooCommerce tab has data to show.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$table = $wpdb->prefix . 'rsa_wc_events';

// guard: table must exist
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore
	WP_CLI::error( "Table {$table} does not exist. Activate Rich Statistics first." );
}

$products = [
	[ 'id' => 101, 'name' => 'Analytics Pro Bundle',   'sku' => 'APB-001', 'price' => 49.00 ],
	[ 'id' => 102, 'name' => 'WordPress Theme Classic', 'sku' => 'WTC-002', 'price' => 29.00 ],
	[ 'id' => 103, 'name' => 'SEO Toolkit Plugin',      'sku' => 'SEO-003', 'price' => 39.00 ],
	[ 'id' => 104, 'name' => 'Email Marketing Add-on',  'sku' => 'EMA-004', 'price' => 19.00 ],
	[ 'id' => 105, 'name' => 'Security Shield Plugin',  'sku' => 'SSP-005', 'price' => 24.00 ],
	[ 'id' => 106, 'name' => 'Performance Optimizer',   'sku' => 'PFO-006', 'price' => 34.00 ],
	[ 'id' => 107, 'name' => 'Backup & Restore Pro',    'sku' => 'BRP-007', 'price' => 15.00 ],
];

$currencies = [ 'USD', 'USD', 'USD', 'USD', 'EUR', 'GBP' ]; // weighted toward USD

$rows_inserted = 0;
$now           = time();
$days          = 90;

for ( $d = $days; $d >= 0; $d-- ) {
	// Vary daily volume — weekdays busier, slight upward trend
	$day_of_week = (int) gmdate( 'N', $now - $d * DAY_IN_SECONDS );
	$is_weekend  = $day_of_week >= 6;
	$base        = $is_weekend ? 8 : 18;
	$trend       = (int) ( ( $days - $d ) / $days * 12 ); // ramp up over 90 days
	$daily_views = $base + $trend + wp_rand( 0, 6 );

	for ( $i = 0; $i < $daily_views; $i++ ) {
		$product  = $products[ array_rand( $products ) ];
		$offset   = wp_rand( 0, DAY_IN_SECONDS - 1 );
		$ts       = gmdate( 'Y-m-d H:i:s', $now - $d * DAY_IN_SECONDS + $offset );
		$sid      = sprintf(
			'%08x-%04x-4%03x-%04x-%012x',
			wp_rand( 0, 0xffffffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xfff ),
			wp_rand( 0x8000, 0xbfff ),
			wp_rand( 0, 0xffffffffffff )
		);

		// Product view
		$wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'session_id'   => $sid,
			'event_type'   => 'wc_product_view',
			'product_id'   => $product['id'],
			'product_name' => $product['name'],
			'product_sku'  => $product['sku'],
			'created_at'   => $ts,
		] );
		$rows_inserted++;

		// ~40% add to cart
		if ( wp_rand( 1, 10 ) <= 4 ) {
			$wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'session_id'   => $sid,
				'event_type'   => 'wc_add_to_cart',
				'product_id'   => $product['id'],
				'product_name' => $product['name'],
				'product_sku'  => $product['sku'],
				'quantity'     => wp_rand( 1, 3 ),
				'created_at'   => $ts,
			] );
			$rows_inserted++;

			// ~55% of add-to-cart complete order
			if ( wp_rand( 1, 100 ) <= 55 ) {
				// order may contain 1–3 products
				$order_items = wp_rand( 1, 3 );
				$total       = 0;
				for ( $p = 0; $p < $order_items; $p++ ) {
					$op     = $products[ array_rand( $products ) ];
					$qty    = wp_rand( 1, 2 );
					$total += $op['price'] * $qty;
				}
				$currency = $currencies[ array_rand( $currencies ) ];
				$wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					'session_id'    => $sid,
					'event_type'    => 'wc_order_complete',
					'product_id'    => $product['id'],
					'product_name'  => $product['name'],
					'product_sku'   => $product['sku'],
					'order_total'   => round( $total, 2 ),
					'order_currency' => $currency,
					'created_at'    => $ts,
				] );
				$rows_inserted++;
			}
		}
	}
}

WP_CLI::success( "Inserted {$rows_inserted} WooCommerce sample events into {$table} (90 days)." );
