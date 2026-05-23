<?php
/**
 * Aggressive bot detection.
 *
 * Strategy: multi-signal scoring.  A request is flagged as a bot when
 * the combined score meets or exceeds the configured threshold.
 *
 * Two layers work together:
 *   1. Client-side (tracker.js) sends a `bot_signals` bitmask with the event.
 *   2. Server-side checks the HTTP request itself (headers, UA).
 *
 * Unlike naive UA-matching that only catches honest bots, this class
 * also detects headless browsers and scrapers that try to hide.
 *
 * @package RichStatistics
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class RSA_Bot_Detection {

	const CS_WEBDRIVER          = 1;
	const CS_NO_PLUGINS         = 2;
	const CS_NO_LANGUAGES       = 4;
	const CS_ZERO_SCREEN        = 8;
	const CS_NO_TOUCH_API       = 16;
	const CS_INSTANT_LOAD       = 32;
	const CS_NO_CANVAS          = 64;
	const CS_HIDDEN_ON_ARRIVAL  = 128;
	const CS_NO_HUMAN_EVENT     = 256;
	const CS_CHROME_MISSING_OBJ = 512;

	private static array $client_weights = [
		self::CS_WEBDRIVER          => 4,
		self::CS_NO_PLUGINS         => 1,
		self::CS_NO_LANGUAGES       => 2,
		self::CS_ZERO_SCREEN        => 3,
		self::CS_NO_TOUCH_API       => 1,
		self::CS_INSTANT_LOAD       => 2,
		self::CS_NO_CANVAS          => 2,
		self::CS_HIDDEN_ON_ARRIVAL  => 2,
		self::CS_NO_HUMAN_EVENT     => 1,
		self::CS_CHROME_MISSING_OBJ => 3,
	];

	private static array $known_bot_patterns = [
		'googlebot',
		'bingbot',
		'slurp',
		'duckduckbot',
		'baiduspider',
		'yandexbot',
		'sogou',
		'exabot',
		'facebot',
		'ia_archiver',
		'semrushbot',
		'ahrefsbot',
		'mj12bot',
		'dotbot',
		'rogerbot',
		'petalbot',
		'dataforseobot',
		'pinterestbot',
		'twitterbot',
		'linkedinbot',
		'whatsapp',
		'telegrambot',
		'applebot',
		'facebookexternalhit',
		'discordbot',
		'slackbot',
		'curl/',
		'python-requests',
		'python-urllib',
		'go-http-client',
		'java/',
		'wget/',
		'libwww-perl',
		'httpunit',
		'nutch',
		'httrack',
		'harvest',
		'webzip',
		'getright',
		'teleport',
		'pavuk',
		'bigbrother',
		'webcopier',
		'websuckers',
		'sucker',
		'webwhacker',
		'netmechanic',
		'online link validator',
		'htmlparser',
		'extractorpro',
		'copier',
		'crawler',
		'spider',
	];

	private static array $suspicious_ua_patterns = [
		'headlesschrome',
		'phantomjs',
		'slimerjs',
		'selenium',
		'webdriver',
		'htmlunit',
		'scrapy',
		'mechanize',
		'guzzle',
		'okhttp',
		'axios/',
		'node-fetch',
	];

	/**
	 * Score a request on the bot-likelihood scale (0-10).
	 *
	 * @param int    $client_bitmask The bitmask sent by tracker.js.
	 * @param string $user_agent     Raw User-Agent header.
	 * @param array  $server         Allowlisted headers ONLY.
	 * @return int   Combined bot score (0 = human, higher = more bot-like).
	 */
	public static function score( int $client_bitmask, string $user_agent, array $server = [] ): int {
		$score = 0;
		$ua    = strtolower( $user_agent );

		foreach ( self::$known_bot_patterns as $pattern ) {
			if ( str_contains( $ua, $pattern ) ) {
				return 10;
			}
		}

		foreach ( self::$suspicious_ua_patterns as $pattern ) {
			if ( str_contains( $ua, $pattern ) ) {
				$score += 4;
			}
		}

		if ( strlen( $ua ) < 10 ) {
			$score += 3;
		}

		$accept_lang = $server['HTTP_ACCEPT_LANGUAGE'] ?? '';
		if ( empty( $accept_lang ) ) {
			$score += 2;
		}

		$accept = $server['HTTP_ACCEPT'] ?? '';
		if ( empty( $accept ) ) {
			++$score;
		}

		$score += self::score_client_bitmask( $client_bitmask );

		return min( $score, 10 );
	}

	/**
	 * Translate a client bitmask into a score value.
	 *
	 * @param int $bitmask The client-side bitmask.
	 * @return int Score from client signals.
	 */
	public static function score_client_bitmask( int $bitmask ): int {
		$score = 0;
		foreach ( self::$client_weights as $flag => $weight ) {
			if ( $bitmask & $flag ) {
				$score += $weight;
			}
		}
		return $score;
	}

	/**
	 * Convenient pass/fail based on the configured threshold.
	 *
	 * @param int $score The bot score.
	 * @return bool True if the score meets or exceeds the threshold.
	 */
	public static function is_bot( int $score ): bool {
		$threshold = (int) get_option( 'rsa_bot_score_threshold', 3 );
		return $score >= $threshold;
	}

	/**
	 * Parse a User-Agent string into OS, browser, and version.
	 *
	 * @param string $ua The User-Agent string.
	 * @return array OS, browser, and browser_version.
	 */
	public static function parse_ua( string $ua ): array {
		return [
			'os'              => self::parse_os( $ua ),
			'browser'         => self::parse_browser( $ua ),
			'browser_version' => self::parse_browser_version( $ua ),
		];
	}

	private static function parse_os( string $ua ): string {
		$patterns = [
			'/windows phone/i'      => 'Windows Phone',
			'/windows nt 10/i'      => 'Windows 10/11',
			'/windows nt 6\.3/i'    => 'Windows 8.1',
			'/windows nt 6\.2/i'    => 'Windows 8',
			'/windows nt 6\.1/i'    => 'Windows 7',
			'/windows/i'            => 'Windows',
			'/android/i'            => 'Android',
			'/ipad/i'               => 'iPadOS',
			'/iphone/i'             => 'iOS',
			'/ipod/i'               => 'iOS',
			'/macintosh|mac os x/i' => 'macOS',
			'/cros/i'               => 'ChromeOS',
			'/linux/i'              => 'Linux',
			'/ubuntu/i'             => 'Ubuntu',
			'/freebsd/i'            => 'FreeBSD',
		];
		foreach ( $patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $label;
			}
		}
		return 'Unknown';
	}

	private static function parse_browser( string $ua ): string {
		$patterns = [
			'/edg\//i'          => 'Edge',
			'/opr\//i'          => 'Opera',
			'/vivaldi/i'        => 'Vivaldi',
			'/brave/i'          => 'Brave',
			'/samsungbrowser/i' => 'Samsung Browser',
			'/ucbrowser/i'      => 'UC Browser',
			'/yabrowser/i'      => 'Yandex Browser',
			'/firefox/i'        => 'Firefox',
			'/fxios/i'          => 'Firefox',
			'/chromium/i'       => 'Chromium',
			'/chrome/i'         => 'Chrome',
			'/crios/i'          => 'Chrome',
			'/safari/i'         => 'Safari',
			'/msie|trident/i'   => 'Internet Explorer',
		];
		foreach ( $patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $label;
			}
		}
		return 'Unknown';
	}

	private static function parse_browser_version( string $ua ): string {
		$version_patterns = [
			'/edg\/([0-9]+)/i',
			'/opr\/([0-9]+)/i',
			'/firefox\/([0-9]+)/i',
			'/fxios\/([0-9]+)/i',
			'/samsungbrowser\/([0-9]+)/i',
			'/chrome\/([0-9]+)/i',
			'/crios\/([0-9]+)/i',
			'/version\/([0-9]+)/i',
			'/msie ([0-9]+)/i',
			'/rv:([0-9]+)/i',
		];
		foreach ( $version_patterns as $pattern ) {
			if ( preg_match( $pattern, $ua, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}
}
