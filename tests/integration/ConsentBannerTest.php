<?php
/**
 * Consent Banner Tests
 *
 * End-to-end coverage for RSA_Consent_Banner including option persistence,
 * CSS injection, privacy trigger, JSON style parsing, privacy disclosure
 * shortcode integration, settings save sanitization, and uninstall cleanup.
 *
 * @package RichStatistics\Tests
 */
class ConsentBannerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();
		$this->clear_consent_options();
	}

	public function tearDown(): void {
		$this->clear_consent_options();
		parent::tearDown();
	}

	private function clear_consent_options(): void {
		delete_option( 'rsa_consent_banner' );
		delete_option( 'rsa_consent_auto' );
		delete_option( 'rsa_consent_styles' );
		delete_option( 'rsa_consent_banner_text' );
	}

	/**
	 * Intercept wp_redirect so RSA_Admin::save_settings() does not reach exit.
	 */
	private function invoke_save_settings(): void {
		$filter = function () {
			throw new \WPDieException( 'Redirect intercepted' );
		};
		add_filter( 'wp_redirect', $filter, 999 );
		ob_start();
		try {
			RSA_Admin::save_settings();
		} catch ( \WPDieException $e ) {
			// Expected redirect interception.
			unset( $e );
		}
		ob_end_clean();
		remove_filter( 'wp_redirect', $filter, 999 );
	}

	/**
	 * ----------------------------------------------------------------
	 * init() hook registration
	 * ----------------------------------------------------------------
	 */
	public function test_init_registers_hooks_when_banner_enabled(): void {
		update_option( 'rsa_consent_banner', 1 );
		RSA_Consent_Banner::init();
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', [ 'RSA_Consent_Banner', 'enqueue' ] ) );
		$this->assertNotFalse( has_action( 'wp_footer', [ 'RSA_Consent_Banner', 'render' ] ) );
	}

	public function test_init_exits_early_when_banner_disabled(): void {
		update_option( 'rsa_consent_banner', 0 );
		RSA_Consent_Banner::init();
		$this->assertFalse( has_action( 'wp_enqueue_scripts', [ 'RSA_Consent_Banner', 'enqueue' ] ) );
		$this->assertFalse( has_action( 'wp_footer', [ 'RSA_Consent_Banner', 'render' ] ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * render() output
	 * ----------------------------------------------------------------
	 */
	public function test_render_outputs_banner_html(): void {
		update_option( 'rsa_consent_banner', 1 );
		ob_start();
		RSA_Consent_Banner::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="rsa-consent-banner"', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'rsa-banner-text', $html );
		$this->assertStringContainsString( 'rsa-accept-btn', $html );
		$this->assertStringContainsString( 'rsa-reject-btn', $html );
		$this->assertStringContainsString( 'rsa-customize-btn', $html );
		$this->assertStringContainsString( 'rsa-collapse-btn', $html );
	}

	public function test_render_outputs_default_text_when_empty(): void {
		update_option( 'rsa_consent_banner_text', '' );
		ob_start();
		RSA_Consent_Banner::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'analytics to understand how visitors use the site', $html );
	}

	public function test_render_outputs_custom_text(): void {
		update_option( 'rsa_consent_banner_text', 'Custom privacy notice text.' );
		ob_start();
		RSA_Consent_Banner::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Custom privacy notice text.', $html );
	}

	public function test_render_escapes_html_in_text(): void {
		update_option( 'rsa_consent_banner_text', '<script>alert(1)</script>' );
		ob_start();
		RSA_Consent_Banner::render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;', $html );
	}

	public function test_render_outputs_return_button(): void {
		ob_start();
		RSA_Consent_Banner::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="rsa-consent-return-btn"', $html );
	}

	public function test_render_outputs_trigger_button(): void {
		ob_start();
		RSA_Consent_Banner::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="rsa-consent-trigger-btn"', $html );
		$this->assertStringContainsString( 'Privacy Settings', $html );
	}

	/**
	 * ----------------------------------------------------------------
	 * build_css() / inline styles
	 * ----------------------------------------------------------------
	 */
	public function test_build_css_generates_css_with_defaults(): void {
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'build_css' );
		$method->setAccessible( true );
		$css = $method->invoke( null, [] );

		$this->assertStringContainsString( 'border-radius: 8px', $css );
		$this->assertStringContainsString( 'rgba(0,0,0,0.15)', $css );
		$this->assertStringContainsString( 'z-index: 999999', $css );
	}

	public function test_build_css_applies_custom_styles(): void {
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'build_css' );
		$method->setAccessible( true );
		$css = $method->invoke(
			null,
			[
				'borderRadius'    => 16,
				'fontColor'       => '#ff0000',
				'backgroundColor' => '#00ff00',
				'borderColor'     => '#0000ff',
				'borderWidth'     => 2,
				'shadowX'         => 1,
				'shadowY'         => 2,
				'shadowBlur'      => 3,
				'shadowSpread'    => 4,
				'shadowColor'     => '#ffffff',
				'shadowAlpha'     => 0.5,
			]
		);

		$this->assertStringContainsString( 'border-radius: 16px', $css );
		$this->assertStringContainsString( 'color: #ff0000', $css );
		$this->assertStringContainsString( 'background: #00ff00', $css );
		$this->assertStringContainsString( '2px solid #0000ff', $css );
		$this->assertStringContainsString( '1px 2px 3px 4px rgba(255,255,255,0.5)', $css );
	}

	public function test_build_css_handles_invalid_json_gracefully(): void {
		update_option( 'rsa_consent_styles', 'not-valid-json' );
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'build_css' );
		$method->setAccessible( true );
		$styles = json_decode( get_option( 'rsa_consent_styles', '{}' ), true );
		$css    = $method->invoke( null, is_array( $styles ) ? $styles : [] );

		// Should not fatal; falls back to defaults.
		$this->assertStringContainsString( 'border-radius: 8px', $css );
	}

	public function test_build_css_escapes_malicious_colors(): void {
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'build_css' );
		$method->setAccessible( true );
		$css = $method->invoke(
			null,
			[
				'fontColor'       => "#ff0000'; background: url(//evil.com);",
				'backgroundColor' => '#00ff00',
			]
		);

		// esc_attr encodes the quote but does not strip the payload entirely.
		// The test documents that the payload is present but HTML-encoded.
		$this->assertStringContainsString( '&#039;', $css );
		$this->assertStringNotContainsString( "'", $css );
	}

	/**
	 * ----------------------------------------------------------------
	 * hex_to_rgba()
	 * ----------------------------------------------------------------
	 */
	public function test_hex_to_rgba_6_digit(): void {
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'hex_to_rgba' );
		$method->setAccessible( true );
		$result = $method->invoke( null, '#ff0000', 0.5 );
		$this->assertSame( 'rgba(255,0,0,0.5)', $result );
	}

	public function test_hex_to_rgba_3_digit(): void {
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'hex_to_rgba' );
		$method->setAccessible( true );
		$result = $method->invoke( null, '#f00', 1 );
		$this->assertSame( 'rgba(255,0,0,1)', $result );
	}

	public function test_hex_to_rgba_invalid_returns_black(): void {
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'hex_to_rgba' );
		$method->setAccessible( true );
		$result = $method->invoke( null, 'ggg', 0.5 );
		$this->assertSame( 'rgba(0,0,0,0.5)', $result );
	}

	public function test_hex_to_rgba_with_hash_prefix(): void {
		$method = new ReflectionMethod( RSA_Consent_Banner::class, 'hex_to_rgba' );
		$method->setAccessible( true );
		$result = $method->invoke( null, '#00ff00', 0.25 );
		$this->assertSame( 'rgba(0,255,0,0.25)', $result );
	}

	/**
	 * ----------------------------------------------------------------
	 * Settings save via RSA_Admin::save_settings()
	 * ----------------------------------------------------------------
	 */
	public function test_nonce_verification_works(): void {
		$this->set_as_admin();
		$nonce = wp_create_nonce( 'rsa_settings_save' );
		$this->assertSame( 1, wp_verify_nonce( $nonce, 'rsa_settings_save' ), 'Nonce should verify' );
	}

	public function test_save_settings_persists_consent_banner(): void {
		$this->set_as_admin();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		$_REQUEST['_wpnonce']                = wp_create_nonce( 'rsa_settings_save' );
		$_POST['_wpnonce']                   = $_REQUEST['_wpnonce'];
		$_POST['rsa_consent_banner']         = '1';
		$_POST['rsa_consent_auto']           = '0';
		$_POST['rsa_consent_styles']         = '{"borderRadius":12}';
		$_POST['rsa_consent_banner_text']    = 'Test banner text.';
		$_POST['rsa_email_digest_enabled']   = '0';
		$_POST['rsa_email_digest_frequency'] = 'weekly';
		$_POST['rsa_allowed_roles']          = [];
		$_POST['rsa_enabled_post_types']     = [];
		// phpcs:enable

		$this->invoke_save_settings();

		$this->assertSame( 1, (int) get_option( 'rsa_consent_banner' ) );
		$this->assertSame( 0, (int) get_option( 'rsa_consent_auto' ) );
		$this->assertSame( '{"borderRadius":12}', get_option( 'rsa_consent_styles' ) );
		$this->assertSame( 'Test banner text.', get_option( 'rsa_consent_banner_text' ) );
	}

	public function test_save_settings_sanitizes_consent_styles(): void {
		$this->set_as_admin();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		$_REQUEST['_wpnonce']                = wp_create_nonce( 'rsa_settings_save' );
		$_POST['_wpnonce']                   = $_REQUEST['_wpnonce'];
		$_POST['rsa_consent_styles']         = '<script>alert(1)</script>';
		$_POST['rsa_email_digest_enabled']   = '0';
		$_POST['rsa_email_digest_frequency'] = 'weekly';
		$_POST['rsa_allowed_roles']          = [];
		$_POST['rsa_enabled_post_types']     = [];
		// phpcs:enable

		$this->invoke_save_settings();

		$saved = get_option( 'rsa_consent_styles' );
		$this->assertStringNotContainsString( '<script>', $saved );
	}

	public function test_save_settings_clamps_numeric_values(): void {
		$this->set_as_admin();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		$_REQUEST['_wpnonce']                = wp_create_nonce( 'rsa_settings_save' );
		$_POST['_wpnonce']                   = $_REQUEST['_wpnonce'];
		$_POST['rsa_retention_days']         = '9999';
		$_POST['rsa_bot_score_threshold']    = '99';
		$_POST['rsa_email_digest_enabled']   = '0';
		$_POST['rsa_email_digest_frequency'] = 'weekly';
		$_POST['rsa_allowed_roles']          = [];
		$_POST['rsa_enabled_post_types']     = [];
		// phpcs:enable

		$this->invoke_save_settings();

		$this->assertSame( 730, (int) get_option( 'rsa_retention_days' ) );
		$this->assertSame( 10, (int) get_option( 'rsa_bot_score_threshold' ) );
	}

	public function test_save_settings_unchecked_checkbox_sets_zero(): void {
		$this->set_as_admin();
		update_option( 'rsa_consent_banner', 1 );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
		$_REQUEST['_wpnonce']                = wp_create_nonce( 'rsa_settings_save' );
		$_POST['_wpnonce']                   = $_REQUEST['_wpnonce'];
		$_POST['rsa_email_digest_enabled']   = '0';
		$_POST['rsa_email_digest_frequency'] = 'weekly';
		$_POST['rsa_allowed_roles']          = [];
		$_POST['rsa_enabled_post_types']     = [];
		// phpcs:enable
		// rsa_consent_banner is NOT in $_POST → should be set to 0.

		$this->invoke_save_settings();

		$this->assertSame( 0, (int) get_option( 'rsa_consent_banner' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * Privacy disclosure shortcode reflects consent mode
	 * ----------------------------------------------------------------
	 */
	public function test_privacy_disclosure_reflects_banner_mode(): void {
		update_option( 'rsa_consent_banner', 1 );
		update_option( 'rsa_consent_auto', 0 );
		$output = do_shortcode( '[rich_statistics_privacy_disclosure]' );

		$this->assertStringContainsString( 'asks for your consent', $output );
		$this->assertStringNotContainsString( 'implied consent', $output );
	}

	public function test_privacy_disclosure_reflects_auto_mode(): void {
		update_option( 'rsa_consent_banner', 0 );
		update_option( 'rsa_consent_auto', 1 );
		$output = do_shortcode( '[rich_statistics_privacy_disclosure]' );

		$this->assertStringContainsString( 'implied consent', $output );
		$this->assertStringNotContainsString( 'asks for your consent', $output );
	}

	public function test_privacy_disclosure_reflects_default_mode(): void {
		update_option( 'rsa_consent_banner', 0 );
		update_option( 'rsa_consent_auto', 0 );
		$output = do_shortcode( '[rich_statistics_privacy_disclosure]' );

		$this->assertStringContainsString( 'legitimate interest', $output );
	}

	/**
	 * ----------------------------------------------------------------
	 * Uninstall cleanup
	 * ----------------------------------------------------------------
	 */
	public function test_uninstall_deletes_consent_options(): void {
		update_option( 'rsa_remove_data_on_uninstall', 1 );
		update_option( 'rsa_consent_banner', 1 );
		update_option( 'rsa_consent_auto', 1 );
		update_option( 'rsa_consent_styles', '{}' );
		update_option( 'rsa_consent_banner_text', 'Test' );

		RSA_DB::maybe_remove_data();

		// Clear alloptions cache so get_option reflects the DB state.
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertFalse( get_option( 'rsa_consent_banner' ) );
		$this->assertFalse( get_option( 'rsa_consent_auto' ) );
		$this->assertFalse( get_option( 'rsa_consent_styles' ) );
		$this->assertFalse( get_option( 'rsa_consent_banner_text' ) );
	}

	public function test_uninstall_keeps_options_when_flag_off(): void {
		update_option( 'rsa_remove_data_on_uninstall', 0 );
		update_option( 'rsa_consent_banner', 1 );

		RSA_DB::maybe_remove_data();

		$this->assertSame( 1, (int) get_option( 'rsa_consent_banner' ) );
	}

	public function test_drop_site_tables_deletes_consent_options(): void {
		update_option( 'rsa_consent_banner', 1 );
		update_option( 'rsa_consent_auto', 1 );
		update_option( 'rsa_consent_styles', '{"test":1}' );
		update_option( 'rsa_consent_banner_text', 'Test' );

		$method = new ReflectionMethod( RSA_DB::class, 'drop_site_tables' );
		$method->setAccessible( true );
		$method->invoke( null );

		// Clear alloptions cache so get_option reflects the DB state.
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertFalse( get_option( 'rsa_consent_banner' ) );
		$this->assertFalse( get_option( 'rsa_consent_auto' ) );
		$this->assertFalse( get_option( 'rsa_consent_styles' ) );
		$this->assertFalse( get_option( 'rsa_consent_banner_text' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------
	 */
	private function set_as_admin(): void {
		$admin = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
		$admin->add_cap( 'rsa_manage_statistics' );
		wp_set_current_user( $admin->ID );
	}
}
