<?php
/**
 * AI Chat — WordPress admin page.
 * Premium only, requires AI configured in Preferences.
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

RSA_Admin::page_header( __( 'AI Analytics Assistant', 'rich-statistics' ) );

// Check if AI is configured
$provider = get_option( 'rsa_ai_provider', 'openai' );
$has_ai  = false;
if ( function_exists( 'rs_fs' ) && rs_fs()->can_use_premium_code__premium_only() ) {
	if ( $provider === 'openai' && get_option( 'rsa_ai_api_key', '' ) ) {
		$has_ai = true;
	} elseif ( $provider === 'custom' && get_option( 'rsa_ai_endpoint', '' ) ) {
		$has_ai = true;
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'AI Analytics Assistant', 'rich-statistics' ); ?></h1>
	
	<?php if ( ! $has_ai ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'AI is not configured. Please go to Preferences → AI Integration to set up your provider.', 'rich-statistics' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=rich-statistics-preferences' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Configure AI', 'rich-statistics' ); ?>
			</a></p>
		</div>
	<?php else : ?>
		<div id="rsa-ai-chat-container" style="margin-top:20px;display:flex;gap:20px;">
			<!-- Chat Panel -->
			<div style="flex:1;background:#fff;border:1px solid #ccc;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;height:600px;">
				<div style="padding:16px;border-bottom:1px solid #ccc;background:#f8f9fa;display:flex;justify-content:space-between;align-items:center;">
					<div>
						<h3 style="margin:0;font-size:16px;">🤖 AI Analytics Assistant</h3>
						<span style="font-size:12px;color:#666;" id="rsa-admin-chat-status">Ready</span>
					</div>
					<div style="font-size:12px;color:#666;">
						<?php echo esc_html( ucfirst( $provider ) ); ?> 
						<span style="background:#e0e0e0;padding:2px 8px;border-radius:4px;"><?php echo esc_html( get_option( 'rsa_ai_model', 'gpt-4o-mini' ) ); ?></span>
					</div>
				</div>
				<div id="rsa-admin-chat-messages" style="flex:1;overflow-y:auto;padding:16px;background:#fafafa;">
					<div style="text-align:center;padding:20px;color:#666;font-size:13px;">
						<p><strong>Ask questions about your analytics in plain English!</strong></p>
						<p style="font-size:11px;color:#999;">Examples:</p>
						<p style="font-size:11px;color:#999;">• "What's my top page this month?"</p>
						<p style="font-size:11px;color:#999;">• "Show me visitor trends"</p>
						<p style="font-size:11px;color:#999;">• "Which campaigns are working?"</p>
						<p style="font-size:11px;color:#999;">• "How many WooCommerce orders this week?"</p>
					</div>
				</div>
				<div style="padding:12px;border-top:1px solid #ccc;background:#fff;">
					<div style="display:flex;gap:8px;">
						<input type="text" id="rsa-admin-chat-input" style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;" 
							placeholder="Ask about your analytics...">
						<button onclick="sendAdminMessage()" style="padding:10px 20px;background:#0073aa;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;">Send</button>
					</div>
				</div>
			</div>
			
			<!-- Info Panel -->
			<div style="width:300px;">
				<div class="rsa-card" style="margin-bottom:16px;">
					<div class="rsa-card-header"><h3 style="margin:0;font-size:14px;">How it works</h3></div>
					<div style="padding:12px;font-size:13px;line-height:1.6;">
						<p><?php esc_html_e( 'The AI assistant analyzes your analytics data and provides conversational insights.', 'rich-statistics' ); ?></p>
						<p><strong><?php esc_html_e( 'Privacy-first:', 'rich-statistics' ); ?></strong><br>
						<?php esc_html_e( 'Only aggregate data is shared. No personal info leaves your site.', 'rich-statistics' ); ?></p>
						<p><strong><?php esc_html_e( 'Provider:', 'rich-statistics' ); ?></strong><br>
						<?php echo esc_html( $provider === 'openai' ? 'OpenAI (cloud)' : 'Custom/Local LLM' ); ?></p>
					</div>
				</div>
				
				<div class="rsa-card">
					<div class="rsa-card-header"><h3 style="margin:0;font-size:14px;">Tips</h3></div>
					<div style="padding:12px;font-size:13px;line-height:1.6;">
						<ul style="margin:0;padding-left:20px;">
							<li><?php esc_html_e( 'Be specific with time periods', 'rich-statistics' ); ?></li>
							<li><?php esc_html_e( 'Ask about pages, visitors, campaigns', 'rich-statistics' ); ?></li>
							<li><?php esc_html_e( 'Request summaries or comparisons', 'rich-statistics' ); ?></li>
							<?php if ( $provider === 'custom' ) : ?>
								<li><?php esc_html_e( 'Using local LLM (Ollama) — no data leaves server', 'rich-statistics' ); ?></li>
							<?php endif; ?>
						</ul>
					</div>
				</div>
			</div>
		</div>
		
		<script>
		(function() {
			'use strict';
			
			var siteUrl  = '<?php echo esc_js( home_url() ); ?>';
			var nonce    = '<?php echo wp_create_nonce( 'wp_rest' ); ?>';
			var userName = '<?php echo esc_js( wp_get_current_user()->user_login ); ?>';
			var appPass = ''; // Will be prompted if not stored
			
			// Send message
			window.sendAdminMessage = function() {
				var input = document.getElementById( 'rsa-admin-chat-input' );
				var msg   = input.value.trim();
				if ( ! msg ) return;
				
				addAdminMessage( 'You', msg, 'user' );
				input.value = '';
				document.getElementById( 'rsa-admin-chat-status' ).textContent = 'Thinking...';
				
				// Get Application Password if not available
				if ( ! appPass ) {
					appPass = prompt( 'Enter your WordPress Application Password:\n(Generate one in Users → Profile → Application Passwords)' );
					if ( ! appPass ) {
						addAdminMessage( 'AI', 'Application Password required for API access.', 'ai' );
						document.getElementById( 'rsa-admin-chat-status' ).textContent = 'Error';
						return;
					}
				}
				
				var credentials = btoa( userName + ':' + appPass );
				
				fetch( siteUrl + '/wp-json/rsa/v1/ai/query', {
					method: 'POST',
					headers: {
						'Authorization': 'Basic ' + credentials,
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					body: JSON.stringify( { question: msg, period: '<?php echo esc_js( $_GET['period'] ?? '30d' ); ?>' } )
				})
				.then( r => r.json() )
				.then( data => {
					document.getElementById( 'rsa-admin-chat-status' ).textContent = 'Ready';
					if ( data.ok ) {
						addAdminMessage( 'AI', data.data.answer, 'ai' );
					} else {
						addAdminMessage( 'AI', 'Error: ' + ( data.error || 'Unknown error' ), 'ai' );
					}
				})
				.catch( e => {
					document.getElementById( 'rsa-admin-chat-status' ).textContent = 'Error';
					addAdminMessage( 'AI', 'Connection error. Is the REST API working?', 'ai' );
				} );
			};
			
			// Add message to chat
			function addAdminMessage( who, text, cls ) {
				var div  = document.getElementById( 'rsa-admin-chat-messages' );
				var msg  = document.createElement( 'div' );
				var time = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
				
				msg.style.cssText = 'margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:13px;line-height:1.5;' +
					( cls === 'user' 
						? 'background:#e3f2fd;margin-left:20%;border:1px solid #90caf9;' 
						: 'background:#fff;margin-right:20%;border:1px solid #e0e0e0;box-shadow:0 1px 2px rgba(0,0,0,0.05);' 
					);
				
				msg.innerHTML = '<div style="font-weight:600;font-size:12px;margin-bottom:4px;color:#666;">' + who + ' · ' + time + '</div>' +
					'<div>' + escapeHtml( text ).replace( /\n/g, '<br>' ) + '</div>';
				
				div.appendChild( msg );
				div.scrollTop = div.scrollHeight;
			}
			
			// Utility: escape HTML
			function escapeHtml( text ) {
				var div = document.createElement( 'div' );
				div.textContent = text;
				return div.innerHTML;
			}
			
			// Listen for Enter key
			document.addEventListener( 'keypress', function( e ) {
				if ( e.key === 'Enter' && document.getElementById( 'rsa-admin-chat-input' ) === document.activeElement ) {
					e.preventDefault();
					sendAdminMessage();
				}
			} );
		})();
		</script>
	<?php endif; ?>
</div>
