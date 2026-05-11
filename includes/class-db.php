<?php
/**
 * Database schema, activation, migration, and uninstall.
 *
 * Table strategy for multisite: each subsite gets its own tables
 * using that site's wpdb->prefix (e.g. wp_2_rsa_events).
 * All methods that write/read data are prefix-aware and must be
 * called while the correct blog is switched in.
 *
 * ── Privacy-first design ──────────────────────────────────────────
 *
 *   NO IP addresses stored anywhere.
 *   NO cookies used for tracking — sessionStorage (JS) + POST params (PHP).
 *   NO PII: session IDs are UUIDv4 (anonymous), referrers are domain-only,
 *           page paths are sanitized (email-like query params stripped).
 *
 * ── Schema relationships ─────────────────────────────────────────────
 *
 *   rsa_events         — raw pageview events. Each row = one page view.
 *                        Links to rsa_sessions via session_id.
 *                        bot_score filters out automated traffic.
 *
 *   rsa_sessions       — session aggregates (updated per event, not real-time).
 *                        Built from rsa_events pageviews per session.
 *
 *   rsa_clicks         — click events (premium). Links to rsa_events/sessions
 *                        via session_id. Recorded by tracker.js sendBeacon
 *                        POST to admin-ajax.php on every page view.
 *
 *   rsa_heatmap        — pre-aggregated click coordinates for canvas rendering.
 *                        Date-bucketed, no session ID stored (aggregated only).
 *
 *   rsa_wc_events      — WooCommerce commerce events. Links to rsa_events
 *                        via session_id. Source: $_POST['rsa_sid'] sent by
 *                        tracker.js on every page load (via sendBeacon).
 *
 * ── Session ID flow ────────────────────────────────────────────────
 *
 *   PHP (RSA_Tracker::enqueue) → outputs window.rsaSessionId as inline script
 *       → JS (tracker.js) picks up window.rsaSessionId, stores in sessionStorage
 *       → JS sends sendBeacon POST with session_id on pagehide/visibilitychange
 *       → PHP (RSA_Tracker::handle_ingest) writes rsa_events + rsa_sessions
 *       → JS sends click events via sendBeacon with same session_id
 *       → PHP (RSA_Woocommerce::session_id) reads $_POST['rsa_sid'] (same POST param)
 *       → PHP (RSA_Woocommerce) writes rsa_wc_events with matching session_id
 *
 *   No cookie involved at any point — purely client-side sessionStorage + POST.
 *
 * ── Bot detection ───────────────────────────────────────────────────
 *
 *   Client-side (tracker.js): bot_signals bitmask (WEBDRIVER, NO_PLUGINS, etc.)
 *   Server-side (RSA_Bot_Detection): UA pattern matching + header analysis
 *   Threshold: configurable, default excludes score >= 10
 *   NO IP address ever passed to bot scorer.
 *
 * @package RichStatistics
 */

defined( 'ABSPATH' ) || exit;

class RSA_DB {

	const SCHEMA_VERSION = 1;
	const OPTION_KEY     = 'rsa_db_version';

	/**
	 * Get a prefixed table name.
	 *
	 * @param string $name Table suffix.
	 * @return string Full table name with prefix.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'rsa_' . $name;
	}

	public static function events_table(): string {
		return self::table( 'events' ); }
	public static function sessions_table(): string {
		return self::table( 'sessions' ); }
	public static function clicks_table(): string {
		return self::table( 'clicks' ); }
	public static function heatmap_table(): string {
		return self::table( 'heatmap' ); }
	public static function wc_events_table(): string {
		return self::table( 'wc_events' ); }

	/**
	 * Activate the plugin on a single site or network-wide.
	 *
	 * @param bool $network_wide Whether the plugin was network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);
			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				self::install();
				restore_current_blog();
			}
		} else {
			self::install();
		}
	}

	/**
	 * Called when a new subsite is created on a network where the plugin is
	 * network-activated.
	 *
	 * @param int $blog_id The new blog ID.
	 */
	public static function on_new_blog( int $blog_id ): void {
		switch_to_blog( $blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Install or upgrade the database schema.
	 */
	public static function install(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$events = 'CREATE TABLE ' . self::events_table() . " (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id    VARCHAR(36)         NOT NULL,
			page          VARCHAR(512)        NOT NULL,
			referrer_domain VARCHAR(255)      DEFAULT NULL,
			os            VARCHAR(64)         DEFAULT NULL,
			browser       VARCHAR(64)         DEFAULT NULL,
			browser_version VARCHAR(16)       DEFAULT NULL,
			language      VARCHAR(10)         DEFAULT NULL,
			timezone      VARCHAR(64)         DEFAULT NULL,
			viewport_w    SMALLINT UNSIGNED   DEFAULT NULL,
			viewport_h    SMALLINT UNSIGNED   DEFAULT NULL,
			time_on_page  SMALLINT UNSIGNED   DEFAULT NULL,
			bot_score     TINYINT UNSIGNED    DEFAULT 0,
			utm_source    VARCHAR(100)        DEFAULT NULL,
			utm_medium    VARCHAR(100)        DEFAULT NULL,
			utm_campaign  VARCHAR(255)        DEFAULT NULL,
			created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY session_id  (session_id),
			KEY page        (page(191)),
			KEY created_at  (created_at),
			KEY utm_campaign (utm_campaign(191))
		) $charset;";

		$sessions = 'CREATE TABLE ' . self::sessions_table() . " (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id    VARCHAR(36)         NOT NULL,
			pages_viewed  SMALLINT UNSIGNED   DEFAULT 1,
			total_time    SMALLINT UNSIGNED   DEFAULT NULL,
			entry_page    VARCHAR(512)        NOT NULL,
			exit_page     VARCHAR(512)        DEFAULT NULL,
			os            VARCHAR(64)         DEFAULT NULL,
			browser       VARCHAR(64)         DEFAULT NULL,
			language      VARCHAR(10)         DEFAULT NULL,
			timezone      VARCHAR(64)         DEFAULT NULL,
			created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY created_at (created_at)
		) $charset;";

		$clicks = 'CREATE TABLE ' . self::clicks_table() . " (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id      VARCHAR(36)         NOT NULL,
			page            VARCHAR(512)        NOT NULL,
			element_tag     VARCHAR(32)         DEFAULT NULL,
			element_id      VARCHAR(255)        DEFAULT NULL,
			element_class   VARCHAR(512)        DEFAULT NULL,
			element_text    VARCHAR(255)        DEFAULT NULL,
			href_protocol   VARCHAR(32)         DEFAULT NULL,
			href_value      VARCHAR(512)        DEFAULT NULL,
			matched_rule    VARCHAR(255)        DEFAULT NULL,
			x_pct           DECIMAL(5,2)        DEFAULT NULL,
			y_pct           DECIMAL(5,2)        DEFAULT NULL,
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY page        (page(191)),
			KEY session_id  (session_id),
			KEY created_at  (created_at)
		) $charset;";

		$heatmap = 'CREATE TABLE ' . self::heatmap_table() . " (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			page        VARCHAR(512)        NOT NULL,
			x_pct       DECIMAL(5,2)        NOT NULL,
			y_pct       DECIMAL(5,2)        NOT NULL,
			weight      INT UNSIGNED        DEFAULT 1,
			date_bucket DATE                NOT NULL,
			PRIMARY KEY (id),
			KEY page_date (page(191), date_bucket)
		) $charset;";

		$wc_events = 'CREATE TABLE ' . self::wc_events_table() . " (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id      VARCHAR(36)         NOT NULL,
			event_type      VARCHAR(32)         NOT NULL,
			product_id      BIGINT(20) UNSIGNED DEFAULT NULL,
			product_name    VARCHAR(255)        DEFAULT NULL,
			product_sku     VARCHAR(100)        DEFAULT NULL,
			quantity        SMALLINT UNSIGNED   DEFAULT NULL,
			order_total     DECIMAL(12,2)       DEFAULT NULL,
			order_currency  VARCHAR(8)          DEFAULT NULL,
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY session_id  (session_id),
			KEY event_type  (event_type),
			KEY created_at  (created_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $events );
		dbDelta( $sessions );
		dbDelta( $clicks );
		dbDelta( $heatmap );
		dbDelta( $wc_events );

		update_option( self::OPTION_KEY, self::SCHEMA_VERSION, false );

		self::seed_defaults();

		if ( ! wp_next_scheduled( 'rsa_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'rsa_daily_maintenance' );
		}
	}

	/**
	 * Seed default plugin options.
	 */
	private static function seed_defaults(): void {
		$defaults = [
			'rsa_retention_days'           => 90,
			'rsa_bot_score_threshold'      => 5,
			'rsa_remove_data_on_uninstall' => 0,
			'rsa_track_protocol_tel'       => 1,
			'rsa_track_protocol_mailto'    => 1,
			'rsa_track_protocol_geo'       => 1,
			'rsa_track_protocol_sms'       => 1,
			'rsa_track_protocol_download'  => 1,
			'rsa_click_track_ids'          => '',
			'rsa_click_track_classes'      => '',
			'rsa_email_digest_enabled'     => 0,
			'rsa_email_digest_frequency'   => 'weekly',
			'rsa_email_digest_recipients'  => get_option( 'admin_email' ),
			'rsa_email_digest_next'        => '',
			'rsa_woocommerce_enabled'      => 1,
		];

		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key ) === false ) {
				add_option( $key, $value, '', false );
			}
		}
	}

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rsa_daily_maintenance' );
	}

	/**
	 * Purge all analytics data for one specific page path.
	 *
	 * @param string $page Exact page path as stored (e.g. '/about/').
	 * @return int Total rows deleted.
	 */
	public static function purge_page_data( string $page ): int {
		global $wpdb;
		$deleted  = 0;
		$deleted += (int) $wpdb->delete(
			$wpdb->prefix . 'rsa_events',
			[ 'page' => $page ],
			[ '%s' ]
		);
		$deleted += (int) $wpdb->delete(
			$wpdb->prefix . 'rsa_clicks',
			[ 'page' => $page ],
			[ '%s' ]
		);
		$deleted += (int) $wpdb->delete(
			$wpdb->prefix . 'rsa_heatmap',
			[ 'page' => $page ],
			[ '%s' ]
		);
		return $deleted;
	}

	/**
	 * Remove all plugin data on uninstall if configured.
	 */
	public static function maybe_remove_data(): void {
		if ( ! get_option( 'rsa_remove_data_on_uninstall' ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_sessions`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_heatmap`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_wc_events`" );

		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rsa_%'"
		);
	}

	/**
	 * Prune old data based on the retention setting.
	 *
	 * @param int|null $days Number of days to retain. Defaults to the configured retention.
	 * @return int Total rows deleted.
	 */
	public static function prune_old_data( ?int $days = null ): int {
		global $wpdb;

		$days    = $days ?? (int) get_option( 'rsa_retention_days', 90 );
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$deleted = 0;

		$result   = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}rsa_events` WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
		$deleted += (int) $result;

		$result   = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}rsa_sessions` WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
		$deleted += (int) $result;

		$result   = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}rsa_clicks` WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
		$deleted += (int) $result;

		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$result      = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}rsa_heatmap` WHERE date_bucket < %s LIMIT 5000",
				$cutoff_date
			)
		);
		$deleted    += (int) $result;

		$result   = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}rsa_wc_events` WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
		$deleted += (int) $result;

		return $deleted;
	}

	/**
	 * Aggregate raw clicks into heatmap buckets.
	 */
	public static function aggregate_heatmap(): void {
		global $wpdb;

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page, x_pct, y_pct FROM `{$wpdb->prefix}rsa_clicks` WHERE DATE(created_at) = %s AND x_pct IS NOT NULL AND y_pct IS NOT NULL",
				$yesterday
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$buckets = [];
		foreach ( $rows as $row ) {
			$x               = round( (float) $row['x_pct'] / 2 ) * 2;
			$y               = round( (float) $row['y_pct'] / 2 ) * 2;
			$key             = $row['page'] . '|' . $x . '|' . $y;
			$buckets[ $key ] = ( $buckets[ $key ] ?? 0 ) + 1;
		}

		foreach ( $buckets as $key => $weight ) {
			[ $page, $x, $y ] = explode( '|', $key, 3 );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `{$wpdb->prefix}rsa_heatmap` (page, x_pct, y_pct, weight, date_bucket)
					 VALUES (%s, %f, %f, %d, %s)
					 ON DUPLICATE KEY UPDATE weight = weight + VALUES(weight)",
					$page,
					(float) $x,
					(float) $y,
					$weight,
					$yesterday
				)
			);
		}
	}

	/**
	 * Run daily maintenance tasks.
	 */
	public static function daily_maintenance(): void {
		if ( is_multisite() ) {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);
			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				self::prune_old_data();
				self::aggregate_heatmap();
				restore_current_blog();
			}
		} else {
			self::prune_old_data();
			self::aggregate_heatmap();
		}
	}

	/**
	 * Register cron and multisite hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'rsa_daily_maintenance', [ __CLASS__, 'daily_maintenance' ] );
		add_action( 'wp_initialize_site', [ __CLASS__, 'on_new_blog_event' ] );
	}

	public static function on_new_blog_event( $new_site ): void {
		if ( is_plugin_active_for_network( plugin_basename( RSA_FILE ) ) ) {
			switch_to_blog( $new_site->blog_id );
			self::install();
			restore_current_blog();
		}
	}
}

RSA_DB::register_hooks();
