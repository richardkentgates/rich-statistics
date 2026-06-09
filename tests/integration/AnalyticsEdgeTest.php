<?php
/**
 * Analytics Edge-Case Tests
 *
 * Covers custom date filters, sort validation, fill_date_gaps with no data,
 * referrers, behavior, and timezone boundary handling.
 *
 * @package RichStatistics\Tests
 */
class AnalyticsEdgeTest extends WP_UnitTestCase {

	private static string $test_sid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		$this->clear_tables();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	private function clear_tables(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_sessions`" );
	}

	private function seed_event( array $overrides = [] ): void {
		global $wpdb;
		$defaults = array(
			'session_id'      => self::$test_sid,
			'page'            => '/test-page/',
			'referrer_domain' => 'google.com',
			'created_at'      => current_time( 'mysql', true ),
			'os'              => 'Windows',
			'browser'         => 'Chrome',
			'browser_version' => '120',
			'language'        => 'en',
			'timezone'        => 'UTC',
			'viewport_w'      => 1920,
			'viewport_h'      => 1080,
			'time_on_page'    => 45,
			'bot_score'       => 0,
		);
		$wpdb->insert(
			$wpdb->prefix . 'rsa_events',
			array_merge( $defaults, $overrides ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
	}

	private function seed_session( array $overrides = [] ): void {
		global $wpdb;
		$defaults = array(
			'session_id'   => self::$test_sid,
			'pages_viewed' => 3,
			'entry_page'   => '/home/',
			'exit_page'    => '/contact/',
			'os'           => 'Windows',
			'browser'      => 'Chrome',
			'language'     => 'en',
			'timezone'     => 'UTC',
			'created_at'   => current_time( 'mysql', true ),
		);
		$wpdb->insert(
			$wpdb->prefix . 'rsa_sessions',
			array_merge( $defaults, $overrides ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Custom date filters
	 */
	public function test_overview_respects_custom_date_range(): void {
		$now = current_time( 'mysql', true );
		$this->seed_event( array( 'created_at' => $now ) );
		$this->seed_session( array( 'created_at' => $now ) );

		$data = RSA_Analytics::get_overview(
			'custom',
			array( 'date_from' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ), 'date_to' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) )
		);
		$this->assertSame( 1, $data['pageviews'] );
	}

	public function test_overview_excludes_events_outside_custom_range(): void {
		$old = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$this->seed_event( array( 'created_at' => $old ) );

		$data = RSA_Analytics::get_overview(
			'custom',
			array( 'date_from' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ), 'date_to' => gmdate( 'Y-m-d' ) )
		);
		$this->assertSame( 0, $data['pageviews'] );
	}

	/**
	 * Top-pages filters
	 */
	public function test_top_pages_filters_by_browser(): void {
		$this->seed_event( array( 'browser' => 'Chrome' ) );
		$this->seed_event( array( 'browser' => 'Firefox', 'session_id' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e' ) );

		$rows = RSA_Analytics::get_top_pages( '7d', 20, array( 'browser' => 'Chrome' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( '/test-page/', $rows[0]['page'] );
	}

	public function test_top_pages_filters_by_os(): void {
		$this->seed_event( array( 'os' => 'Windows' ) );
		$this->seed_event( array( 'os' => 'macOS', 'session_id' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e' ) );

		$rows = RSA_Analytics::get_top_pages( '7d', 20, array( 'os' => 'macOS' ) );
		$this->assertCount( 1, $rows );
	}

	public function test_top_pages_sort_by_avg_time(): void {
		$this->seed_event( array( 'page' => '/fast-page/', 'time_on_page' => 10, 'session_id' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e' ) );
		$this->seed_event( array( 'page' => '/slow-page/', 'time_on_page' => 300, 'session_id' => 'c3d4e5f6-a7b8-4c9d-0e1f-2a3b4c5d6e7f' ) );

		$rows = RSA_Analytics::get_top_pages( '7d', 20, array( 'sort' => 'avg_time', 'sort_dir' => 'desc' ) );
		$this->assertGreaterThanOrEqual( 2, count( $rows ) );
		// Higher avg_time should come first when desc.
		$this->assertGreaterThanOrEqual( $rows[1]['avg_time'], $rows[0]['avg_time'] );
	}

	public function test_top_pages_invalid_sort_defaults_to_views(): void {
		$this->seed_event();
		$rows = RSA_Analytics::get_top_pages( '7d', 20, array( 'sort' => 'invalid_column' ) );
		$this->assertCount( 1, $rows );
	}

	/**
	 * Fill date gaps
	 */
	public function test_fill_date_gaps_returns_full_range_with_zeros(): void {
		$range = RSA_Analytics::period_range( '7d' );
		$daily = RSA_Analytics::get_overview( '7d' );
		$this->assertNotEmpty( $daily['daily'] );
		// 7-day period should produce 8 days (inclusive) or 7 days depending on implementation.
		$this->assertGreaterThanOrEqual( 7, count( $daily['daily'] ) );
		// All entries should have zero views since no data seeded.
		foreach ( $daily['daily'] as $d ) {
			$this->assertArrayHasKey( 'day', $d );
			$this->assertArrayHasKey( 'views', $d );
			$this->assertSame( 0, $d['views'] );
		}
	}

	/**
	 * Referrers
	 */
	public function test_referrers_extracts_domains_from_seeded_data(): void {
		$this->seed_event( array( 'referrer_domain' => 'facebook.com' ) );
		$this->seed_event( array( 'referrer_domain' => 'facebook.com', 'session_id' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e' ) );

		$rows = RSA_Analytics::get_referrers( '7d', 20 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'facebook.com', $rows[0]['domain'] );
		$this->assertSame( 2, $rows[0]['visits'] );
	}

	public function test_referrers_filters_by_page(): void {
		$this->seed_event( array( 'page' => '/about/', 'referrer_domain' => 'twitter.com' ) );
		$this->seed_event( array( 'page' => '/contact/', 'referrer_domain' => 'linkedin.com' ) );

		$rows = RSA_Analytics::get_referrers( '7d', 20, array( 'page' => '/about/' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'twitter.com', $rows[0]['domain'] );
	}

	/**
	 * Behavior
	 */
	public function test_behavior_time_histogram_buckets_correctly(): void {
		$this->seed_event( array( 'time_on_page' => 5 ) );
		$this->seed_event( array( 'time_on_page' => 15, 'session_id' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e' ) );
		$this->seed_event( array( 'time_on_page' => 180, 'session_id' => 'c3d4e5f6-a7b8-4c9d-0e1f-2a3b4c5d6e7f' ) );

		$data    = RSA_Analytics::get_behavior( '7d' );
		$buckets = array_column( $data['time_histogram'], 'bucket' );
		$this->assertContains( '0-9s', $buckets );
		$this->assertContains( '10-29s', $buckets );
		$this->assertContains( '2-5 min', $buckets );
	}

	public function test_behavior_session_depth_distribution(): void {
		$this->seed_session( array( 'pages_viewed' => 1 ) );
		$this->seed_session( array( 'pages_viewed' => 3, 'session_id' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e' ) );
		$this->seed_session( array( 'pages_viewed' => 10, 'session_id' => 'c3d4e5f6-a7b8-4c9d-0e1f-2a3b4c5d6e7f' ) );

		$data    = RSA_Analytics::get_behavior( '7d' );
		$buckets = array_column( $data['session_depth'], 'bucket' );
		$this->assertContains( '1 page', $buckets );
		$this->assertContains( '3-4 pages', $buckets );
		$this->assertContains( '8+ pages', $buckets );
	}

	/**
	 * Timezone boundary
	 */
	public function test_pageview_at_2359_utc_appears_in_correct_daily_bucket(): void {
		$midnight_yesterday = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day', current_time( 'timestamp' ) ) );
		$this->seed_event( array( 'created_at' => $midnight_yesterday ) );

		$data          = RSA_Analytics::get_overview( '7d' );
		$yesterday     = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$yesterday_row = array_filter(
			$data['daily'],
			function ( $d ) use ( $yesterday ) {
				return $d['day'] === $yesterday;
			}
		);
		$this->assertCount( 1, $yesterday_row );
		$this->assertSame( 1, reset( $yesterday_row )['views'] );
	}

	/**
	 * ----------------------------------------------------------------
	 * MySQL window functions capability detection
	 * ----------------------------------------------------------------
	 */
	public function test_mysql_supports_window_functions_returns_bool(): void {
		$method = new ReflectionMethod( RSA_Analytics::class, 'mysql_supports_window_functions' );
		$method->setAccessible( true );
		$result = $method->invoke( null );
		$this->assertIsBool( $result );
	}

	public function test_mysql_supports_window_functions_detects_mysql_80(): void {
		global $wpdb;
		$method = new ReflectionMethod( RSA_Analytics::class, 'mysql_supports_window_functions' );
		$method->setAccessible( true );

		$original = $wpdb;

		// MySQL 8.0.
		$wpdb = new class( $original ) {
			private $base;
			public function __construct( $base ) {
				$this->base = $base; }
			public function get_var( $query = null, $x = 0, $y = 0 ) {
				if ( 'SELECT VERSION()' === $query ) {
					return '8.0.33';
				}
				return $this->base->get_var( $query, $x, $y );
			}
		};
		$this->assertTrue( $method->invoke( null ) );

		// MySQL 5.7.
		$wpdb = new class( $original ) {
			private $base;
			public function __construct( $base ) {
				$this->base = $base; }
			public function get_var( $query = null, $x = 0, $y = 0 ) {
				if ( 'SELECT VERSION()' === $query ) {
					return '5.7.42';
				}
				return $this->base->get_var( $query, $x, $y );
			}
		};
		$this->assertFalse( $method->invoke( null ) );

		// MariaDB 10.2.
		$wpdb = new class( $original ) {
			private $base;
			public function __construct( $base ) {
				$this->base = $base; }
			public function get_var( $query = null, $x = 0, $y = 0 ) {
				if ( 'SELECT VERSION()' === $query ) {
					return '10.2.38-MariaDB';
				}
				return $this->base->get_var( $query, $x, $y );
			}
		};
		$this->assertTrue( $method->invoke( null ) );

		// MariaDB 10.1.
		$wpdb = new class( $original ) {
			private $base;
			public function __construct( $base ) {
				$this->base = $base; }
			public function get_var( $query = null, $x = 0, $y = 0 ) {
				if ( 'SELECT VERSION()' === $query ) {
					return '10.1.48-MariaDB';
				}
				return $this->base->get_var( $query, $x, $y );
			}
		};
		$this->assertFalse( $method->invoke( null ) );

		$wpdb = $original;
	}
}
