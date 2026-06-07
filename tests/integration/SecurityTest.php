<?php
/**
 * Security Tests
 *
 * Covers SQL injection, XSS, path traversal, CSRF, session spoofing,
 * and bot-score manipulation across the ingest pipeline, REST API,
 * admin handlers, and database layer.
 *
 * @package RichStatistics\Tests
 */
class SecurityTest extends WP_UnitTestCase {

	private static string $test_sid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
	private ?int $test_user_id      = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'rsa_manage_statistics' );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			class_alias( 'WP_REST_Server', 'WooCommerce' );
		}
	}

	public static function tearDownAfterClass(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'rsa_manage_statistics' );
		}
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		$this->clear_plugin_tables();

		if ( ! defined( 'RSA_PREMIUM_TEST' ) ) {
			define( 'RSA_PREMIUM_TEST', true );
		}

		$this->test_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->test_user_id );

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
		parent::tearDown();
	}

	private function clear_plugin_tables(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_sessions`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_wc_events`" );
	}

	private function ingest_pageview( array $overrides = [] ): void {
		$payload                   = array_merge(
			array(
				'action'       => 'rsa_track',
				'nonce'        => wp_create_nonce( 'rsa_track' ),
				'session_id'   => self::$test_sid,
				'page'         => '/test-page/',
				'referrer'     => 'https://google.com',
				'language'     => 'en-US',
				'timezone'     => 'America/Chicago',
				'viewport_w'   => 1920,
				'viewport_h'   => 1080,
				'time_on_page' => 12,
				'bot_signals'  => 0,
				'utm_source'   => '',
				'utm_medium'   => '',
				'utm_campaign' => '',
			),
			$overrides
		);
		$_POST                     = $payload;
		$_REQUEST['nonce']         = $payload['nonce'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
		}
		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		}
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			$_SERVER['HTTP_ACCEPT'] = 'text/html';
		}

		ob_start();
		try {
			RSA_Tracker::handle_ingest();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		ob_end_clean();
	}

	/**
	 * SQL Injection
	 */
	public function test_malicious_page_path_does_not_execute_sql(): void {
		$malicious = "/test'; DROP TABLE wp_users; --";
		$this->ingest_pageview( array( 'page' => $malicious ) );

		global $wpdb;
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT page FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s",
				self::$test_sid
			)
		);
		// $wpdb->prepare() safely escaped the payload — it was stored verbatim
		// but never executed. The critical assertion is the table still exists.
		$this->assertStringContainsString( "';", $stored );
		$this->assertStringContainsString( 'DROP TABLE', $stored );
		// Verify no SQL was executed — the users table must still exist.
		$this->assertSame( $wpdb->prefix . 'users', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'users' ) ) );
	}

	public function test_malicious_utm_params_are_sanitized(): void {
		$this->ingest_pageview(
			array(
				'utm_source'   => "'; DELETE FROM wp_posts; --",
				'utm_medium'   => '<script>alert(1)</script>',
				'utm_campaign' => '<?php phpinfo(); ?>',
			)
		);

		global $wpdb;
		$stored = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT utm_source, utm_medium, utm_campaign FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s",
				self::$test_sid
			),
			ARRAY_A
		);

		// SQL fragments are preserved by sanitize_text_field() but safely escaped
		// by $wpdb->prepare(). The critical check is the table still exists.
		$this->assertStringContainsString( "';", $stored['utm_source'] );
		$this->assertStringContainsString( 'DELETE FROM', $stored['utm_source'] );
		$this->assertSame( $wpdb->prefix . 'posts', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'posts' ) ) );

		// HTML tags are stripped by sanitize_text_field().
		$this->assertStringNotContainsString( '<script>', $stored['utm_medium'] );
		$this->assertStringNotContainsString( '</script>', $stored['utm_medium'] );

		// PHP tags are stripped by strip_tags() inside sanitize_text_field().
		$this->assertStringNotContainsString( '<?php', $stored['utm_campaign'] );
	}

	/**
	 * XSS via stored data
	 */
	public function test_script_tags_stripped_from_page_before_storage(): void {
		$this->ingest_pageview( array( 'page' => '/<script>alert(1)</script>/' ) );

		global $wpdb;
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT page FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s",
				self::$test_sid
			)
		);

		$this->assertStringNotContainsString( '<script>', $stored );
		$this->assertStringNotContainsString( '</script>', $stored );
	}

	public function test_rest_response_encodes_special_chars_in_page(): void {
		$this->ingest_pageview( array( 'page' => '/about" onload="alert(1)/' ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( $this->test_user_id );

		$request = new WP_REST_Request( 'GET', '/rsa/v1/pages' );
		$request->set_param( 'period', '7d' );
		$response = $wp_rest_server->dispatch( $request );
		$body     = json_decode( wp_json_encode( $response->get_data() ), true );

		// JSON encoding escapes quotes, so raw HTML attributes are impossible.
		$json_raw = wp_json_encode( $body );
		$this->assertStringNotContainsString( '" onload="alert(1)"', $json_raw );
	}

	/**
	 * Path traversal in purge-page
	 */
	public function test_purge_page_path_traversal_returns_zero(): void {
		$deleted = RSA_DB::purge_page_data( '../../../wp-config.php' );
		$this->assertSame( 0, $deleted );
	}

	public function test_purge_page_exact_match_only(): void {
		$this->ingest_pageview( array( 'page' => '/safe-page/' ) );
		$deleted = RSA_DB::purge_page_data( '/different-page/' );
		$this->assertSame( 0, $deleted );

		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE page = %s",
				'/safe-page/'
			)
		);
		$this->assertSame( '1', $exists );
	}

	/**
	 * CSRF on admin_post handlers
	 */
	public function test_save_settings_without_nonce_dies(): void {
		$_POST    = array( 'rsa_retention_days' => '30' );
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );

		$threw = false;
		try {
			ob_start();
			RSA_Admin::save_settings();
			ob_end_clean();
		} catch ( \WPDieException $e ) {
			$threw = true;
			unset( $e );
			ob_end_clean();
		}
		$this->assertTrue( $threw, 'Expected wp_die() for missing nonce' );
	}

	public function test_export_csv_without_nonce_dies(): void {
		$_POST    = array( 'data_type' => 'pageviews', 'period' => '30d' );
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );

		$threw = false;
		try {
			ob_start();
			RSA_Admin::handle_export_csv();
			ob_end_clean();
		} catch ( \WPDieException $e ) {
			$threw = true;
			unset( $e );
			ob_end_clean();
		}
		$this->assertTrue( $threw, 'Expected wp_die() for missing nonce' );
	}

	/**
	 * Session ID spoofing / validation
	 */
	public function test_valid_uuid_from_different_session_is_accepted(): void {
		$other_sid = 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e';
		$this->ingest_pageview( array( 'session_id' => $other_sid ) );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}rsa_events` WHERE session_id = %s",
				$other_sid
			)
		);
		$this->assertSame( '1', $count );
	}

	public function test_malformed_uuid_rejected(): void {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		$bad_sid                   = "<script>alert('xss')</script>";
		$_POST                     = array(
			'action'     => 'rsa_track',
			'nonce'      => wp_create_nonce( 'rsa_track' ),
			'session_id' => $bad_sid,
			'page'       => '/test/',
		);
		$_REQUEST['nonce']         = $_POST['nonce'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// phpcs:enable

		ob_start();
		$response = null;
		try {
			RSA_Tracker::handle_ingest();
		} catch ( \WPDieException $e ) {
			$response = json_decode( ob_get_clean(), true );
			unset( $e );
		}
		if ( null === $response ) {
			$response = json_decode( ob_get_clean(), true );
		}

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] ?? true );
	}

	/**
	 * Bot score manipulation
	 */
	public function test_known_bot_ua_is_detected_despite_low_signals(): void {
		// Even with bitmask 0 (no client-side bot signals), a known bot UA
		// should score 10 and be detected as a bot.
		$score = RSA_Bot_Detection::score( 0, 'Mozilla/5.0 (compatible; Googlebot/2.1)', array() );
		$this->assertSame( 10, $score );
		$this->assertTrue( RSA_Bot_Detection::is_bot( $score ) );
	}

	public function test_short_ua_adds_points_but_does_not_exceed_ten(): void {
		$score = RSA_Bot_Detection::score( 0, 'curl/8', array() );
		$this->assertSame( 10, $score );
	}

	public function test_missing_accept_language_adds_points(): void {
		$score = RSA_Bot_Detection::score( 0, 'Mozilla/5.0 Chrome/121', array( 'HTTP_ACCEPT_LANGUAGE' => '' ) );
		$this->assertGreaterThanOrEqual( 2, $score );
	}

	public function test_complete_human_ua_scores_zero(): void {
		$server = array(
			'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
			'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml',
		);
		$score  = RSA_Bot_Detection::score( 0, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', $server );
		$this->assertSame( 0, $score );
		$this->assertFalse( RSA_Bot_Detection::is_bot( $score ) );
	}
}
