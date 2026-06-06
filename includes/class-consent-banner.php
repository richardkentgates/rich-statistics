<?php
/**
 * Consent Banner
 *
 * Renders the visitor consent banner on the frontend when enabled.
 * Injects banner HTML, inline <style> block with CSS custom properties,
 * and a return button for collapsed state.
 *
 * @package RichStatistics
 */

defined( 'ABSPATH' ) || exit;

class RSA_Consent_Banner {

	/**
	 * Register hooks. Exits early if banner is disabled.
	 */
	public static function init(): void {
		if ( ! get_option( 'rsa_consent_banner' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render' ], 999 );
	}

	/**
	 * Enqueue inline styles with CSS custom properties from admin settings.
	 */
	public static function enqueue(): void {
		$styles = json_decode( get_option( 'rsa_consent_styles', '{}' ), true );
		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		$css = self::build_css( $styles );
		wp_add_inline_style( 'rsa-tracker', $css );
	}

	/**
	 * Output banner HTML and return button in the footer.
	 */
	public static function render(): void {
		$text = get_option( 'rsa_consent_banner_text', '' );
		if ( ! $text ) {
			$text = __( 'This site uses analytics to understand how visitors use the site. You can control which data is collected.', 'rich-statistics' );
		}

		$accept_label = __( 'Accept', 'rich-statistics' );
		$reject_label = __( 'Reject', 'rich-statistics' );
		$customize_label = __( 'Customize', 'rich-statistics' );
		$return_label = __( 'Privacy Settings', 'rich-statistics' );

		?>
		<div id="rsa-consent-banner" role="dialog" aria-label="<?php esc_attr_e( 'Consent Banner', 'rich-statistics' ); ?>">
			<button class="rsa-collapse-btn" aria-label="<?php esc_attr_e( 'Collapse', 'rich-statistics' ); ?>" title="<?php esc_attr_e( 'Collapse', 'rich-statistics' ); ?>">&times;</button>
			<p class="rsa-banner-text"><?php echo esc_html( $text ); ?></p>
			<div class="rsa-banner-actions">
				<button class="rsa-accept-btn"><?php echo esc_html( $accept_label ); ?></button>
				<button class="rsa-reject-btn"><?php echo esc_html( $reject_label ); ?></button>
				<button class="rsa-customize-btn"><?php echo esc_html( $customize_label ); ?></button>
			</div>
		</div>
		<button id="rsa-consent-return-btn" aria-label="<?php esc_attr_e( 'Return', 'rich-statistics' ); ?>"><?php echo esc_html( $return_label ); ?></button>
		<?php
	}

	/**
	 * Build the inline CSS for the banner using admin style settings.
	 */
	private static function build_css( array $styles ): string {
		$border_radius = isset( $styles['borderRadius'] ) ? (int) $styles['borderRadius'] : 8;
		$font_color = isset( $styles['fontColor'] ) ? esc_attr( $styles['fontColor'] ) : '#1a1a2e';
		$bg_color = isset( $styles['backgroundColor'] ) ? esc_attr( $styles['backgroundColor'] ) : '#ffffff';
		$border_color = isset( $styles['borderColor'] ) ? esc_attr( $styles['borderColor'] ) : '#e0e0e0';
		$border_width = isset( $styles['borderWidth'] ) ? (int) $styles['borderWidth'] : 1;
		$shadow_x = isset( $styles['shadowX'] ) ? (int) $styles['shadowX'] : 0;
		$shadow_y = isset( $styles['shadowY'] ) ? (int) $styles['shadowY'] : 4;
		$shadow_blur = isset( $styles['shadowBlur'] ) ? (int) $styles['shadowBlur'] : 12;
		$shadow_spread = isset( $styles['shadowSpread'] ) ? (int) $styles['shadowSpread'] : 0;
		$shadow_color = isset( $styles['shadowColor'] ) ? esc_attr( $styles['shadowColor'] ) : '#000000';
		$shadow_alpha = isset( $styles['shadowAlpha'] ) ? floatval( $styles['shadowAlpha'] ) : 0.15;

		$shadow_rgba = self::hex_to_rgba( $shadow_color, $shadow_alpha );

		return '
			#rsa-consent-banner {
				position: fixed;
				bottom: 20px;
				left: 20px;
				max-width: 400px;
				background: ' . $bg_color . ';
				color: ' . $font_color . ';
				border: ' . $border_width . 'px solid ' . $border_color . ';
				border-radius: ' . $border_radius . 'px;
				box-shadow: ' . $shadow_x . 'px ' . $shadow_y . 'px ' . $shadow_blur . 'px ' . $shadow_spread . 'px ' . $shadow_rgba . ';
				padding: 16px;
				z-index: 999999;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				font-size: 14px;
				transition: transform 0.3s ease;
			}
			#rsa-consent-banner.rsa-collapsed {
				transform: translateX(-120%);
			}
			#rsa-consent-banner .rsa-banner-text {
				margin: 0 0 12px;
				line-height: 1.4;
			}
			#rsa-consent-banner .rsa-banner-actions {
				display: flex;
				gap: 8px;
				flex-wrap: wrap;
			}
			#rsa-consent-banner button {
				padding: 6px 12px;
				border: 1px solid ' . $border_color . ';
				border-radius: 4px;
				background: ' . $bg_color . ';
				color: ' . $font_color . ';
				cursor: pointer;
				font-size: 13px;
			}
			#rsa-consent-banner button:hover {
				background: ' . $border_color . ';
			}
			#rsa-consent-banner .rsa-collapse-btn {
				position: absolute;
				top: 8px;
				right: 8px;
				padding: 2px 6px;
				font-size: 16px;
				line-height: 1;
				border: none;
				background: transparent;
			}
			#rsa-consent-return-btn {
				position: fixed;
				bottom: 20px;
				left: 20px;
				padding: 8px 12px;
				background: ' . $bg_color . ';
				color: ' . $font_color . ';
				border: ' . $border_width . 'px solid ' . $border_color . ';
				border-radius: ' . $border_radius . 'px;
				box-shadow: ' . $shadow_x . 'px ' . $shadow_y . 'px ' . $shadow_blur . 'px ' . $shadow_spread . 'px ' . $shadow_rgba . ';
				cursor: pointer;
				z-index: 999998;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				font-size: 13px;
				display: none;
			}
			#rsa-consent-return-btn.rsa-visible {
				display: block;
			}
		';
	}

	/**
	 * Convert hex color to rgba string.
	 */
	private static function hex_to_rgba( string $hex, float $alpha ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) {
			return 'rgba(0,0,0,' . $alpha . ')';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
	}
}
