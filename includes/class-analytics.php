<?php
/**
 * Analytics computation layer.
 *
 * All queries return plain arrays/objects suitable for JSON encoding
 * into Chart.js datasets. Heavy queries use SQL aggregation rather
 * than pulling rows into PHP.
 *
 * @package RichStatistics
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class RSA_Analytics {

	// ----------------------------------------------------------------
	// Period helper
	// ----------------------------------------------------------------

	/**
	 * Returns an array [ 'start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s' ]
	 * for the given period string (7d, 30d, 90d, thismonth, lastmonth).
	 *
	 * @param string $period     Period key (7d, 30d, 90d, thismonth, lastmonth, custom).
	 * @param string $date_from  Start date (Y-m-d).
	 * @param string $date_to    End date (Y-m-d).
	 * @return array  Associative array with 'start' and 'end' keys.
	 */
	public static function period_range( string $period, string $date_from = '', string $date_to = '' ): array {
		// Use UTC so period boundaries align with database created_at (UTC).
		$now = time();
		if ( 'custom' === $period && $date_from && $date_to ) {
			$start = strtotime( $date_from . ' 00:00:00' );
			$end   = strtotime( $date_to . ' 23:59:59' );
			if ( $start > $end ) {
				[ $start, $end ] = array( $end, $start ); }
			return array(
				'start' => gmdate( 'Y-m-d H:i:s', $start ),
				'end'   => gmdate( 'Y-m-d H:i:s', $end ),
			);
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
			default: // Default 30d.
				$start = strtotime( '-30 days', $now );
		}
		return array(
			'start' => gmdate( 'Y-m-d H:i:s', $start ),
			'end'   => gmdate( 'Y-m-d H:i:s', $now ),
		);
	}

	// ----------------------------------------------------------------
	// Overview / KPI cards
	// ----------------------------------------------------------------

	/**
	 * Overview / KPI cards.
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Optional filters (date_from, date_to).
	 * @return array  Pageviews, sessions, avg_time, bounce_rate, daily.
	 */
	public static function get_overview( string $period = '30d', array $filters = array() ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );

		$pageviews = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d",
				$range['start'],
				$range['end'],
				self::bot_threshold()
			)
		);

		$sessions = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s",
				$range['start'],
				$range['end']
			)
		);

		$avg_time = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT AVG(time_on_page) FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND time_on_page > 0 AND bot_score < %d",
				$range['start'],
				$range['end'],
				self::bot_threshold()
			)
		);

		// Bounce: sessions with only 1 page viewed.
		$bounced = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s AND pages_viewed = 1",
				$range['start'],
				$range['end']
			)
		);

		$bounce_rate = $sessions > 0 ? round( ( $bounced / $sessions ) * 100, 1 ) : 0;

		// Pageviews per day for sparkline.
		$daily_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS views FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d GROUP BY DATE(created_at) ORDER BY day ASC",
				$range['start'],
				$range['end'],
				self::bot_threshold()
			),
			ARRAY_A
		);

		return array(
			'pageviews'   => $pageviews,
			'sessions'    => $sessions,
			'avg_time'    => round( $avg_time ),
			'bounce_rate' => $bounce_rate,
			'daily'       => self::fill_date_gaps( $daily_rows, $range ),
		);
	}

	// ----------------------------------------------------------------
	// Top pages
	// ----------------------------------------------------------------

	/**
	 * Top pages.
	 *
	 * @param string $period  Period key.
	 * @param int    $limit   Max results.
	 * @param array  $filters Optional filters (date_from, date_to, browser, os, page, search, sort, sort_dir).
	 * @return array  Array of pages with views and avg_time.
	 */
	public static function get_top_pages( string $period = '30d', int $limit = 20, array $filters = array() ): array {
		global $wpdb;
		$range       = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt          = self::bot_threshold();
		$browser     = $filters['browser'] ?? '';
		$os          = $filters['os'] ?? '';
		$page_exact  = $filters['page'] ?? '';
		$page_search = $filters['search'] ?? '';
		$search_like = '' !== $page_search ? '%' . $wpdb->esc_like( $page_search ) . '%' : '';
		$sort_col    = in_array( $filters['sort'] ?? '', array( 'views', 'avg_time' ), true ) ? $filters['sort'] : 'views';
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
				$range['start'],
				$range['end'],
				$bt,
				$browser,
				$browser,
				$os,
				$os,
				$page_exact,
				$page_search,
				$page_exact,
				$page_exact,
				$page_exact,
				$page_search,
				$search_like,
				$sort_col,
				$sort_dir,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			function ( $r ) {
				return array(
					'page'     => $r['page'],
					'views'    => (int) $r['views'],
					'avg_time' => round( (float) $r['avg_time'] ),
				);
			},
			$rows ?? array()
		);
	}

	// ----------------------------------------------------------------
	// Audience breakdown
	// ----------------------------------------------------------------

	/**
	 * Audience breakdown.
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Optional filters (date_from, date_to).
	 * @return array  OS, browser, language, timezone, viewport breakdowns.
	 */
	public static function get_audience( string $period = '30d', array $filters = array() ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt    = self::bot_threshold();

		$map_col = fn( $rows ) => array_map(
			fn( $r ) => array(
				'label' => $r['label'] ? $r['label'] : 'Unknown',
				'count' => (int) $r['count'],
			),
			$rows
		);

		$os_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `os` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `os` IS NOT NULL GROUP BY `os` ORDER BY count DESC LIMIT 20",
				$range['start'],
				$range['end'],
				$bt
			),
			ARRAY_A
		);

		$browser_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `browser` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `browser` IS NOT NULL GROUP BY `browser` ORDER BY count DESC LIMIT 20",
				$range['start'],
				$range['end'],
				$bt
			),
			ARRAY_A
		);

		$language_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `language` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `language` IS NOT NULL GROUP BY `language` ORDER BY count DESC LIMIT 20",
				$range['start'],
				$range['end'],
				$bt
			),
			ARRAY_A
		);

		$timezone_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT `timezone` AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `timezone` IS NOT NULL GROUP BY `timezone` ORDER BY count DESC LIMIT 20",
				$range['start'],
				$range['end'],
				$bt
			),
			ARRAY_A
		);

		// Viewport buckets (segment by width).
		$viewport_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT CASE WHEN viewport_w < 640 THEN 'Mobile (<640px)' WHEN viewport_w < 1024 THEN 'Tablet (640\u{2013}1023px)' WHEN viewport_w < 1440 THEN 'Desktop (1024\u{2013}1439px)' ELSE 'Wide (\u{2265}1440px)' END AS label, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND viewport_w > 0 GROUP BY label ORDER BY count DESC",
				$range['start'],
				$range['end'],
				$bt
			),
			ARRAY_A
		);

		return array(
			'os'       => $map_col( $os_rows ),
			'browser'  => $map_col( $browser_rows ),
			'language' => $map_col( $language_rows ),
			'timezone' => $map_col( $timezone_rows ),
			'viewport' => array_map(
				fn( $r ) => array(
					'label' => $r['label'],
					'count' => (int) $r['count'],
				),
				$viewport_rows
			),
		);
	}

	// ----------------------------------------------------------------
	// Referrers
	// ----------------------------------------------------------------

	/**
	 * Referrers.
	 *
	 * @param string $period  Period key.
	 * @param int    $limit   Max results.
	 * @param array  $filters Optional filters (date_from, date_to, page).
	 * @return array  Array of referrer domains with visits and top_page.
	 */
	public static function get_referrers( string $period = '30d', int $limit = 20, array $filters = array() ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt    = self::bot_threshold();
		$page  = $filters['page'] ?? '';

		// Single query with correlated subquery for top landing page per referrer.
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
				$range['start'],
				$range['end'],
				$bt,
				$range['start'],
				$range['end'],
				$bt,
				$page,
				$page,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			fn( $r ) => array(
				'domain'   => $r['domain'],
				'visits'   => (int) $r['visits'],
				'top_page' => $r['top_page'] ?? '',
			),
			$rows
		);
	}

	// ----------------------------------------------------------------
	// UTM Campaigns
	// ----------------------------------------------------------------

	/**
	 * UTM Campaigns.
	 *
	 * @param string $period  Period key.
	 * @param int    $limit   Max results.
	 * @param array  $filters Optional filters (date_from, date_to, medium).
	 * @return array  Array of campaigns with source, medium, pageviews, sessions.
	 */
	public static function get_campaigns( string $period = '30d', int $limit = 100, array $filters = array() ): array {
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
				$range['start'],
				$range['end'],
				$bt,
				$medium,
				$medium,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			fn( $r ) => array(
				'source'    => $r['source'] ?? '',
				'medium'    => $r['medium'] ?? '',
				'campaign'  => $r['campaign'] ?? '',
				'pageviews' => (int) $r['pageviews'],
				'sessions'  => (int) $r['sessions'],
			),
			$rows
		);
	}

	/**
	 * Distinct utm_medium values for the filter dropdown.
	 *
	 * @param string $period  Period key.
	 * @return array  Distinct utm_medium values.
	 */
	public static function get_utm_mediums( string $period = '30d' ): array {
		global $wpdb;
		$range = self::period_range( $period );
		$bt    = self::bot_threshold();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT DISTINCT utm_medium FROM `{$wpdb->prefix}rsa_events`
				 WHERE created_at BETWEEN %s AND %s AND bot_score < %d
				   AND utm_medium IS NOT NULL AND utm_medium != ''
				 ORDER BY utm_medium LIMIT 50",
				$range['start'],
				$range['end'],
				$bt
			),
			ARRAY_A
		);
		return $rows ? array_column( $rows, 'utm_medium' ) : array();
	}

	// ----------------------------------------------------------------
	// Behavior: time-on-page histogram + session depth
	// ----------------------------------------------------------------

	/**
	 * Behavior: time-on-page histogram + session depth.
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Optional filters (date_from, date_to, browser, os).
	 * @return array  Histogram, session depth, entry pages, exit pages.
	 */
	public static function get_behavior( string $period = '30d', array $filters = array() ): array {
		global $wpdb;
		$range   = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt      = self::bot_threshold();
		$browser = $filters['browser'] ?? '';
		$os      = $filters['os'] ?? '';

		// Time-on-page histogram buckets — OR-pattern for optional browser/os filters.
		$histogram_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT CASE WHEN time_on_page < 10 THEN '0-9s' WHEN time_on_page < 30 THEN '10-29s' WHEN time_on_page < 60 THEN '30-59s' WHEN time_on_page < 120 THEN '1-2 min' WHEN time_on_page < 300 THEN '2-5 min' ELSE '5+ min' END AS bucket, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND time_on_page > 0 AND (%s = '' OR browser = %s) AND (%s = '' OR os = %s) GROUP BY bucket",
				$range['start'],
				$range['end'],
				$bt,
				$browser,
				$browser,
				$os,
				$os
			),
			ARRAY_A
		);

		// Sort histogram in logical order.
		$bucket_order = array( '0-9s', '10-29s', '30-59s', '1-2 min', '2-5 min', '5+ min' );
		usort(
			$histogram_rows,
			function ( $a, $b ) use ( $bucket_order ) {
				return array_search( $a['bucket'], $bucket_order, true ) - array_search( $b['bucket'], $bucket_order, true );
			}
		);

		// Session depth distribution.
		$depth_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT CASE WHEN pages_viewed = 1 THEN '1 page' WHEN pages_viewed = 2 THEN '2 pages' WHEN pages_viewed <= 4 THEN '3-4 pages' WHEN pages_viewed <= 7 THEN '5-7 pages' ELSE '8+ pages' END AS bucket, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s GROUP BY bucket",
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		// Entry pages.
		$entry_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT entry_page AS page, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s GROUP BY entry_page ORDER BY count DESC LIMIT 10",
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		// Exit pages.
		$exit_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT exit_page AS page, COUNT(*) AS count FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s AND exit_page IS NOT NULL GROUP BY exit_page ORDER BY count DESC LIMIT 10",
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		return array(
			'time_histogram' => array_map(
				fn( $r ) => array(
					'bucket' => $r['bucket'],
					'count'  => (int) $r['count'],
				),
				$histogram_rows
			),
			'session_depth'  => array_map(
				fn( $r ) => array(
					'bucket' => $r['bucket'],
					'count'  => (int) $r['count'],
				),
				$depth_rows
			),
			'entry_pages'    => array_map(
				fn( $r ) => array(
					'page'  => $r['page'],
					'count' => (int) $r['count'],
				),
				$entry_rows
			),
			'exit_pages'     => array_map(
				fn( $r ) => array(
					'page'  => $r['page'],
					'count' => (int) $r['count'],
				),
				$exit_rows
			),
		);
	}

	// ----------------------------------------------------------------
	// MySQL version guard for window functions
	// ----------------------------------------------------------------

	/**
	 * Check if the database server supports window functions.
	 *
	 * MySQL 8.0+ and MariaDB 10.2+ support LEAD() OVER and ROW_NUMBER() OVER.
	 *
	 * @return bool
	 */
	private static function mysql_supports_window_functions(): bool {
		global $wpdb;
		$version = $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time capability check
		if ( ! $version ) {
			return false;
		}
		// MariaDB: window functions available from 10.2.
		if ( false !== stripos( $version, 'MariaDB' ) ) {
			if ( preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
				return ( (int) $m[1] > 10 ) || ( 10 === (int) $m[1] && (int) $m[2] >= 2 );
			}
			return false;
		}
		// MySQL: window functions available from 8.0.
		if ( preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
			return (int) $m[1] >= 8;
		}
		return false;
	}

	// ----------------------------------------------------------------
	// User flow: page-to-page transition pairs (requires MySQL 8.0+)
	// ----------------------------------------------------------------

	/**
	 * User flow: page-to-page transition pairs (requires MySQL 8.0+).
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Optional filters (date_from, date_to, from_page, to_page, min_count, sort, sort_dir, limit).
	 * @return array  Array of transitions with from_page, to_page, count.
	 */
	public static function get_user_flow( string $period = '30d', array $filters = array() ): array {
		if ( ! self::mysql_supports_window_functions() ) {
			return array( 'error' => __( 'User Flow requires MySQL 8.0+ or MariaDB 10.2+.', 'rich-statistics' ) );
		}
		global $wpdb;
		$range     = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt        = self::bot_threshold();
		$from_page = $filters['from_page'] ?? '';
		$to_page   = $filters['to_page'] ?? '';
		$min_count = max( 1, (int) ( $filters['min_count'] ?? 1 ) );
		$sort_col  = in_array( $filters['sort'] ?? '', array( 'count', 'from_page', 'to_page' ), true ) ? $filters['sort'] : 'count';
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
				$range['start'],
				$range['end'],
				$bt,
				$from_page,
				$from_page,
				$to_page,
				$to_page,
				$min_count,
				$sort_dir,
				$sort_col,
				$sort_col,
				$sort_dir,
				$sort_col,
				$sort_col,
				$limit
			),
			ARRAY_A
		);

		return $rows ? array_map(
			fn( $r ) => array(
				'from_page' => $r['from_page'],
				'to_page'   => $r['to_page'],
				'count'     => (int) $r['count'],
			),
			$rows
		) : array();
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
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Filter options (date_from, date_to, entry_source, focus_page, min_sessions, steps).
	 * @return array  Steps, links, and total_sessions.
	 */
	public static function get_path_flow( string $period = '30d', array $filters = array() ): array {
		if ( ! self::mysql_supports_window_functions() ) {
			return array(
				'steps'          => array(),
				'links'          => array(),
				'total_sessions' => 0,
				'error'          => __( 'User Flow requires MySQL 8.0+ or MariaDB 10.2+.', 'rich-statistics' ),
			);
		}
		global $wpdb;
		$range     = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt        = self::bot_threshold();
		$f_source  = $filters['entry_source'] ?? '';
		$f_focus   = $filters['focus_page'] ?? '';
		$min_s     = max( 1, (int) ( $filters['min_sessions'] ?? 1 ) );
		$max_steps = min( 5, max( 2, (int) ( $filters['steps'] ?? 4 ) ) );
		$top_n     = 8;

		// Step node counts — using OR-pattern for optional session filters.
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
				$range['start'],
				$range['end'],
				$bt,
				$f_source,
				$range['start'],
				$range['end'],
				$bt,
				$f_source,
				$f_focus,
				$range['start'],
				$range['end'],
				$bt,
				$f_focus,
				$max_steps,
				$min_s
			),
			ARRAY_A
		);

		if ( ! $node_rows ) {
			return array(
				'steps'          => array(),
				'links'          => array(),
				'total_sessions' => 0,
			);
		}

		// Build steps array, capping at top_n per step.
		$steps        = array();
		$top_per_step = array();
		foreach ( $node_rows as $r ) {
			$sn = (int) $r['step_num'];
			if ( ! isset( $steps[ $sn ] ) ) {
				$steps[ $sn ] = array();
			}
			if ( count( $steps[ $sn ] ) < $top_n ) {
				$steps[ $sn ][]        = array(
					'page'     => $r['page'],
					'sessions' => (int) $r['sessions'],
				);
				$top_per_step[ $sn ][] = $r['page'];
			}
		}

		$total_sessions = isset( $steps[1] ) ? array_sum( array_column( $steps[1], 'sessions' ) ) : 0;

		// Compute (exit) nodes.
		$step_totals = array();
		foreach ( $steps as $sn => $nodes ) {
			$step_totals[ $sn ] = array_sum( array_column( $nodes, 'sessions' ) );
		}
		for ( $sn = 1; $sn < $max_steps; $sn++ ) {
			if ( ! isset( $step_totals[ $sn ] ) ) {
				continue; }
			$exit_count = $step_totals[ $sn ] - ( $step_totals[ $sn + 1 ] ?? 0 );
			if ( $exit_count >= $min_s ) {
				$steps[ $sn ][] = array(
					'page'     => '(exit)',
					'sessions' => $exit_count,
				);
			}
		}

		// Step transition links (step N → step N+1).
		$links = array();
		for ( $sn = 1; $sn < $max_steps; $sn++ ) {
			if ( empty( $top_per_step[ $sn ] ) ) {
				continue; }

			$from_pages = $top_per_step[ $sn ];
			$to_pages   = $top_per_step[ $sn + 1 ] ?? array();
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
						array(
							$range['start'],
							$range['end'],
							$bt,
							$f_source,
							$range['start'],
							$range['end'],
							$bt,
							$f_source,
							$f_focus,
							$range['start'],
							$range['end'],
							$bt,
							$f_focus,
							$range['start'],
							$range['end'],
							$bt,
							$f_source,
							$range['start'],
							$range['end'],
							$bt,
							$f_source,
							$f_focus,
							$range['start'],
							$range['end'],
							$bt,
							$f_focus,
							$sn,
						),
						$from_pages,
						$to_pages,
						array( $min_s )
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$link_rows = $wpdb->get_results( $link_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL prepared on preceding lines

				foreach ( $link_rows as $lr ) {
					$links[] = array(
						'step'  => $sn,
						'from'  => $lr['from_page'],
						'to'    => $lr['to_page'],
						'count' => (int) $lr['cnt'],
					);
				}
			}

			// Exit links.
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
					array(
						$range['start'],
						$range['end'],
						$bt,
						$f_source,
						$range['start'],
						$range['end'],
						$bt,
						$f_source,
						$f_focus,
						$range['start'],
						$range['end'],
						$bt,
						$f_focus,
						$range['start'],
						$range['end'],
						$bt,
						$f_source,
						$range['start'],
						$range['end'],
						$bt,
						$f_source,
						$f_focus,
						$range['start'],
						$range['end'],
						$bt,
						$f_focus,
						$sn,
					),
					$from_pages,
					array( $min_s )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$exit_rows = $wpdb->get_results( $exit_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL prepared on preceding lines

			foreach ( $exit_rows as $er ) {
				$links[] = array(
					'step'  => $sn,
					'from'  => $er['from_page'],
					'to'    => '(exit)',
					'count' => (int) $er['cnt'],
				);
			}
		}

		return array(
			'steps'          => $steps,
			'links'          => $links,
			'total_sessions' => $total_sessions,
		);
	}

	/**
	 * Distinct referrer domains for the entry-source dropdown.
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Optional filters (date_from, date_to).
	 * @return array  Distinct referrer domains.
	 */
	public static function get_entry_sources( string $period = '30d', array $filters = array() ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt    = self::bot_threshold();

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time filter options
			$wpdb->prepare(
				"SELECT DISTINCT referrer_domain FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND referrer_domain IS NOT NULL AND referrer_domain != '' ORDER BY referrer_domain LIMIT 200",
				$range['start'],
				$range['end'],
				$bt
			)
		);

		return $rows ? $rows : array();
	}

	// ----------------------------------------------------------------
	// Premium: click tracking data
	// ----------------------------------------------------------------

	/**
	 * Premium: click tracking data.
	 *
	 * @param string $period  Period key.
	 * @param string $page    Optional page filter.
	 * @return array  Array of click data with tag, id, class, protocol, href, text, clicks.
	 */
	public static function get_click_map( string $period = '30d', string $page = '' ): array {
		global $wpdb;
		$range = self::period_range( $period );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT element_tag, element_id, element_class, href_protocol, matched_rule, MAX(element_text) AS element_text, MAX(href_value) AS href_value, COUNT(*) AS clicks FROM `{$wpdb->prefix}rsa_clicks` WHERE created_at BETWEEN %s AND %s AND (%s = '' OR page = %s) GROUP BY element_tag, element_id, element_class, href_protocol, matched_rule ORDER BY clicks DESC LIMIT 100",
				$range['start'],
				$range['end'],
				$page,
				$page
			),
			ARRAY_A
		);

		return array_map(
			fn( $r ) => array(
				'tag'          => $r['element_tag'],
				'id'           => $r['element_id'],
				'class'        => $r['element_class'],
				'protocol'     => $r['href_protocol'],
				'matched_rule' => $r['matched_rule'],
				'href_value'   => $r['href_value'],
				'text'         => $r['element_text'],
				'clicks'       => (int) $r['clicks'],
			),
			$rows
		);
	}

	// ----------------------------------------------------------------
	// Premium: heatmap data for a page + date range
	// ----------------------------------------------------------------

	/**
	 * Premium: heatmap data for a page + date range.
	 *
	 * @param string $page      Page path.
	 * @param string $period    Period key.
	 * @param string $date_from Start date (Y-m-d).
	 * @param string $date_to   End date (Y-m-d).
	 * @return array  Array of heatmap coordinates with weights.
	 */
	public static function get_heatmap( string $page, string $period = '30d', string $date_from = '', string $date_to = '' ): array {
		global $wpdb;
		$range = self::period_range( $period, $date_from, $date_to );

		// Query raw clicks directly so data is always current (no nightly aggregation lag).
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT ROUND(x_pct / 2) * 2 AS x_pct, ROUND(y_pct / 2) * 2 AS y_pct, COUNT(*) AS weight FROM `{$wpdb->prefix}rsa_clicks` WHERE page = %s AND created_at BETWEEN %s AND %s AND x_pct IS NOT NULL AND y_pct IS NOT NULL GROUP BY ROUND(x_pct / 2) * 2, ROUND(y_pct / 2) * 2 ORDER BY weight DESC",
				$page,
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		// Element breakdown per coordinate bucket — used for hotspot hover tooltips.
		$elem_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT ROUND(x_pct / 2) * 2 AS xb, ROUND(y_pct / 2) * 2 AS yb,
				        element_tag, MAX(element_text) AS element_text, MAX(href_value) AS href_value,
				        COUNT(*) AS cnt
				 FROM `{$wpdb->prefix}rsa_clicks`
				 WHERE page = %s AND created_at BETWEEN %s AND %s AND x_pct IS NOT NULL AND y_pct IS NOT NULL
				 GROUP BY xb, yb, element_tag, element_id, element_class, href_protocol, matched_rule
				 ORDER BY cnt DESC",
				$page,
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		$elem_map = array();
		foreach ( $elem_rows as $er ) {
			$key = $er['xb'] . ':' . $er['yb'];
			if ( ! isset( $elem_map[ $key ] ) ) {
				$elem_map[ $key ] = array();
			}
			if ( count( $elem_map[ $key ] ) < 5 ) {
				$elem_map[ $key ][] = array(
					'tag'   => $er['element_tag'] ? $er['element_tag'] : null,
					'text'  => $er['element_text'] ? $er['element_text'] : ( $er['href_value'] ? $er['href_value'] : null ),
					'href'  => $er['href_value'] ? $er['href_value'] : null,
					'count' => (int) $er['cnt'],
				);
			}
		}

		return array_map(
			function ( $r ) use ( $elem_map ) {
				$key = $r['x_pct'] . ':' . $r['y_pct'];
				return array(
					'x'        => (float) $r['x_pct'],
					'y'        => (float) $r['y_pct'],
					'weight'   => (int) $r['weight'],
					'elements' => $elem_map[ $key ] ?? array(),
				);
			},
			$rows
		);
	}

	// ----------------------------------------------------------------
	// Data export (raw events)
	// ----------------------------------------------------------------

	/**
	 * Data export (raw events).
	 *
	 * @param string $period  Period key.
	 * @param string $format  Export format (json or csv).
	 * @return string  Exported data.
	 */
	/**
	 * Export raw events (deprecated — use export_data('pageviews', …) instead).
	 *
	 * @deprecated 2.4.27 Use RSA_Analytics::export_data( 'pageviews', … )
	 *
	 * @param string $period Period key.
	 * @param string $format json | csv.
	 * @return string Exported data.
	 */
	public static function export_events( string $period = '90d', string $format = 'json' ): string {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- delegated method handles escaping
		return self::export_data( 'pageviews', $period, $format );
	}

	/**
	 * Export any data type for the REST API or admin.
	 *
	 * @param string $data_type  pageviews | sessions | clicks | referrers.
	 * @param string $period     Period key (7d, 30d, etc.).
	 * @param string $format     json | csv.
	 * @param string $date_from  Y-m-d (optional, for custom range).
	 * @param string $date_to    Y-m-d (optional, for custom range).
	 * @return string  Exported data in requested format.
	 */
	public static function export_data( string $data_type, string $period = '30d', string $format = 'json', string $date_from = '', string $date_to = '' ): string {
		global $wpdb;
		$range   = self::period_range( $period, $date_from, $date_to );
		$bt      = self::bot_threshold();
		$headers = array();

		switch ( $data_type ) {
			case 'sessions':
				$results = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
						"SELECT session_id, entry_page, exit_page, pages_viewed, total_time, browser, os, language, timezone, created_at FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC",
						$range['start'],
						$range['end']
					),
					ARRAY_A
				);
				$rows    = $results ? $results : array();
				$headers = array( 'session_id', 'entry_page', 'exit_page', 'pages_viewed', 'total_time', 'browser', 'os', 'language', 'timezone', 'created_at' );
				break;

			case 'clicks':
				$results = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
						"SELECT session_id, page, element_tag, element_id, element_class, element_text, href_protocol, matched_rule, x_pct, y_pct, created_at FROM `{$wpdb->prefix}rsa_clicks` WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC",
						$range['start'],
						$range['end']
					),
					ARRAY_A
				);
				$rows    = $results ? $results : array();
				$headers = array( 'session_id', 'page', 'element_tag', 'element_id', 'element_class', 'element_text', 'href_protocol', 'matched_rule', 'x_pct', 'y_pct', 'created_at' );
				break;

			case 'referrers':
				$results = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
						"SELECT referrer_domain, COUNT(*) AS pageviews, COUNT(DISTINCT session_id) AS sessions FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d GROUP BY referrer_domain ORDER BY pageviews DESC",
						$range['start'],
						$range['end'],
						$bt
					),
					ARRAY_A
				);
				$rows    = $results ? $results : array();
				$headers = array( 'referrer_domain', 'pageviews', 'sessions' );
				break;

			default: // pageviews.
				$results = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export on demand
						"SELECT session_id, page, referrer_domain, os, browser, browser_version, language, timezone, viewport_w, viewport_h, time_on_page, bot_score, created_at FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d ORDER BY created_at DESC",
						$range['start'],
						$range['end'],
						$bt
					),
					ARRAY_A
				);
				$rows    = $results ? $results : array();
				$headers = array( 'session_id', 'page', 'referrer_domain', 'os', 'browser', 'browser_version', 'language', 'timezone', 'viewport_w', 'viewport_h', 'time_on_page', 'bot_score', 'created_at' );
		}

		if ( 'csv' === $format ) {
			if ( empty( $rows ) ) {
				return "\xEF\xBB\xBF" . implode( ',', $headers ) . "\n";
			}
			// Use fputcsv for RFC 4180 compliance — handles commas, quotes, and newlines in values.
			$handle = fopen( 'php://temp', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp stream, not file system
			fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://temp stream, not file system
			fputcsv( $handle, $headers );
			foreach ( $rows as $row ) {
				fputcsv( $handle, array_values( $row ) );
			}
			rewind( $handle );
			$csv = stream_get_contents( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp stream, not file system
			return $csv;
		}

		return wp_json_encode( $rows );
	}

	// ----------------------------------------------------------------
	// Utility: fill date gaps so charts don't have holes
	// ----------------------------------------------------------------

	/**
	 * Utility: fill date gaps so charts don't have holes.
	 *
	 * @param array $rows  Daily data rows.
	 * @param array $range Period range with 'start' and 'end'.
	 * @return array  Filled daily data with no gaps.
	 */
	private static function fill_date_gaps( array $rows, array $range ): array {
		$map = array();
		foreach ( $rows as $r ) {
			$map[ $r['day'] ] = (int) $r['views'];
		}

		$filled = array();
		$cursor = strtotime( $range['start'] );
		$end    = strtotime( $range['end'] );

		while ( $cursor <= $end ) {
			$day      = wp_date( 'Y-m-d', $cursor );
			$filled[] = array(
				'day'   => $day,
				'views' => $map[ $day ] ?? 0,
			);
			$cursor  += DAY_IN_SECONDS;
		}
		return $filled;
	}

	// ----------------------------------------------------------------
	// Filter options — distinct values available in current data set
	// ----------------------------------------------------------------

	/**
	 * Filter options — distinct values available in current data set.
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Optional filters (date_from, date_to).
	 * @return array  Browsers, OS, pages.
	 */
	public static function get_filter_options( string $period, array $filters = array() ): array {
		global $wpdb;
		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		$bt    = self::bot_threshold();

		$browser_results = $wpdb->get_col(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time filter options
				"SELECT DISTINCT `browser` FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `browser` IS NOT NULL AND `browser` != '' ORDER BY `browser` ASC LIMIT 50",
				$range['start'],
				$range['end'],
				$bt
			)
		);
		$browsers        = $browser_results ? $browser_results : array();

		$os_results = $wpdb->get_col(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time filter options
				"SELECT DISTINCT `os` FROM `{$wpdb->prefix}rsa_events` WHERE created_at BETWEEN %s AND %s AND bot_score < %d AND `os` IS NOT NULL AND `os` != '' ORDER BY `os` ASC LIMIT 50",
				$range['start'],
				$range['end'],
				$bt
			)
		);
		$os         = $os_results ? $os_results : array();

		// Pages: all public WordPress content — published + non-trash.
		// Returned as {value, label} objects so the webapp, REST consumers,
		// and WP admin templates all render human-readable titles from one source.
		$trackable = RSA_Admin::get_trackable_pages();
		$wp_pages  = array();
		foreach ( $trackable as $path => $title ) {
			$wp_pages[] = array(
				'value' => $path,
				'label' => $title,
			);
		}

		return array(
			'browsers'      => $browsers,
			'os'            => $os,
			'pages'         => $wp_pages,
			'heatmap_pages' => $wp_pages,
		);
	}

	// ----------------------------------------------------------------
	// Cached bot threshold (avoid repeated get_option calls)
	// ----------------------------------------------------------------

	/**
	 * Cached bot threshold (avoid repeated get_option calls).
	 *
	 * @var int|null
	 */
	private static ?int $bot_threshold = null;

	/**
	 * Cached bot threshold.
	 *
	 * @return int  Bot score threshold.
	 */
	private static function bot_threshold(): int {
		if ( null === self::$bot_threshold ) {
			self::$bot_threshold = (int) get_option( 'rsa_bot_score_threshold', 5 );
		}
		return self::$bot_threshold;
	}

	// ----------------------------------------------------------------
	// WooCommerce: product views, add-to-cart events, orders, revenue
	// ----------------------------------------------------------------

	/**
	 * WooCommerce: product views, add-to-cart events, and order completions.
	 *
	 * @param string $period  Period key.
	 * @param array  $filters Optional filters (date_from, date_to).
	 * @return array  Top products and funnel data.
	 */
	public static function get_woocommerce( string $period = '30d', array $filters = array() ): array {
		global $wpdb;

		$empty = array(
			'top_products_viewed' => array(),
			'top_products_cart'   => array(),
			'orders_count'        => 0,
			'funnel'              => array(
				'views'  => 0,
				'cart'   => 0,
				'orders' => 0,
			),
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $empty;
		}

		$range = self::period_range( $period, $filters['date_from'] ?? '', $filters['date_to'] ?? '' );
		// Funnel counts.
		$funnel_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS cnt FROM `{$wpdb->prefix}rsa_wc_events` WHERE created_at BETWEEN %s AND %s GROUP BY event_type",
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		$funnel = array(
			'views'  => 0,
			'cart'   => 0,
			'orders' => 0,
		);
		foreach ( (array) $funnel_rows as $row ) {
			if ( 'wc_product_view' === $row['event_type'] ) {
				$funnel['views'] = (int) $row['cnt']; }
			if ( 'wc_add_to_cart' === $row['event_type'] ) {
				$funnel['cart'] = (int) $row['cnt']; }
			if ( 'wc_order_complete' === $row['event_type'] ) {
				$funnel['orders'] = (int) $row['cnt']; }
		}

		// Top products by views.
		$top_viewed = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT product_id, product_name, COUNT(*) AS views
				 FROM `{$wpdb->prefix}rsa_wc_events` WHERE event_type = 'wc_product_view' AND created_at BETWEEN %s AND %s
				 GROUP BY product_id, product_name ORDER BY views DESC LIMIT 10",
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		// Top products by add-to-cart.
		$top_cart = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT product_id, product_name, SUM(quantity) AS total_qty, COUNT(*) AS events
				 FROM `{$wpdb->prefix}rsa_wc_events` WHERE event_type = 'wc_add_to_cart' AND created_at BETWEEN %s AND %s
				 GROUP BY product_id, product_name ORDER BY events DESC LIMIT 10",
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		// Order count.
		$orders_row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt
				 FROM `{$wpdb->prefix}rsa_wc_events` WHERE event_type = 'wc_order_complete' AND created_at BETWEEN %s AND %s",
				$range['start'],
				$range['end']
			),
			ARRAY_A
		);

		return array(
			'top_products_viewed' => (array) $top_viewed,
			'top_products_cart'   => (array) $top_cart,
			'orders_count'        => (int) ( $orders_row['cnt'] ?? 0 ),
			'funnel'              => $funnel,
		);
	}

	// ----------------------------------------------------------------
	// Maintenance: all tracked paths with live / deleted status
	// ----------------------------------------------------------------

	/**
	 * Maintenance: all tracked paths with live / deleted status.
	 *
	 * @return array  Array of tracked pages with events, clicks, heatmap, status.
	 */
	public static function get_all_tracked_pages(): array {
		global $wpdb;

		// Union of distinct page paths across all three tables, with per-table counts.
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance query
			"SELECT page,
				SUM(ev) AS events,
				SUM(cl) AS clicks,
				SUM(hm) AS heatmap
			FROM (
				SELECT page, COUNT(*) AS ev, 0 AS cl, 0 AS hm FROM `{$wpdb->prefix}rsa_events` WHERE page != '' AND page IS NOT NULL GROUP BY page
				UNION ALL
				SELECT page, 0, COUNT(*), 0 FROM `{$wpdb->prefix}rsa_clicks` WHERE page != '' AND page IS NOT NULL GROUP BY page
				UNION ALL
				SELECT page, 0, 0, SUM(weight) FROM `{$wpdb->prefix}rsa_heatmap` WHERE page != '' AND page IS NOT NULL GROUP BY page
			) sub
			GROUP BY page
			ORDER BY events DESC
			LIMIT 500",
			ARRAY_A
		);
		$rows    = $results ? $results : array();

		// Cross-reference DB paths against all non-trash WP content (any post type,
		// any non-trash status).  Anything NOT in that set is 'unmatched'.
		$live_paths = array_keys( RSA_Admin::get_trackable_pages() );
		$live_set   = array_flip( $live_paths ); // O(1) lookups.

		$data_retention = (int) get_option( 'rsa_retention_days', 90 );

		return array_map(
			static function ( $row ) use ( $live_set, $data_retention ) {
				$path    = $row['page'];
				$is_home = ( '/' === $path || '' === $path );

				if ( $is_home || isset( $live_set[ $path ] ) ) {
					$status = 'live';
				} else {
					$status = 'unmatched';
				}

				return array(
					'page'           => $path,
					'events'         => (int) $row['events'],
					'clicks'         => (int) $row['clicks'],
					'heatmap'        => (int) $row['heatmap'],
					'status'         => $status,
					'retention_days' => $data_retention,
				);
			},
			$rows
		);
	}
}
