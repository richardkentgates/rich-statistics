<?php
/**
 * Email Content Tests
 *
 * Covers HTML output structure, recipient resolution, MIME headers,
 * subject line, and WooCommerce section absence after revenue removal.
 *
 * @package RichStatistics\Tests
 */
class EmailContentTest extends WP_UnitTestCase {

	private static string $test_sid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
	private array $captured_mail    = array();

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		$this->clear_tables();
		$this->seed_analytics();
		add_filter( 'wp_mail', array( $this, 'capture_mail' ), 999 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_mail', array( $this, 'capture_mail' ), 999 );
		parent::tearDown();
	}

	public function capture_mail( array $args ): array {
		$this->captured_mail = $args;
		return $args;
	}

	private function clear_tables(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}rsa_sessions`" );
	}

	private function seed_analytics(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'rsa_events',
			array(
				'session_id'      => self::$test_sid,
				'page'            => '/home/',
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
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
		$wpdb->insert(
			$wpdb->prefix . 'rsa_sessions',
			array(
				'session_id'   => self::$test_sid,
				'pages_viewed' => 3,
				'entry_page'   => '/home/',
				'exit_page'    => '/contact/',
				'os'           => 'Windows',
				'browser'      => 'Chrome',
				'language'     => 'en',
				'timezone'     => 'UTC',
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private function call_build_html( array $overview, array $pages, array $referrers, ?array $wc_data, string $period ): string {
		$ref = new ReflectionMethod( 'RSA_Email', 'build_html' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $overview, $pages, $referrers, $wc_data, $period );
	}

	/**
	 * Structure and content
	 */
	public function test_email_html_contains_site_name_and_period(): void {
		$overview  = RSA_Analytics::get_overview( '7d' );
		$pages     = RSA_Analytics::get_top_pages( '7d', 10 );
		$referrers = RSA_Analytics::get_referrers( '7d', 5 );
		$html      = $this->call_build_html( $overview, $pages, $referrers, null, '7d' );

		$this->assertStringContainsString( 'Analytics Digest', $html );
		$this->assertStringContainsString( 'last 7 days', $html );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $html );
	}

	public function test_email_html_contains_kpi_values(): void {
		$overview  = RSA_Analytics::get_overview( '7d' );
		$pages     = RSA_Analytics::get_top_pages( '7d', 10 );
		$referrers = RSA_Analytics::get_referrers( '7d', 5 );
		$html      = $this->call_build_html( $overview, $pages, $referrers, null, '7d' );

		$this->assertStringContainsString( (string) $overview['pageviews'], $html );
		$this->assertStringContainsString( (string) $overview['sessions'], $html );
		$this->assertStringContainsString( (string) $overview['bounce_rate'], $html );
	}

	public function test_email_html_escapes_page_paths(): void {
		$overview  = array( 'pageviews' => 1, 'sessions' => 1, 'avg_time' => 10, 'bounce_rate' => 0, 'daily' => array() );
		$pages     = array( array( 'page' => '/<script>alert(1)</script>/', 'views' => 1, 'avg_time' => 10 ) );
		$referrers = array();
		$html      = $this->call_build_html( $overview, $pages, $referrers, null, '7d' );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	/**
	 * Subject line
	 */
	public function test_send_digest_subject_contains_site_name(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_recipients', 'admin@example.com' );
		update_option( 'rsa_email_digest_use_roles', 0 );

		RSA_Email::send_digest( '7d' );

		$this->assertNotEmpty( $this->captured_mail );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $this->captured_mail['subject'] );
		$this->assertStringContainsString( 'Analytics Digest', $this->captured_mail['subject'] );
	}

	/**
	 * MIME headers
	 */
	public function test_send_digest_uses_html_content_type(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_recipients', 'admin@example.com' );
		update_option( 'rsa_email_digest_use_roles', 0 );

		RSA_Email::send_digest( '7d' );

		$headers = is_array( $this->captured_mail['headers'] )
			? implode( "\n", $this->captured_mail['headers'] )
			: $this->captured_mail['headers'];
		$this->assertStringContainsString( 'text/html', $headers );
	}

	/**
	 * Recipient resolution
	 */
	public function test_send_digest_uses_explicit_recipients_when_roles_disabled(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_recipients', 'a@example.com, b@example.com ' );
		update_option( 'rsa_email_digest_use_roles', 0 );

		RSA_Email::send_digest( '7d' );

		$to = is_array( $this->captured_mail['to'] )
			? implode( ', ', $this->captured_mail['to'] )
			: $this->captured_mail['to'];
		$this->assertStringContainsString( 'a@example.com', $to );
		$this->assertStringContainsString( 'b@example.com', $to );
	}

	public function test_send_digest_uses_role_recipients_when_roles_enabled(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'user_email'   => 'roleuser@example.com',
				'user_login'   => 'roleuser',
				'display_name' => 'Role User',
			)
		);
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_use_roles', 1 );
		update_option( 'rsa_allowed_roles', array( 'administrator' ) );

		RSA_Email::send_digest( '7d' );

		$to = is_array( $this->captured_mail['to'] )
			? implode( ', ', $this->captured_mail['to'] )
			: $this->captured_mail['to'];
		$this->assertStringContainsString( 'roleuser@example.com', $to );
	}

	/**
	 * WooCommerce section (revenue removed)
	 */
	public function test_email_no_wc_section_when_premium_inactive(): void {
		$overview  = RSA_Analytics::get_overview( '7d' );
		$pages     = RSA_Analytics::get_top_pages( '7d', 10 );
		$referrers = RSA_Analytics::get_referrers( '7d', 5 );
		$html      = $this->call_build_html( $overview, $pages, $referrers, null, '7d' );

		$this->assertStringNotContainsString( 'WooCommerce', $html );
		$this->assertStringNotContainsString( 'Revenue', $html );
	}
}
