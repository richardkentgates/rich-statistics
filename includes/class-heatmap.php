<?php
/**
 * [PREMIUM] Heatmap — admin asset enqueuing for the heatmap page.
 * The canvas overlay renderer is in assets/js/heatmap-overlay.js.
 *
 * @package RichStatistics
 * @fs_premium_only
 *
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class RSA_Heatmap {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_heatmap_assets' ] );
	}

	public static function enqueue_heatmap_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'rich-statistics-heatmap' ) ) {
			return;
		}

		$css = RSA_DIR . 'assets/css/heatmap.css';
		wp_enqueue_style(
			'rsa-heatmap',
			RSA_ASSETS_URL . 'css/heatmap.css',
			[ 'rsa-admin' ],
			(string) ( file_exists( $css ) ? filemtime( $css ) : RSA_VERSION )
		);

		$js = RSA_DIR . 'assets/js/heatmap-overlay.js';
		wp_enqueue_script(
			'rsa-heatmap-overlay',
			RSA_ASSETS_URL . 'js/heatmap-overlay.js',
			[],
			(string) ( file_exists( $js ) ? filemtime( $js ) : RSA_VERSION ),
			true
		);
	}
}
