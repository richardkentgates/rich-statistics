<?php
/**
 * WP-CLI commands for Rich Statistics.
 * Available in the FREE tier.
 *
 * Usage:
 *   wp rich-stats overview [--period=30d] [--blog-id=<id>]
 *   wp rich-stats top-pages [--period=30d] [--limit=10] [--blog-id=<id>]
 *   wp rich-stats audience [--period=30d] [--blog-id=<id>]
 *   wp rich-stats referrers [--period=30d] [--limit=10] [--blog-id=<id>]
 *   wp rich-stats behavior [--period=30d] [--blog-id=<id>]
 *   wp rich-stats campaigns [--period=30d] [--limit=10] [--blog-id=<id>] (Premium)
 *   wp rich-stats user-flow [--period=30d] [--blog-id=<id>] (Premium)
 *   wp rich-stats export [--format=json|csv] [--period=90d] [--blog-id=<id>]
 *   wp rich-stats purge [--older-than=90] [--dry-run] [--blog-id=<id>]
 *   wp rich-stats email-test [--recipient=you@example.com]
 *   wp rich-stats status
 *   wp rich-stats clicks [--period=30d] [--limit=20] [--page=/] [--blog-id=<id>] (Premium)
 *   wp rich-stats woocommerce [--period=30d] [--limit=10] [--blog-id=<id>] (Premium)
 *
 * @package RichStatistics
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand overview
	 */
	public function overview( array $args, array $assoc ): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			WP_CLI::error( __( 'You do not have permission to use Rich Statistics commands.', 'rich-statistics' ) );
		}
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$this->maybe_switch_blog( $assoc );

		$data = RSA_Analytics::get_overview( $period );

		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Site:', 'rich-statistics' ) . '%n ' . get_bloginfo( 'name' ) ) );
		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Period:', 'rich-statistics' ) . '%n ' . $period ) );
		WP_CLI::line( '' );

		$items = [
			[ __( 'Metric', 'rich-statistics' ), __( 'Value', 'rich-statistics' ) ],
			[ __( 'Page Views', 'rich-statistics' ), number_format( $data['pageviews'] ) ],
			[ __( 'Sessions', 'rich-statistics' ), number_format( $data['sessions'] ) ],
			[ __( 'Avg Time', 'rich-statistics' ), $this->format_seconds( $data['avg_time'] ) ],
			[ __( 'Bounce Rate', 'rich-statistics' ), $data['bounce_rate'] . '%' ],
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand top-pages
	 */
	public function top_pages( array $args, array $assoc ): void {
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 10 ) );
		$this->maybe_switch_blog( $assoc );

		$rows = RSA_Analytics::get_top_pages( $period, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( __( 'No page data found.', 'rich-statistics' ) );
			return;
		}

		$items = [ [ '#', __( 'Page', 'rich-statistics' ), __( 'Views', 'rich-statistics' ), __( 'Avg Time', 'rich-statistics' ) ] ];
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand audience
	 */
	public function audience( array $args, array $assoc ): void {
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$this->maybe_switch_blog( $assoc );

		$data = RSA_Analytics::get_audience( $period );

		foreach ( [
			'os'       => __( 'OS', 'rich-statistics' ),
			'browser'  => __( 'Browser', 'rich-statistics' ),
			'language' => __( 'Language', 'rich-statistics' ),
		] as $key => $label ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%B' . $label . '%n' ) );
			if ( empty( $data[ $key ] ) ) {
				WP_CLI::line( '  ' . __( '(no data)', 'rich-statistics' ) );
				continue;
			}
			$items = [ [ __( 'Label', 'rich-statistics' ), __( 'Count', 'rich-statistics' ) ] ];
			foreach ( array_slice( $data[ $key ], 0, 8 ) as $r ) {
				$items[] = [ $r['label'], number_format( $r['count'] ) ];
			}
			$this->cli_table( $items );
		}
	}

	// ----------------------------------------------------------------
	// referrers
	// ----------------------------------------------------------------

	/**
	 * List top referrer domains.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Default: 30d.
	 *
	 * [--limit=<n>]
	 * : Number of rows. Default: 10.
	 *
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand referrers
	 */
	public function referrers( array $args, array $assoc ): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			WP_CLI::error( __( 'You do not have permission to use Rich Statistics commands.', 'rich-statistics' ) );
		}
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 10 ) );
		$this->maybe_switch_blog( $assoc );

		$rows = RSA_Analytics::get_referrers( $period, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( __( 'No referrer data found.', 'rich-statistics' ) );
			return;
		}

		$items = [ [ __( 'Domain', 'rich-statistics' ), __( 'Visits', 'rich-statistics' ) ] ];
		foreach ( $rows as $r ) {
			$items[] = [ ! empty( $r['domain'] ) ? $r['domain'] : '(direct)', number_format( $r['visits'] ) ];
		}
		$this->cli_table( $items );
	}

	// ----------------------------------------------------------------
	// behavior
	// ----------------------------------------------------------------

	/**
	 * Show behavior analysis: time histogram, session depth, entry pages.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Default: 30d.
	 *
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand behavior
	 */
	public function behavior( array $args, array $assoc ): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			WP_CLI::error( __( 'You do not have permission to use Rich Statistics commands.', 'rich-statistics' ) );
		}
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$this->maybe_switch_blog( $assoc );

		$data = RSA_Analytics::get_behavior( $period );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Time on Page', 'rich-statistics' ) . '%n' ) );
		$time_items = [ [ __( 'Range', 'rich-statistics' ), __( 'Sessions', 'rich-statistics' ) ] ];
		foreach ( array_slice( $data['time_on_page'] ?? [], 0, 8 ) as $r ) {
			$time_items[] = [ $r['label'], number_format( $r['count'] ) ];
		}
		$this->cli_table( $time_items );

		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Session Depth', 'rich-statistics' ) . '%n' ) );
		$depth_items = [ [ __( 'Depth', 'rich-statistics' ), __( 'Sessions', 'rich-statistics' ) ] ];
		foreach ( array_slice( $data['session_depth'] ?? [], 0, 8 ) as $r ) {
			$depth_items[] = [ $r['label'], number_format( $r['count'] ) ];
		}
		$this->cli_table( $depth_items );

		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Entry Pages', 'rich-statistics' ) . '%n' ) );
		$entry_items = [ [ __( 'Page', 'rich-statistics' ), __( 'Sessions', 'rich-statistics' ) ] ];
		foreach ( array_slice( $data['entry_pages'] ?? [], 0, 8 ) as $r ) {
			$entry_items[] = [ $r['page'], number_format( $r['sessions'] ) ];
		}
		$this->cli_table( $entry_items );
	}

	// ----------------------------------------------------------------
	// campaigns  (premium)
	// ----------------------------------------------------------------

	/**
	 * Show UTM campaign breakdown with session and pageview counts.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Default: 30d.
	 *
	 * [--limit=<n>]
	 * : Number of rows. Default: 10.
	 *
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand campaigns
	 */
	public function campaigns( array $args, array $assoc ): void {
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			WP_CLI::error( __( 'Campaign tracking requires a Rich Statistics Premium licence.', 'rich-statistics' ) );
		}
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 10 ) );
		$this->maybe_switch_blog( $assoc );

		$rows = RSA_Analytics::get_campaigns( $period, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( __( 'No campaign data found for this period.', 'rich-statistics' ) );
			return;
		}

		$items = [ [ __( 'Campaign', 'rich-statistics' ), __( 'Source', 'rich-statistics' ), __( 'Medium', 'rich-statistics' ), __( 'Sessions', 'rich-statistics' ), __( 'Pageviews', 'rich-statistics' ) ] ];
		foreach ( $rows as $r ) {
			$items[] = [
				! empty( $r['campaign'] ) ? $r['campaign'] : '—',
				! empty( $r['source'] ) ? $r['source'] : '—',
				! empty( $r['medium'] ) ? $r['medium'] : '—',
				number_format( $r['sessions'] ),
				number_format( $r['pageviews'] ),
			];
		}
		$this->cli_table( $items );
	}

	// ----------------------------------------------------------------
	// user-flow  (premium)
	// ----------------------------------------------------------------

	/**
	 * Show user flow path explorer data.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Default: 30d.
	 *
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand user-flow
	 */
	public function user_flow( array $args, array $assoc ): void {
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			WP_CLI::error( __( 'User Flow requires a Rich Statistics Premium licence.', 'rich-statistics' ) );
		}
		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$this->maybe_switch_blog( $assoc );

		$rows = RSA_Analytics::get_user_flow( $period );

		if ( empty( $rows ) ) {
			WP_CLI::warning( __( 'No user flow data found for this period.', 'rich-statistics' ) );
			return;
		}

		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'User Flow (page transitions)', 'rich-statistics' ) . '%n' ) );
		$items = [ [ __( 'From', 'rich-statistics' ), __( 'To', 'rich-statistics' ), __( 'Transitions', 'rich-statistics' ) ] ];
		foreach ( $rows as $r ) {
			$items[] = [ $r['from_page'], $r['to_page'], number_format( $r['count'] ) ];
		}
		$this->cli_table( $items );
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand export
	 */
	public function export( array $args, array $assoc ): void {
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			WP_CLI::error( __( 'Data export requires a Rich Statistics Premium licence.', 'rich-statistics' ) );
		}
		$period = $this->validate_period( $assoc['period'] ?? '90d' );
		$format = in_array( $assoc['format'] ?? 'json', [ 'json', 'csv' ], true ) ? ( $assoc['format'] ?? 'json' ) : 'json';
		$this->maybe_switch_blog( $assoc );

		/* translators: 1: period, 2: format */
		WP_CLI::line( sprintf( __( 'Exporting (%1$s, %2$s)…', 'rich-statistics' ), $period, $format ) );
		$data = RSA_Analytics::export_events( $period, $format );

		if ( ! empty( $assoc['output'] ) ) {
			$output  = $assoc['output'];
			$real    = realpath( dirname( $output ) );
			$abspath = realpath( ABSPATH );
			if ( ! $real || ! $abspath || 0 !== strpos( $real . '/', trailingslashit( $abspath ) ) ) {
				WP_CLI::error( __( 'Output path must be within the WordPress directory.', 'rich-statistics' ) );
			}
			file_put_contents( $output, $data );
			/* translators: %s: output file path */
			WP_CLI::success( sprintf( __( 'Written to %s', 'rich-statistics' ), $output ) );
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand purge
	 */
	public function purge( array $args, array $assoc ): void {
		$days    = isset( $assoc['older-than'] ) ? (int) $assoc['older-than'] : null;
		$dry_run = isset( $assoc['dry-run'] );
		$this->maybe_switch_blog( $assoc );

		if ( $dry_run ) {
			WP_CLI::line( __( 'Dry run — no data will be deleted.', 'rich-statistics' ) );
			// Just count.
			global $wpdb;
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $days ?? get_option( 'rsa_retention_days', 90 ) ) . ' days' ) );
			$et     = RSA_DB::events_table();
			$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$et}` WHERE created_at < %s", $cutoff ) ); // phpcs:ignore
			/* translators: %d: number of event rows */
			WP_CLI::line( sprintf( __( 'Would delete approximately %d event rows.', 'rich-statistics' ), $count ) );
			return;
		}

		$deleted = RSA_DB::prune_old_data( $days );
		/* translators: %d: number of deleted records */
		WP_CLI::success( sprintf( __( 'Pruned %d records.', 'rich-statistics' ), $deleted ) );
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand email-test
	 */
	public function email_test( array $args, array $assoc ): void {
		$recipient = sanitize_email( $assoc['recipient'] ?? get_option( 'admin_email' ) );
		if ( ! is_email( $recipient ) ) {
			WP_CLI::error( __( 'Invalid email address.', 'rich-statistics' ) );
		}

		// Temporarily override the recipient option.
		$original = get_option( 'rsa_email_digest_recipients' );
		update_option( 'rsa_email_digest_recipients', $recipient );

		$sent = RSA_Email::send_digest( '30d' );

		update_option( 'rsa_email_digest_recipients', $original );

		if ( $sent ) {
			/* translators: %s: recipient email */
			WP_CLI::success( sprintf( __( 'Test digest sent to %s.', 'rich-statistics' ), $recipient ) );
		} else {
			WP_CLI::error( __( 'Failed to send. Check WordPress mail settings.', 'rich-statistics' ) );
		}
	}

	// ----------------------------------------------------------------
	// status
	// ----------------------------------------------------------------

	/**
	 * Show plugin status, options summary, and cron schedule.
	 *
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc ): void {
		$is_premium = function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only();

		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Rich Statistics Status', 'rich-statistics' ) . '%n' ) );
		WP_CLI::line( '' );

		$next_cron   = wp_next_scheduled( 'rsa_daily_maintenance' );
		$next_digest = wp_next_scheduled( 'rsa_send_digest' );

		$items = [
			[ __( 'Setting', 'rich-statistics' ), __( 'Value', 'rich-statistics' ) ],
			[ __( 'Version', 'rich-statistics' ), RSA_VERSION ],
			[ __( 'Tier', 'rich-statistics' ), $is_premium ? __( 'Premium', 'rich-statistics' ) : __( 'Free', 'rich-statistics' ) ],
			[ __( 'Retention (days)', 'rich-statistics' ), get_option( 'rsa_retention_days', 90 ) ],
			[ __( 'Bot threshold', 'rich-statistics' ), get_option( 'rsa_bot_score_threshold', 3 ) ],
			[ __( 'Email digest enabled', 'rich-statistics' ), get_option( 'rsa_email_digest_enabled' ) ? __( 'Yes', 'rich-statistics' ) : __( 'No', 'rich-statistics' ) ],
			[ __( 'Email frequency', 'rich-statistics' ), get_option( 'rsa_email_digest_frequency', 'weekly' ) ],
			[ __( 'Next maintenance', 'rich-statistics' ), $next_cron ? gmdate( 'Y-m-d H:i T', $next_cron ) : __( 'not scheduled', 'rich-statistics' ) ],
			[ __( 'Next digest', 'rich-statistics' ), $next_digest ? gmdate( 'Y-m-d H:i T', $next_digest ) : __( 'not scheduled', 'rich-statistics' ) ],
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand clicks
	 */
	public function clicks( array $args, array $assoc ): void {
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			WP_CLI::error( __( 'Click tracking requires a Rich Statistics Premium licence.', 'rich-statistics' ) );
		}

		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 20 ) );
		$page   = sanitize_text_field( $assoc['page'] ?? '' );
		$this->maybe_switch_blog( $assoc );

		$rows = array_slice( RSA_Analytics::get_click_map( $period, $page ), 0, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( __( 'No click data found for this period.', 'rich-statistics' ) );
			return;
		}

		$items = [ [ __( 'Protocol', 'rich-statistics' ), __( 'Destination', 'rich-statistics' ), __( 'Tag', 'rich-statistics' ), __( 'Text', 'rich-statistics' ), __( 'Clicks', 'rich-statistics' ) ] ];
		foreach ( $rows as $r ) {
			$items[] = [
				$r['protocol'] ? $r['protocol'] : '—',
				$r['href_value'] ? $r['href_value'] : '—',
				$r['tag'],
				mb_strimwidth( $r['text'] ? $r['text'] : '—', 0, 40, '…' ),
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
	 * @param array $args  CLI positional arguments.
	 * @param array $assoc CLI associative arguments.
	 *
	 * @subcommand woocommerce
	 */
	public function woocommerce( array $args, array $assoc ): void {
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			WP_CLI::error( __( 'WooCommerce analytics requires a Rich Statistics Premium licence.', 'rich-statistics' ) );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( __( 'WooCommerce is not active on this site.', 'rich-statistics' ) );
		}

		$period = $this->validate_period( $assoc['period'] ?? '30d' );
		$limit  = max( 1, (int) ( $assoc['limit'] ?? 10 ) );
		$this->maybe_switch_blog( $assoc );

		$data   = RSA_Analytics::get_woocommerce( $period );
		$funnel = $data['funnel'] ?? [
			'views'  => 0,
			'cart'   => 0,
			'orders' => 0,
		];

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Funnel', 'rich-statistics' ) . '%n' ) );
		$this->cli_table(
			[
				[ __( 'Event', 'rich-statistics' ), __( 'Count', 'rich-statistics' ) ],
				[ __( 'Product Views', 'rich-statistics' ), number_format( $funnel['views'] ) ],
				[ __( 'Add to Cart', 'rich-statistics' ), number_format( $funnel['cart'] ) ],
				[ __( 'Orders', 'rich-statistics' ), number_format( $funnel['orders'] ) ],
			]
		);

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Revenue', 'rich-statistics' ) . '%n' ) );
		/* translators: %s: Total orders count */
		WP_CLI::line( '  ' . sprintf( __( 'Total orders: %s', 'rich-statistics' ), number_format( $data['orders_count'] ?? 0 ) ) );
		/* translators: %s: Total revenue amount */
		WP_CLI::line( '  ' . sprintf( __( 'Total revenue: $%s', 'rich-statistics' ), number_format( (float) ( $data['revenue_total'] ?? 0 ), 2 ) ) );

		$viewed = array_slice( $data['top_products_viewed'] ?? [], 0, $limit );
		if ( ! empty( $viewed ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Top Viewed Products', 'rich-statistics' ) . '%n' ) );
			$items = [ [ '#', __( 'Product', 'rich-statistics' ), __( 'Views', 'rich-statistics' ) ] ];
			foreach ( $viewed as $i => $p ) {
				$items[] = [ $i + 1, mb_strimwidth( $p['product_name'], 0, 50, '\u2026' ), number_format( $p['views'] ) ];
			}
			$this->cli_table( $items );
		}

		$top_cart = array_slice( $data['top_products_cart'] ?? [], 0, $limit );
		if ( ! empty( $top_cart ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%B' . __( 'Top Add-to-Cart', 'rich-statistics' ) . '%n' ) );
			$items = [ [ '#', __( 'Product', 'rich-statistics' ), __( 'Events', 'rich-statistics' ) ] ];
			foreach ( $top_cart as $i => $p ) {
				$items[] = [ $i + 1, mb_strimwidth( $p['product_name'], 0, 50, '\u2026' ), number_format( $p['events'] ) ];
			}
			$this->cli_table( $items );
		}
	}

	// ----------------------------------------------------------------
	// Private helpers
	// ----------------------------------------------------------------

	/**
	 * Validate and normalize a period string.
	 *
	 * @param string $p The period string to validate.
	 * @return string Validated period string.
	 */
	private function validate_period( string $p ): string {
		$allowed = [ '7d', '30d', '90d', 'thismonth', 'lastmonth' ];
		return in_array( $p, $allowed, true ) ? $p : '30d';
	}

	/**
	 * Format seconds into a human-readable duration.
	 *
	 * @param int $secs The number of seconds.
	 * @return string Formatted duration string.
	 */
	private function format_seconds( int $secs ): string {
		return 60 <= $secs ? floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's' : $secs . 's';
	}

	/**
	 * Switch to a different blog in multisite if requested.
	 *
	 * @param array $assoc Associative array of CLI arguments.
	 * @return void
	 */
	private function maybe_switch_blog( array $assoc ): void {
		if ( is_multisite() && ! empty( $assoc['blog-id'] ) ) {
			$blog_id = (int) $assoc['blog-id'];
			if ( get_site( $blog_id ) ) {
				switch_to_blog( $blog_id );
				/* translators: %d: Blog ID */
				WP_CLI::line( sprintf( __( 'Switched to blog %d.', 'rich-statistics' ), $blog_id ) );
			} else {
				/* translators: %d: Blog ID */
				WP_CLI::error( sprintf( __( 'Blog %d not found.', 'rich-statistics' ), $blog_id ) );
			}
		}
	}

	/**
	 * Render a CLI table with headers and rows.
	 *
	 * @param array $rows Array of rows, first being the header row.
	 * @return void
	 */
	private function cli_table( array $rows ): void {
		if ( 2 > count( $rows ) ) {
			return;
		}
		$headers = array_shift( $rows );
		WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $row ) => array_combine( $headers, $row ),
				$rows
			),
			$headers
		);
	}
}
