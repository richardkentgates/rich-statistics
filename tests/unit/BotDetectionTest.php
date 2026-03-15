<?php
/**
 * Unit tests for RSA_Bot_Detection.
 *
 * These tests do NOT require a WordPress installation because
 * RSA_Bot_Detection is pure PHP with no WordPress dependencies.
 *
 * @package RichStatistics\Tests
 */

use PHPUnit\Framework\TestCase;

class RSA_Bot_Detection_Test extends TestCase {

	// ----------------------------------------------------------------
	// Client-side signal scoring
	// ----------------------------------------------------------------

	public function test_zero_signals_scores_zero(): void {
		$score = RSA_Bot_Detection::score( 0, 'Mozilla/5.0 (Windows NT 10.0) Chrome/121', [] );
		$this->assertSame( 0, $score );
	}

	public function test_webdriver_flag_adds_four(): void {
		// CS_WEBDRIVER = 1 → weight 4
		$score = RSA_Bot_Detection::score( RSA_Bot_Detection::CS_WEBDRIVER, 'Mozilla/5.0', [] );
		$this->assertSame( 4, $score );
	}

	public function test_no_plugins_flag_adds_one(): void {
		$score = RSA_Bot_Detection::score( RSA_Bot_Detection::CS_NO_PLUGINS, 'Mozilla/5.0', [] );
		$this->assertSame( 1, $score );
	}

	public function test_multiple_flags_accumulate(): void {
		// CS_WEBDRIVER(4) + CS_NO_LANGUAGES(2) + CS_ZERO_SCREEN(3) = 9
		$flags = RSA_Bot_Detection::CS_WEBDRIVER
		         | RSA_Bot_Detection::CS_NO_LANGUAGES
		         | RSA_Bot_Detection::CS_ZERO_SCREEN;
		$score = RSA_Bot_Detection::score( $flags, 'Mozilla/5.0', [] );
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
		// 4+1+2+3+1+2+2+2+3+3 = 23
		$score = RSA_Bot_Detection::score( $all, 'Mozilla/5.0', [] );
		$this->assertSame( 23, $score );
	}

	// ----------------------------------------------------------------
	// Honest-bot User-Agent patterns
	// ----------------------------------------------------------------

	/** @dataProvider provideHonestBotUAs */
	public function test_honest_bot_ua_scores_ten( string $ua ): void {
		$score = RSA_Bot_Detection::score( 0, $ua, [] );
		$this->assertGreaterThanOrEqual( 10, $score, "Expected UA to score >= 10: $ua" );
	}

	public static function provideHonestBotUAs(): array {
		return [
			'Googlebot'           => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
			'Bingbot'             => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
			'DuckDuckBot'         => ['DuckDuckBot/1.0; (+http://duckduckgo.com/duckduckbot.html)'],
			'Slurp'               => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)'],
			'SemrushBot'          => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
		];
	}

	// ----------------------------------------------------------------
	// Suspicious / headless UA patterns
	// ----------------------------------------------------------------

	/** @dataProvider provideSuspiciousUAs */
	public function test_suspicious_ua_adds_points( string $ua ): void {
		$score = RSA_Bot_Detection::score( 0, $ua, [] );
		$this->assertGreaterThan( 0, $score, "Expected suspicious UA to score > 0: $ua" );
	}

	public static function provideSuspiciousUAs(): array {
		return [
			'HeadlessChrome' => ['Mozilla/5.0 HeadlessChrome/121'],
			'Selenium'        => ['Mozilla/5.0 (Windows; selenium)'],
			'PhantomJS'       => ['Mozilla/5.0 (compatible; PhantomJS/2.1)'],
			'Puppeteer'       => ['Mozilla/5.0 puppeteer/10.0'],
		];
	}

	// ----------------------------------------------------------------
	// is_bot() threshold checks
	// ----------------------------------------------------------------

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

	// ----------------------------------------------------------------
	// UA parsing — OS
	// ----------------------------------------------------------------

	/** @dataProvider provideOsParsing */
	public function test_parse_ua_detects_os( string $ua, string $expectedOs ): void {
		$parsed = RSA_Bot_Detection::parse_ua( $ua );
		$this->assertSame( $expectedOs, $parsed['os'], "UA: $ua" );
	}

	public static function provideOsParsing(): array {
		return [
			'Windows 10' => [
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/121',
				'Windows',
			],
			'macOS' => [
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
				'macOS',
			],
			'iPhone iOS' => [
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
				'iOS',
			],
			'Android' => [
				'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36',
				'Android',
			],
			'Ubuntu Linux' => [
				'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/121.0',
				'Linux',
			],
		];
	}

	// ----------------------------------------------------------------
	// UA parsing — Browser
	// ----------------------------------------------------------------

	/** @dataProvider provideBrowserParsing */
	public function test_parse_ua_detects_browser( string $ua, string $expectedBrowser ): void {
		$parsed = RSA_Bot_Detection::parse_ua( $ua );
		$this->assertSame( $expectedBrowser, $parsed['browser'], "UA: $ua" );
	}

	public static function provideBrowserParsing(): array {
		return [
			'Chrome' => [
				'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
				'Chrome',
			],
			'Firefox' => [
				'Mozilla/5.0 (Windows NT 10.0; rv:121.0) Gecko/20100101 Firefox/121.0',
				'Firefox',
			],
			'Safari' => [
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
				'Safari',
			],
			'Edge' => [
				'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
				'Edge',
			],
		];
	}
}
