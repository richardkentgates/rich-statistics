<?php
/**
 * Seed sample UTM campaign data into rsa_events / rsa_sessions.
 *
 * Usage (from the WordPress root, or anywhere wp-cli can bootstrap WP):
 *
 *   wp eval-file /path/to/rich-statistics/bin/seed-utm-data.php
 *
 * Run it once; re-running adds another batch on top.
 * To wipe first:  wp db query "DELETE FROM wp_rsa_events WHERE utm_source IS NOT NULL;"
 */

defined( 'ABSPATH' ) || die( 'Run via wp eval-file.' );

global $wpdb;

$events_table  = $wpdb->prefix . 'rsa_events';
$sessions_table = $wpdb->prefix . 'rsa_sessions';

// ── Config ────────────────────────────────────────────────────────────────────

$campaigns = [
	[ 'source' => 'newsletter',   'medium' => 'email',   'campaign' => 'march-digest' ],
	[ 'source' => 'newsletter',   'medium' => 'email',   'campaign' => 'product-launch' ],
	[ 'source' => 'newsletter',   'medium' => 'email',   'campaign' => 'weekly-tips' ],
	[ 'source' => 'google',       'medium' => 'cpc',     'campaign' => 'spring-sale' ],
	[ 'source' => 'google',       'medium' => 'cpc',     'campaign' => 'brand-keywords' ],
	[ 'source' => 'google',       'medium' => 'organic', 'campaign' => 'seo-blog' ],
	[ 'source' => 'facebook',     'medium' => 'social',  'campaign' => 'spring-sale' ],
	[ 'source' => 'facebook',     'medium' => 'social',  'campaign' => 'product-launch' ],
	[ 'source' => 'instagram',    'medium' => 'social',  'campaign' => 'product-launch' ],
	[ 'source' => 'twitter',      'medium' => 'social',  'campaign' => 'blog-post' ],
	[ 'source' => 'linkedin',     'medium' => 'social',  'campaign' => 'b2b-outreach' ],
	[ 'source' => 'partner-site', 'medium' => 'referral','campaign' => 'collab-promo' ],
];

$pages = [
	'/',
	'/blog/',
	'/pricing/',
	'/features/',
	'/about/',
	'/contact/',
	'/blog/getting-started/',
	'/blog/top-10-tips/',
	'/blog/new-release/',
];

$browsers = [ 'Chrome', 'Firefox', 'Safari', 'Edge' ];
$oses      = [ 'Windows', 'macOS', 'Linux', 'iOS', 'Android' ];
$langs     = [ 'en-US', 'en-GB', 'de-DE', 'fr-FR', 'es-ES', 'ja-JP' ];
$tz        = 'America/New_York';
$referrers = [ 'google.com', 'facebook.com', 'instagram.com', 'twitter.com', null, null ];

$total_sessions = 180; // rows to generate
$now            = time();
$window         = 45 * DAY_IN_SECONDS; // spread over last 45 days

// ── Seed ──────────────────────────────────────────────────────────────────────

$inserted_events   = 0;
$inserted_sessions = 0;

for ( $i = 0; $i < $total_sessions; $i++ ) {
	$campaign  = $campaigns[ array_rand( $campaigns ) ];
	$entry     = $pages[ array_rand( $pages ) ];
	$browser   = $browsers[ array_rand( $browsers ) ];
	$os        = $oses[ array_rand( $oses ) ];
	$lang      = $langs[ array_rand( $langs ) ];
	$referrer  = $referrers[ array_rand( $referrers ) ];
	$ts        = $now - wp_rand( 0, $window );
	$created   = gmdate( 'Y-m-d H:i:s', $ts );
	$session_id = wp_generate_uuid4();
	$pages_viewed = wp_rand( 1, 5 );
	$total_time   = wp_rand( 30, 600 );

	// Write session row.
	$session_ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$sessions_table,
		[
			'session_id'   => $session_id,
			'pages_viewed' => $pages_viewed,
			'total_time'   => $total_time,
			'entry_page'   => $entry,
			'exit_page'    => $pages[ array_rand( $pages ) ],
			'os'           => $os,
			'browser'      => $browser,
			'language'     => $lang,
			'timezone'     => $tz,
			'created_at'   => $created,
			'updated_at'   => $created,
		],
		[ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( $session_ok ) {
		$inserted_sessions++;
	}

	// Write one event per page viewed within this session.
	for ( $p = 0; $p < $pages_viewed; $p++ ) {
		$page_ts = $ts + ( $p * wp_rand( 10, 120 ) );
		$event_ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$events_table,
			[
				'session_id'      => $session_id,
				'page'            => ( $p === 0 ) ? $entry : $pages[ array_rand( $pages ) ],
				'referrer_domain' => ( $p === 0 ) ? $referrer : null,
				'os'              => $os,
				'browser'         => $browser,
				'browser_version' => (string) wp_rand( 100, 130 ),
				'language'        => $lang,
				'timezone'        => $tz,
				'viewport_w'      => 1440,
				'viewport_h'      => 900,
				'time_on_page'    => wp_rand( 10, 300 ),
				'bot_score'       => 0,
				'utm_source'      => $campaign['source'],
				'utm_medium'      => $campaign['medium'],
				'utm_campaign'    => $campaign['campaign'],
				'created_at'      => gmdate( 'Y-m-d H:i:s', $page_ts ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( $event_ok ) {
			$inserted_events++;
		}
	}
}

WP_CLI::success( "Seeded {$inserted_sessions} sessions and {$inserted_events} events with UTM data." );
