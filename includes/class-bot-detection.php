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
 */
defined( 'ABSPATH' ) || exit;

class RSA_Bot_Detection {

	// ----------------------------------------------------------------
	// Client-side signal bit-flags (matched in tracker.js)
	// ----------------------------------------------------------------
	const CS_WEBDRIVER          = 1;    // navigator.webdriver === true
	const CS_NO_PLUGINS         = 2;    // navigator.plugins.length === 0
	const CS_NO_LANGUAGES       = 4;    // navigator.languages empty/missing
	const CS_ZERO_SCREEN        = 8;    // screen.width/height === 0
	const CS_NO_TOUCH_API       = 16;   // no touch/pointer support AND mobile UA claim
	const CS_INSTANT_LOAD       = 32;   // navigation timing: page loaded in < 50ms
	const CS_NO_CANVAS          = 64;   // HTMLCanvasElement missing
	const CS_HIDDEN_ON_ARRIVAL  = 128;  // document.hidden was true immediately
	const CS_NO_HUMAN_EVENT     = 256;  // no mouse/touch/keyboard event before send
	const CS_CHROME_MISSING_OBJ = 512;  // claims Chrome UA but window.chrome absent

	// ----------------------------------------------------------------
	// Score weights for each signal
	// ----------------------------------------------------------------
	private static array $client_weights = [
		self::CS_WEBDRIVER          => 4,  // near-certain headless
		self::CS_NO_PLUGINS         => 1,  // weak alone, strong combined
		self::CS_NO_LANGUAGES       => 2,
		self::CS_ZERO_SCREEN        => 3,
		self::CS_NO_TOUCH_API       => 1,
		self::CS_INSTANT_LOAD       => 2,
		self::CS_NO_CANVAS          => 2,
		self::CS_HIDDEN_ON_ARRIVAL  => 2,
		self::CS_NO_HUMAN_EVENT     => 3,  // strong signal — real humans move/scroll
		self::CS_CHROME_MISSING_OBJ => 3,
	];

	// ----------------------------------------------------------------
	// Known honest-bot UA patterns (these always fail, score = 10)
	// Self-announced bots we can trust to identify themselves.
	// ----------------------------------------------------------------
	private static array $known_bot_patterns = [
		'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
		'yandexbot', 'sogou', 'exabot', 'facebot', 'ia_archiver',
		'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'rogerbot',
		'petalbot', 'dataforseobot', 'pinterestbot', 'twitterbot',
		'linkedinbot', 'whatsapp', 'telegrambot', 'applebot',
		'facebookexternalhit', 'discordbot', 'slackbot', 'curl/',
		'python-requests', 'python-urllib', 'go-http-client',
		'java/', 'wget/', 'libwww-perl', 'httpunit', 'nutch',
		'httrack', 'harvest', 'webzip', 'getright', 'teleport',
		'pavuk', 'bigbrother', 'webcopier', 'websuckers', 'sucker',
		'webwhacker', 'netmechanic', 'online link validator',
		'htmlparser', 'extractorpro', 'copier', 'crawler', 'spider',
	];

	// ----------------------------------------------------------------
	// Suspicious UA sub-strings (dishonorable patterns)
	// ----------------------------------------------------------------
	private static array $suspicious_ua_patterns = [
		'headlesschrome', 'phantomjs', 'slimerjs', 'selenium',
		'webdriver', 'htmlunit', 'scrapy', 'mechanize',
		'guzzle', 'okhttp', 'axios/', 'node-fetch',
	];

	// ----------------------------------------------------------------
	// Server-side scoring
	// ----------------------------------------------------------------

	/**
	 * @param int    $client_bitmask  The bitmask sent by tracker.js
	 * @param string $user_agent      Raw User-Agent header
	 * @param array  $server          Allowlisted headers ONLY — must NOT contain REMOTE_ADDR or any IP field.
	 *                                Caller is responsible for passing only HTTP_ACCEPT_LANGUAGE and HTTP_ACCEPT.
	 * @return int   Combined bot score (0 = human, higher = more bot-like)
	 */
	public static function score( int $client_bitmask, string $user_agent, array $server = [] ): int {
		$score = 0;
		$ua    = strtolower( $user_agent );

		// --- Known honest bots: always max score ---
		foreach ( self::$known_bot_patterns as $pattern ) {
			if ( str_contains( $ua, $pattern ) ) {
				return 10;
			}
		}

		// --- Suspicious UA patterns ---
		foreach ( self::$suspicious_ua_patterns as $pattern ) {
			if ( str_contains( $ua, $pattern ) ) {
				$score += 4;
			}
		}

		// --- Empty or very short UA ---
		if ( strlen( $ua ) < 10 ) {
			$score += 3;
		}

		// --- Missing Accept-Language header ---
		$accept_lang = $server['HTTP_ACCEPT_LANGUAGE'] ?? '';
		if ( empty( $accept_lang ) ) {
			$score += 2;
		}

		// --- Missing Accept header ---
		$accept = $server['HTTP_ACCEPT'] ?? '';
		if ( empty( $accept ) ) {
			$score += 1;
		}

		// --- No Referer when navigating to a deep page ---
		// (If page != homepage and no referrer and no direct nav UA signals, suspect)
		// Only a soft signal — skip for now to avoid false positives.

		// --- Client-side signals ---
		$score += self::score_client_bitmask( $client_bitmask );

		return min( $score, 10 );
	}

	/**
	 * Translate a client bitmask into a score value.
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
	 */
	public static function is_bot( int $score ): bool {
		$threshold = (int) get_option( 'rsa_bot_score_threshold', 3 );
		return $score >= $threshold;
	}

	// ----------------------------------------------------------------
	// UA Parsing  (no third-party library, covers common browsers/OSes)
	// ----------------------------------------------------------------

	/**
	 * Returns [ 'os' => '...', 'browser' => '...', 'browser_version' => '...' ]
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
			'/windows phone/i'          => 'Windows Phone',
			'/windows nt 10/i'          => 'Windows 10/11',
			'/windows nt 6\.3/i'        => 'Windows 8.1',
			'/windows nt 6\.2/i'        => 'Windows 8',
			'/windows nt 6\.1/i'        => 'Windows 7',
			'/windows/i'                => 'Windows',
			'/android/i'                => 'Android',
			'/ipad/i'                   => 'iPadOS',
			'/iphone/i'                 => 'iOS',
			'/ipod/i'                   => 'iOS',
			'/macintosh|mac os x/i'     => 'macOS',
			'/cros/i'                   => 'ChromeOS',
			'/linux/i'                  => 'Linux',
			'/ubuntu/i'                 => 'Ubuntu',
			'/freebsd/i'                => 'FreeBSD',
		];
		foreach ( $patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $label;
			}
		}
		return 'Unknown';
	}

	private static function parse_browser( string $ua ): string {
		// Order matters: check specific tokens before generic ones
		$patterns = [
			'/edg\//i'         => 'Edge',
			'/opr\//i'         => 'Opera',
			'/vivaldi/i'       => 'Vivaldi',
			'/brave/i'         => 'Brave',
			'/samsungbrowser/i'=> 'Samsung Browser',
			'/ucbrowser/i'     => 'UC Browser',
			'/yabrowser/i'     => 'Yandex Browser',
			'/firefox/i'       => 'Firefox',
			'/fxios/i'         => 'Firefox',
			'/chromium/i'      => 'Chromium',
			'/chrome/i'        => 'Chrome',
			'/crios/i'         => 'Chrome',
			'/safari/i'        => 'Safari',
			'/msie|trident/i'  => 'Internet Explorer',
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
			'/version\/([0-9]+)/i',   // Safari
			'/msie ([0-9]+)/i',
			'/rv:([0-9]+)/i',         // IE 11 / Firefox fallback
		];
		foreach ( $version_patterns as $pattern ) {
			if ( preg_match( $pattern, $ua, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}
}
