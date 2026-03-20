<?php
/**
 * WP-CLI commands for Rich Statistics.
 * Available in the FREE tier.
 *
 * Usage:
 *   wp rich-stats overview [--period=30d]
 *   wp rich-stats top-pages [--period=30d] [--limit=10]
 *   wp rich-stats audience [--period=30d]
 *   wp rich-stats export [--format=json|csv] [--period=90d]
 *   wp rich-stats purge [--older-than=90] [--dry-run]
 *   wp rich-stats email-test [--recipient=you@example.com]
 *   wp rich-stats status
 *   wp rich-stats clicks [--period=30d] [--limit=20] [--page=/] (Premium)
 *   wp rich-stats woocommerce [--period=30d] [--limit=10] (Premium)
 */
defined( 'ABSPATH' ) || exit;

class RSA_CLI extends WP_CLI_Command {

	// ----------------------------------------------------------------
	// overview
	// ----------------------------------------------------------------

	/**
	 * Show key metrics for a period.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : One of: 7d, 30d, 90d, thismonth, lastmonth. Default: 30d.
	 *
	 * [--blog-id=<id>]
	 * : Multisite: switch to this blog before querying. Default: current.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rich-stats overview --period=7d
	 *
	 * @subcommand overview
	 */
	public function overview( array $args, array $assoc ): void {
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$this->maybe_switch_blog( $assoc );

		$data = RSA_Analytics::get_overview( $period );

		WP_CLI::line( WP_CLI::colorize( "%BSite:%n " . get_bloginfo( 'name' ) ) );
		WP_CLI::line( WP_CLI::colorize( "%BPeriod:%n " . $period ) );
		WP_CLI::line( '' );

		$items = [
			[ 'Metric', 'Value' ],
			[ 'Page Views',    number_format( $data['pageviews'] ) ],
			[ 'Sessions',      number_format( $data['sessions'] ) ],
			[ 'Avg Time',      $this->format_seconds( $data['avg_time'] ) ],
			[ 'Bounce Rate',   $data['bounce_rate'] . '%' ],
		];
		$this->cli_table( $items );
	}

	// ----------------------------------------------------------------
	// top-pages
	// ----------------------------------------------------------------

	/**
	 * List top pages by view count.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Default: 30d.
	 *
	 * [--limit=<n>]
	 * : Number of pages to show. Default: 10.
	 *
	 * @subcommand top-pages
	 */
	public function top_pages( array $args, array $assoc ): void {
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 10 ) );
		$this->maybe_switch_blog( $assoc );

		$rows = RSA_Analytics::get_top_pages( $period, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No page data found.' );
			return;
		}

		$items = [ [ '#', 'Page', 'Views', 'Avg Time' ] ];
		foreach ( $rows as $i => $r ) {
			$items[] = [
				$i + 1,
				$r['page'],
				number_format( $r['views'] ),
				$this->format_seconds( $r['avg_time'] ),
			];
		}
		$this->cli_table( $items );
	}

	// ----------------------------------------------------------------
	// audience
	// ----------------------------------------------------------------

	/**
	 * Show audience breakdown (OS, browser, language).
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Default: 30d.
	 *
	 * @subcommand audience
	 */
	public function audience( array $args, array $assoc ): void {
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$this->maybe_switch_blog( $assoc );

		$data = RSA_Analytics::get_audience( $period );

		foreach ( [ 'os' => 'OS', 'browser' => 'Browser', 'language' => 'Language' ] as $key => $label ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( "%B{$label}%n" ) );
			if ( empty( $data[ $key ] ) ) {
				WP_CLI::line( '  (no data)' );
				continue;
			}
			$items = [ [ 'Label', 'Count' ] ];
			foreach ( array_slice( $data[ $key ], 0, 8 ) as $r ) {
				$items[] = [ $r['label'], number_format( $r['count'] ) ];
			}
			$this->cli_table( $items );
		}
	}

	// ----------------------------------------------------------------
	// export
	// ----------------------------------------------------------------

	/**
	 * Export raw event data.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : json or csv. Default: json.
	 *
	 * [--period=<period>]
	 * : Default: 90d.
	 *
	 * [--output=<file>]
	 * : Path to write data. Defaults to stdout.
	 *
	 * @subcommand export
	 */
	public function export( array $args, array $assoc ): void {
		$period = $this->validate_period( $assoc['period'] ?? '90d' );
		$format = in_array( $assoc['format'] ?? 'json', [ 'json', 'csv' ], true ) ? ( $assoc['format'] ?? 'json' ) : 'json';
		$this->maybe_switch_blog( $assoc );

		WP_CLI::line( 'Exporting (' . $period . ', ' . $format . ')…' );
		$data = RSA_Analytics::export_events( $period, $format );

		if ( ! empty( $assoc['output'] ) ) {
			file_put_contents( $assoc['output'], $data );
			WP_CLI::success( 'Written to ' . $assoc['output'] );
		} else {
			echo $data . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	// ----------------------------------------------------------------
	// purge
	// ----------------------------------------------------------------

	/**
	 * Delete records older than the retention threshold.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Override retention days. Default: site setting.
	 *
	 * [--dry-run]
	 * : Report count without deleting.
	 *
	 * @subcommand purge
	 */
	public function purge( array $args, array $assoc ): void {
		$days    = isset( $assoc['older-than'] ) ? (int) $assoc['older-than'] : null;
		$dry_run = isset( $assoc['dry-run'] );
		$this->maybe_switch_blog( $assoc );

		if ( $dry_run ) {
			WP_CLI::line( 'Dry run — no data will be deleted.' );
			// Just count
			global $wpdb;
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $days ?? get_option( 'rsa_retention_days', 90 ) ) . ' days' ) );
			$et     = RSA_DB::events_table();
			$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$et}` WHERE created_at < %s", $cutoff ) ); // phpcs:ignore
			WP_CLI::line( "Would delete approximately {$count} event rows." );
			return;
		}

		$deleted = RSA_DB::prune_old_data( $days );
		WP_CLI::success( "Pruned {$deleted} records." );
	}

	// ----------------------------------------------------------------
	// email-test
	// ----------------------------------------------------------------

	/**
	 * Send a test digest email.
	 *
	 * ## OPTIONS
	 *
	 * [--recipient=<email>]
	 * : Override recipient email. Default: site admin.
	 *
	 * @subcommand email-test
	 */
	public function email_test( array $args, array $assoc ): void {
		$recipient = sanitize_email( $assoc['recipient'] ?? get_option( 'admin_email' ) );
		if ( ! is_email( $recipient ) ) {
			WP_CLI::error( 'Invalid email address.' );
		}

		// Temporarily override the recipient option
		$original = get_option( 'rsa_email_digest_recipients' );
		update_option( 'rsa_email_digest_recipients', $recipient );

		$sent = RSA_Email::send_digest( '30d' );

		update_option( 'rsa_email_digest_recipients', $original );

		if ( $sent ) {
			WP_CLI::success( "Test digest sent to {$recipient}." );
		} else {
			WP_CLI::error( 'Failed to send. Check WordPress mail settings.' );
		}
	}

	// ----------------------------------------------------------------
	// status
	// ----------------------------------------------------------------

	/**
	 * Show plugin status, options summary, and cron schedule.
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc ): void {
		$is_premium = function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only();

		WP_CLI::line( WP_CLI::colorize( '%BRich Statistics Status%n' ) );
		WP_CLI::line( '' );

		$next_cron    = wp_next_scheduled( 'rsa_daily_maintenance' );
		$next_digest  = wp_next_scheduled( 'rsa_send_digest' );

		$items = [
			[ 'Setting', 'Value' ],
			[ 'Version',               RSA_VERSION ],
			[ 'Tier',                  $is_premium ? 'Premium' : 'Free' ],
			[ 'Retention (days)',      get_option( 'rsa_retention_days', 90 ) ],
			[ 'Bot threshold',         get_option( 'rsa_bot_score_threshold', 3 ) ],
			[ 'Email digest enabled',  get_option( 'rsa_email_digest_enabled' ) ? 'Yes' : 'No' ],
			[ 'Email frequency',       get_option( 'rsa_email_digest_frequency', 'weekly' ) ],
			[ 'Next maintenance',      $next_cron  ? gmdate( 'Y-m-d H:i T', $next_cron  ) : 'not scheduled' ],
			[ 'Next digest',           $next_digest ? gmdate( 'Y-m-d H:i T', $next_digest ) : 'not scheduled' ],
		];
		$this->cli_table( $items );
	}

	// ----------------------------------------------------------------
	// clicks  (premium data)
	// ----------------------------------------------------------------

	/**
	 * List tracked click events by protocol and element.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : One of: 7d, 30d, 90d, thismonth, lastmonth. Default: 30d.
	 *
	 * [--limit=<n>]
	 * : Number of rows to show. Default: 20.
	 *
	 * [--page=<path>]
	 * : Filter by page path. Default: all pages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rich-stats clicks --period=7d
	 *     wp rich-stats clicks --period=30d --page=/contact/
	 *
	 * @subcommand clicks
	 */
	public function clicks( array $args, array $assoc ): void {
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			WP_CLI::error( 'Click tracking requires a Rich Statistics Premium licence.' );
		}

		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 20 ) );
		$page   = sanitize_text_field( $assoc['page'] ?? '' );
		$this->maybe_switch_blog( $assoc );

		$rows = array_slice( RSA_Analytics::get_click_map( $period, $page ), 0, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No click data found for this period.' );
			return;
		}

		$items = [ [ 'Protocol', 'Destination', 'Tag', 'Text', 'Clicks' ] ];
		foreach ( $rows as $r ) {
			$items[] = [
				$r['protocol']   ?: '—',
				$r['href_value'] ?: '—',
				$r['tag'],
				mb_strimwidth( $r['text'] ?: '—', 0, 40, '…' ),
				number_format( $r['clicks'] ),
			];
		}
		$this->cli_table( $items );
	}

	// ----------------------------------------------------------------
	// woocommerce  (premium data)
	// ----------------------------------------------------------------

	/**
	 * Show WooCommerce analytics: funnel, revenue, and top products.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : One of: 7d, 30d, 90d, thismonth, lastmonth. Default: 30d.
	 *
	 * [--limit=<n>]
	 * : Number of top products to show per table. Default: 10.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rich-stats woocommerce --period=30d
	 *     wp rich-stats woocommerce --period=7d --limit=5
	 *
	 * @subcommand woocommerce
	 */
	public function woocommerce( array $args, array $assoc ): void {
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			WP_CLI::error( 'WooCommerce analytics requires a Rich Statistics Premium licence.' );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce is not active on this site.' );
		}

		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 10 ) );
		$this->maybe_switch_blog( $assoc );

		$data   = RSA_Analytics::get_woocommerce( $period );
		$funnel = $data['funnel'] ?? [ 'views' => 0, 'cart' => 0, 'orders' => 0 ];

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%BFunnel%n' ) );
		$this->cli_table( [
			[ 'Event', 'Count' ],
			[ 'Product Views', number_format( $funnel['views']  ) ],
			[ 'Add to Cart',   number_format( $funnel['cart']   ) ],
			[ 'Orders',        number_format( $funnel['orders'] ) ],
		] );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%BRevenue%n' ) );
		WP_CLI::line( '  Total orders:  ' . number_format( $data['orders_count'] ?? 0 ) );
		WP_CLI::line( '  Total revenue: $' . number_format( (float) ( $data['revenue_total'] ?? 0 ), 2 ) );

		$viewed = array_slice( $data['top_products_viewed'] ?? [], 0, $limit );
		if ( ! empty( $viewed ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%BTop Viewed Products%n' ) );
			$items = [ [ '#', 'Product', 'Views' ] ];
			foreach ( $viewed as $i => $p ) {
				$items[] = [ $i + 1, mb_strimwidth( $p['product_name'], 0, 50, '\u2026' ), number_format( $p['views'] ) ];
			}
			$this->cli_table( $items );
		}

		$top_cart = array_slice( $data['top_products_cart'] ?? [], 0, $limit );
		if ( ! empty( $top_cart ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%BTop Add-to-Cart%n' ) );
			$items = [ [ '#', 'Product', 'Events' ] ];
			foreach ( $top_cart as $i => $p ) {
				$items[] = [ $i + 1, mb_strimwidth( $p['product_name'], 0, 50, '\u2026' ), number_format( $p['events'] ) ];
			}
			$this->cli_table( $items );
		}
	}

	// ----------------------------------------------------------------
	// Private helpers
	// ----------------------------------------------------------------

	private function validate_period( string $p ): string {
		$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
		return in_array( $p, $allowed, true ) ? $p : '30d';
	}

	private function format_seconds( int $secs ): string {
		return $secs >= 60 ? floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's' : $secs . 's';
	}

	private function maybe_switch_blog( array $assoc ): void {
		if ( is_multisite() && ! empty( $assoc['blog-id'] ) ) {
			$blog_id = (int) $assoc['blog-id'];
			if ( get_site( $blog_id ) ) {
				switch_to_blog( $blog_id );
				WP_CLI::line( "Switched to blog {$blog_id}." );
			} else {
				WP_CLI::error( "Blog {$blog_id} not found." );
			}
		}
	}

	private function cli_table( array $rows ): void {
		if ( count( $rows ) < 2 ) {
			return;
		}
		$headers = array_shift( $rows );
		WP_CLI\Utils\format_items( 'table', array_map(
			fn( $row ) => array_combine( $headers, $row ),
			$rows
		), $headers );
	}
}
