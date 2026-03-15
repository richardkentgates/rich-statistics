<?php
/**
 * [PREMIUM] Click tracking — server-side ingest handler.
 * The client-side collection is part of tracker.js (gated by RSA.premium.clickEnabled).
 */
defined( 'ABSPATH' ) || exit;

class RSA_Click_Tracking {

	public static function init(): void {
		add_action( 'wp_ajax_nopriv_rsa_track_click', [ __CLASS__, 'handle_click' ] );
		add_action( 'wp_ajax_rsa_track_click',        [ __CLASS__, 'handle_click' ] );
	}

	public static function handle_click(): void {
		if ( ! check_ajax_referer( 'rsa_track', 'nonce', false ) ) {
			wp_send_json_error( 'invalid_nonce', 403 );
		}

		global $wpdb;

		// Parse + validate
		$raw  = file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = $_POST;
		}

		$session_id = sanitize_text_field( $data['session_id'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session_id ) ) {
			wp_send_json_error( 'invalid_session', 400 );
		}

		$page = sanitize_text_field( $data['page'] ?? '/' );
		$page = substr( $page, 0, 512 );

		$element_tag   = substr( sanitize_text_field( $data['element_tag']   ?? '' ), 0, 32 );
		$element_id    = substr( sanitize_text_field( $data['element_id']    ?? '' ), 0, 255 );
		$element_class = substr( sanitize_text_field( $data['element_class'] ?? '' ), 0, 512 );
		$element_text  = substr( sanitize_text_field( $data['element_text']  ?? '' ), 0, 255 );
		$href_protocol = substr( sanitize_text_field( $data['href_protocol'] ?? '' ), 0, 32 );

		$x_pct = round( min( 100, max( 0, (float) ( $data['x_pct'] ?? 0 ) ) ), 2 );
		$y_pct = round( min( 100, max( 0, (float) ( $data['y_pct'] ?? 0 ) ) ), 2 );

		$wpdb->insert(
			RSA_DB::clicks_table(),
			[
				'session_id'    => $session_id,
				'page'          => $page,
				'element_tag'   => $element_tag   ?: null,
				'element_id'    => $element_id    ?: null,
				'element_class' => $element_class ?: null,
				'element_text'  => $element_text  ?: null,
				'href_protocol' => $href_protocol ?: null,
				'x_pct'         => $x_pct > 0 ? $x_pct : null,
				'y_pct'         => $y_pct > 0 ? $y_pct : null,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f' ]
		);

		wp_send_json_success( [ 'ok' => true ] );
	}
}
