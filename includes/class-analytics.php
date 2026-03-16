<?php
/**
 * Analytics computation layer.
 *
 * All queries return plain arrays/objects suitable for JSON encoding
 * into Chart.js datasets. Heavy queries use SQL aggregation rather
 * than pulling rows into PHP.
 */
defined( 'ABSPATH' ) || exit;

class RSA_Analytics {

	// ----------------------------------------------------------------
	// Period helper
	// ----------------------------------------------------------------

	/**
	 * Returns an array [ 'start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s' ]
	 * for the given period string (7d, 30d, 90d, thismonth, lastmonth).
	 */
	public static function period_range( string $period, string $date_from = '', string $date_to = '' ): array {
		$now = time(); // UTC — matches DB CURRENT_TIMESTAMP
		if ( $period === 'custom' && $date_from && $date_to ) {
			$start = strtotime( $date_from . ' 00:00:00' );
			$end   = strtotime( $date_to   . ' 23:59:59' );
			if ( $start > $end ) { [ $start, $end ] = [ $end, $start ]; }
			return [
				'start' => gmdate( 'Y-m-d H:i:s', $start ),
				'end'   => gmdate( 'Y-m-d H:i:s', $end ),
			];
		}
		switch ( $period ) {
			case '7d':
				$start = strtotime( '-7 days', $now );
				break;
			case '30d':
				$start = strtotime( '-30 days', $now );
				break;
			case '90d':
				$start = strtotime( '-90 days', $now );
				break;
			case 'thismonth':
				$start = strtotime( gmdate( 'Y-m-01', $now ) );
				break;
			case 'lastmonth':
				$start = strtotime( gmdate( 'Y-m-01', strtotime( '-1 month', $now ) ) );
				$now   = strtotime( gmdate( 'Y-m-t', strtotime( '-1 month', $now ) ) . ' 23:59:59' );
				break;
			default: // Default 30d
				$start = strtotime( '-30 days', $now );
		}
		return [
			'start' => gmdate( 'Y-m-d H:i:s', $start ),
			'end'   => gmdate( 'Y-m-d H:i:s', $now ),
		];
	}

	// ----------------------------------------------------------------
	// Overview / KPI cards
	// ----------------------------------------------------------------

	public static function get_overview( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$et    = RSA_DB::events_table();
		$st    = RSA_DB::sessions_table();

		$pageviews = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$et}` WHERE created_at BETWEEN %s AND %s AND bot_score < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end'], self::bot_threshold()
			)
		);

		$sessions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$st}` WHERE created_at BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end']
			)
		);

		$avg_time = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(time_on_page) FROM `{$et}`
				 WHERE created_at BETWEEN %s AND %s AND time_on_page > 0 AND bot_score < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end'], self::bot_threshold()
			)
		);

		// Bounce: sessions with only 1 page viewed
		$bounced = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$st}` WHERE created_at BETWEEN %s AND %s AND pages_viewed = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end']
			)
		);

		$bounce_rate = $sessions > 0 ? round( ( $bounced / $sessions ) * 100, 1 ) : 0;

		// Pageviews per day for sparkline
		$daily_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS views
				 FROM `{$et}`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				 GROUP BY DATE(created_at)
				 ORDER BY day ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end'], self::bot_threshold()
			),
			ARRAY_A
		);

		return [
			'pageviews'   => $pageviews,
			'sessions'    => $sessions,
			'avg_time'    => round( $avg_time ),
			'bounce_rate' => $bounce_rate,
			'daily'       => self::fill_date_gaps( $daily_rows, $range ),
		];
	}

	// ----------------------------------------------------------------
	// Top pages
	// ----------------------------------------------------------------

	public static function get_top_pages( string $period = '30d', int $limit = 20, array $filters = [] ): array {
		global $wpdb;
		$range  = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$et     = RSA_DB::events_table();
		$bt     = self::bot_threshold();

		$conds  = [ 'created_at BETWEEN %s AND %s AND bot_score < %d' ];
		$params = [ $range['start'], $range['end'], $bt ];

		if ( ! empty( $filters['browser'] ) ) {
			$conds[]  = 'browser = %s';
			$params[] = $filters['browser'];
		}
		if ( ! empty( $filters['os'] ) ) {
			$conds[]  = 'os = %s';
			$params[] = $filters['os'];
		}
		if ( ! empty( $filters['page'] ) ) {
			$conds[]  = 'page = %s';
			$params[] = $filters['page'];
		} elseif ( ! empty( $filters['search'] ) ) {
			$conds[]  = 'page LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}

		$where    = implode( ' AND ', $conds );
		$sort_map = [ 'views' => 'views', 'avg_time' => 'avg_time' ];
		$sort_col = $sort_map[ $filters['sort'] ?? 'views' ] ?? 'views';
		$sort_dir = ( ( $filters['sort_dir'] ?? 'desc' ) === 'asc' ) ? 'ASC' : 'DESC';
		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page, COUNT(*) AS views, AVG(time_on_page) AS avg_time
				 FROM `{$et}`
				 WHERE {$where}
				 GROUP BY page
				 ORDER BY {$sort_col} {$sort_dir}
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			),
			ARRAY_A
		);

		return array_map( function ( $r ) {
			return [
				'page'     => $r['page'],
				'views'    => (int) $r['views'],
				'avg_time' => round( (float) $r['avg_time'] ),
			];
		}, $rows );
	}

	// ----------------------------------------------------------------
	// Audience breakdown
	// ----------------------------------------------------------------

	public static function get_audience( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$et    = RSA_DB::events_table();

		$aggregate = function ( string $column ) use ( $wpdb, $range, $et ): array {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `{$column}` AS label, COUNT(*) AS count
					 FROM `{$et}`
					 WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `{$column}` IS NOT NULL
					 GROUP BY `{$column}`
					 ORDER BY count DESC
					 LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$range['start'], $range['end'], self::bot_threshold()
				),
				ARRAY_A
			);
			return array_map( fn( $r ) => [ 'label' => $r['label'] ?: 'Unknown', 'count' => (int) $r['count'] ], $rows );
		};

		// Viewport buckets (segment by width)
		$viewport_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN viewport_w < 640  THEN 'Mobile (<640px)'
						WHEN viewport_w < 1024 THEN 'Tablet (640–1023px)'
						WHEN viewport_w < 1440 THEN 'Desktop (1024–1439px)'
						ELSE 'Wide (≥1440px)'
					END AS label,
					COUNT(*) AS count
				 FROM `{$et}`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND viewport_w > 0
				 GROUP BY label
				 ORDER BY count DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end'], self::bot_threshold()
			),
			ARRAY_A
		);

		return [
			'os'       => $aggregate( 'os' ),
			'browser'  => $aggregate( 'browser' ),
			'language' => $aggregate( 'language' ),
			'timezone' => $aggregate( 'timezone' ),
			'viewport' => array_map( fn( $r ) => [ 'label' => $r['label'], 'count' => (int) $r['count'] ], $viewport_rows ),
		];
	}

	// ----------------------------------------------------------------
	// Referrers
	// ----------------------------------------------------------------

	public static function get_referrers( string $period = '30d', int $limit = 20, array $filters = [] ): array {
		global $wpdb;
		$range  = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$et     = RSA_DB::events_table();
		$bt     = self::bot_threshold();

		$conds  = [ "created_at BETWEEN %s AND %s AND bot_score < %d AND referrer_domain IS NOT NULL AND referrer_domain != ''" ];
		$params = [ $range['start'], $range['end'], $bt ];

		if ( ! empty( $filters['page'] ) ) {
			$conds[]  = 'page = %s';
			$params[] = $filters['page'];
		}

		$where    = implode( ' AND ', $conds );
		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_domain AS domain, COUNT(*) AS visits
				 FROM `{$et}`
				 WHERE {$where}
				 GROUP BY referrer_domain
				 ORDER BY visits DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		// Fetch top landing page per referrer domain
		$domains    = array_column( $rows, 'domain' );
		$in_holders = implode( ',', array_fill( 0, count( $domains ), '%s' ) );
		$tp_params  = array_merge( [ $range['start'], $range['end'], $bt ], $domains );
		$tp_rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_domain, page
				 FROM `{$et}`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				   AND referrer_domain IN ({$in_holders})
				 GROUP BY referrer_domain, page
				 ORDER BY referrer_domain, COUNT(*) DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$tp_params
			),
			ARRAY_A
		);

		$top_pages = [];
		foreach ( $tp_rows as $r ) {
			if ( ! isset( $top_pages[ $r['referrer_domain'] ] ) ) {
				$top_pages[ $r['referrer_domain'] ] = $r['page'];
			}
		}

		return array_map( fn( $r ) => [
			'domain'   => $r['domain'],
			'visits'   => (int) $r['visits'],
			'top_page' => $top_pages[ $r['domain'] ] ?? '',
		], $rows );
	}

	// ----------------------------------------------------------------
	// Behavior: time-on-page histogram + session depth
	// ----------------------------------------------------------------

	public static function get_behavior( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$et    = RSA_DB::events_table();
		$st    = RSA_DB::sessions_table();
		$bt    = self::bot_threshold();

		// Optional browser/OS filter on event-level queries
		$evt_conds  = 'created_at BETWEEN %s AND %s AND bot_score < %d AND time_on_page > 0';
		$evt_params = [ $range['start'], $range['end'], $bt ];
		if ( ! empty( $filters['browser'] ) ) {
			$evt_conds   .= ' AND browser = %s';
			$evt_params[] = $filters['browser'];
		}
		if ( ! empty( $filters['os'] ) ) {
			$evt_conds   .= ' AND os = %s';
			$evt_params[] = $filters['os'];
		}

		// Time-on-page histogram buckets (seconds)
		$histogram_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN time_on_page < 10  THEN '0\u20139s'
						WHEN time_on_page < 30  THEN '10\u201329s'
						WHEN time_on_page < 60  THEN '30\u201359s'
						WHEN time_on_page < 120 THEN '1\u20132 min'
						WHEN time_on_page < 300 THEN '2\u20135 min'
						ELSE '5+ min'
					END AS bucket,
					COUNT(*) AS count
				 FROM `{$et}`
				 WHERE {$evt_conds}
				 GROUP BY bucket", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$evt_params
			),
			ARRAY_A
		);

		// Sort histogram in logical order
		$bucket_order = [ '0\u20139s', '10\u201329s', '30\u201359s', '1\u20132 min', '2\u20135 min', '5+ min' ];
		usort( $histogram_rows, function ( $a, $b ) use ( $bucket_order ) {
			return array_search( $a['bucket'], $bucket_order ) - array_search( $b['bucket'], $bucket_order );
		} );

		// Session depth distribution
		$depth_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN pages_viewed = 1 THEN '1 page'
						WHEN pages_viewed = 2 THEN '2 pages'
						WHEN pages_viewed <= 4 THEN '3\u20134 pages'
						WHEN pages_viewed <= 7 THEN '5\u20137 pages'
						ELSE '8+ pages'
					END AS bucket,
					COUNT(*) AS count
				 FROM `{$st}`
				 WHERE created_at BETWEEN %s AND %s
				 GROUP BY bucket", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end']
			),
			ARRAY_A
		);

		// Entry pages
		$entry_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_page AS page, COUNT(*) AS count
				 FROM `{$st}`
				 WHERE created_at BETWEEN %s AND %s
				 GROUP BY entry_page
				 ORDER BY count DESC
				 LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end']
			),
			ARRAY_A
		);

		// Exit pages
		$exit_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exit_page AS page, COUNT(*) AS count
				 FROM `{$st}`
				 WHERE created_at BETWEEN %s AND %s AND exit_page IS NOT NULL
				 GROUP BY exit_page
				 ORDER BY count DESC
				 LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end']
			),
			ARRAY_A
		);

		return [
			'time_histogram' => array_map( fn( $r ) => [ 'bucket' => $r['bucket'], 'count' => (int) $r['count'] ], $histogram_rows ),
			'session_depth'  => array_map( fn( $r ) => [ 'bucket' => $r['bucket'], 'count' => (int) $r['count'] ], $depth_rows ),
			'entry_pages'    => array_map( fn( $r ) => [ 'page' => $r['page'], 'count' => (int) $r['count'] ], $entry_rows ),
			'exit_pages'     => array_map( fn( $r ) => [ 'page' => $r['page'], 'count' => (int) $r['count'] ], $exit_rows ),
		];
	}

	// ----------------------------------------------------------------
	// User flow: page-to-page transition pairs (requires MySQL 8.0+)
	// ----------------------------------------------------------------

	public static function get_user_flow( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$et    = RSA_DB::events_table();
		$bt    = self::bot_threshold();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT from_page, to_page, COUNT(*) AS `count`
				 FROM (
				     SELECT
				         page AS from_page,
				         LEAD(page) OVER (PARTITION BY session_id ORDER BY created_at) AS to_page
				     FROM `{$et}`
				     WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				 ) transitions
				 WHERE to_page IS NOT NULL AND from_page != to_page
				 GROUP BY from_page, to_page
				 ORDER BY `count` DESC
				 LIMIT 30", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end'], $bt
			),
			ARRAY_A
		);

		return $rows ? array_map( fn( $r ) => [
			'from_page' => $r['from_page'],
			'to_page'   => $r['to_page'],
			'count'     => (int) $r['count'],
		], $rows ) : [];
	}

	// ----------------------------------------------------------------
	// Premium: click map data
	// ----------------------------------------------------------------

	public static function get_click_map( string $period = '30d', string $page = '' ): array {
		global $wpdb;
		$range = self::period_range( $period );
		$ct    = RSA_DB::clicks_table();

		$page_clause = $page ? $wpdb->prepare( 'AND page = %s', $page ) : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_tag, element_id, element_class, href_protocol, matched_rule,
				        MAX(element_text) AS element_text, COUNT(*) AS clicks
				 FROM `{$ct}`
				 WHERE created_at BETWEEN %s AND %s {$page_clause}
				 GROUP BY element_tag, element_id, element_class, href_protocol, matched_rule
				 ORDER BY clicks DESC
				 LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end']
			),
			ARRAY_A
		);

		return array_map( fn( $r ) => [
			'tag'          => $r['element_tag'],
			'id'           => $r['element_id'],
			'class'        => $r['element_class'],
			'protocol'     => $r['href_protocol'],
			'matched_rule' => $r['matched_rule'],
			'text'         => $r['element_text'],
			'clicks'       => (int) $r['clicks'],
		], $rows );
	}

	// ----------------------------------------------------------------
	// Premium: heatmap data for a page + date range
	// ----------------------------------------------------------------

	public static function get_heatmap( string $page, string $period = '30d' ): array {
		global $wpdb;
		$range = self::period_range( $period );
		$ht    = RSA_DB::heatmap_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT x_pct, y_pct, SUM(weight) AS weight
				 FROM `{$ht}`
				 WHERE page = %s AND date_bucket BETWEEN %s AND %s
				 GROUP BY x_pct, y_pct", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$page,
				substr( $range['start'], 0, 10 ),
				substr( $range['end'],   0, 10 )
			),
			ARRAY_A
		);

		return array_map( fn( $r ) => [
			'x'      => (float) $r['x_pct'],
			'y'      => (float) $r['y_pct'],
			'weight' => (int)   $r['weight'],
		], $rows );
	}

	// ----------------------------------------------------------------
	// Data export (raw events)
	// ----------------------------------------------------------------

	public static function export_events( string $period = '90d', string $format = 'json' ): string {
		global $wpdb;
		$range = self::period_range( $period );
		$et    = RSA_DB::events_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, page, referrer_domain, os, browser, browser_version,
				        language, timezone, viewport_w, viewport_h, time_on_page, created_at
				 FROM `{$et}`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				 ORDER BY created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end'], self::bot_threshold()
			),
			ARRAY_A
		);

		if ( $format === 'csv' ) {
			if ( empty( $rows ) ) {
				return '';
			}
			$output = implode( ',', array_keys( $rows[0] ) ) . "\n";
			foreach ( $rows as $row ) {
				$output .= implode( ',', array_map( fn( $v ) => '"' . str_replace( '"', '""', $v ) . '"', $row ) ) . "\n";
			}
			return $output;
		}

		return wp_json_encode( $rows );
	}

	// ----------------------------------------------------------------
	// Utility: fill date gaps so charts don't have holes
	// ----------------------------------------------------------------

	private static function fill_date_gaps( array $rows, array $range ): array {
		$map   = [];
		foreach ( $rows as $r ) {
			$map[ $r['day'] ] = (int) $r['views'];
		}

		$filled = [];
		$cursor = strtotime( $range['start'] );
		$end    = strtotime( $range['end'] );

		while ( $cursor <= $end ) {
			$day            = gmdate( 'Y-m-d', $cursor );
			$filled[]       = [ 'day' => $day, 'views' => $map[ $day ] ?? 0 ];
			$cursor        += DAY_IN_SECONDS;
		}
		return $filled;
	}

	// ----------------------------------------------------------------
	// Filter options — distinct values available in current data set
	// ----------------------------------------------------------------

	public static function get_filter_options( string $period, array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$et    = RSA_DB::events_table();
		$bt    = self::bot_threshold();

		$col_opts = function ( string $col ) use ( $wpdb, $range, $et, $bt ): array {
			return $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT `{$col}` FROM `{$et}`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				   AND `{$col}` IS NOT NULL AND `{$col}` != ''
				 ORDER BY `{$col}` ASC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$range['start'], $range['end'], $bt
			) ) ?: [];
		};

		$pages = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT page FROM `{$et}`
			 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
			   AND page IS NOT NULL AND page != ''
			 ORDER BY page ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$range['start'], $range['end'], $bt
		) ) ?: [];

		return [
			'browsers' => $col_opts( 'browser' ),
			'os'       => $col_opts( 'os' ),
			'pages'    => $pages,
		];
	}

	// ----------------------------------------------------------------
	// Cached bot threshold (avoid repeated get_option calls)
	// ----------------------------------------------------------------

	private static ?int $bot_threshold = null;

	private static function bot_threshold(): int {
		if ( self::$bot_threshold === null ) {
			self::$bot_threshold = (int) get_option( 'rsa_bot_score_threshold', 3 );
		}
		return self::$bot_threshold;
	}
}
