<?php
/**
 * Rate Limiting Tests
 *
 * Covers transient storage, 60 req/min enforcement, per-session isolation,
 * transient reset, and REST /wc-event rate-limiting rejection.
 *
 * @package RichStatistics\Tests
 */
class RateLimitTest extends WP_UnitTestCase {

	private static string $test_sid  = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
	private static string $other_sid = 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e';

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		$this->clear_tables();
		$this->clear_rate_limit_transients();

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );

		$_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		$_SERVER['HTTP_ACCEPT']          = 'text/html';
	}

	public function tearDown(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['HTTP_ACCEPT'], $_SERVER['REQUEST_METHOD'] );
		$this->clear_rate_limit_transients();
		parent::tearDown();
	}

	private function clear_tables(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_sessions`" );
	}

	private function clear_rate_limit_transients(): void {
		delete_transient( 'rsa_rl_' . substr( md5( self::$test_sid ), 0, 16 ) );
		delete_transient( 'rsa_rl_' . substr( md5( self::$other_sid ), 0, 16 ) );
	}

	private function ingest_pageview( string $session_id, int $time_on_page = 1 ): void {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		$_POST                     = array(
			'action'       => 'rsa_track',
			'nonce'        => wp_create_nonce( 'rsa_track' ),
			'session_id'   => $session_id,
			'page'         => '/test-page/',
			'referrer'     => '',
			'language'     => 'en',
			'timezone'     => 'UTC',
			'viewport_w'   => 1920,
			'viewport_h'   => 1080,
			'time_on_page' => $time_on_page,
			'bot_signals'  => 0,
			'utm_source'   => '',
			'utm_medium'   => '',
			'utm_campaign' => '',
		);
		$_REQUEST['nonce']         = $_POST['nonce'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// phpcs:enable

		ob_start();
		try {
			RSA_Tracker::handle_ingest();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		ob_end_clean();
	}

	/**
	 * Basic enforcement
	 */
	public function test_rate_limit_blocks_after_sixty_requests(): void {
		global $wpdb;
		for ( $i = 0; $i < 65; $i++ ) {
			$this->ingest_pageview( self::$test_sid );
		}
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s",
				self::$test_sid
			)
		);
		// First 60 should be stored; requests 61+ are silently dropped.
		$this->assertSame( 60, $count );
	}

	public function test_rate_limit_transient_counts_requests(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->ingest_pageview( self::$test_sid );
		}
		$key   = 'rsa_rl_' . substr( md5( self::$test_sid ), 0, 16 );
		$count = (int) get_transient( $key );
		$this->assertSame( 5, $count );
	}

	/**
	 * Per-session isolation
	 */
	public function test_sessions_do_not_share_rate_limit_buckets(): void {
		global $wpdb;
		for ( $i = 0; $i < 60; $i++ ) {
			$this->ingest_pageview( self::$test_sid );
		}
		// Other session should still be allowed.
		$this->ingest_pageview( self::$other_sid );
		$other_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s",
				self::$other_sid
			)
		);
		$this->assertSame( 1, $other_count );
	}

	/**
	 * Transient reset
	 */
	public function test_rate_limit_resets_after_transient_deleted(): void {
		global $wpdb;
		for ( $i = 0; $i < 60; $i++ ) {
			$this->ingest_pageview( self::$test_sid );
		}
		$this->clear_rate_limit_transients();
		$this->ingest_pageview( self::$test_sid );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s",
				self::$test_sid
			)
		);
		$this->assertSame( 61, $count );
	}

	/**
	 * REST /wc-event rate limiting
	 */
	public function test_wc_event_rest_returns_rate_limited(): void {
		// Seed the rate-limit transient to 60.
		$key = 'rsa_rl_' . substr( md5( self::$test_sid ), 0, 16 );
		set_transient( $key, 60, 60 );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'POST', '/rsa/v1/wc-event' );
		$request->set_param( 'event_type', 'wc_product_view' );
		$request->set_param( 'session_id', self::$test_sid );
		$request->set_param( 'nonce', wp_create_nonce( 'rsa_track' ) );
		$request->set_param( 'product_id', 1 );
		$response = $wp_rest_server->dispatch( $request );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $body['ok'] );
		$this->assertFalse( $body['data']['recorded'] );
		$this->assertSame( 'rate_limited', $body['data']['reason'] );
	}
}
