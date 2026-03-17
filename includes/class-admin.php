<?php
/**
 * Admin class: registers menus, enqueues admin assets,
 * renders the dashboard, and handles the Settings page.
 */
defined( 'ABSPATH' ) || exit;

class RSA_Admin {

	public static function init(): void {
		add_action( 'admin_menu',             [ __CLASS__, 'register_menus' ] );
		add_action( 'network_admin_menu',     [ __CLASS__, 'register_network_menus' ] );
		add_action( 'admin_enqueue_scripts',  [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_rsa_save_settings', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_rsa_export_csv',    [ __CLASS__, 'handle_export_csv' ] );
		add_action( 'current_screen',        [ __CLASS__, 'register_help_tabs' ] );

		// Show download button near Application Passwords in user profile
		add_action( 'show_user_security_settings', [ __CLASS__, 'profile_webapp_button' ] );
	}

	// ----------------------------------------------------------------
	// Menus
	// ----------------------------------------------------------------

	public static function register_menus(): void {
		add_menu_page(
			__( 'Rich Statistics', 'rich-statistics' ),
			__( 'Rich Statistics', 'rich-statistics' ),
			'manage_options',
			'rich-statistics',
			[ __CLASS__, 'page_overview' ],
			'dashicons-chart-area',
			25
		);

		$sub_pages = self::get_sub_pages();
		foreach ( $sub_pages as $slug => $page ) {
			add_submenu_page(
				'rich-statistics',
				$page['title'] . ' — ' . __( 'Rich Statistics', 'rich-statistics' ),
				$page['label'],
				$page['cap'],
				'rich-statistics' . ( $slug === 'overview' ? '' : '-' . $slug ),
				[ __CLASS__, 'page_' . str_replace( '-', '_', $slug ) ]
			);
		}
	}

	public static function register_network_menus(): void {
		add_menu_page(
			__( 'Rich Statistics (Network)', 'rich-statistics' ),
				__( 'Rich Statistics', 'rich-statistics' ),
			'manage_network_options',
			'rich-statistics-network',
			[ __CLASS__, 'page_network_settings' ],
			'dashicons-chart-area',
			25
		);
	}

	private static function get_sub_pages(): array {
		$is_premium = function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only();
		$pages = [
			'overview'  => [ 'title' => __( 'Overview',  'rich-statistics' ), 'label' => __( 'Overview',  'rich-statistics' ), 'cap' => 'manage_options' ],
			'pages'     => [ 'title' => __( 'Pages',     'rich-statistics' ), 'label' => __( 'Pages',     'rich-statistics' ), 'cap' => 'manage_options' ],
			'audience'  => [ 'title' => __( 'Audience',  'rich-statistics' ), 'label' => __( 'Audience',  'rich-statistics' ), 'cap' => 'manage_options' ],
			'referrers' => [ 'title' => __( 'Referrers', 'rich-statistics' ), 'label' => __( 'Referrers', 'rich-statistics' ), 'cap' => 'manage_options' ],
			'behavior'  => [ 'title' => __( 'Behavior',  'rich-statistics' ), 'label' => __( 'Behavior',  'rich-statistics' ), 'cap' => 'manage_options' ],
			'user-flow' => [ 'title' => __( 'User Flow', 'rich-statistics' ), 'label' => __( 'User Flow', 'rich-statistics' ), 'cap' => 'manage_options' ],
		];
		$upgrade_label = function_exists( 'rs_fs' )
			? ' <a href="' . esc_url( rs_fs()->get_upgrade_url() ) . '" style="font-size:11px;font-weight:normal;">(' . esc_html__( 'Upgrade', 'rich-statistics' ) . ')</a>'
			: '';
		if ( $is_premium ) {
			$pages['click-map'] = [ 'title' => __( 'Click Map', 'rich-statistics' ), 'label' => __( 'Click Map', 'rich-statistics' ), 'cap' => 'manage_options' ];
			$pages['heatmap']   = [ 'title' => __( 'Heatmap',   'rich-statistics' ), 'label' => __( 'Heatmap',   'rich-statistics' ), 'cap' => 'manage_options' ];
		} else {
			$pages['click-map'] = [ 'title' => __( 'Click Map', 'rich-statistics' ), 'label' => __( 'Click Map', 'rich-statistics' ) . $upgrade_label, 'cap' => 'manage_options' ];
			$pages['heatmap']   = [ 'title' => __( 'Heatmap',   'rich-statistics' ), 'label' => __( 'Heatmap',   'rich-statistics' ) . $upgrade_label, 'cap' => 'manage_options' ];
		}
		$pages['preferences'] = [ 'title' => __( 'Preferences', 'rich-statistics' ), 'label' => __( 'Preferences', 'rich-statistics' ), 'cap' => 'manage_options' ];
		$pages['export']      = [ 'title' => __( 'Export',      'rich-statistics' ), 'label' => __( 'Export',      'rich-statistics' ), 'cap' => 'manage_options' ];
		return $pages;
	}

	// ----------------------------------------------------------------
	// Asset enqueuing
	// ----------------------------------------------------------------

	public static function enqueue_assets( string $hook ): void {
		// Only load on our own pages
		if ( strpos( $hook, 'rich-statistics' ) === false
		     && strpos( $hook, 'rich-stats' ) === false ) {
			return;
		}

		// Chart.js (bundled — no CDN)
		wp_enqueue_script(
			'rsa-chartjs',
			RSA_ASSETS_URL . '../vendor/chart.min.js',
			[],
			'4.4.2',
			true
		);

		$css_file = RSA_DIR . 'assets/css/admin.css';
		wp_enqueue_style(
			'rsa-admin',
			RSA_ASSETS_URL . 'css/admin.css',
			[],
			(string) ( file_exists( $css_file ) ? filemtime( $css_file ) : RSA_VERSION )
		);

		$js_file = RSA_DIR . 'assets/js/admin-charts.js';
		wp_enqueue_script(
			'rsa-admin-charts',
			RSA_ASSETS_URL . 'js/admin-charts.js',
			[ 'rsa-chartjs' ],
			(string) ( file_exists( $js_file ) ? filemtime( $js_file ) : RSA_VERSION ),
			true
		);

		// Expose PHP data for the current page
		$page_data = self::get_page_data_for_current_screen( $hook );
		wp_localize_script( 'rsa-admin-charts', 'RSA_DATA', $page_data );
	}

	private static function get_page_data_for_current_screen( string $hook ): array {
		$period = sanitize_text_field( $_GET['period'] ?? '30d' );
		$allowed_periods = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
		if ( ! in_array( $period, $allowed_periods, true ) ) {
			$period = '30d';
		}

		$date_from = $date_to = '';
		if ( $period === 'custom' ) {
			$date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
			$date_to   = sanitize_text_field( $_GET['date_to']   ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = date( 'Y-m-d', current_time( 'timestamp' ) ); } // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		}

		$page_filters = [
			'browser'   => sanitize_text_field( $_GET['browser']  ?? '' ),
			'os'        => sanitize_text_field( $_GET['os']       ?? '' ),
			'search'    => sanitize_text_field( $_GET['search']   ?? '' ),
			'page'      => sanitize_text_field( $_GET['ref_page'] ?? '' ),
			'sort'      => in_array( $_GET['sort'] ?? '', [ 'views', 'avg_time' ], true ) ? $_GET['sort'] : 'views',
			'sort_dir'  => ( ( $_GET['sort_dir'] ?? 'desc' ) === 'asc' ) ? 'asc' : 'desc',
			'date_from' => $date_from,
			'date_to'   => $date_to,
		];

		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-pages' ) ) {
			$pf         = $page_filters;
			$pf['page'] = sanitize_text_field( $_GET['path'] ?? '' );
			return [ 'view' => 'pages', 'data' => RSA_Analytics::get_top_pages( $period, 20, $pf ), 'period' => $period ];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-audience' ) ) {
			return [ 'view' => 'audience', 'data' => RSA_Analytics::get_audience( $period, $page_filters ), 'period' => $period ];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-referrers' ) ) {
			$ref_filters = [ 'page' => $page_filters['page'] ];
			return [ 'view' => 'referrers', 'data' => RSA_Analytics::get_referrers( $period, 20, $ref_filters ), 'period' => $period ];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-behavior' ) ) {
			$beh_filters = [ 'browser' => $page_filters['browser'], 'os' => $page_filters['os'], 'date_from' => $date_from, 'date_to' => $date_to ];
			$beh_data    = RSA_Analytics::get_behavior( $period, $beh_filters );
			return [ 'view' => 'behavior', 'data' => $beh_data, 'period' => $period ];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-user-flow' ) ) {
			$entry_source = sanitize_text_field( $_GET['entry_source'] ?? '' );
			$uf_page      = sanitize_text_field( $_GET['page_filter']  ?? '' );
			$uf_filters   = [
				'date_from'  => $date_from,
				'date_to'    => $date_to,
				'from_page'  => sanitize_text_field( $_GET['from_page'] ?? '' ),
				'to_page'    => sanitize_text_field( $_GET['to_page']   ?? '' ),
				'min_count'  => max( 1, (int) ( $_GET['min_count'] ?? 1 ) ),
				'limit'      => 30,
			];
			return [
				'view'   => 'user-flow',
				'data'   => [
					'journey_flow' => RSA_Analytics::get_journey_flow( $period, [
						'date_from'    => $date_from,
						'date_to'      => $date_to,
						'entry_source' => $entry_source,
						'page'         => $uf_page,
					] ),
					'user_flow' => RSA_Analytics::get_user_flow( $period, $uf_filters ),
				],
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-click-map' ) ) {
			$page = sanitize_text_field( $_GET['page_filter'] ?? '' );
			return [ 'view' => 'click-map', 'data' => RSA_Analytics::get_click_map( $period, $page ), 'period' => $period ];
		}

		// Default: overview
		return [ 'view' => 'overview', 'data' => RSA_Analytics::get_overview( $period, $page_filters ), 'period' => $period ];
	}

	// ----------------------------------------------------------------
	// Page renderers — each delegates to a template partial
	// ----------------------------------------------------------------

	public static function page_overview():       void { self::render( 'overview' ); }
	public static function page_pages():          void { self::render( 'pages' ); }
	public static function page_audience():       void { self::render( 'audience' ); }
	public static function page_referrers():      void { self::render( 'referrers' ); }
	public static function page_behavior():       void { self::render( 'behavior' ); }
	public static function page_user_flow():      void { self::render( 'user-flow' ); }
	public static function page_click_map():      void { self::render( 'click-map' ); }
	public static function page_heatmap():        void { self::render( 'heatmap' ); }
	public static function page_preferences():      void { self::render( 'preferences' ); }
	public static function page_export():           void { self::render( 'export' ); }
	public static function page_network_settings(): void { self::render( 'network-settings' ); }

	private static function render( string $template ): void {
		$file = RSA_DIR . 'templates/admin/' . $template . '.php';
		if ( file_exists( $file ) ) {
			include $file;
		}
	}

	// ----------------------------------------------------------------
	// Page dropdown helper — WordPress-native list for page filters
	// ----------------------------------------------------------------

	public static function get_trackable_pages(): array {
		$enabled_cpts = get_option( 'rsa_enabled_post_types', [] );
		$post_types   = array_merge( [ 'page', 'post' ], is_array( $enabled_cpts ) ? $enabled_cpts : [] );

		$posts = get_posts( [
			'post_type'      => array_unique( $post_types ),
			'post_status'    => 'publish',
			'numberposts'    => 500,
			'orderby'        => 'post_type',
			'order'          => 'ASC',
		] );

		$pages = [];
		foreach ( $posts as $post ) {
			$url  = get_permalink( $post );
			$path = wp_make_link_relative( $url );
			$pages[ $path ] = get_the_title( $post ) . ' (' . $path . ')';
		}
		ksort( $pages );
		return $pages;
	}

	// ----------------------------------------------------------------
	// CSV Export handler
	// ----------------------------------------------------------------

	public static function handle_export_csv(): void {
		check_admin_referer( 'rsa_export_csv' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rich-statistics' ) );
		}

		global $wpdb;

		$data_type = sanitize_key( $_POST['data_type'] ?? 'pageviews' );
		$period    = sanitize_text_field( $_POST['period'] ?? '30d' );
		$allowed   = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
		if ( ! in_array( $period, $allowed, true ) ) { $period = '30d'; }

		$date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
		$date_to   = sanitize_text_field( $_POST['date_to']   ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = ''; }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = ''; }

		$range = RSA_Analytics::period_range( $period, $date_from, $date_to );
		$et    = RSA_DB::events_table();
		$st    = RSA_DB::sessions_table();
		$ct    = RSA_DB::clicks_table();
		$bt    = (int) get_option( 'rsa_bot_score_threshold', 5 );

		switch ( $data_type ) {
			case 'sessions':
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT session_id, entry_page, exit_page, pages_viewed, total_time, browser, os, language, timezone, created_at
					 FROM `{$st}` WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$range['start'], $range['end']
				), ARRAY_A );
				$headers = [ 'session_id', 'entry_page', 'exit_page', 'pages_viewed', 'total_time', 'browser', 'os', 'language', 'timezone', 'created_at' ];
				break;
			case 'clicks':
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT session_id, page, element_tag, element_id, element_class, element_text, href_protocol, matched_rule, x_pct, y_pct, created_at
					 FROM `{$ct}` WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$range['start'], $range['end']
				), ARRAY_A );
				$headers = [ 'session_id', 'page', 'element_tag', 'element_id', 'element_class', 'element_text', 'href_protocol', 'matched_rule', 'x_pct', 'y_pct', 'created_at' ];
				break;
			case 'referrers':
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT referrer_domain, COUNT(*) AS pageviews, COUNT(DISTINCT session_id) AS sessions
					 FROM `{$et}` WHERE created_at BETWEEN %s AND %s AND bot_score < %d
					 GROUP BY referrer_domain ORDER BY pageviews DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$range['start'], $range['end'], $bt
				), ARRAY_A );
				$headers = [ 'referrer_domain', 'pageviews', 'sessions' ];
				break;
			default: // pageviews
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT session_id, page, referrer_domain, os, browser, browser_version, language, timezone, viewport_w, viewport_h, time_on_page, bot_score, created_at
					 FROM `{$et}` WHERE created_at BETWEEN %s AND %s AND bot_score < %d ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$range['start'], $range['end'], $bt
				), ARRAY_A );
				$headers = [ 'session_id', 'page', 'referrer_domain', 'os', 'browser', 'browser_version', 'language', 'timezone', 'viewport_w', 'viewport_h', 'time_on_page', 'bot_score', 'created_at' ];
		}

		$filename = 'rich-statistics-' . $data_type . '-' . date( 'Y-m-d', current_time( 'timestamp' ) ) . '.csv'; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel
		fputcsv( $out, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_values( $row ) );
		}
		fclose( $out );
		exit;
	}

	// ----------------------------------------------------------------
	// Settings save handler
	// ----------------------------------------------------------------

	public static function save_settings(): void {
		check_admin_referer( 'rsa_settings_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rich-statistics' ) );
		}

		$fields = [
			'rsa_retention_days'           => 'absint',
			'rsa_bot_score_threshold'      => 'absint',
			'rsa_remove_data_on_uninstall' => 'absint',
			'rsa_track_protocol_tel'          => 'absint',
			'rsa_track_protocol_mailto'       => 'absint',
			'rsa_track_protocol_geo'          => 'absint',
			'rsa_track_protocol_sms'          => 'absint',
			'rsa_track_protocol_download'     => 'absint',
			'rsa_click_track_ids'             => 'sanitize_text_field',
			'rsa_click_track_classes'         => 'sanitize_text_field',
			'rsa_email_digest_enabled'     => 'absint',
			'rsa_email_digest_frequency'   => 'sanitize_text_field',
			'rsa_email_digest_recipients'  => 'sanitize_text_field',
		];

		foreach ( $fields as $key => $sanitizer ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = $sanitizer( $_POST[ $key ] );
				// Clamp numeric values
				if ( $key === 'rsa_retention_days' ) {
					$value = max( 1, min( 730, $value ) );
				}
				if ( $key === 'rsa_bot_score_threshold' ) {
					$value = max( 1, min( 10, $value ) );
				}
				update_option( $key, $value );
			} elseif ( in_array( $sanitizer, [ 'absint' ], true ) ) {
				// Checkboxes: unchecked = 0
				update_option( $key, 0 );
			}
		}

		// Custom post types array — sanitize each slug
		$raw_cpts = isset( $_POST['rsa_enabled_post_types'] ) && is_array( $_POST['rsa_enabled_post_types'] )
			? $_POST['rsa_enabled_post_types']
			: [];
		$safe_cpts = array_values( array_filter( array_map( 'sanitize_key', $raw_cpts ) ) );
		update_option( 'rsa_enabled_post_types', $safe_cpts );

		wp_safe_redirect( add_query_arg( [ 'page' => 'rich-statistics-preferences', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ----------------------------------------------------------------
	// Profile: Web App download button near Application Passwords
	// ----------------------------------------------------------------

	public static function profile_webapp_button( WP_User $profile_user ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) ) {
			if ( function_exists( 'rs_fs' ) ) {
				?>
				<tr class="rsa-webapp-row">
					<th scope="row"><?php esc_html_e( 'Rich Statistics App', 'rich-statistics' ); ?></th>
					<td>
						<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary">
							<?php esc_html_e( 'Upgrade to unlock the Stats App', 'rich-statistics' ); ?>
						</a>
						<p class="description"><?php esc_html_e( 'The Rich Statistics App lets you view your stats from any device as a PWA — no browser required. Available with a premium licence.', 'rich-statistics' ); ?></p>
					</td>
				</tr>
				<?php
			}
			return;
		}

		$download_url    = wp_nonce_url(
			admin_url( 'admin-ajax.php?action=rsa_download_pwa' ),
			'rsa_download_pwa'
		);
		$site_config_url = wp_nonce_url(
			admin_url( 'admin-ajax.php?action=rsa_site_config' ),
			'rsa_site_config'
		);
		?>
		<tr class="rsa-webapp-row">
			<th scope="row"><?php esc_html_e( 'Rich Statistics App', 'rich-statistics' ); ?></th>
			<td>
				<a href="<?php echo esc_url( $site_config_url ); ?>"
				   class="button button-primary">
					<?php esc_html_e( 'Add This Site to App', 'rich-statistics' ); ?>
				</a>
				<a href="<?php echo esc_url( $download_url ); ?>"
				   class="button"
				   style="margin-left:8px;">
					<?php esc_html_e( 'Download App', 'rich-statistics' ); ?>
				</a>
				<p class="description">
					<?php esc_html_e( 'First time? Use "Download App" to install the app once on any device. Then return here and click "Add This Site to App" — open the app, import the downloaded file, and enter your Application Password below to connect.', 'rich-statistics' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	// ----------------------------------------------------------------
	// Shared: period selector HTML (used by templates)
	// ----------------------------------------------------------------

	public static function period_selector( string $current = '30d' ): string {
		$options = [
			'7d'        => __( 'Last 7 days',   'rich-statistics' ),
			'30d'       => __( 'Last 30 days',  'rich-statistics' ),
			'90d'       => __( 'Last 90 days',  'rich-statistics' ),
			'thismonth' => __( 'This month',    'rich-statistics' ),
			'lastmonth' => __( 'Last month',    'rich-statistics' ),
		];

		$page      = sanitize_text_field( $_GET['page'] ?? 'rich-statistics' );
		$url       = admin_url( 'admin.php' );
		$is_custom = ( $current === 'custom' );
		// Always populate dates so the inputs are pre-filled regardless of mode
		$date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
		$date_to   = sanitize_text_field( $_GET['date_to']   ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) ); }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = gmdate( 'Y-m-d' ); }

		$html = '<div class="rsa-period-controls">';

		// Preset period buttons (navigational links)
		$html .= '<div class="rsa-period-selector">';
		foreach ( $options as $val => $label ) {
			$href   = add_query_arg( [ 'page' => $page, 'period' => $val ], $url );
			$active = $val === $current ? ' rsa-period-active' : '';
			$html  .= '<a href="' . esc_url( $href ) . '" class="rsa-period-btn' . $active . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</div>';

		// Custom date range — always visible, no JS toggle
		$custom_active = $is_custom ? ' rsa-period-active' : '';
		$html .= '<div class="rsa-custom-range">';
		$html .= '<form method="get" action="' . esc_url( $url ) . '" class="rsa-custom-range-form">';
		$html .= '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		$html .= '<input type="hidden" name="period" value="custom">';
		$html .= '<input type="date" name="date_from" value="' . esc_attr( $date_from ) . '" max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '">';
		$html .= '<span class="rsa-date-sep">' . esc_html__( 'to', 'rich-statistics' ) . '</span>';
		$html .= '<input type="date" name="date_to" value="' . esc_attr( $date_to ) . '" max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '">';
		$html .= '<button type="submit" class="rsa-period-btn' . $custom_active . '">' . esc_html__( 'Apply', 'rich-statistics' ) . '</button>';
		$html .= '</form>';
		$html .= '</div>';

		$html .= '</div>'; // .rsa-period-controls

		return $html;
	}

	// ----------------------------------------------------------------
	// Shared: page header (used by templates)
	// ----------------------------------------------------------------

	public static function page_header( string $title, string $period = '30d' ): void {
		?>
		<div class="wrap rsa-wrap">
			<div class="rsa-header">
				<h1 class="rsa-title">
					<span class="rsa-logo">📊</span>
					<?php echo esc_html( $title ); ?>
				</h1>
				<?php echo self::period_selector( $period ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( function_exists( 'rs_fs' ) && rs_fs()->is_not_paying() ) : ?>
			<div class="rsa-upsell-banner">
				<div class="rsa-upsell-banner__content">
					<strong><?php esc_html_e( 'Unlock Click Maps, Heatmaps &amp; the Stats App', 'rich-statistics' ); ?></strong>
					<?php esc_html_e( 'Get the full picture — see exactly where visitors click, scroll, and engage on every page.', 'rich-statistics' ); ?>
				</div>
				<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary">
					<?php esc_html_e( 'Upgrade Now', 'rich-statistics' ); ?>
				</a>
			</div>
			<?php endif; ?>
		<?php
	}

	public static function page_footer(): void {
		echo '</div><!-- .rsa-wrap -->';
	}

	// ----------------------------------------------------------------
	// Help tabs — appear in the upper-right "Help" dropdown on each page
	// ----------------------------------------------------------------

	public static function register_help_tabs(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'rich-statistics' ) === false ) {
			return;
		}

		// Sidebar shown on all Rich Stats pages
		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'rich-statistics' ) . '</strong></p>' .
			'<p><a href="https://statistics.richardkentgates.com" target="_blank" rel="noopener">' .
			esc_html__( 'Plugin website', 'rich-statistics' ) . '</a></p>' .
			'<p><a href="https://github.com/richardkentgates/rich-statistics/wiki" target="_blank" rel="noopener">' .
			esc_html__( 'Documentation wiki', 'rich-statistics' ) . '</a></p>' .
			'<p><a href="https://github.com/richardkentgates/rich-statistics/issues" target="_blank" rel="noopener">' .
			esc_html__( 'Report an issue', 'rich-statistics' ) . '</a></p>'
		);

		// Shared tab: Privacy
		$screen->add_help_tab( [
			'id'      => 'rsa-privacy',
			'title'   => __( 'Privacy', 'rich-statistics' ),
			'content' =>
				'<h2>' . esc_html__( 'Privacy by Design', 'rich-statistics' ) . '</h2>' .
				'<p>' . esc_html__( 'Rich Statistics does not store personally identifiable information. Sessions are identified by a UUID stored in sessionStorage (not cookies) that is cleared when the browser tab closes. IP addresses are never stored. Referrer URLs are truncated to domain only.', 'rich-statistics' ) . '</p>' .
				'<p>' . esc_html__( 'Because no PII is collected, most sites using Rich Statistics do not require a cookie consent banner for the analytics data collected by this plugin. Always consult a qualified lawyer for advice specific to your situation.', 'rich-statistics' ) . '</p>',
		] );

		// Page-specific help tab content
		$page_help = [
			'toplevel_page_rich-statistics' => [
				'id'      => 'rsa-overview-help',
				'title'   => __( 'Overview', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Overview Dashboard', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'The overview shows your total pageviews, unique sessions, average time on page, and bounce rate for the selected period. The daily chart lets you spot traffic trends at a glance.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Use the period selector (top right) to switch between Last 7 days, Last 30 days, Last 90 days, This month, and Last month.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Bounce rate', 'rich-statistics' ) . '</strong> ' . esc_html__( 'is calculated as the percentage of sessions that viewed only a single page.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-pages' => [
				'id'      => 'rsa-pages-help',
				'title'   => __( 'Pages', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Top Pages', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'This view ranks every page on your site by the number of pageviews in the selected period. The average time on page is shown for each URL.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Page paths are stored after stripping query parameters that appear to contain personal data (email-shaped values or strings longer than 40 characters).', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-audience' => [
				'id'      => 'rsa-audience-help',
				'title'   => __( 'Audience', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Audience Breakdown', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Operating system, browser name, browser version, viewport size, language, and timezone are detected from the browser environment using JavaScript and stored as non-identifying aggregate categories.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Viewport buckets: Mobile (≤480px), Tablet (481–1024px), Desktop (>1024px).', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-referrers' => [
				'id'      => 'rsa-referrers-help',
				'title'   => __( 'Referrers', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Referrer Tracking', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Only the referring domain is stored — never the full URL. This prevents leaking of personal data that might appear in referrer URLs (e.g. search queries, email campaign tokens).', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Direct traffic, bookmark visits, and traffic from HTTPS sites to your HTTP site appear as "(direct)" because browsers do not send a Referer header in these cases.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-behavior' => [
				'id'      => 'rsa-behavior-help',
				'title'   => __( 'Behavior', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Behavior Analysis', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Time on page is measured using the Visibility API — the timer pauses when the visitor switches tabs and resumes when they return. The value is sent when the page is closed via the Beacon API.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Session depth shows how many pages most visitors view in a single session. Entry pages lists the pages where most sessions start.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-user-flow' => [
				'id'      => 'rsa-user-flow-help',
				'title'   => __( 'User Flow', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'User Flow Analysis', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'The flow chart visualises how visitors navigate between pages. Each ribbon represents a page-to-page transition; the wider the ribbon, the more visitors made that journey.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Use the Entry Page and Exit Page dropdowns to focus on traffic entering or leaving a specific page. Switch to the Transitions Table view to sort and browse every recorded page pair with counts and percentages.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-click-map' => [
				'id'      => 'rsa-clicks-help',
				'title'   => __( 'Click Map (Premium)', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Click Tracking (Premium)', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Click tracking captures non-navigational interactions: phone links (tel:), email links (mailto:), map links (geo:), SMS links (sms:), and file downloads. HTTP/HTTPS link navigation is tracked automatically as pageviews. You can add additional element IDs and CSS classes in Preferences.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Click tracking uses event delegation — no inline event handlers are added to the page.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-heatmap' => [
				'id'      => 'rsa-heatmap-help',
				'title'   => __( 'Heatmap (Premium)', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Heatmap (Premium)', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'The heatmap shows where visitors click on a page using a thermal colour overlay (blue = cold → red = hot). Coordinates are stored as viewport-relative percentages so the heatmap works at any screen size.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Raw click coordinates are aggregated into a 2% grid nightly by a background cron task to keep storage efficient.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-email-settings' => [
				'id'      => 'rsa-email-help',
				'title'   => __( 'Email Digests', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Email Digest Settings', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Rich Statistics can send a periodic digest email summarising your key stats. Emails are sent via wp_mail — no third-party email service is required.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Frequencies: Daily (sent at midnight), Weekly (sent Monday), Monthly (sent on the 1st).', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Multiple recipients: enter comma-separated email addresses.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-data-settings' => [
				'id'      => 'rsa-data-help',
				'title'   => __( 'Data Settings', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Data & Privacy Settings', 'rich-statistics' ) . '</h2>' .
					'<p><strong>' . esc_html__( 'Retention days', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'Events older than this number of days are deleted nightly. Range: 1–730. Default: 90.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Bot threshold', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'Any request scoring at or above this value is silently discarded as a bot. Range: 1–10. Default: 3. Lower = more aggressive filtering.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Remove data on uninstall', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'When enabled, all plugin data and options are permanently deleted when you delete the plugin. Cannot be undone.', 'rich-statistics' ) . '</p>',
			],
		];

		if ( isset( $page_help[ $screen->id ] ) ) {
			$screen->add_help_tab( $page_help[ $screen->id ] );
		}
	}
}
