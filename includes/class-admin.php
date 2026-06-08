<?php
/**
 * Admin class: registers menus, enqueues admin assets,
 * renders the dashboard, and handles the Settings page.
 *
 * @package RichStatistics
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class RSA_Admin {

	/** Initialize admin hooks, capabilities, and rewrite rules. */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
		add_action( 'network_admin_menu', [ __CLASS__, 'register_network_menus' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_rsa_save_settings', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_rsa_export_csv', [ __CLASS__, 'handle_export_csv' ] );
		add_action( 'current_screen', [ __CLASS__, 'register_help_tabs' ] );

		// Register custom role and capability on init.
		if ( ! get_role( 'rsa_analyst' ) ) {
			add_role(
				'rsa_analyst',
				__( 'Statistics Analyst', 'rich-statistics' ),
				[
					'rsa_manage_statistics' => true,
					'read'                  => true,
				]
			);
		}
		// Ensure administrator has the custom capability too.
		$admin = get_role( 'administrator' );
		if ( $admin && ! isset( $admin->capabilities['rsa_manage_statistics'] ) ) {
			$admin->add_cap( 'rsa_manage_statistics' );
		}

		// Priority 1 ensures RSA section appears first among plugin sections,
		// placing it directly after WP's built-in Application Passwords block.
		add_action( 'show_user_profile', [ __CLASS__, 'profile_webapp_section' ], 1 );
		add_action( 'edit_user_profile', [ __CLASS__, 'profile_webapp_section' ], 1 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_profile_assets' ] );
	}

	/**
	 * Enqueue the small OTP script only on the user profile / user-edit screen.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_profile_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {
			return;
		}
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			return;
		}
		$js_file = RSA_DIR . 'assets/js/rsa-profile-otp.js';
		wp_enqueue_script(
			'rsa-profile-otp',
			RSA_ASSETS_URL . 'js/rsa-profile-otp.js',
			[],
			(string) ( file_exists( $js_file ) ? filemtime( $js_file ) : RSA_VERSION ),
			true
		);
		wp_localize_script(
			'rsa-profile-otp',
			'rsaOtp',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'rsa_generate_otp' ),
				'generateLabel'   => __( 'Generate App Code', 'rich-statistics' ),
				'generating'      => __( 'Generating…', 'rich-statistics' ),
				'regenerateLabel' => __( 'New Code', 'rich-statistics' ),
				'copyLabel'       => __( 'Copy', 'rich-statistics' ),
				'copiedMsg'       => __( 'Copied!', 'rich-statistics' ),
				'expiredMsg'      => __( 'Expired', 'rich-statistics' ),
				'errorMsg'        => __( 'Could not generate a code. Please try again.', 'rich-statistics' ),
			]
		);
	}


	/** Register the admin menu and submenu pages. */
	public static function register_menus(): void {
		add_menu_page(
			__( 'Rich Statistics', 'rich-statistics' ),
			__( 'Rich Statistics', 'rich-statistics' ),
			'rsa_manage_statistics',
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
				'rich-statistics' . ( 'overview' === $slug ? '' : '-' . $slug ),
				[ __CLASS__, 'page_' . str_replace( '-', '_', $slug ) ]
			);
		}
	}

	/** Register the network admin menu page. */
	public static function register_network_menus(): void {
		add_menu_page(
			__( 'Rich Statistics (Network)', 'rich-statistics' ),
			__( 'Rich Statistics', 'rich-statistics' ),
			'manage_network_options',
			'rich-statistics-network',
			[ __CLASS__, 'page_network_dashboard' ],
			'dashicons-chart-area',
			25
		);
		add_submenu_page(
			'rich-statistics-network',
			__( 'Network Settings', 'rich-statistics' ),
			__( 'Network Settings', 'rich-statistics' ),
			'manage_network_options',
			'rich-statistics-network-settings',
			[ __CLASS__, 'page_network_settings' ]
		);
	}

	/**
	 * Get the list of submenu pages.
	 *
	 * @return array Submenu pages configuration.
	 */
	private static function get_sub_pages(): array {
		$is_premium = function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only();
		$pages      = [
			'overview'  => [
				'title' => __( 'Overview', 'rich-statistics' ),
				'label' => __( 'Overview', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			],
			'pages'     => [
				'title' => __( 'Pages', 'rich-statistics' ),
				'label' => __( 'Pages', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			],
			'audience'  => [
				'title' => __( 'Audience', 'rich-statistics' ),
				'label' => __( 'Audience', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			],
			'referrers' => [
				'title' => __( 'Referrers', 'rich-statistics' ),
				'label' => __( 'Referrers', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			],
			'behavior'  => [
				'title' => __( 'Behavior', 'rich-statistics' ),
				'label' => __( 'Behavior', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			],
		];

		if ( class_exists( 'WooCommerce' ) ) {
			if ( $is_premium ) {
				// Premium: only add the menu item when tracking is enabled; disabled = hidden entirely.
				if ( get_option( 'rsa_woocommerce_enabled', 1 ) ) {
					$pages['woocommerce'] = [
						'title' => __( 'WooCommerce', 'rich-statistics' ),
						'label' => __( 'WooCommerce', 'rich-statistics' ),
						'cap'   => 'rsa_manage_statistics',
					];
				}
			} else {
				// Not premium — show with upgrade prompt.
				$pages['woocommerce'] = [
					'title' => __( 'WooCommerce', 'rich-statistics' ),
					'label' => __( 'WooCommerce', 'rich-statistics' ),
					'cap'   => 'rsa_manage_statistics',
				];
			}
		}
		if ( $is_premium ) {
			$pages['campaigns'] = [
				'title' => __( 'Campaigns', 'rich-statistics' ),
				'label' => __( 'Campaigns', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['user-flow'] = [
				'title' => __( 'User Flow', 'rich-statistics' ),
				'label' => __( 'User Flow', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['click-map'] = [
				'title' => __( 'Click Tracking', 'rich-statistics' ),
				'label' => __( 'Click Tracking', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['heatmap']   = [
				'title' => __( 'Heatmap', 'rich-statistics' ),
				'label' => __( 'Heatmap', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['export']    = [
				'title' => __( 'Export', 'rich-statistics' ),
				'label' => __( 'Export', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
		} else {
			$pages['campaigns'] = [
				'title' => __( 'Campaigns', 'rich-statistics' ),
				'label' => __( 'Campaigns', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['user-flow'] = [
				'title' => __( 'User Flow', 'rich-statistics' ),
				'label' => __( 'User Flow', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['click-map'] = [
				'title' => __( 'Click Tracking', 'rich-statistics' ),
				'label' => __( 'Click Tracking', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['heatmap']   = [
				'title' => __( 'Heatmap', 'rich-statistics' ),
				'label' => __( 'Heatmap', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
			$pages['export']    = [
				'title' => __( 'Export', 'rich-statistics' ),
				'label' => __( 'Export', 'rich-statistics' ),
				'cap'   => 'rsa_manage_statistics',
			];
		}
		$pages['preferences'] = [
			'title' => __( 'Preferences', 'rich-statistics' ),
			'label' => __( 'Preferences', 'rich-statistics' ),
			'cap'   => 'rsa_manage_statistics',
		];
		$pages['maintenance'] = [
			'title' => __( 'Maintenance', 'rich-statistics' ),
			'label' => __( 'Maintenance', 'rich-statistics' ),
			'cap'   => 'rsa_manage_statistics',
		];
		$pages['install']     = [
			'title' => __( 'Install App', 'rich-statistics' ),
			'label' => __( 'Install App', 'rich-statistics' ),
			'cap'   => 'rsa_manage_statistics',
		];
		return $pages;
	}

	// ----------------------------------------------------------------
	// Asset enqueuing.
	// ----------------------------------------------------------------

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		// Only load on our own pages.
		if ( false === strpos( $hook, 'rich-statistics' )
			&& false === strpos( $hook, 'rich-stats' ) ) {
			return;
		}

		// Chart.js (bundled — no CDN).
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

		// Expose PHP data for the current page.
		$page_data = self::get_page_data_for_current_screen( $hook );
		wp_localize_script( 'rsa-admin-charts', 'RSA_DATA', $page_data );
	}

	/**
	 * Get page data for the current admin screen.
	 *
	 * @param string $hook The current admin page hook.
	 * @return array Page data for the view.
	 */
	private static function get_page_data_for_current_screen( string $hook ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- admin display page; GET params control display filters only, no state changes
		$period          = sanitize_text_field( wp_unslash( $_GET['period'] ?? '30d' ) );
		$allowed_periods = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
		if ( ! in_array( $period, $allowed_periods, true ) ) {
			$period = '30d';
		}

		$date_from = $date_to = '';
		if ( 'custom' === $period ) {
			$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
			$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
				$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) ); }
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
				$date_to = gmdate( 'Y-m-d' ); }
		}

		$page_filters = [
			'browser'   => sanitize_text_field( wp_unslash( $_GET['browser'] ?? '' ) ),
			'os'        => sanitize_text_field( wp_unslash( $_GET['os'] ?? '' ) ),
			'search'    => sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) ),
			'page'      => sanitize_text_field( wp_unslash( $_GET['ref_page'] ?? '' ) ),
			'sort'      => in_array( $_GET['sort'] ?? '', [ 'views', 'avg_time' ], true ) ? sanitize_key( $_GET['sort'] ) : 'views',
			'sort_dir'  => ( 'asc' === sanitize_key( wp_unslash( $_GET['sort_dir'] ?? 'desc' ) ) ) ? 'asc' : 'desc',
			'date_from' => $date_from,
			'date_to'   => $date_to,
		];

		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-pages' ) ) {
			$pf         = $page_filters;
			$pf['page'] = sanitize_text_field( wp_unslash( $_GET['path'] ?? '' ) );
			return [
				'view'   => 'pages',
				'data'   => RSA_Analytics::get_top_pages( $period, 20, $pf ),
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-audience' ) ) {
			return [
				'view'   => 'audience',
				'data'   => RSA_Analytics::get_audience( $period, $page_filters ),
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-referrers' ) ) {
			$ref_filters = [ 'page' => $page_filters['page'] ];
			return [
				'view'   => 'referrers',
				'data'   => RSA_Analytics::get_referrers( $period, 20, $ref_filters ),
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-campaigns' ) ) {
			if ( ! function_exists( 'rs_fs' ) || ! rs_fs()->can_use_premium_code__premium_only() ) {
				return [ 'view' => 'campaigns', 'data' => [], 'mediums' => [], 'period' => $period ];
			}
			$medium      = sanitize_text_field( wp_unslash( $_GET['utm_medium'] ?? '' ) );
			$cam_filters = [
				'medium'    => $medium,
				'date_from' => $date_from,
				'date_to'   => $date_to,
			];
			return [
				'view'    => 'campaigns',
				'data'    => RSA_Analytics::get_campaigns( $period, 100, $cam_filters ),
				'mediums' => RSA_Analytics::get_utm_mediums( $period ),
				'period'  => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-behavior' ) ) {
			$beh_filters = [
				'browser'   => $page_filters['browser'],
				'os'        => $page_filters['os'],
				'date_from' => $date_from,
				'date_to'   => $date_to,
			];
			$beh_data    = RSA_Analytics::get_behavior( $period, $beh_filters );
			return [
				'view'   => 'behavior',
				'data'   => $beh_data,
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-user-flow' ) ) {
			if ( ! function_exists( 'rs_fs' ) || ! rs_fs()->can_use_premium_code__premium_only() ) {
				return [ 'view' => 'user-flow', 'data' => [ 'path_flow' => [] ], 'period' => $period ];
			}
			$entry_source = sanitize_text_field( wp_unslash( $_GET['entry_source'] ?? '' ) );
			$focus_page   = sanitize_text_field( wp_unslash( $_GET['focus_page'] ?? '' ) );
			$min_sessions = max( 1, absint( $_GET['min_sessions'] ?? 1 ) );
			$steps        = min( 5, max( 2, absint( $_GET['steps'] ?? 4 ) ) );
			return [
				'view'   => 'user-flow',
				'data'   => [
					'path_flow' => RSA_Analytics::get_path_flow(
						$period,
						[
							'date_from'    => $date_from,
							'date_to'      => $date_to,
							'entry_source' => $entry_source,
							'focus_page'   => $focus_page,
							'min_sessions' => $min_sessions,
							'steps'        => $steps,
						]
					),
				],
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-click-map' ) ) {
			if ( ! function_exists( 'rs_fs' ) || ! rs_fs()->can_use_premium_code__premium_only() ) {
				return [ 'view' => 'click-map', 'data' => [], 'period' => $period ];
			}
			$page = sanitize_text_field( wp_unslash( $_GET['page_filter'] ?? '' ) );
			return [
				'view'   => 'click-map',
				'data'   => RSA_Analytics::get_click_map( $period, $page ),
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-heatmap' ) ) {
			if ( ! function_exists( 'rs_fs' ) || ! rs_fs()->can_use_premium_code__premium_only() ) {
				return [ 'view' => 'heatmap', 'data' => [], 'period' => $period ];
			}
			$page = sanitize_text_field( wp_unslash( $_GET['page_filter'] ?? '' ) );
			return [
				'view'   => 'heatmap',
				'data'   => RSA_Analytics::get_heatmap( $page, $period ),
				'period' => $period,
			];
		}
		if ( str_contains( $hook, 'rich-statistics_page_rich-statistics-woocommerce' ) ) {
			if ( ! function_exists( 'rs_fs' ) || ! rs_fs()->can_use_premium_code__premium_only() ) {
				return [ 'view' => 'woocommerce', 'data' => [], 'period' => $period ];
			}
			return [
				'view'   => 'woocommerce',
				'data'   => RSA_Analytics::get_woocommerce(
					$period,
					[
						'date_from' => $date_from,
						'date_to'   => $date_to,
					]
				),
				'period' => $period,
			];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Default: overview.
		return [
			'view'   => 'overview',
			'data'   => RSA_Analytics::get_overview( $period, $page_filters ),
			'period' => $period,
		];
	}

	// ----------------------------------------------------------------
	// Page renderers — each delegates to a template partial.
	// ----------------------------------------------------------------

	/** Render the overview page. */
	public static function page_overview(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'overview' ); }
	/** Render the pages view. */
	public static function page_pages(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'pages' ); }
	/** Render the audience view. */
	public static function page_audience(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'audience' ); }
	/** Render the referrers view. */
	public static function page_referrers(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'referrers' ); }
	/** Render the campaigns view. */
	public static function page_campaigns(): void {
		self::require_premium_or_exit();
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'campaigns' ); }
	/** Render the behavior view. */
	public static function page_behavior(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'behavior' ); }
	/** Render the user flow view. */
	public static function page_user_flow(): void {
		self::require_premium_or_exit();
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'user-flow' ); }
	/** Render the click map view. */
	public static function page_click_map(): void {
		self::require_premium_or_exit();
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'click-map' ); }
	/** Render the heatmap view. */
	public static function page_heatmap(): void {
		self::require_premium_or_exit();
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'heatmap' ); }
	/** Render the preferences page. */
	public static function page_preferences(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'preferences' ); }
	/** Render the maintenance page. */
	public static function page_maintenance(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'maintenance' ); }
	/** Render the export page. */
	public static function page_export(): void {
		self::require_premium_or_exit();
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'export' ); }
	/** Render the WooCommerce page. */
	public static function page_woocommerce(): void {
		self::require_premium_or_exit();
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'woocommerce' ); }
	/** Render the network dashboard page. */
	public static function page_network_dashboard(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'network-dashboard' ); }
	/** Render the network settings page. */
	public static function page_network_settings(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'network-settings' ); }
	/** Render the AI chat page (redirects to the Overview page). Kept for backward compat — may have been bookmarked. */
	public static function page_ai_chat(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=rich-statistics-overview' ) );
		exit; }
	/** Render the install page. */
	public static function page_install(): void {
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		self::render( 'install' ); }

	/** Require a premium license or exit with an error. */
	private static function require_premium_or_exit(): void {
		if ( ! function_exists( 'rs_fs' ) || ! rs_fs()->can_use_premium_code__premium_only() ) {
			wp_die( esc_html__( 'This feature requires a premium licence.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Render an admin template.
	 *
	 * @param string $template Template slug.
	 */
	private static function render( string $template ): void {
		// Whitelist check — prevents path traversal if any caller passes
		// user-controlled input as the template slug.
		$allowed = [
			'overview', 'pages', 'audience', 'referrers', 'campaigns',
			'behavior', 'user-flow', 'click-map', 'heatmap', 'preferences',
			'maintenance', 'export', 'woocommerce', 'network-dashboard',
			'network-settings', 'install',
		];
		if ( ! in_array( $template, $allowed, true ) ) {
			return;
		}
		$file = RSA_DIR . 'templates/admin/' . $template . '.php';
		if ( file_exists( $file ) ) {
			include $file;
		}
	}

	// ----------------------------------------------------------------
	// Page dropdown helper — all public WordPress content.
	// ----------------------------------------------------------------

	/**
	 * Get all trackable pages (public post types, all non-trash statuses).
	 *
	 * @param int $limit  Maximum posts to return. -1 for unlimited (default).
	 * @param int $offset Number of posts to skip (default 0).
	 * @return array Associative array of path => title.
	 */
	public static function get_trackable_pages( int $limit = -1, int $offset = 0 ): array {
		// All public post types, all non-trash statuses — same source used
		// for purge eligibility checks so the two stay in sync automatically.
		$post_types = array_diff(
			array_keys( get_post_types( [ 'public' => true ] ) ),
			[ 'attachment' ]
		);

		// WordPress get_posts ignores offset when numberposts is -1.
		$effective_offset = ( $limit > 0 ) ? $offset : 0;

		$posts = get_posts(
			[
				'post_type'     => $post_types,
				'post_status'   => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'numberposts'   => $limit,
				'offset'        => $effective_offset,
				'no_found_rows' => true,
				'orderby'       => 'post_title',
				'order'         => 'ASC',
			]
		);

		// Home is always pinned first.
		$pages = [ '/' => __( 'Home', 'rich-statistics' ) ];

		foreach ( $posts as $post ) {
			$url = get_permalink( $post );
			if ( ! $url ) {
				continue; }
			$path = wp_make_link_relative( $url );
			// Skip query-string-only URLs (e.g. ?p=123 for un-slugged drafts).
			if ( ! $path || '/' !== $path[0] || false !== strpos( $path, '?' ) ) {
				continue; }
			if ( '/' === $path ) {
				continue; } // home already added
			$pages[ $path ] = get_the_title( $post );
		}

		// Keep home pinned at top; sort the rest alphabetically by path.
		$home = [ '/' => $pages['/'] ];
		unset( $pages['/'] );
		ksort( $pages );
		return $home + $pages;
	}

	// ----------------------------------------------------------------
	// CSV Export handler.
	// ----------------------------------------------------------------

	/** Handle CSV export request. */
	public static function handle_export_csv(): void {
		check_admin_referer( 'rsa_export_csv' );
		if ( ! function_exists( 'rs_fs' ) || ! rs_fs()->can_use_premium_code__premium_only() ) {
			wp_die( esc_html__( 'This feature requires a premium licence.', 'rich-statistics' ), '', [ 'response' => 403 ] );
		}
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rich-statistics' ) );
		}

		global $wpdb;

		$data_type = sanitize_key( wp_unslash( $_POST['data_type'] ?? 'pageviews' ) );
		$period    = sanitize_text_field( wp_unslash( $_POST['period'] ?? '30d' ) );
		$allowed   = [ '7d', '30d', '90d', 'thismonth', 'lastmonth', 'custom' ];
		if ( ! in_array( $period, $allowed, true ) ) {
			$period = '30d'; }

		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = ''; }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = ''; }

		$range = RSA_Analytics::period_range( $period, $date_from, $date_to );
		$bt    = (int) get_option( 'rsa_bot_score_threshold', 5 );

		switch ( $data_type ) {
			case 'sessions':
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from RSA_DB helper, not user input
				$rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CSV export on demand
						'SELECT session_id, entry_page, exit_page, pages_viewed, total_time, browser, os, language, timezone, created_at FROM ' . RSA_DB::sessions_table() . ' WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC',
						$range['start'],
						$range['end']
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$headers = [ 'session_id', 'entry_page', 'exit_page', 'pages_viewed', 'total_time', 'browser', 'os', 'language', 'timezone', 'created_at' ];
				break;
			case 'clicks':
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from RSA_DB helper, not user input
				$rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CSV export on demand
						'SELECT session_id, page, element_tag, element_id, element_class, element_text, href_protocol, matched_rule, x_pct, y_pct, created_at FROM ' . RSA_DB::clicks_table() . ' WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC',
						$range['start'],
						$range['end']
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$headers = [ 'session_id', 'page', 'element_tag', 'element_id', 'element_class', 'element_text', 'href_protocol', 'matched_rule', 'x_pct', 'y_pct', 'created_at' ];
				break;
			case 'referrers':
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from RSA_DB helper, not user input
				$rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CSV export on demand
						'SELECT referrer_domain, COUNT(*) AS pageviews, COUNT(DISTINCT session_id) AS sessions FROM ' . RSA_DB::events_table() . ' WHERE created_at BETWEEN %s AND %s AND bot_score < %d GROUP BY referrer_domain ORDER BY pageviews DESC',
						$range['start'],
						$range['end'],
						$bt
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$headers = [ 'referrer_domain', 'pageviews', 'sessions' ];
				break;
			default: // pageviews
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from RSA_DB helper, not user input
				$rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CSV export on demand
						'SELECT session_id, page, referrer_domain, os, browser, browser_version, language, timezone, viewport_w, viewport_h, time_on_page, bot_score, created_at FROM ' . RSA_DB::events_table() . ' WHERE created_at BETWEEN %s AND %s AND bot_score < %d ORDER BY created_at DESC',
						$range['start'],
						$range['end'],
						$bt
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$headers = [ 'session_id', 'page', 'referrer_domain', 'os', 'browser', 'browser_version', 'language', 'timezone', 'viewport_w', 'viewport_h', 'time_on_page', 'bot_score', 'created_at' ];
		}

		$filename = 'rich-statistics-' . $data_type . '-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel; php://output stream cannot use WP_Filesystem
		fputcsv( $out, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_values( $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream cannot use WP_Filesystem
		exit;
	}

	// ----------------------------------------------------------------
	// Settings save handler.
	// ----------------------------------------------------------------

	/** Save plugin settings. */
	public static function save_settings(): void {
		check_admin_referer( 'rsa_settings_save' );
		if ( ! current_user_can( 'rsa_manage_statistics' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rich-statistics' ) );
		}

		$fields = [
			'rsa_retention_days'           => 'absint',
			'rsa_bot_score_threshold'      => 'absint',
			'rsa_remove_data_on_uninstall' => 'absint',
			'rsa_track_protocol_tel'       => 'absint',
			'rsa_track_protocol_mailto'    => 'absint',
			'rsa_track_protocol_geo'       => 'absint',
			'rsa_track_protocol_sms'       => 'absint',
			'rsa_track_protocol_download'  => 'absint',
			'rsa_click_track_ids'          => 'sanitize_text_field',
			'rsa_click_track_classes'      => 'sanitize_text_field',
			'rsa_email_digest_enabled'     => 'absint',
			'rsa_email_digest_frequency'   => 'sanitize_text_field',
			'rsa_email_digest_recipients'  => 'sanitize_text_field',
			'rsa_email_digest_use_roles'   => 'absint',
			'rsa_woocommerce_enabled'      => 'absint',
			'rsa_beta_channel'             => 'absint',
			'rsa_consent_banner'           => 'absint',
			'rsa_consent_auto'             => 'absint',
			'rsa_consent_styles'           => [ __CLASS__, 'sanitize_json_field' ],
			'rsa_consent_banner_text'      => 'sanitize_textarea_field',
		];

		foreach ( $fields as $key => $sanitizer ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = $sanitizer( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $sanitizer is a sanitization callback from the $fields map
				// Clamp numeric values.
				if ( 'rsa_retention_days' === $key ) {
					$value = max( 1, min( 730, $value ) );
				}
				if ( 'rsa_bot_score_threshold' === $key ) {
					$value = max( 1, min( 10, $value ) );
				}
				update_option( $key, $value );
			} elseif ( in_array( $sanitizer, [ 'absint' ], true ) ) {
				// Checkboxes: unchecked = 0.
				update_option( $key, 0 );
			}
		}

		// Custom post types array — sanitize each slug.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array is unslashed and each element sanitized via array_map below
		$raw_cpts = isset( $_POST['rsa_enabled_post_types'] ) && is_array( $_POST['rsa_enabled_post_types'] )
			? array_map( 'wp_unslash', $_POST['rsa_enabled_post_types'] )
			: [];
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$safe_cpts = array_values( array_filter( array_map( 'sanitize_key', $raw_cpts ) ) );
		update_option( 'rsa_enabled_post_types', $safe_cpts );

		// Allowed roles for app access (REST API + profile OTP).
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_roles = isset( $_POST['rsa_allowed_roles'] ) && is_array( $_POST['rsa_allowed_roles'] )
			? array_map( 'wp_unslash', $_POST['rsa_allowed_roles'] )
			: [];
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$valid_roles = array_keys( wp_roles()->roles );
		$safe_roles  = array_values(
			array_filter(
				array_map( 'sanitize_key', $raw_roles ),
				function ( $role ) use ( $valid_roles ) {
					// Administrators: always allowed, never stored in option.
					return 'administrator' !== $role && in_array( $role, $valid_roles, true );
				}
			)
		);
		update_option( 'rsa_allowed_roles', $safe_roles );

		// Sync beta channel preference with Freemius.
		// Errors are suppressed so a slow / unavailable Freemius API never
		// white-screens the settings save page.
		if ( function_exists( 'rs_fs' ) && rs_fs()->is_connected() ) {
			try {
				$is_beta = get_option( 'rsa_beta_channel' ) ? 'true' : 'false';
				// Call the same Freemius API endpoint that the AJAX handler uses.
				rs_fs()->get_api_site_scope()->call(
					'/plugin-tags/beta-mode.json',
					'put',
					[
						'is_beta' => $is_beta,
						'fields'  => 'is_beta',
					]
				);
			} catch ( \Exception $e ) {
				// Freemius API failure must not block settings save.
				unset( $e );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'  => 'rich-statistics-preferences',
					'saved' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ----------------------------------------------------------------
	// App access: per-role permission check.
	// ----------------------------------------------------------------

	/**
	 * Returns true when the given user (default: current user) has a role
	 * that is permitted to use the Rich Statistics App (REST API + OTP).
	 * Administrators are always allowed regardless of the stored option.
	 *
	 * @param ?WP_User $user Optional. User object to check. Defaults to current user.
	 * @return bool True if the user can access the app.
	 */
	public static function user_can_access_app( ?WP_User $user = null ): bool {
		if ( ! $user ) {
			$user = wp_get_current_user();
		}
		if ( ! $user || ! $user->ID ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}
		$allowed = (array) get_option( 'rsa_allowed_roles', [ 'administrator' ] );
		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	// ----------------------------------------------------------------
	// Profile: Rich Statistics App section (before Application Passwords).
	// ----------------------------------------------------------------

	/**
	 * Output a full profile section. Hooks show_user_profile / edit_user_profile
	 * fire outside any table context, so we provide the <h2> + table wrapper.
	 *
	 * @param WP_User $profile_user The user being edited.
	 */
	public static function profile_webapp_section( WP_User $profile_user ): void {
		// Show only if the profile user's role is permitted to use the app.
		if ( ! self::user_can_access_app( $profile_user ) ) {
			return;
		}
		// When editing another user, require the viewing user to be an admin or Statistics Analyst.
		if ( $profile_user->ID !== get_current_user_id() && ! current_user_can( 'rsa_manage_statistics' ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Rich Statistics App', 'rich-statistics' ); ?></h2>
		<table class="form-table" role="presentation"><tbody>
		<?php self::profile_webapp_button( $profile_user ); ?>
		</tbody></table>
		<?php
	}

	/**
	 * Render the web app button in the user profile.
	 *
	 * @internal Called from profile_webapp_section(); also used standalone in unit tests.
	 *
	 * @param WP_User $profile_user The user being edited.
	 */
	public static function profile_webapp_button( WP_User $profile_user ): void {
		if ( ! self::user_can_access_app( $profile_user ) ) {
			return;
		}
		// Only show auth key (App Code) to administrators.
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

		$app_url = RSA_APP_URL;
		?>
		<tr class="rsa-webapp-row">
			<th scope="row"><?php esc_html_e( 'Rich Statistics App', 'rich-statistics' ); ?></th>
			<td>
				<button type="button" id="rsa-generate-otp-btn" class="button button-primary">
					<?php esc_html_e( 'Generate App Code', 'rich-statistics' ); ?>
				</button>
				<a href="<?php echo esc_url( $app_url ); ?>"
					class="button"
					target="_blank"
					rel="noopener"
					style="margin-left:8px;">
					<?php esc_html_e( 'Open App', 'rich-statistics' ); ?>
				</a>

				<div id="rsa-otp-display" style="display:none;margin-top:14px;" aria-live="polite" aria-atomic="true">
					<p style="margin:0 0 4px;display:flex;align-items:center;gap:10px;">
						<strong><?php esc_html_e( 'App Code:', 'rich-statistics' ); ?></strong>
						<span id="rsa-otp-code"
								style="font-family:monospace;font-size:1.6em;letter-spacing:.12em;"
						></span>
						<button type="button" id="rsa-otp-copy" class="button button-small">
							<?php esc_html_e( 'Copy', 'rich-statistics' ); ?>
						</button>
					</p>
					<p class="description" style="margin:4px 0;">
						<?php
						printf(
							/* translators: %s is replaced by the countdown timer element */
							esc_html__( 'Expires in %s — enter this code in the app when adding this site.', 'rich-statistics' ),
							'<span id="rsa-otp-timer" aria-live="off">15:00</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static trusted HTML
						);
						?>
					</p>
				</div>

				<p class="description" style="margin-top:10px;">
					<?php esc_html_e( 'Click "Open App" to launch the Stats App. On first visit, click "Generate App Code", tap "Add Site" in the app, enter this site\'s URL and the code, then create an Application Password in the section below to complete the connection.', 'rich-statistics' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	// ----------------------------------------------------------------
	// Shared: period selector HTML (used by templates).
	// ----------------------------------------------------------------

	/**
	 * Render the period selector HTML.
	 *
	 * @param string $current Current period value.
	 * @return string Period selector HTML.
	 */
	public static function period_selector( string $current = '30d' ): string {
		$options = [
			'7d'        => __( 'Last 7 days', 'rich-statistics' ),
			'30d'       => __( 'Last 30 days', 'rich-statistics' ),
			'90d'       => __( 'Last 90 days', 'rich-statistics' ),
			'thismonth' => __( 'This month', 'rich-statistics' ),
			'lastmonth' => __( 'Last month', 'rich-statistics' ),
		];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- period_selector only reads GET params for display; no state changes
		$page      = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'rich-statistics' ) );
		$url       = admin_url( 'admin.php' );
		$is_custom = ( 'custom' === $current );
		// Always populate dates so the inputs are pre-filled regardless of mode.
		$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) ); }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = gmdate( 'Y-m-d' ); }

		$html = '<div class="rsa-period-controls">';

		// Preset period buttons (navigational links).
		$html .= '<div class="rsa-period-selector">';
		foreach ( $options as $val => $label ) {
			$href   = add_query_arg(
				[
					'page'   => $page,
					'period' => $val,
				],
				$url
			);
			$active = $val === $current ? ' rsa-period-active' : '';
			$html  .= '<a href="' . esc_url( $href ) . '" class="rsa-period-btn' . $active . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</div>';

		// Custom date range — always visible, no JS toggle.
		$custom_active = $is_custom ? ' rsa-period-active' : '';
		$html         .= '<div class="rsa-custom-range">';
		$html         .= '<form method="get" action="' . esc_url( $url ) . '" class="rsa-custom-range-form">';
		$html         .= '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		$html         .= '<input type="hidden" name="period" value="custom">';
		$html         .= '<input type="date" name="date_from" value="' . esc_attr( $date_from ) . '" max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" aria-label="' . esc_attr__( 'From date', 'rich-statistics' ) . '">';
		$html         .= '<span class="rsa-date-sep">' . esc_html__( 'to', 'rich-statistics' ) . '</span>';
		$html         .= '<input type="date" name="date_to" value="' . esc_attr( $date_to ) . '" max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" aria-label="' . esc_attr__( 'To date', 'rich-statistics' ) . '">';
		$html         .= '<button type="submit" class="rsa-period-btn' . $custom_active . '">' . esc_html__( 'Apply', 'rich-statistics' ) . '</button>';
		$html         .= '</form>';
		$html         .= '</div>';

		$html .= '</div>'; // .rsa-period-controls

		return $html;
	}

	// ----------------------------------------------------------------
	// Shared: page header (used by templates).
	// ----------------------------------------------------------------

	/**
	 * Render the page header with title and period selector.
	 *
	 * @param string $title  Page title.
	 * @param string $period Current period.
	 */
	public static function page_header( string $title, string $period = '30d' ): void {
		?>
		<div class="wrap rsa-wrap">
			<div class="rsa-header">
				<h1 class="rsa-title">
					<span class="rsa-logo" aria-hidden="true">📊</span>
					<?php echo esc_html( $title ); ?>
				</h1>
				<?php echo self::period_selector( $period ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( function_exists( 'rs_fs' ) && rs_fs()->is_not_paying() ) : ?>
			<div class="rsa-upsell-banner">
				<div class="rsa-upsell-banner__content">
					<strong><?php esc_html_e( 'Unlock Campaigns, User Flow, Click Tracking, Heatmap &amp; Export', 'rich-statistics' ); ?></strong>
					<?php esc_html_e( 'See exactly where visitors come from, where they go, what they click, where they drop off, and export your raw data.', 'rich-statistics' ); ?>
				</div>
				<a href="<?php echo esc_url( rs_fs()->get_upgrade_url() ); ?>" class="button button-primary">
					<?php esc_html_e( 'Upgrade Now', 'rich-statistics' ); ?>
				</a>
			</div>
			<?php endif; ?>
		<?php
	}

	/** Render the page footer. */
	public static function page_footer(): void {
		echo '</div><!-- .rsa-wrap -->';
	}

	// ----------------------------------------------------------------
	// Help tabs — appear in the upper-right "Help" dropdown on each page.
	// ----------------------------------------------------------------

	/** Register help tabs for the admin screens. */
	public static function register_help_tabs(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'rich-statistics' ) ) {
			return;
		}

		// Sidebar shown on all Rich Stats pages.
		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'rich-statistics' ) . '</strong></p>' .
			'<p><a href="https://statistics.richardkentgates.com" target="_blank" rel="noopener">' .
			esc_html__( 'Plugin website', 'rich-statistics' ) . '</a></p>' .
			'<p><a href="https://github.com/richardkentgates/rich-statistics/wiki" target="_blank" rel="noopener">' .
			esc_html__( 'Documentation wiki', 'rich-statistics' ) . '</a></p>' .
			'<p><a href="https://github.com/richardkentgates/rich-statistics/issues" target="_blank" rel="noopener">' .
			esc_html__( 'Report an issue', 'rich-statistics' ) . '</a></p>'
		);

		// Shared tab: Getting Started.
		$screen->add_help_tab(
			[
				'id'      => 'rsa-getting-started',
				'title'   => __( 'Getting Started', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Getting Started with Rich Statistics', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Once activated, navigate to Rich Statistics in the admin sidebar to view your data. The dashboard starts collecting pageviews immediately — no configuration required.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Key Settings:', 'rich-statistics' ) . '</strong></p>' .
					'<ul>' .
					'<li>' . esc_html__( 'Data retention: Rich Statistics → Preferences → Data Retention', 'rich-statistics' ) . '</li>' .
					'<li>' . esc_html__( 'Bot filtering sensitivity: Rich Statistics → Preferences → Tracking', 'rich-statistics' ) . '</li>' .
					'<li>' . esc_html__( 'Email digests: Rich Statistics → Preferences → Email Reports', 'rich-statistics' ) . '</li>' .
					'<li>' . esc_html__( 'App access: Rich Statistics → Preferences → App Access', 'rich-statistics' ) . '</li>' .
					'<li>' . esc_html__( 'Multisite: Network Admin → Rich Statistics for cross-site overview', 'rich-statistics' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'Premium features (click tracking, heatmaps, WooCommerce, REST API) show an upgrade prompt on their respective pages.', 'rich-statistics' ) . '</p>',
			]
		);

		// Shared tab: Privacy.
		$screen->add_help_tab(
			[
				'id'      => 'rsa-privacy',
				'title'   => __( 'Privacy', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Privacy by Design', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Rich Statistics does not store personally identifiable information. Sessions are identified by a UUID stored in sessionStorage (not cookies) that is cleared when the browser tab closes. IP addresses are never stored. Referrer URLs are truncated to domain only.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'If a visitor\'s browser has Do Not Track (DNT) or Global Privacy Control (GPC) enabled, the tracker exits immediately — no session is created and no data is sent.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Because no PII is collected, most sites using Rich Statistics do not require a cookie consent banner for the analytics data collected by this plugin. Always consult a qualified lawyer for advice specific to your situation.', 'rich-statistics' ) . '</p>',
			]
		);

		// Page-specific help tab content.
		$page_help = [
			'toplevel_page_rich-statistics'                => [
				'id'      => 'rsa-overview-help',
				'title'   => __( 'Overview', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Overview Dashboard', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'The overview shows your total pageviews, unique sessions, average time on page, and bounce rate for the selected period. The daily chart lets you spot traffic trends at a glance.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Use the period selector (top right) to switch between Last 7 days, Last 30 days, Last 90 days, This month, and Last month.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Bounce rate', 'rich-statistics' ) . '</strong> ' . esc_html__( 'is calculated as the percentage of sessions that viewed only a single page.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-pages'   => [
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
					'<p>' . esc_html__( 'Viewport buckets: Mobile (<640px), Tablet (640–1023px), Desktop (1024–1439px), Wide (≥1440px).', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Language codes follow the BCP 47 standard as reported by navigator.language (e.g. en-US, fr-FR, de).', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-referrers' => [
				'id'      => 'rsa-referrers-help',
				'title'   => __( 'Referrers', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Referrer Tracking', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Only the referring domain is stored — never the full URL. This prevents leaking of personal data that might appear in referrer URLs (e.g. search queries, email campaign tokens).', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Direct traffic, bookmark visits, and traffic from HTTPS sites to your HTTP site appear as "(direct)" because browsers do not send a Referer header in these cases.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-campaigns' => [
				'id'      => 'rsa-campaigns-help',
				'title'   => __( 'Campaigns', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'UTM Campaign Tracking', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Campaigns are tracked automatically when visitors land on your site with UTM parameters in the URL (utm_source, utm_medium, utm_campaign). The values are captured from the landing-page URL and attributed to every pageview in that browser session.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Use the Medium filter to isolate traffic from a specific channel (e.g. email, cpc, social).', 'rich-statistics' ) . '</p>',
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
					'<p>' . esc_html__( 'The Path Explorer shows page-to-page navigation in Miller columns. Each column is one step in the journey — click any page to drill forward and see where visitors went next. A drop-off funnel above the columns shows how many sessions reached each step.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Percentages in each column are relative to the selected page in the previous column, so they reflect "of visitors who reached this page, N% continued to…". Switch to the Journey Table view to browse every recorded page pair with counts and percentages.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-click-map' => [
				'id'      => 'rsa-clicks-help',
				'title'   => __( 'Click Tracking (Premium)', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Click Tracking (Premium)', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Click tracking captures non-navigational interactions: phone links (tel:), email links (mailto:), map links (geo:), SMS links (sms:), and file downloads. HTTP/HTTPS link navigation is tracked automatically as pageviews. You can add additional element IDs and CSS classes in Preferences.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'The Destination column shows the actual value supplied to the protocol handler: the phone number for tel: links, the email address for mailto: links, the coordinates for geo: links, the SMS number for sms: links, and the file path or URL for downloads.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Click tracking uses event delegation — no inline event handlers are added to the page.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-heatmap' => [
				'id'      => 'rsa-heatmap-help',
				'title'   => __( 'Heatmap (Premium)', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Heatmap (Premium)', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'The heatmap displays a dark canvas showing scroll-depth guide lines and click-hotspot dots for any tracked page. Dot colour ranges from cool blue (few clicks) to hot red (many clicks). Hover a dot to see which DOM elements were clicked at that position. Coordinates are stored as viewport-relative percentages so the heatmap works at any screen size.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'The side panel lists the top-clicked elements with a click bar chart — useful for identifying links and buttons that get the most engagement.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Use the Period selector to change the date range. Custom start/end dates are supported. Raw click coordinates are aggregated into a 2% grid nightly by a background cron task to keep storage efficient.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-woocommerce' => [
				'id'      => 'rsa-woocommerce-help',
				'title'   => __( 'WooCommerce Analytics (Premium)', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'WooCommerce Analytics (Premium)', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Automatically tracks product views, add-to-cart events (standard and AJAX), and order completions. Surfaces a conversion funnel, top products table, and a revenue-over-time chart.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'No customer data is stored — events are linked to anonymous session IDs only. Order totals are recorded in the store currency but no customer name, address, or email is retained.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'WooCommerce tracking can be toggled on or off from Rich Statistics → Preferences.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-export'  => [
				'id'      => 'rsa-export-help',
				'title'   => __( 'Export (Premium)', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'CSV Export (Premium)', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Download raw events, sessions, or click data as a CSV file for any date range you choose. Use it with spreadsheet software, R, Python, or any business intelligence tool.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Exports are generated on demand — they are not cached. Large date ranges on high-traffic sites may take a moment to compile.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-preferences' => [
				'id'      => 'rsa-preferences-help',
				'title'   => __( 'Preferences', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Preferences', 'rich-statistics' ) . '</h2>' .
					'<p><strong>' . esc_html__( 'Bot score threshold', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'Requests scoring at or above this value are silently discarded as automated traffic. Range: 1–10. Default: 5. Lower values filter more aggressively.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Data retention', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'Events older than the retention period are pruned nightly. Range: 1–730 days. Default: 90 days. Disable pruning to keep all data indefinitely.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Email digests', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'Configure a periodic summary email (daily, weekly, or monthly). Sent via wp_mail — no third-party email service required. Enter comma-separated addresses for multiple recipients.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Insights panel', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'The Overview page now includes a free Insights section derived directly from your analytics data. No LLM or API key needed — insight cards are computed server-side from your metrics. For conversational AI, use the Rich Statistics PWA with your own AI provider configured in the app\'s settings.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Remove data on uninstall', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'When enabled, all plugin data and options are permanently deleted when you remove the plugin. This action cannot be undone.', 'rich-statistics' ) . '</p>',
			],
			'rich-statistics_page_rich-statistics-maintenance' => [
				'id'      => 'rsa-maintenance-help',
				'title'   => __( 'Maintenance', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Maintenance', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'Lists every distinct page path recorded in the database. Live paths correspond to published pages or posts on your site. Unmatched paths belong to deleted pages, renamed URLs, or test paths that are no longer active.', 'rich-statistics' ) . '</p>' .
					'<p>' . esc_html__( 'Use the Purge button to permanently delete all events, click records, and heatmap data for a specific path. This is useful for removing test traffic, redirect chains, or data for pages you have since deleted. Purging cannot be undone.', 'rich-statistics' ) . '</p>',
			],
			'toplevel_page_rich-statistics-network'        => [
				'id'      => 'rsa-network-help',
				'title'   => __( 'Network Dashboard', 'rich-statistics' ),
				'content' =>
					'<h2>' . esc_html__( 'Network Dashboard', 'rich-statistics' ) . '</h2>' .
					'<p>' . esc_html__( 'The network dashboard shows analytics KPIs for every sub-site in your WordPress multisite network. The table displays pageviews, sessions, and bounce rate for the last 30 days. Click a site name to open its individual dashboard.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Cross-Site AI Assistant', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'Configure your AI provider (endpoint, API key, model) in the settings panel, then ask questions about any site in your network. The AI fetches data from each site\'s REST API and generates conversational answers. Voice input and output are supported in compatible browsers.', 'rich-statistics' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Premium gating', 'rich-statistics' ) . '</strong> — ' . esc_html__( 'The dashboard itself is free for all multisite networks. Premium tools (campaigns, user-flow, clicks, heatmap, woocommerce) are gated per-site based on each sub-site\'s own Freemius licence. The AI tool endpoint handles this automatically.', 'rich-statistics' ) . '</p>',
			],
		];

		if ( isset( $page_help[ $screen->id ] ) ) {
			$screen->add_help_tab( $page_help[ $screen->id ] );
		}
	}

	/**
	 * Sanitize a JSON string by parsing and re-encoding it.
	 * Returns '{}' if the input is invalid JSON.
	 *
	 * @param string $json Raw JSON string.
	 * @return string Sanitized JSON string.
	 */
	public static function sanitize_json_field( string $json ): string {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return '{}';
		}
		return wp_json_encode( $decoded );
	}
}