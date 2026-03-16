<?php
/**
 * Database schema, activation, migration, and uninstall.
 *
 * Table strategy for multisite: each subsite gets its own tables
 * using that site's wpdb->prefix (e.g. wp_2_rsa_events).
 * All methods that write/read data are prefix-aware and must be
 * called while the correct blog is switched in.
 */
defined( 'ABSPATH' ) || exit;

class RSA_DB {

	// Schema version stored per-site
	const SCHEMA_VERSION = 6;
	const OPTION_KEY     = 'rsa_db_version';

	// ----------------------------------------------------------------
	// Table name helpers (always call these — never hardcode prefixes)
	// ----------------------------------------------------------------

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'rsa_' . $name;
	}

	public static function events_table(): string   { return self::table( 'events' ); }
	public static function sessions_table(): string { return self::table( 'sessions' ); }
	public static function clicks_table(): string   { return self::table( 'clicks' ); }
	public static function heatmap_table(): string  { return self::table( 'heatmap' ); }

	// ----------------------------------------------------------------
	// Activation
	// ----------------------------------------------------------------

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
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
	 */
	public static function on_new_blog( int $blog_id ): void {
		switch_to_blog( $blog_id );
		self::install();
		restore_current_blog();
	}

	// ----------------------------------------------------------------
	// Schema install / upgrade
	// ----------------------------------------------------------------

	public static function install(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$events = "CREATE TABLE " . self::events_table() . " (
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
			created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY session_id  (session_id),
			KEY page        (page(191)),
			KEY created_at  (created_at)
		) $charset;";

		$sessions = "CREATE TABLE " . self::sessions_table() . " (
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

		$clicks = "CREATE TABLE " . self::clicks_table() . " (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id      VARCHAR(36)         NOT NULL,
			page            VARCHAR(512)        NOT NULL,
			element_tag     VARCHAR(32)         DEFAULT NULL,
			element_id      VARCHAR(255)        DEFAULT NULL,
			element_class   VARCHAR(512)        DEFAULT NULL,
			element_text    VARCHAR(255)        DEFAULT NULL,
			href_protocol   VARCHAR(32)         DEFAULT NULL,
			matched_rule    VARCHAR(255)        DEFAULT NULL,
			x_pct           DECIMAL(5,2)        DEFAULT NULL,
			y_pct           DECIMAL(5,2)        DEFAULT NULL,
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY page        (page(191)),
			KEY session_id  (session_id),
			KEY created_at  (created_at)
		) $charset;";

		$heatmap = "CREATE TABLE " . self::heatmap_table() . " (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			page        VARCHAR(512)        NOT NULL,
			x_pct       DECIMAL(5,2)        NOT NULL,
			y_pct       DECIMAL(5,2)        NOT NULL,
			weight      INT UNSIGNED        DEFAULT 1,
			date_bucket DATE                NOT NULL,
			PRIMARY KEY (id),
			KEY page_date (page(191), date_bucket)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $events );
		dbDelta( $sessions );
		dbDelta( $clicks );
		dbDelta( $heatmap );

		update_option( self::OPTION_KEY, self::SCHEMA_VERSION, false );

		// Seed default options if not present
		self::seed_defaults();

		// Run any incremental column migrations
		self::maybe_upgrade();

		// Schedule maintenance cron
		if ( ! wp_next_scheduled( 'rsa_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'rsa_daily_maintenance' );
		}
	}

	// ----------------------------------------------------------------
	// Incremental column migrations (safe to re-run — ADD COLUMN IF NOT EXISTS)
	// ----------------------------------------------------------------

	public static function maybe_upgrade(): void {
		global $wpdb;
		$ct = self::clicks_table();

		// v6: add matched_rule column if missing
		$col = $wpdb->get_results( $wpdb->prepare(
			'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			DB_NAME, $ct, 'matched_rule'
		) );
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE `{$ct}` ADD COLUMN matched_rule VARCHAR(255) DEFAULT NULL AFTER href_protocol" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	// ----------------------------------------------------------------
	// Default option seeding
	// ----------------------------------------------------------------

	private static function seed_defaults(): void {
		$defaults = [
			'rsa_retention_days'              => 90,
			'rsa_bot_score_threshold'         => 5,
			'rsa_remove_data_on_uninstall'    => 0,
			'rsa_track_protocol_inbound'      => 1,
			'rsa_track_protocol_outbound'     => 1,
			'rsa_track_protocol_tel'          => 1,
			'rsa_track_protocol_mailto'       => 1,
			'rsa_track_protocol_geo'          => 1,
			'rsa_track_protocol_sms'          => 1,
			'rsa_track_protocol_download'     => 1,
			'rsa_click_track_ids'             => '',
			'rsa_click_track_classes'         => '',
			'rsa_email_digest_enabled'     => 0,
			'rsa_email_digest_frequency'   => 'weekly',
			'rsa_email_digest_recipients'  => get_option( 'admin_email' ),
			'rsa_email_digest_next'        => '',
		];

		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key ) === false ) {
				add_option( $key, $value, '', false );
			}
		}
	}

	// ----------------------------------------------------------------
	// Deactivation
	// ----------------------------------------------------------------

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rsa_daily_maintenance' );
	}

	// ----------------------------------------------------------------
	// Uninstall (called from uninstall.php)
	// ----------------------------------------------------------------

	public static function maybe_remove_data(): void {
		if ( ! get_option( 'rsa_remove_data_on_uninstall' ) ) {
			return;
		}

		global $wpdb;

		// Drop tables
		$tables = [
			self::events_table(),
			self::sessions_table(),
			self::clicks_table(),
			self::heatmap_table(),
		];

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Remove all plugin options
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rsa_%'"
		);
	}

	// ----------------------------------------------------------------
	// Maintenance: prune old rows
	// ----------------------------------------------------------------

	public static function prune_old_data( ?int $days = null ): int {
		global $wpdb;

		$days    = $days ?? (int) get_option( 'rsa_retention_days', 90 );
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$deleted = 0;

		$tables = [
			self::events_table(),
			self::sessions_table(),
			self::clicks_table(),
		];

		foreach ( $tables as $table ) {
			$result = $wpdb->query(
				$wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s LIMIT 5000", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$deleted += (int) $result;
		}

		// Prune heatmap date buckets older than the retention window
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$result = $wpdb->query(
			$wpdb->prepare( "DELETE FROM `" . self::heatmap_table() . "` WHERE date_bucket < %s LIMIT 5000", $cutoff_date ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$deleted += (int) $result;

		return $deleted;
	}

	// ----------------------------------------------------------------
	// Heatmap aggregation: rolls up raw clicks into heatmap buckets
	// Called nightly via cron.
	// ----------------------------------------------------------------

	public static function aggregate_heatmap(): void {
		global $wpdb;

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// Pull yesterday's clicks
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page, x_pct, y_pct
				 FROM `" . self::clicks_table() . "`
				 WHERE DATE(created_at) = %s
				   AND x_pct IS NOT NULL
				   AND y_pct IS NOT NULL",
				$yesterday
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		// Bucket into 2% grid for efficient rendering
		$buckets = [];
		foreach ( $rows as $row ) {
			$x   = round( (float) $row['x_pct'] / 2 ) * 2;
			$y   = round( (float) $row['y_pct'] / 2 ) * 2;
			$key = $row['page'] . '|' . $x . '|' . $y;
			$buckets[ $key ] = ( $buckets[ $key ] ?? 0 ) + 1;
		}

		$heatmap_table = self::heatmap_table();

		foreach ( $buckets as $key => $weight ) {
			[ $page, $x, $y ] = explode( '|', $key, 3 );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `{$heatmap_table}` (page, x_pct, y_pct, weight, date_bucket)
					 VALUES (%s, %f, %f, %d, %s)
					 ON DUPLICATE KEY UPDATE weight = weight + VALUES(weight)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$page,
					(float) $x,
					(float) $y,
					$weight,
					$yesterday
				)
			);
		}
	}

	// ----------------------------------------------------------------
	// Cron handler
	// ----------------------------------------------------------------

	public static function daily_maintenance(): void {
		if ( is_multisite() ) {
			$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
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

	// ----------------------------------------------------------------
	// Hooks for multisite new blog creation
	// ----------------------------------------------------------------

	public static function register_hooks(): void {
		add_action( 'rsa_daily_maintenance', [ __CLASS__, 'daily_maintenance' ] );
		add_action( 'wp_initialize_site',    [ __CLASS__, 'on_new_blog_event' ] );
		add_action( 'admin_init',            [ __CLASS__, 'maybe_upgrade' ] );
	}

	public static function on_new_blog_event( $new_site ): void {
		if ( is_plugin_active_for_network( plugin_basename( RSA_FILE ) ) ) {
			switch_to_blog( $new_site->blog_id );
			self::install();
			restore_current_blog();
		}
	}
}

// Register cron + multisite hooks immediately when this file is loaded
RSA_DB::register_hooks();
