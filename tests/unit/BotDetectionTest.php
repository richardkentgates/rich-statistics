<?php
/**
 * Unit tests for RSA_Bot_Detection.
 *
 * These tests do NOT require a WordPress installation because
 * RSA_Bot_Detection is pure PHP with no WordPress dependencies.
 * Class is loaded in setUp() after Brain\Monkey's Patchwork is initialized.
 *
 * @package RichStatistics\Tests
 */

use PHPUnit\Framework\TestCase;

class BotDetectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Load class after Brain\Monkey/Patchwork are initialized
		require_once RSA_DIR . 'includes/class-bot-detection.php';
	}

	/**
	 * ----------------------------------------------------------------
	 * Client-side signal scoring
	 * ----------------------------------------------------------------
	 */
	public function test_zero_signals_scores_zero(): void {
		$score = RSA_Bot_Detection::score_client_bitmask( 0 );
		$this->assertSame( 0, $score );
	}

	public function test_webdriver_flag_adds_four(): void {
		// CS_WEBDRIVER = 1 → weight 4
		$score = RSA_Bot_Detection::score_client_bitmask( RSA_Bot_Detection::CS_WEBDRIVER );
		$this->assertSame( 4, $score );
	}

	public function test_no_plugins_flag_adds_one(): void {
		$score = RSA_Bot_Detection::score_client_bitmask( RSA_Bot_Detection::CS_NO_PLUGINS );
		$this->assertSame( 1, $score );
	}

	public function test_multiple_flags_accumulate(): void {
		// CS_WEBDRIVER(4) + CS_NO_LANGUAGES(2) + CS_ZERO_SCREEN(3) = 9
		$flags = RSA_Bot_Detection::CS_WEBDRIVER
				| RSA_Bot_Detection::CS_NO_LANGUAGES
				| RSA_Bot_Detection::CS_ZERO_SCREEN;
		$score = RSA_Bot_Detection::score_client_bitmask( $flags );
		$this->assertSame( 9, $score );
	}

	public function test_all_ten_signals_maxes_out_weight(): void {
		$all = RSA_Bot_Detection::CS_WEBDRIVER
				| RSA_Bot_Detection::CS_NO_PLUGINS
				| RSA_Bot_Detection::CS_NO_LANGUAGES
				| RSA_Bot_Detection::CS_ZERO_SCREEN
				| RSA_Bot_Detection::CS_NO_TOUCH_API
				| RSA_Bot_Detection::CS_INSTANT_LOAD
				| RSA_Bot_Detection::CS_NO_CANVAS
				| RSA_Bot_Detection::CS_HIDDEN_ON_ARRIVAL
				| RSA_Bot_Detection::CS_NO_HUMAN_EVENT
				| RSA_Bot_Detection::CS_CHROME_MISSING_OBJ;
		// 4+1+2+3+1+2+2+2+1+3 = 21
		$score = RSA_Bot_Detection::score_client_bitmask( $all );
		$this->assertSame( 21, $score );
	}

	/**
	 * ----------------------------------------------------------------
	 * Honest-bot User-Agent patterns
	 * ----------------------------------------------------------------
	 */

	/**
	 * @param string $ua User-Agent string
	 *
	 * @dataProvider provideHonestBotUAs
	 */
	public function test_honest_bot_ua_scores_ten( string $ua ): void {
		$score = RSA_Bot_Detection::score( 0, $ua, array() );
		$this->assertGreaterThanOrEqual( 10, $score, "Expected UA to score >= 10: $ua" );
	}

	public static function provideHonestBotUAs(): array {
		return array(
			'Googlebot'   => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'Bingbot'     => array( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ),
			'DuckDuckBot' => array( 'DuckDuckBot/1.0; (+http://duckduckgo.com/duckduckbot.html)' ),
			'Slurp'       => array( 'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)' ),
			'SemrushBot'  => array( 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)' ),
		);
	}

	/**
	 * ----------------------------------------------------------------
	 * Suspicious / headless UA patterns
	 * ----------------------------------------------------------------
	 */

	/**
	 * @param string $ua User-Agent string
	 *
	 * @dataProvider provideSuspiciousUAs
	 */
	public function test_suspicious_ua_adds_points( string $ua ): void {
		$score = RSA_Bot_Detection::score( 0, $ua, array() );
		$this->assertGreaterThan( 0, $score, "Expected suspicious UA to score > 0: $ua" );
	}

	public static function provideSuspiciousUAs(): array {
		return array(
			'HeadlessChrome' => array( 'Mozilla/5.0 HeadlessChrome/121' ),
			'Selenium'       => array( 'Mozilla/5.0 (Windows; selenium)' ),
			'PhantomJS'      => array( 'Mozilla/5.0 (compatible; PhantomJS/2.1)' ),
			'Puppeteer'      => array( 'Mozilla/5.0 puppeteer/10.0' ),
		);
	}

	/**
	 * ----------------------------------------------------------------
	 * is_bot() threshold checks
	 * ----------------------------------------------------------------
	 */
	public function test_score_below_threshold_is_not_bot(): void {
		// Default threshold is option value; bypass by testing the method directly
		$this->assertFalse( RSA_Bot_Detection::is_bot( 0 ) );
		$this->assertFalse( RSA_Bot_Detection::is_bot( 2 ) );
	}

	public function test_score_at_or_above_threshold_is_bot(): void {
		// Default option is 3; ensure the filter/scoring rules give expected result
		// We test with a known threshold by mocking get_option
		$this->assertTrue( RSA_Bot_Detection::is_bot( 10 ) );
		$this->assertTrue( RSA_Bot_Detection::is_bot( 23 ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * UA parsing — OS
	 * ----------------------------------------------------------------
	 */

	/**
	 * @param string $ua           User-Agent string
	 * @param string $expected_os  Expected OS name
	 *
	 * @dataProvider provideOsParsing
	 */
	public function test_parse_ua_detects_os( string $ua, string $expected_os ): void {
		$parsed = RSA_Bot_Detection::parse_ua( $ua );
		$this->assertSame( $expected_os, $parsed['os'], "UA: $ua" );
	}

	public static function provideOsParsing(): array {
		return array(
			'Windows 10'   => array(
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/121',
				'Windows 10/11',
			),
			'macOS'        => array(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
				'macOS',
			),
			'iPhone iOS'   => array(
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
				'iOS',
			),
			'Android'      => array(
				'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36',
				'Android',
			),
			'Ubuntu Linux' => array(
				'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/121.0',
				'Linux',
			),
		);
	}

	/**
	 * ----------------------------------------------------------------
	 * UA parsing — Browser
	 * ----------------------------------------------------------------
	 */

	/**
	 * @param string $ua               User-Agent string
	 * @param string $expected_browser Expected browser name
	 *
	 * @dataProvider provideBrowserParsing
	 */
	public function test_parse_ua_detects_browser( string $ua, string $expected_browser ): void {
		$parsed = RSA_Bot_Detection::parse_ua( $ua );
		$this->assertSame( $expected_browser, $parsed['browser'], "UA: $ua" );
	}

	public static function provideBrowserParsing(): array {
		return array(
			'Chrome'  => array(
				'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
				'Chrome',
			),
			'Firefox' => array(
				'Mozilla/5.0 (Windows NT 10.0; rv:121.0) Gecko/20100101 Firefox/121.0',
				'Firefox',
			),
			'Safari'  => array(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
				'Safari',
			),
			'Edge'    => array(
				'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
				'Edge',
			),
		);
	}
}
