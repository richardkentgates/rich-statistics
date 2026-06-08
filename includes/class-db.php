<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database schema, activation, migration, and uninstall.
 *
 * Schema is applied via dbDelta() on activation. Additive changes are safe;
 * full migration history is planned for v2.5.0.
 *
 * @package RichStatistics
 *
 * @license GPL-2.0-or-later
 */
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
			$batch_size = 100;
			$offset     = 0;
			do {
				$sites = get_sites(
					[
						'fields' => 'ids',
						'number' => $batch_size,
						'offset' => $offset,
					]
				);
				foreach ( $sites as $blog_id ) {
					try {
						switch_to_blog( $blog_id );
						self::install();
					} finally {
						restore_current_blog();
					}
				}
				$offset    += $batch_size;
				$site_count = count( $sites );
			} while ( $site_count === $batch_size );
		} else {
			self::install();
		}
	}

	/**
	 * Install tables on a specific subsite (utility — not currently hooked;
	 * see on_new_blog_event() for the actual wp_initialize_site handler).
	 *
	 * @param int $blog_id The blog ID.
	 */
	public static function on_new_blog( int $blog_id ): void {
		try {
			switch_to_blog( $blog_id );
			self::install();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Install or upgrade the database schema.
	 */
	public static function install(): void {
		global $wpdb;

		$stored_version = get_option( self::OPTION_KEY, 0 );
		if ( $stored_version >= self::SCHEMA_VERSION ) {
			// Guard against test environments where tables may have been
			// dropped while the option persisted (e.g. WordPress test
			// framework teardown). If the events table is missing,
			// re-run dbDelta so all tables are recreated.
			$table_exists = $wpdb->get_var( $wpdb->prepare(
				'SHOW TABLES LIKE %s',
				self::events_table()
			) );
			if ( $table_exists ) {
				return;
			}
		}

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
			KEY utm_source   (utm_source),
			KEY utm_medium   (utm_medium),
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
			UNIQUE KEY page_coords_date (page(191), x_pct, y_pct, date_bucket),
			KEY page_date (page(191), date_bucket),
			KEY date_bucket (date_bucket)
		) $charset;";

		$wc_events = 'CREATE TABLE ' . self::wc_events_table() . " (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id      VARCHAR(36)         NOT NULL,
			event_type      VARCHAR(32)         NOT NULL,
			product_id      BIGINT(20) UNSIGNED DEFAULT NULL,
			product_name    VARCHAR(255)        DEFAULT NULL,
			product_sku     VARCHAR(100)        DEFAULT NULL,
			quantity        SMALLINT UNSIGNED   DEFAULT NULL,
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
			wp_schedule_event( strtotime( 'tomorrow 2:00 AM' ), 'daily', 'rsa_daily_maintenance' );
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
			'rsa_email_digest_use_roles'   => 0,
			'rsa_woocommerce_enabled'      => 1,
			'rsa_beta_channel'             => 0,
			'rsa_consent_banner'           => 0,
			'rsa_consent_auto'             => 0,
			'rsa_consent_styles'           => '{}',
			'rsa_consent_banner_text'      => '',
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
	 * Purge events, clicks, and heatmap data for one specific page path.
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
	 * In multisite mode, iterates all sites and also cleans network-level options.
	 */
	public static function maybe_remove_data(): void {
		if ( ! get_option( 'rsa_remove_data_on_uninstall' ) ) {
			return;
		}

		if ( is_multisite() ) {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);
			foreach ( $sites as $blog_id ) {
				try {
					switch_to_blog( $blog_id );
					self::drop_site_tables();
				} finally {
					restore_current_blog();
				}
			}
			// Clean network-level options from sitemeta.
			delete_site_option( 'rsa_default_retention_days' );
			delete_site_option( 'rsa_network_disable_tracker' );
		} else {
			self::drop_site_tables();
		}
	}

	/**
	 * Drop tables and options for the current site.
	 */
	private static function drop_site_tables(): void {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_events`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_sessions`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_clicks`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_heatmap`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}rsa_wc_events`" );

		$options      = array(
			'rsa_retention_days',
			'rsa_bot_score_threshold',
			'rsa_remove_data_on_uninstall',
			'rsa_track_protocol_tel',
			'rsa_track_protocol_mailto',
			'rsa_track_protocol_geo',
			'rsa_track_protocol_sms',
			'rsa_track_protocol_download',
			'rsa_click_track_ids',
			'rsa_click_track_classes',
			'rsa_email_digest_enabled',
			'rsa_email_digest_frequency',
			'rsa_email_digest_recipients',
			'rsa_email_digest_use_roles',
			'rsa_woocommerce_enabled',
			'rsa_beta_channel',
			'rsa_consent_banner',
			'rsa_consent_auto',
			'rsa_consent_styles',
			'rsa_consent_banner_text',
			'rsa_enabled_post_types',
			'rsa_allowed_roles',
			'rsa_db_version',
		);
		$placeholders = implode( ',', array_fill( 0, count( $options ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->options . " WHERE option_name IN ($placeholders)", $options ) );
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
		$start   = microtime( true );

		$tables = [
			'rsa_events'    => [ 'created_at', $cutoff ],
			'rsa_sessions'  => [ 'created_at', $cutoff ],
			'rsa_clicks'    => [ 'created_at', $cutoff ],
			'rsa_wc_events' => [ 'created_at', $cutoff ],
		];

		foreach ( $tables as $table => $config ) {
			[ $col, $val ] = $config;
			do {
				$result = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $col from hardcoded whitelist
						"DELETE FROM `{$wpdb->prefix}{$table}` WHERE {$col} < %s LIMIT 5000",
						$val
					)
				);
				$deleted += (int) $result;
				// Break if we've been running for 55 seconds — the next
				// cron invocation will start fresh.
				if ( microtime( true ) - $start > 55 ) {
					return $deleted;
				}
			} while ( $result > 0 );
		}

		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		do {
			$result   = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$wpdb->prefix}rsa_heatmap` WHERE date_bucket < %s LIMIT 5000",
					$cutoff_date
				)
			);
			$deleted += (int) $result;
			if ( microtime( true ) - $start > 55 ) {
				return $deleted;
			}
		} while ( $result > 0 );

		return $deleted;
	}

	/**
	 * Aggregate raw clicks into heatmap buckets.
	 * Uses pure-SQL INSERT ... SELECT to avoid loading millions of clicks
	 * into PHP memory on high-traffic sites.
	 */
	public static function aggregate_heatmap(): void {
		global $wpdb;

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$today     = gmdate( 'Y-m-d', strtotime( '0 day' ) );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$wpdb->prefix}rsa_heatmap` (page, x_pct, y_pct, weight, date_bucket)
				 SELECT page,
				        ROUND(x_pct / 2) * 2,
				        ROUND(y_pct / 2) * 2,
				        COUNT(*),
				        %s
				 FROM `{$wpdb->prefix}rsa_clicks`
				 WHERE created_at >= %s AND created_at < %s AND x_pct IS NOT NULL AND y_pct IS NOT NULL
				 GROUP BY page, ROUND(x_pct / 2) * 2, ROUND(y_pct / 2) * 2
				 ON DUPLICATE KEY UPDATE weight = weight + VALUES(weight)",
				$yesterday,
				$yesterday . ' 00:00:00',
				$today . ' 00:00:00'
			)
		);

		// Delete processed raw clicks to prevent double-counting on re-run.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}rsa_clicks` WHERE created_at >= %s AND created_at < %s",
				$yesterday . ' 00:00:00',
				$today . ' 00:00:00'
			)
		);

		if ( $wpdb->last_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Rich Statistics heatmap aggregation failed: ' . $wpdb->last_error );
		}
	}

	/**
	 * Run daily maintenance tasks.
	 */
	public static function daily_maintenance(): void {
		// Prevent concurrent cron runs — if maintenance takes longer than
		// expected (e.g. on very large sites), skip this invocation.
		if ( get_transient( 'rsa_maintenance_lock' ) ) {
			return;
		}
		set_transient( 'rsa_maintenance_lock', 1, HOUR_IN_SECONDS );

		if ( is_multisite() ) {
			$batch_size = 100;
			$offset     = 0;
			do {
				$sites = get_sites(
					[
						'fields' => 'ids',
						'number' => $batch_size,
						'offset' => $offset,
					]
				);
				foreach ( $sites as $blog_id ) {
					try {
						switch_to_blog( $blog_id );
						self::prune_old_data();
						self::aggregate_heatmap();
					} finally {
						restore_current_blog();
					}
				}
				$offset    += $batch_size;
				$site_count = count( $sites );
			} while ( $site_count === $batch_size );
		} else {
			self::prune_old_data();
			self::aggregate_heatmap();
		}

		delete_transient( 'rsa_maintenance_lock' );
	}

	/**
	 * Register cron and multisite hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'rsa_daily_maintenance', [ __CLASS__, 'daily_maintenance' ] );
		add_action( 'wp_initialize_site', [ __CLASS__, 'on_new_blog_event' ] );
	}

	public static function on_new_blog_event( $new_site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( RSA_FILE ) ) ) {
			try {
				switch_to_blog( $new_site->blog_id );
				self::install();
			} finally {
				restore_current_blog();
			}
		}
	}
}

RSA_DB::register_hooks();
