/**
 * Rich Statistics — AI Chat Component (Privacy-First)
 * Supports OpenAI cloud or local LLMs (Ollama, etc.)
 */
( function () {
	'use strict';

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------
	var chatOpen = false;
	var siteUrl  = '';
	var creds    = '';

	// -----------------------------------------------------------------------
	// Init — add chat button to nav
	// -----------------------------------------------------------------------
	function addChatButton() {
		var nav = document.querySelector( '.rsa-nav' );
		if ( ! nav || document.getElementById( 'rsa-chat-toggle' ) ) return;

		var btn = document.createElement( 'button' );
		btn.id      = 'rsa-chat-toggle';
		btn.className = 'rsa-nav-btn';
		btn.innerHTML = '🤖 AI';
		btn.onclick  = toggleChat;
		nav.appendChild( btn );
	}

	// -----------------------------------------------------------------------
	// Toggle chat panel
	// -----------------------------------------------------------------------
	window.toggleChat = function () {
		var panel = document.getElementById( 'rsa-chat-panel' );
		if ( ! panel ) { createChatPanel(); panel = document.getElementById( 'rsa-chat-panel' ); }
		chatOpen = ! chatOpen;
		panel.style.right = chatOpen ? '0px' : '-420px';
		if ( chatOpen ) document.getElementById( 'rsa-chat-input' ).focus();
	};

	// -----------------------------------------------------------------------
	// Create chat panel
	// -----------------------------------------------------------------------
	function createChatPanel() {
		var panel = document.createElement( 'div' );
		panel.id  = 'rsa-chat-panel';
		panel.style.cssText = 'position:fixed;right:-420px;top:0;width:420px;height:100%;background:#fff;' +
			'border-left:1px solid #ccc;transition:right 0.3s;z-index:9999;display:flex;flex-direction:column;' +
			'box-shadow:-2px 0 8px rgba(0,0,0,0.1);';

		panel.innerHTML = `
			<div style="padding:16px;border-bottom:1px solid #ccc;background:#f8f9fa;display:flex;justify-content:space-between;align-items:center;">
				<div>
					<h3 style="margin:0;font-size:16px;">🤖 AI Analytics</h3>
					<span style="font-size:12px;color:#666;" id="rsa-chat-status">Ready</span>
				</div>
				<button onclick="toggleChat()" style="background:none;border:none;font-size:24px;cursor:pointer;padding:4px 8px;">×</button>
			</div>
			<div id="rsa-chat-messages" style="flex:1;overflow-y:auto;padding:16px;background:#fafafa;">
				<div style="text-align:center;padding:20px;color:#666;font-size:13px;">
					<p>Ask questions about your analytics in plain English!</p>
					<p style="font-size:11px;color:#999;">Examples:</p>
					<p style="font-size:11px;color:#999;">• "What's my top page this month?"</p>
					<p style="font-size:11px;color:#999;">• "Show me visitor trends"</p>
					<p style="font-size:11px;color:#999;">• "Which campaigns are working?"</p>
				</div>
			</div>
			<div style="padding:12px;border-top:1px solid #ccc;background:#fff;">
				<div style="display:flex;gap:8px;">
					<input type="text" id="rsa-chat-input" style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;" 
						placeholder="Ask about your analytics...">
					<button onclick="sendMessage()" style="padding:10px 20px;background:#0073aa;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;">Send</button>
				</div>
			</div>
		`;

		document.body.appendChild( panel );
	}

	// -----------------------------------------------------------------------
	// Send message
	// -----------------------------------------------------------------------
	window.sendMessage = function () {
		var input = document.getElementById( 'rsa-chat-input' );
		var msg   = input.value.trim();
		if ( ! msg ) return;

		addMessage( 'You', msg, 'user' );
		input.value = '';
		document.getElementById( 'rsa-chat-status' ).textContent = 'Thinking...';

		// Get active site
		var site = state.sites.find( s => s.id === state.activeId );
		if ( ! site ) {
			addMessage( 'AI', 'No site connected. Please add a site first.', 'ai' );
			document.getElementById( 'rsa-chat-status' ).textContent = 'Error';
			return;
		}

		var credentials = btoa( site.username + ':' + site.appPass );

		fetch( site.siteUrl + '/wp-json/rsa/v1/ai/query', {
			method: 'POST',
			headers: {
				'Authorization': 'Basic ' + credentials,
				'Content-Type': 'application/json'
			},
			body: JSON.stringify( { question: msg, period: state.period } )
		})
		.then( r => r.json() )
		.then( data => {
			document.getElementById( 'rsa-chat-status' ).textContent = 'Ready';
			if ( data.ok ) {
				addMessage( 'AI', data.data.answer, 'ai' );
			} else {
				addMessage( 'AI', 'Error: ' + ( data.error || 'Unknown error' ), 'ai' );
			}
		})
		.catch( e => {
			document.getElementById( 'rsa-chat-status' ).textContent = 'Error';
			addMessage( 'AI', 'Connection error. Is your site online?', 'ai' );
		} );
	};

	// -----------------------------------------------------------------------
	// Add message to chat
	// -----------------------------------------------------------------------
	function addMessage( who, text, cls ) {
		var div  = document.getElementById( 'rsa-chat-messages' );
		var msg  = document.createElement( 'div' );
		var time = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );

		msg.style.cssText = 'margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:13px;line-height:1.5;' +
			( cls === 'user' 
				? 'background:#e3f2fd;margin-left:20%;border:1px solid #90caf9;' 
				: 'background:#fff;margin-right:20%;border:1px solid #e0e0e0;box-shadow:0 1px 2px rgba(0,0,0,0.05);' );

		msg.innerHTML = '<div style="font-weight:600;font-size:12px;margin-bottom:4px;color:#666;">' + who + ' · ' + time + '</div>' +
			'<div>' + escapeHtml( text ).replace( /\n/g, '<br>' ) + '</div>';

		div.appendChild( msg );
		div.scrollTop = div.scrollHeight;
	}

	// -----------------------------------------------------------------------
	// Utility: escape HTML
	// -----------------------------------------------------------------------
	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	// -----------------------------------------------------------------------
	// Listen for Enter key
	// -----------------------------------------------------------------------
	document.addEventListener( 'keypress', function ( e ) {
		if ( e.key === 'Enter' && document.getElementById( 'rsa-chat-input' ) === document.activeElement ) {
			e.preventDefault();
			sendMessage();
		}
	} );

	// -----------------------------------------------------------------------
	// Init on DOM ready
	// -----------------------------------------------------------------------
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { setTimeout( addChatButton, 1500 ); } );
	} else {
		setTimeout( addChatButton, 1500 );
	}
}() );
