/**
 * Rich Statistics — AI Chat Component
 * Adds conversational analytics to the desktop app.
 */
( function () {
	'use strict';

	// Add chat toggle button to nav
	function addChatButton() {
		var nav = document.querySelector( '.rsa-nav' );
		if ( ! nav || document.getElementById( 'rsa-chat-toggle' ) ) return;

		var btn = document.createElement( 'button' );
		btn.id = 'rsa-chat-toggle';
		btn.className = 'rsa-nav-btn';
		btn.innerHTML = '🤖 AI';
		btn.onclick = toggleChat;
		nav.appendChild( btn );
	}

	// Create chat panel
	function createChatPanel() {
		if ( document.getElementById( 'rsa-chat-panel' ) ) return;

		var panel = document.createElement( 'div' );
		panel.id = 'rsa-chat-panel';
		panel.style.cssText = 'position:fixed;right:-400px;top:0;width:400px;height:100%;background:#fff;border-left:1px solid #ccc;transition:right 0.3s;z-index:9999;display:flex;flex-direction:column;';

		panel.innerHTML = `
			<div style="padding:16px;border-bottom:1px solid #ccc;background:#f5f5f5;">
				<h3 style="margin:0;">AI Analytics Assistant</h3>
				<button onclick="toggleChat()" style="float:right;background:none;border:none;font-size:20px;cursor:pointer;">×</button>
			</div>
			<div id="rsa-chat-messages" style="flex:1;overflow-y:auto;padding:16px;">
			</div>
			<div style="padding:16px;border-top:1px solid #ccc;">
				<input type="text" id="rsa-chat-input" style="width:100%;padding:8px;" placeholder="Ask about your analytics...">
				<button onclick="sendMessage()" style="margin-top:8px;padding:8px 16px;background:#0073aa;color:#fff;border:none;cursor:pointer;">Send</button>
			</div>
		`;

		document.body.appendChild( panel );
	}

	// Toggle chat panel
	window.toggleChat = function() {
		var panel = document.getElementById( 'rsa-chat-panel' );
		if ( ! panel ) { createChatPanel(); panel = document.getElementById( 'rsa-chat-panel' ); }
		panel.style.right = panel.style.right === '0px' ? '-400px' : '0px';
	};

	// Send message
	window.sendMessage = function() {
		var input = document.getElementById( 'rsa-chat-input' );
		var msg = input.value.trim();
		if ( ! msg ) return;

		addMessage( 'You', msg, 'user' );
		input.value = '';

		// Call API
		var site = state.sites.find( s => s.id === state.activeId );
		if ( ! site ) { addMessage( 'AI', 'No site connected', 'ai' ); return; }

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
			if ( data.ok ) {
				addMessage( 'AI', data.data.answer, 'ai' );
			} else {
				addMessage( 'AI', data.error || 'Error processing request', 'ai' );
			}
		})
		.catch( e => addMessage( 'AI', 'Connection error', 'ai' ) );
	};

	// Add message to chat
	function addMessage( who, text, cls ) {
		var div = document.getElementById( 'rsa-chat-messages' );
		var msg = document.createElement( 'div' );
		msg.style.cssText = 'margin-bottom:12px;padding:8px;border-radius:4px;' +
			( cls === 'user' ? 'background:#e3f2fd;margin-left:20%;' : 'background:#f5f5f5;margin-right:20%;' );
		msg.innerHTML = '<strong>' + who + ':</strong><br>' + text.replace( /\n/g, '<br>' );
		div.appendChild( msg );
		div.scrollTop = div.scrollHeight;
	}

	// Listen for Enter key
	document.addEventListener( 'keypress', function( e ) {
		if ( e.key === 'Enter' && document.getElementById( 'rsa-chat-input' ) === document.activeElement ) {
			sendMessage();
		}
	} );

	// Init
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			setTimeout( addChatButton, 1000 );
		} );
	} else {
		setTimeout( addChatButton, 1000 );
	}
}() );
