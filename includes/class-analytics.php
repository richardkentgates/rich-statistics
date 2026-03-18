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
		// Use WordPress local time so date bucketing matches the site's configured timezone.
		$now = current_time( 'timestamp' );
		if ( $period === 'custom' && $date_from && $date_to ) {
			$start = strtotime( $date_from . ' 00:00:00' );
			$end   = strtotime( $date_to   . ' 23:59:59' );
			if ( $start > $end ) { [ $start, $end ] = [ $end, $start ]; }
			return [
				'start' => date( 'Y-m-d H:i:s', $start ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				'end'   => date( 'Y-m-d H:i:s', $end ),   // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
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
				$start = strtotime( date( 'Y-m-01', $now ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				break;
			case 'lastmonth':
				$start = strtotime( date( 'Y-m-01', strtotime( '-1 month', $now ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				$now   = strtotime( date( 'Y-m-t', strtotime( '-1 month', $now ) ) . ' 23:59:59' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				break;
			default: // Default 30d
				$start = strtotime( '-30 days', $now );
		}
		return [
			'start' => date( 'Y-m-d H:i:s', $start ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			'end'   => date( 'Y-m-d H:i:s', $now ),   // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		];
	}

	// ----------------------------------------------------------------
	// Overview / KPI cards
	// ----------------------------------------------------------------

	public static function get_overview( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );

		$pageviews = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d",
				$range['start'], $range['end'], self::bot_threshold()
			)
		);

		$sessions = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s",
				$range['start'], $range['end']
			)
		);

		$avg_time = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT AVG(time_on_page) FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND time_on_page > 0 AND bot_score < %d",
				$range['start'], $range['end'], self::bot_threshold()
			)
		);

		// Bounce: sessions with only 1 page viewed
		$bounced = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s AND pages_viewed = 1",
				$range['start'], $range['end']
			)
		);

		$bounce_rate = $sessions > 0 ? round( ( $bounced / $sessions ) * 100, 1 ) : 0;

		// Pageviews per day for sparkline
		$daily_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS views FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d GROUP BY DATE(created_at) ORDER BY day ASC",
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
		$range       = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt          = self::bot_threshold();
		$browser     = $filters['browser'] ?? '';
		$os          = $filters['os']      ?? '';
		$page_exact  = $filters['page']    ?? '';
		$page_search = $filters['search']  ?? '';
		$search_like = $page_search !== '' ? '%' . $wpdb->esc_like( $page_search ) . '%' : '';
		$sort_col    = in_array( $filters['sort']     ?? '', [ 'views', 'avg_time' ], true ) ? $filters['sort']     : 'views';
		$sort_dir    = ( ( $filters['sort_dir'] ?? 'desc' ) === 'asc' ) ? 'asc' : 'desc';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT page, COUNT(*) AS views, AVG(time_on_page) AS avg_time
				 FROM `{$wpdb->prefix}rsa_events`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				   AND (%s = '' OR browser = %s)
				   AND (%s = '' OR os = %s)
				   AND (
				     (%s = '' AND %s = '')
				     OR (%s != '' AND page = %s)
				     OR (%s = '' AND %s != '' AND page LIKE %s)
				   )
				 GROUP BY page
				 ORDER BY
				   CASE WHEN %s = 'avg_time' THEN AVG(time_on_page) ELSE COUNT(*) END
				   * CASE WHEN %s = 'asc' THEN 1 ELSE -1 END
				 ASC
				 LIMIT %d",
				$range['start'], $range['end'], $bt,
				$browser, $browser,
				$os, $os,
				$page_exact, $page_search,
				$page_exact, $page_exact,
				$page_exact, $page_search, $search_like,
				$sort_col, $sort_dir,
				$limit
			),
			ARRAY_A
		);

		return array_map( function ( $r ) {
			return [
				'page'     => $r['page'],
				'views'    => (int) $r['views'],
				'avg_time' => round( (float) $r['avg_time'] ),
			];
		}, $rows ?? [] );
	}

	// ----------------------------------------------------------------
	// Audience breakdown
	// ----------------------------------------------------------------

	public static function get_audience( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt    = self::bot_threshold();

		$map_col = fn( $rows ) => array_map( fn( $r ) => [ 'label' => $r['label'] ?: 'Unknown', 'count' => (int) $r['count'] ], $rows );

		$os_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `os` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `os` IS NOT NULL GROUP BY `os` ORDER BY count DESC LIMIT 20",
				$range['start'], $range['end'], $bt
			), ARRAY_A
		);

		$browser_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `browser` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `browser` IS NOT NULL GROUP BY `browser` ORDER BY count DESC LIMIT 20",
				$range['start'], $range['end'], $bt
			), ARRAY_A
		);

		$language_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `language` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `language` IS NOT NULL GROUP BY `language` ORDER BY count DESC LIMIT 20",
				$range['start'], $range['end'], $bt
			), ARRAY_A
		);

		$timezone_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `timezone` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `timezone` IS NOT NULL GROUP BY `timezone` ORDER BY count DESC LIMIT 20",
				$range['start'], $range['end'], $bt
			), ARRAY_A
		);

		// Viewport buckets (segment by width)
		$viewport_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT CASE WHEN viewport_w < 640 THEN 'Mobile (<640px)' WHEN viewport_w < 1024 THEN 'Tablet (640\u{2013}1023px)' WHEN viewport_w < 1440 THEN 'Desktop (1024\u{2013}1439px)' ELSE 'Wide (\u{2265}1440px)' END AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND viewport_w > 0 GROUP BY label ORDER BY count DESC",
				$range['start'], $range['end'], $bt
			),
			ARRAY_A
		);

		return [
			'os'       => $map_col( $os_rows ),
			'browser'  => $map_col( $browser_rows ),
			'language' => $map_col( $language_rows ),
			'timezone' => $map_col( $timezone_rows ),
			'viewport' => array_map( fn( $r ) => [ 'label' => $r['label'], 'count' => (int) $r['count'] ], $viewport_rows ),
		];
	}

	// ----------------------------------------------------------------
	// Referrers
	// ----------------------------------------------------------------

	public static function get_referrers( string $period = '30d', int $limit = 20, array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt    = self::bot_threshold();
		$page  = $filters['page'] ?? '';

		// Single query with correlated subquery for top landing page per referrer
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT e1.referrer_domain AS domain, COUNT(*) AS visits,
				        (SELECT e2.page FROM `{$wpdb->prefix}rsa_events` e2
				         WHERE e2.referrer_domain = e1.referrer_domain
				           AND e2.created_at BETWEEN %s AND %s AND e2.bot_score < %d
				         GROUP BY e2.page ORDER BY COUNT(*) DESC LIMIT 1) AS top_page
				 FROM `{$wpdb->prefix}rsa_events` e1
				 WHERE e1.created_at BETWEEN %s AND %s AND e1.bot_score < %d
				   AND e1.referrer_domain IS NOT NULL AND e1.referrer_domain != ''
				   AND (%s = '' OR e1.page = %s)
				 GROUP BY e1.referrer_domain
				 ORDER BY visits DESC
				 LIMIT %d",
				$range['start'], $range['end'], $bt,
				$range['start'], $range['end'], $bt,
				$page, $page,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		return array_map( fn( $r ) => [
			'domain'   => $r['domain'],
			'visits'   => (int) $r['visits'],
			'top_page' => $r['top_page'] ?? '',
		], $rows );
	}

	// ----------------------------------------------------------------
	// UTM Campaigns
	// ----------------------------------------------------------------

	public static function get_campaigns( string $period = '30d', int $limit = 100, array $filters = [] ): array {
		global $wpdb;
		$range  = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt     = self::bot_threshold();
		$medium = $filters['medium'] ?? '';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT utm_source AS source, utm_medium AS medium, utm_campaign AS campaign,
				        COUNT(*) AS pageviews, COUNT(DISTINCT session_id) AS sessions
				 FROM `{$wpdb->prefix}rsa_events`
				 WHERE created_at BETWEEN %s AND %s
				   AND bot_score < %d
				   AND utm_campaign IS NOT NULL AND utm_campaign != ''
				   AND (%s = '' OR utm_medium = %s)
				 GROUP BY utm_source, utm_medium, utm_campaign
				 ORDER BY sessions DESC, pageviews DESC
				 LIMIT %d",
				$range['start'], $range['end'], $bt,
				$medium, $medium,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		return array_map( fn( $r ) => [
			'source'    => $r['source']   ?? '',
			'medium'    => $r['medium']   ?? '',
			'campaign'  => $r['campaign'] ?? '',
			'pageviews' => (int) $r['pageviews'],
			'sessions'  => (int) $r['sessions'],
		], $rows );
	}

	/** Distinct utm_medium values for the filter dropdown. */
	public static function get_utm_mediums( string $period = '30d' ): array {
		global $wpdb;
		$range = self::period_range( $period );
		$bt    = self::bot_threshold();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT DISTINCT utm_medium FROM `{$wpdb->prefix}rsa_events`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				   AND utm_medium IS NOT NULL AND utm_medium != ''
				 ORDER BY utm_medium LIMIT 50",
				$range['start'], $range['end'], $bt
			),
			ARRAY_A
		);
		return $rows ? array_column( $rows, 'utm_medium' ) : [];
	}

	// ----------------------------------------------------------------
	// Behavior: time-on-page histogram + session depth
	// ----------------------------------------------------------------

	public static function get_behavior( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range   = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt      = self::bot_threshold();
		$browser = $filters['browser'] ?? '';
		$os      = $filters['os']      ?? '';

		// Time-on-page histogram buckets — OR-pattern for optional browser/os filters
		$histogram_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT CASE WHEN time_on_page < 10 THEN '0-9s' WHEN time_on_page < 30 THEN '10-29s' WHEN time_on_page < 60 THEN '30-59s' WHEN time_on_page < 120 THEN '1-2 min' WHEN time_on_page < 300 THEN '2-5 min' ELSE '5+ min' END AS bucket, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND time_on_page > 0 AND (%s = '' OR browser = %s) AND (%s = '' OR os = %s) GROUP BY bucket",
				$range['start'], $range['end'], $bt,
				$browser, $browser,
				$os, $os
			),
			ARRAY_A
		);

		// Sort histogram in logical order
		$bucket_order = [ '0-9s', '10-29s', '30-59s', '1-2 min', '2-5 min', '5+ min' ];
		usort( $histogram_rows, function ( $a, $b ) use ( $bucket_order ) {
			return array_search( $a['bucket'], $bucket_order ) - array_search( $b['bucket'], $bucket_order );
		} );

		// Session depth distribution
		$depth_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT CASE WHEN pages_viewed = 1 THEN '1 page' WHEN pages_viewed = 2 THEN '2 pages' WHEN pages_viewed <= 4 THEN '3-4 pages' WHEN pages_viewed <= 7 THEN '5-7 pages' ELSE '8+ pages' END AS bucket, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s GROUP BY bucket",
				$range['start'], $range['end']
			),
			ARRAY_A
		);

		// Entry pages
		$entry_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT entry_page AS page, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s GROUP BY entry_page ORDER BY count DESC LIMIT 10",
				$range['start'], $range['end']
			),
			ARRAY_A
		);

		// Exit pages
		$exit_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT exit_page AS page, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s AND exit_page IS NOT NULL GROUP BY exit_page ORDER BY count DESC LIMIT 10",
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
		$range     = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt        = self::bot_threshold();
		$from_page = $filters['from_page'] ?? '';
		$to_page   = $filters['to_page']   ?? '';
		$min_count = max( 1, (int) ( $filters['min_count'] ?? 1 ) );
		$sort_col  = in_array( $filters['sort'] ?? '', [ 'count', 'from_page', 'to_page' ], true ) ? $filters['sort'] : 'count';
		$sort_dir  = ( ( $filters['sort_dir'] ?? 'desc' ) === 'asc' ) ? 'asc' : 'desc';
		$limit     = max( 10, min( 250, (int) ( $filters['limit'] ?? 30 ) ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT from_page, to_page, COUNT(*) AS `count`
				 FROM (
				   SELECT page AS from_page,
				          LEAD(page) OVER (PARTITION BY session_id ORDER BY created_at) AS to_page
				   FROM `{$wpdb->prefix}rsa_events`
				   WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				 ) transitions
				 WHERE to_page IS NOT NULL AND from_page != to_page
				   AND (%s = '' OR from_page = %s)
				   AND (%s = '' OR to_page = %s)
				 GROUP BY from_page, to_page
				 HAVING COUNT(*) >= %d
				 ORDER BY
				   CASE WHEN %s = 'asc' THEN
				     CASE WHEN %s = 'from_page' THEN from_page
				          WHEN %s = 'to_page' THEN to_page
				          ELSE LPAD(CAST(COUNT(*) AS CHAR), 20, '0') END
				   END ASC,
				   CASE WHEN %s = 'desc' THEN
				     CASE WHEN %s = 'from_page' THEN from_page
				          WHEN %s = 'to_page' THEN to_page
				          ELSE LPAD(CAST(COUNT(*) AS CHAR), 20, '0') END
				   END DESC
				 LIMIT %d",
				$range['start'], $range['end'], $bt,
				$from_page, $from_page,
				$to_page, $to_page,
				$min_count,
				$sort_dir, $sort_col, $sort_col,
				$sort_dir, $sort_col, $sort_col,
				$limit
			),
			ARRAY_A
		);

		return $rows ? array_map( fn( $r ) => [
			'from_page' => $r['from_page'],
			'to_page'   => $r['to_page'],
			'count'     => (int) $r['count'],
		], $rows ) : [];
	}

	/**
	 * Step-based path flow for the Sankey diagram.
	 *
	 * Columns represent actual chronological steps in visitor sessions
	 * (Step 1 = first page, Step 2 = second page, …). Sessions that end
	 * before reaching the next step contribute an "(exit)" node so
	 * drop-off rates are visible at each stage.
	 *
	 * Returns:
	 *   [
	 *     'steps'          => [ 1 => [ ['page'=>…,'sessions'=>N], … ], 2 => […], … ],
	 *     'links'          => [ ['step'=>1,'from'=>…,'to'=>…,'count'=>N], … ],
	 *     'total_sessions' => N,
	 *   ]
	 *
	 * Filters:
	 *   date_from, date_to   — custom date range (when period = 'custom')
	 *   entry_source         — restrict to sessions whose first event has this referrer domain
	 *   focus_page           — restrict to sessions that include this page at any step
	 *   min_sessions         — hide nodes/links with fewer sessions than this (default 1)
	 *   steps                — max step depth to show (2–5, default 4)
	 */
	public static function get_path_flow( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range     = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt        = self::bot_threshold();
		$f_source  = $filters['entry_source'] ?? '';
		$f_focus   = $filters['focus_page']   ?? '';
		$min_s     = max( 1, (int) ( $filters['min_sessions'] ?? 1 ) );
		$max_steps = min( 5, max( 2, (int) ( $filters['steps'] ?? 4 ) ) );
		$top_n     = 8;

		// Step node counts — using OR-pattern for optional session filters
		$node_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time path flow
			$wpdb->prepare(
				"SELECT step_num, page, COUNT(*) AS sessions
				 FROM (
				   SELECT session_id, page,
				          ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS step_num
				   FROM `{$wpdb->prefix}rsa_events`
				   WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				     AND (%s = '' OR session_id IN (
				           SELECT session_id FROM (
				             SELECT session_id,
				                    COALESCE(NULLIF(referrer_domain,''),'(direct)') AS src,
				                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS rn
				             FROM `{$wpdb->prefix}rsa_events`
				             WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				           ) _src WHERE rn = 1 AND src = %s
				         ))
				     AND (%s = '' OR session_id IN (
				           SELECT DISTINCT session_id FROM `{$wpdb->prefix}rsa_events`
				           WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND page = %s
				         ))
				 ) _steps
				 WHERE step_num <= %d
				 GROUP BY step_num, page
				 HAVING sessions >= %d
				 ORDER BY step_num ASC, sessions DESC",
				$range['start'], $range['end'], $bt,
				$f_source, $range['start'], $range['end'], $bt, $f_source,
				$f_focus,  $range['start'], $range['end'], $bt, $f_focus,
				$max_steps, $min_s
			),
			ARRAY_A
		);

		if ( ! $node_rows ) {
			return [ 'steps' => [], 'links' => [], 'total_sessions' => 0 ];
		}

		// Build steps array, capping at top_n per step
		$steps        = [];
		$top_per_step = [];
		foreach ( $node_rows as $r ) {
			$sn = (int) $r['step_num'];
			if ( ! isset( $steps[ $sn ] ) ) {
				$steps[ $sn ] = [];
			}
			if ( count( $steps[ $sn ] ) < $top_n ) {
				$steps[ $sn ][]        = [ 'page' => $r['page'], 'sessions' => (int) $r['sessions'] ];
				$top_per_step[ $sn ][] = $r['page'];
			}
		}

		$total_sessions = isset( $steps[1] ) ? array_sum( array_column( $steps[1], 'sessions' ) ) : 0;

		// Compute (exit) nodes
		$step_totals = [];
		foreach ( $steps as $sn => $nodes ) {
			$step_totals[ $sn ] = array_sum( array_column( $nodes, 'sessions' ) );
		}
		for ( $sn = 1; $sn < $max_steps; $sn++ ) {
			if ( ! isset( $step_totals[ $sn ] ) ) { continue; }
			$exit_count = $step_totals[ $sn ] - ( $step_totals[ $sn + 1 ] ?? 0 );
			if ( $exit_count >= $min_s ) {
				$steps[ $sn ][] = [ 'page' => '(exit)', 'sessions' => $exit_count ];
			}
		}

		// Step transition links (step N → step N+1)
		$links = [];
		for ( $sn = 1; $sn < $max_steps; $sn++ ) {
			if ( empty( $top_per_step[ $sn ] ) ) { continue; }

			$from_pages = $top_per_step[ $sn ];
			$to_pages   = $top_per_step[ $sn + 1 ] ?? [];
			$from_n     = count( $from_pages );
			$from_ph    = implode( ',', array_fill( 0, $from_n, '%s' ) );

			if ( $to_pages ) {
				$to_ph = implode( ',', array_fill( 0, count( $to_pages ), '%s' ) );

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from_ph/$to_ph contain only %s placeholders built from count()
				$link_sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- count cannot be determined statically when using spread operator with array_merge
					"SELECT s1.page AS from_page, s2.page AS to_page, COUNT(*) AS cnt
					 FROM (
					   SELECT session_id, page,
					          ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS step_num
					   FROM `{$wpdb->prefix}rsa_events`
					   WHERE created_at BETWEEN %s AND %s AND bot_score < %d
					     AND (%s = '' OR session_id IN (
					           SELECT session_id FROM (
					             SELECT session_id,
					                    COALESCE(NULLIF(referrer_domain,''),'(direct)') AS src,
					                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS rn
					             FROM `{$wpdb->prefix}rsa_events`
					             WHERE created_at BETWEEN %s AND %s AND bot_score < %d
					           ) _src WHERE rn = 1 AND src = %s
					         ))
					     AND (%s = '' OR session_id IN (
					           SELECT DISTINCT session_id FROM `{$wpdb->prefix}rsa_events`
					           WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND page = %s
					         ))
					 ) s1
					 JOIN (
					   SELECT session_id, page,
					          ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS step_num
					   FROM `{$wpdb->prefix}rsa_events`
					   WHERE created_at BETWEEN %s AND %s AND bot_score < %d
					     AND (%s = '' OR session_id IN (
					           SELECT session_id FROM (
					             SELECT session_id,
					                    COALESCE(NULLIF(referrer_domain,''),'(direct)') AS src,
					                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS rn
					             FROM `{$wpdb->prefix}rsa_events`
					             WHERE created_at BETWEEN %s AND %s AND bot_score < %d
					           ) _src WHERE rn = 1 AND src = %s
					         ))
					     AND (%s = '' OR session_id IN (
					           SELECT DISTINCT session_id FROM `{$wpdb->prefix}rsa_events`
					           WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND page = %s
					         ))
					 ) s2 ON s1.session_id = s2.session_id AND s2.step_num = s1.step_num + 1
						 WHERE s1.step_num = %d AND s1.page IN ($from_ph) AND s2.page IN ($to_ph)
					 GROUP BY s1.page, s2.page HAVING cnt >= %d ORDER BY cnt DESC",
					...array_merge(
						[
							$range['start'], $range['end'], $bt,
							$f_source, $range['start'], $range['end'], $bt, $f_source,
							$f_focus,  $range['start'], $range['end'], $bt, $f_focus,
							$range['start'], $range['end'], $bt,
							$f_source, $range['start'], $range['end'], $bt, $f_source,
							$f_focus,  $range['start'], $range['end'], $bt, $f_focus,
							$sn,
						],
						$from_pages,
						$to_pages,
						[ $min_s ]
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$link_rows = $wpdb->get_results( $link_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL prepared on preceding lines

				foreach ( $link_rows as $lr ) {
					$links[] = [ 'step' => $sn, 'from' => $lr['from_page'], 'to' => $lr['to_page'], 'count' => (int) $lr['cnt'] ];
				}
			}

			// Exit links
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from_ph contains only %s placeholders built from count()
			$exit_sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- count cannot be determined statically when using spread operator with array_merge
				"SELECT s1.page AS from_page, COUNT(*) AS cnt
				 FROM (
				   SELECT session_id, page,
				          ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS step_num
				   FROM `{$wpdb->prefix}rsa_events`
				   WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				     AND (%s = '' OR session_id IN (
				           SELECT session_id FROM (
				             SELECT session_id,
				                    COALESCE(NULLIF(referrer_domain,''),'(direct)') AS src,
				                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS rn
				             FROM `{$wpdb->prefix}rsa_events`
				             WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				           ) _src WHERE rn = 1 AND src = %s
				         ))
				     AND (%s = '' OR session_id IN (
				           SELECT DISTINCT session_id FROM `{$wpdb->prefix}rsa_events`
				           WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND page = %s
				         ))
				 ) s1
				 LEFT JOIN (
				   SELECT session_id,
				          ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS step_num
				   FROM `{$wpdb->prefix}rsa_events`
				   WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				     AND (%s = '' OR session_id IN (
				           SELECT session_id FROM (
				             SELECT session_id,
				                    COALESCE(NULLIF(referrer_domain,''),'(direct)') AS src,
				                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY created_at) AS rn
				             FROM `{$wpdb->prefix}rsa_events`
				             WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				           ) _src WHERE rn = 1 AND src = %s
				         ))
				     AND (%s = '' OR session_id IN (
				           SELECT DISTINCT session_id FROM `{$wpdb->prefix}rsa_events`
				           WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND page = %s
				         ))
				 ) s2 ON s1.session_id = s2.session_id AND s2.step_num = s1.step_num + 1
				 WHERE s1.step_num = %d AND s2.session_id IS NULL AND s1.page IN ($from_ph)
				 GROUP BY s1.page HAVING cnt >= %d ORDER BY cnt DESC",
				...array_merge(
					[
						$range['start'], $range['end'], $bt,
						$f_source, $range['start'], $range['end'], $bt, $f_source,
						$f_focus,  $range['start'], $range['end'], $bt, $f_focus,
						$range['start'], $range['end'], $bt,
						$f_source, $range['start'], $range['end'], $bt, $f_source,
						$f_focus,  $range['start'], $range['end'], $bt, $f_focus,
						$sn,
					],
					$from_pages,
					[ $min_s ]
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$exit_rows = $wpdb->get_results( $exit_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL prepared on preceding lines

			foreach ( $exit_rows as $er ) {
				$links[] = [ 'step' => $sn, 'from' => $er['from_page'], 'to' => '(exit)', 'count' => (int) $er['cnt'] ];
			}
		}

		return [
			'steps'          => $steps,
			'links'          => $links,
			'total_sessions' => $total_sessions,
		];
	}

	/** Distinct referrer domains for the entry-source dropdown. */
	public static function get_entry_sources( string $period = '30d', array $filters = [] ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt    = self::bot_threshold();

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time filter options
			$wpdb->prepare(
				"SELECT DISTINCT referrer_domain FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND referrer_domain IS NOT NULL AND referrer_domain != '' ORDER BY referrer_domain LIMIT 200",
				$range['start'], $range['end'], $bt
			)
		);

		return $rows ?: [];
	}

	// ----------------------------------------------------------------
	// Premium: click tracking data
	// ----------------------------------------------------------------

	public static function get_click_map( string $period = '30d', string $page = '' ): array {
		global $wpdb;
		$range = self::period_range( $period );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT element_tag, element_id, element_class, href_protocol, matched_rule, MAX(element_text) AS element_text, MAX(href_value) AS href_value, COUNT(*) AS clicks FROM `{$wpdb->prefix}rsa_clicks` WHERE created_at BETWEEN %s AND %s AND (%s = '' OR page = %s) GROUP BY element_tag, element_id, element_class, href_protocol, matched_rule ORDER BY clicks DESC LIMIT 100",
				$range['start'], $range['end'], $page, $page
			),
			ARRAY_A
		);

		return array_map( fn( $r ) => [
			'tag'          => $r['element_tag'],
			'id'           => $r['element_id'],
			'class'        => $r['element_class'],
			'protocol'     => $r['href_protocol'],
			'matched_rule' => $r['matched_rule'],
			'href_value'   => $r['href_value'],
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

		// Query raw clicks directly so data is always current (no nightly aggregation lag).
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT ROUND(x_pct / 2) * 2 AS x_pct, ROUND(y_pct / 2) * 2 AS y_pct, COUNT(*) AS weight FROM `{$wpdb->prefix}rsa_clicks` WHERE page = %s AND created_at BETWEEN %s AND %s AND x_pct IS NOT NULL AND y_pct IS NOT NULL GROUP BY x_pct, y_pct ORDER BY weight DESC",
				$page,
				$range['start'],
				$range['end']
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

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data export, no caching appropriate
			$wpdb->prepare(
				"SELECT session_id, page, referrer_domain, os, browser, browser_version, language, timezone, viewport_w, viewport_h, time_on_page, created_at FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d ORDER BY created_at ASC",
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

	/**
	 * Export any data type for the REST API or admin.
	 *
	 * @param string $data_type  pageviews | sessions | clicks | referrers
	 * @param string $period     Period key (7d, 30d, etc.)
	 * @param string $format     json | csv
	 * @param string $date_from  Y-m-d (optional, for custom range)
	 * @param string $date_to    Y-m-d (optional, for custom range)
	 */
	public static function export_data( string $data_type, string $period = '30d', string $format = 'json', string $date_from = '', string $date_to = '' ): string {
		global $wpdb;
		$range   = self::period_range( $period, $date_from, $date_to );
		$bt      = self::bot_threshold();
		$headers = [];

		switch ( $data_type ) {
			case 'sessions':
				$rows    = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
					"SELECT session_id, entry_page, exit_page, pages_viewed, total_time, browser, os, language, timezone, created_at FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC",
					$range['start'], $range['end']
				), ARRAY_A ) ?: [];
				$headers = [ 'session_id', 'entry_page', 'exit_page', 'pages_viewed', 'total_time', 'browser', 'os', 'language', 'timezone', 'created_at' ];
				break;

			case 'clicks':
				$rows    = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
					"SELECT session_id, page, element_tag, element_id, element_class, element_text, href_protocol, matched_rule, x_pct, y_pct, created_at FROM `{$wpdb->prefix}rsa_clicks` WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC",
					$range['start'], $range['end']
				), ARRAY_A ) ?: [];
				$headers = [ 'session_id', 'page', 'element_tag', 'element_id', 'element_class', 'element_text', 'href_protocol', 'matched_rule', 'x_pct', 'y_pct', 'created_at' ];
				break;

			case 'referrers':
				$rows    = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
					"SELECT referrer_domain, COUNT(*) AS pageviews, COUNT(DISTINCT session_id) AS sessions FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d GROUP BY referrer_domain ORDER BY pageviews DESC",
					$range['start'], $range['end'], $bt
				), ARRAY_A ) ?: [];
				$headers = [ 'referrer_domain', 'pageviews', 'sessions' ];
				break;

			default: // pageviews
				$rows    = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
					"SELECT session_id, page, referrer_domain, os, browser, browser_version, language, timezone, viewport_w, viewport_h, time_on_page, bot_score, created_at FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d ORDER BY created_at DESC",
					$range['start'], $range['end'], $bt
				), ARRAY_A ) ?: [];
				$headers = [ 'session_id', 'page', 'referrer_domain', 'os', 'browser', 'browser_version', 'language', 'timezone', 'viewport_w', 'viewport_h', 'time_on_page', 'bot_score', 'created_at' ];
		}

		if ( $format === 'csv' ) {
			if ( empty( $rows ) ) {
				return "\xEF\xBB\xBF" . implode( ',', $headers ) . "\n";
			}
			$out = "\xEF\xBB\xBF" . implode( ',', $headers ) . "\n"; // UTF-8 BOM for Excel
			foreach ( $rows as $row ) {
				$out .= implode( ',', array_map( static fn( $v ) => '"' . str_replace( '"', '""', (string) $v ) . '"', array_values( $row ) ) ) . "\n";
			}
			return $out;
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
		$bt    = self::bot_threshold();

		$browsers = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time filter options
			"SELECT DISTINCT `browser` FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `browser` IS NOT NULL AND `browser` != '' ORDER BY `browser` ASC LIMIT 50",
			$range['start'], $range['end'], $bt
		) ) ?: [];

		$os = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time filter options
			"SELECT DISTINCT `os` FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `os` IS NOT NULL AND `os` != '' ORDER BY `os` ASC LIMIT 50",
			$range['start'], $range['end'], $bt
		) ) ?: [];

		$pages = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time filter options
			"SELECT DISTINCT page FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND page IS NOT NULL AND page != '' ORDER BY page ASC LIMIT 200",
			$range['start'], $range['end'], $bt
		) ) ?: [];

		return [
			'browsers' => $browsers,
			'os'       => $os,
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
