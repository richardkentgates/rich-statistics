<?php
/**
 * Network Dashboard — cross-site analytics with AI for multisite networks.
 *
 * @package RichStatistics
 */

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_network_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'rich-statistics' ) );
}

// Handle AI + voice settings save.
if ( isset( $_POST['rsa_network_ai_save'] ) ) {
	check_admin_referer( 'rsa_network_ai_save' );
	update_site_option( 'rsa_network_ai_endpoint', esc_url_raw( $_POST['rsa_network_ai_endpoint'] ?? '' ) );
	if ( isset( $_POST['rsa_network_ai_key'] ) && '' !== $_POST['rsa_network_ai_key'] ) {
		$raw = sanitize_text_field( wp_unslash( $_POST['rsa_network_ai_key'] ) );
		if ( ! preg_match( '/^\*+$/', $raw ) ) {
			update_site_option( 'rsa_network_ai_key', $raw );
		}
	}
	update_site_option( 'rsa_network_ai_model', sanitize_text_field( wp_unslash( $_POST['rsa_network_ai_model'] ?? 'gpt-4o-mini' ) ) );
	update_site_option( 'rsa_network_voice_input', absint( $_POST['rsa_network_voice_input'] ?? 0 ) );
	update_site_option( 'rsa_network_voice_output', absint( $_POST['rsa_network_voice_output'] ?? 0 ) );
	update_site_option( 'rsa_network_voice_lang', sanitize_text_field( wp_unslash( $_POST['rsa_network_voice_lang'] ?? 'en-US' ) ) );
	update_site_option( 'rsa_network_voice_speed', floatval( $_POST['rsa_network_voice_speed'] ?? 1.0 ) );
	$saved = true;
}

$ai_endpoint  = get_site_option( 'rsa_network_ai_endpoint', '' );
$ai_key       = get_site_option( 'rsa_network_ai_key', '' );
$ai_model     = get_site_option( 'rsa_network_ai_model', 'gpt-4o-mini' );
$voice_input  = (int) get_site_option( 'rsa_network_voice_input', 0 );
$voice_output = (int) get_site_option( 'rsa_network_voice_output', 0 );
$voice_lang   = get_site_option( 'rsa_network_voice_lang', 'en-US' );
$voice_speed  = (float) get_site_option( 'rsa_network_voice_speed', 1.0 );
$has_ai       = ! empty( $ai_endpoint );
?>
<div class="wrap rsa-wrap">
	<h1>
		<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
		<?php esc_html_e( 'Rich Statistics — Network Dashboard', 'rich-statistics' ); ?>
	</h1>

	<?php if ( isset( $saved ) && $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'AI settings saved.', 'rich-statistics' ); ?></p></div>
	<?php endif; ?>

	<!-- Sub-site Status Table -->
	<h2><?php esc_html_e( 'Sub-site Overview', 'rich-statistics' ); ?></h2>
	<p><?php esc_html_e( 'Per-site analytics for the last 30 days. Click a site name to view its detailed dashboard.', 'rich-statistics' ); ?></p>

	<?php
	$sites = get_sites( array( 'number' => 100, 'orderby' => 'id', 'order' => 'ASC' ) );
	if ( $sites ) :
		global $wpdb;
		$now               = current_time( 'mysql' );
		$start             = date( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$default_retention = (int) get_site_option( 'rsa_default_retention_days', 90 );
		$network_disable   = (int) get_site_option( 'rsa_network_disable_tracker', 0 );
		$site_data         = array();
		?>
		<table class="wp-list-table widefat fixed striped" id="rsa-network-sites-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Pageviews (30d)', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Sessions (30d)', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Bounce Rate', 'rich-statistics' ); ?></th>
					<th><?php esc_html_e( 'Tracker', 'rich-statistics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $sites as $site ) :
				switch_to_blog( $site->blog_id );
				$has_table  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'rsa_events' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$bt         = (int) get_option( 'rsa_bot_score_threshold', 5 );
				$tracker_on = ! $network_disable && ! (bool) get_option( 'rsa_network_disable_tracker', 0 );

				$pageviews = 0;
				$sessions  = 0;
				$bounce    = 0;
				if ( $has_table ) {
					$pageviews = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rsa_events WHERE created_at BETWEEN %s AND %s AND bot_score < %d", $start, $now, $bt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$sessions  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rsa_sessions WHERE created_at BETWEEN %s AND %s", $start, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( $sessions > 0 ) {
						$single = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rsa_sessions WHERE created_at BETWEEN %s AND %s AND pageviews <= 1", $start, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$bounce = round( ( $single / $sessions ) * 100 );
					}
				}
				$site_details  = get_blog_details( $site->blog_id );
				$dashboard_url = get_admin_url( $site->blog_id, 'admin.php?page=rich-statistics' );
				$site_data[]   = array(
					'id'        => $site->blog_id,
					'name'      => $site_details->blogname,
					'url'       => get_home_url(),
					'pageviews' => $pageviews,
					'sessions'  => $sessions,
					'bounce'    => $bounce,
				);
				restore_current_blog();
				?>
		<tr>
			<td><a href="<?php echo esc_url( $dashboard_url ); ?>"><?php echo esc_html( $site_details->blogname ); ?></a></td>
			<td><?php echo $has_table ? esc_html( number_format( $pageviews ) ) : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
			<td><?php echo $has_table ? esc_html( number_format( $sessions ) ) : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
			<td><?php echo $has_table ? esc_html( $bounce . '%' ) : '<span style="color:#a0a5ae">&mdash;</span>'; ?></td>
			<td><?php echo $tracker_on && $has_table ? '<span style="color:#10b981">&#10003;</span>' : '<span style="color:#ef4444">&#10007;</span>'; ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<hr style="margin:32px 0;">

<!-- AI Analytics Assistant -->
<h2><?php esc_html_e( 'AI Analytics Assistant', 'rich-statistics' ); ?></h2>
<p><?php esc_html_e( 'Ask questions about any site in your network. The AI fetches data across all sub-sites and provides conversational answers.', 'rich-statistics' ); ?></p>

<div id="rsa-network-ai-settings" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px;margin-bottom:16px;max-width:800px;">
	<form method="post" action="">
		<?php wp_nonce_field( 'rsa_network_ai_save' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="rsa_network_ai_endpoint"><?php esc_html_e( 'API Endpoint', 'rich-statistics' ); ?></label></th>
				<td><input type="url" id="rsa_network_ai_endpoint" name="rsa_network_ai_endpoint" class="regular-text" value="<?php echo esc_attr( $ai_endpoint ); ?>" placeholder="https://api.openai.com/v1/chat/completions"></td>
			</tr>
			<tr>
				<th><label for="rsa_network_ai_key"><?php esc_html_e( 'API Key', 'rich-statistics' ); ?></label></th>
				<td><input type="password" id="rsa_network_ai_key" name="rsa_network_ai_key" class="regular-text" value="<?php echo $ai_key ? '********' : ''; ?>" placeholder="sk-..."></td>
			</tr>
			<tr>
				<th><label for="rsa_network_ai_model"><?php esc_html_e( 'Model', 'rich-statistics' ); ?></label></th>
				<td><input type="text" id="rsa_network_ai_model" name="rsa_network_ai_model" class="regular-text" value="<?php echo esc_attr( $ai_model ); ?>" placeholder="gpt-4o-mini"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Voice Input', 'rich-statistics' ); ?></th>
				<td><label><input type="checkbox" name="rsa_network_voice_input" value="1" <?php checked( $voice_input, 1 ); ?>> <?php esc_html_e( 'Enable microphone', 'rich-statistics' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Voice Output', 'rich-statistics' ); ?></th>
				<td><label><input type="checkbox" name="rsa_network_voice_output" value="1" <?php checked( $voice_output, 1 ); ?>> <?php esc_html_e( 'Enable speaker', 'rich-statistics' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="rsa_network_voice_lang"><?php esc_html_e( 'Language', 'rich-statistics' ); ?></label></th>
				<td>
					<select id="rsa_network_voice_lang" name="rsa_network_voice_lang">
						<option value="en-US" <?php selected( $voice_lang, 'en-US' ); ?>><?php esc_html_e( 'English (US)', 'rich-statistics' ); ?></option>
						<option value="en-GB" <?php selected( $voice_lang, 'en-GB' ); ?>><?php esc_html_e( 'English (UK)', 'rich-statistics' ); ?></option>
						<option value="es-ES" <?php selected( $voice_lang, 'es-ES' ); ?>><?php esc_html_e( 'Spanish', 'rich-statistics' ); ?></option>
						<option value="fr-FR" <?php selected( $voice_lang, 'fr-FR' ); ?>><?php esc_html_e( 'French', 'rich-statistics' ); ?></option>
						<option value="de-DE" <?php selected( $voice_lang, 'de-DE' ); ?>><?php esc_html_e( 'German', 'rich-statistics' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rsa_network_voice_speed"><?php esc_html_e( 'Speech Speed', 'rich-statistics' ); ?></label></th>
				<td>
					<input type="range" id="rsa_network_voice_speed" name="rsa_network_voice_speed" min="0.5" max="2.0" step="0.1" value="<?php echo esc_attr( $voice_speed ); ?>" style="width:200px;" oninput="document.getElementById('rsa-network-speed-val').textContent=this.value">
					<span id="rsa-network-speed-val"><?php echo esc_html( $voice_speed ); ?></span>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save AI Settings', 'rich-statistics' ), 'primary', 'rsa_network_ai_save' ); ?>
	</form>
</div>

<!-- Chat Interface -->
<?php if ( $has_ai ) : ?>
<div id="rsa-network-ai-chat" style="max-width:800px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;height:450px;">
	<div style="padding:8px 12px;border-bottom:1px solid #e0e0e0;background:#f8f9fa;display:flex;gap:12px;align-items:center;font-size:12px;color:#888;">
		<?php if ( $voice_output ) : ?>
		<button id="rsa-net-stop-speech" style="padding:4px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer;font-size:12px;" hidden><?php esc_html_e( 'Stop Speaking', 'rich-statistics' ); ?></button>
		<?php endif; ?>
		<span id="rsa-net-voice-status" style="flex:1;font-style:italic;"></span>
	</div>
	<div id="rsa-net-messages" style="flex:1;overflow-y:auto;padding:16px;background:#fafafa;font-size:13px;line-height:1.6;"></div>
	<div style="padding:12px;border-top:1px solid #e0e0e0;display:flex;gap:6px;align-items:center;">
		<?php if ( $voice_input ) : ?>
		<button id="rsa-net-mic" style="padding:8px 12px;border:1px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;font-size:16px;line-height:1;" title="Speak your question">🎤</button>
		<?php endif; ?>
		<input type="text" id="rsa-net-input" style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;" placeholder="Ask about any site in your network..." autocomplete="off">
		<button id="rsa-net-send" style="padding:10px 20px;background:#2e6f8e;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;"><?php esc_html_e( 'Send', 'rich-statistics' ); ?></button>
	</div>
</div>

<script>
(function() {
	'use strict';
	var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
	var siteData = <?php echo wp_json_encode( $site_data ); ?>;
	var hasVoiceInput  = <?php echo $voice_input ? 'true' : 'false'; ?>;
	var hasVoiceOutput = <?php echo $voice_output ? 'true' : 'false'; ?>;
	var voiceLang = '<?php echo esc_js( $voice_lang ); ?>';
	var voiceSpeed = <?php echo (float) $voice_speed; ?>;
	var apiEndpoint = '<?php echo esc_js( $ai_endpoint ); ?>';
	var apiKey = '<?php echo esc_js( $ai_key ); ?>';
	var apiModel = '<?php echo esc_js( $ai_model ); ?>';
	var l10n = 
	<?php
	echo wp_json_encode( array(
		'speaking'            => __( 'Speaking...', 'rich-statistics' ),
		'voice_not_supported' => __( 'Voice input not supported.', 'rich-statistics' ),
		'unable_to_generate'  => __( 'Unable to generate a response.', 'rich-statistics' ),
		'connection_error'    => __( 'Connection error. Check your AI provider endpoint.', 'rich-statistics' ),
		'hello'               => __( 'Hello! Ask me anything about your network of', 'rich-statistics' ),
		'sites'               => __( 'sites.', 'rich-statistics' ),
	) );
	?>
				;

	var input   = document.getElementById( 'rsa-net-input' );
	var sendBtn = document.getElementById( 'rsa-net-send' );
	var micBtn  = document.getElementById( 'rsa-net-mic' );
	var stopBtn = document.getElementById( 'rsa-net-stop-speech' );
	var msgDiv  = document.getElementById( 'rsa-net-messages' );
	var vsEl    = document.getElementById( 'rsa-net-voice-status' );

	function addMsg( who, text ) {
		var d = document.createElement( 'div' );
		d.style.cssText = 'margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:13px;line-height:1.5;max-width:80%;' +
			( who === 'user'
				? 'background:#e3f2fd;margin-left:20%;border:1px solid #90caf9;'
				: 'background:#fff;margin-right:20%;border:1px solid #e0e0e0;box-shadow:0 1px 2px rgba(0,0,0,0.05);' );
		var t = document.createElement( 'div' );
		t.textContent = text.replace( /\n/g, '\n' );
		t.style.whiteSpace = 'pre-wrap';
		d.appendChild( t );
		msgDiv.appendChild( d );
		msgDiv.scrollTop = msgDiv.scrollHeight;
	}

	function speakText( text ) {
		if ( ! hasVoiceOutput ) return;
		window.speechSynthesis.cancel();
		var u = new SpeechSynthesisUtterance( text.replace( /<[^>]+>/g, '' ) );
		u.lang = voiceLang;
		u.rate = voiceSpeed;
		if ( stopBtn ) stopBtn.hidden = false;
		if ( vsEl ) vsEl.textContent = l10n.speaking;
		u.onend = function () { if ( stopBtn ) stopBtn.hidden = true; if ( vsEl ) vsEl.textContent = ''; };
		window.speechSynthesis.speak( u );
	}
	if ( stopBtn ) {
		stopBtn.addEventListener( 'click', function () {
			window.speechSynthesis.cancel();
			stopBtn.hidden = true;
			if ( vsEl ) vsEl.textContent = '';
		} );
	}

	// Voice input
	var recognition = null;
	var isListening = false;
	if ( micBtn ) {
		micBtn.addEventListener( 'click', function () {
			var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
			if ( ! SR ) { if ( vsEl ) vsEl.textContent = l10n.voice_not_supported; return; }
			if ( isListening ) {
				if ( recognition ) { recognition.stop(); isListening = false; }
				micBtn.style.borderColor = '#ccc';
				return;
			}
			recognition = new SR();
			recognition.lang = voiceLang;
			recognition.interimResults = true;
			recognition.continuous = false;
			recognition.onresult = function ( e ) {
				for ( var i = e.resultIndex; i < e.results.length; i++ ) {
					if ( e.results[i].isFinal ) {
						input.value = e.results[i][0].transcript;
						doSend();
					} else {
						input.value = e.results[i][0].transcript;
						input.style.color = '#888';
					}
				}
			};
			recognition.onend = function () { isListening = false; micBtn.style.borderColor = '#ccc'; input.style.color = ''; };
			isListening = true;
			micBtn.style.borderColor = '#e74c3c';
			recognition.start();
		} );
	}

	function doSend() {
		var msg = input.value.trim().replace( /\uFEFF/g, '' );
		if ( ! msg ) return;
		input.style.color = '';
		addMsg( 'user', msg );
		input.value = '';
		sendBtn.disabled = true;
		sendBtn.textContent = '...';

		// Fetch data from all sites via REST tool endpoint.
		// Always try all tools — each site's /ai/tool endpoint gates per-site.
		var promises = [];
		var tools = [ 'overview', 'pages', 'audience', 'referrers', 'behavior', 'campaigns' ];

		siteData.forEach( function ( site ) {
			tools.forEach( function ( tool ) {
				var url = site.url + '/wp-json/rsa/v1/ai/tool';
				promises.push(
					fetch( url, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify( { tool: tool, params: { period: '30d', limit: 5 } } )
					} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
						d._site = site.name;
						d._siteId = site.id;
						return d;
					} ).catch( function () { return null; } )
				);
			} );
		} );

		Promise.all( promises ).then( function ( results ) {
			var contextData = { sites: {} };
			results.forEach( function ( res ) {
				if ( ! res || ! res.ok || ! res.data ) return;
				if ( ! contextData.sites[ res._site ] ) contextData.sites[ res._site ] = {};
				contextData.sites[ res._site ][ res.data.tool ] = res.data.data;
			} );

			var systemPrompt = 'You are a multi-site analytics assistant. Answer based on the data below for each site. Never invent numbers. Be concise. Highlight interesting comparisons between sites.';
			var body = {
				model: apiModel,
				messages: [
					{ role: 'system', content: systemPrompt + '\n\nNetwork has ' + siteData.length + ' sites.\n\nData:\n' + JSON.stringify( contextData, null, 2 ) },
					{ role: 'user', content: msg }
				],
				max_tokens: 800
			};
			var hdrs = { 'Content-Type': 'application/json' };
			if ( apiKey ) hdrs['Authorization'] = 'Bearer ' + apiKey;
			return fetch( apiEndpoint, { method: 'POST', headers: hdrs, body: JSON.stringify( body ) } );
		} ).then( function ( r ) { return r.json(); } )
		.then( function ( llmData ) {
			sendBtn.disabled = false;
			sendBtn.textContent = 'Send';
			var answer = ( llmData.choices && llmData.choices[0] && llmData.choices[0].message && llmData.choices[0].message.content )
				|| ( llmData.error && llmData.error.message )
				|| l10n.unable_to_generate;
			addMsg( 'ai', answer );
			if ( hasVoiceOutput ) speakText( answer );
		} )
		.catch( function () {
			sendBtn.disabled = false;
			sendBtn.textContent = 'Send';
			addMsg( 'ai', l10n.connection_error );
		} );
	}

	sendBtn.addEventListener( 'click', doSend );
	input.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); doSend(); } } );
	addMsg( 'ai', l10n.hello + ' ' + siteData.length + ' ' + l10n.sites );
})();
</script>
<?php else : ?>
<div class="notice notice-info" style="max-width:800px;">
	<p><?php esc_html_e( 'Configure your AI provider above to enable the cross-site analytics assistant.', 'rich-statistics' ); ?></p>
</div>
<?php endif; ?>
</div>
<?php
