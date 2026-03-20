/**
 * Rich Statistics — Profile page OTP generator.
 *
 * Handles the "Generate App Code" button on the WordPress user profile page.
 * Calls the rsa_generate_otp AJAX action, displays the 6-digit code with a
 * live countdown timer, and provides a one-click copy button.
 *
 * Depends on rsaOtp (wp_localize_script) with keys:
 *   ajaxUrl, nonce, generateLabel, generating, regenerateLabel,
 *   copyLabel, copiedMsg, expiredMsg, errorMsg
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn     = document.getElementById( 'rsa-generate-otp-btn' );
		if ( ! btn ) { return; }

		var box     = document.getElementById( 'rsa-otp-display' );
		var codeEl  = document.getElementById( 'rsa-otp-code' );
		var timerEl = document.getElementById( 'rsa-otp-timer' );
		var copyBtn = document.getElementById( 'rsa-otp-copy' );
		var countdown = null;

		// ── Generate ────────────────────────────────────────────────────
		btn.addEventListener( 'click', function () {
			btn.disabled    = true;
			btn.textContent = rsaOtp.generating;

			var body = new FormData();
			body.append( 'action',      'rsa_generate_otp' );
			body.append( '_ajax_nonce', rsaOtp.nonce );

			fetch( rsaOtp.ajaxUrl, { method: 'POST', body: body } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					if ( ! data.success ) {
						throw new Error( ( data.data && data.data.message ) || rsaOtp.errorMsg );
					}
					showOtp( data.data.otp, data.data.expires_in );
				} )
				.catch( function ( err ) {
					// eslint-disable-next-line no-alert
					alert( err.message );
					btn.disabled    = false;
					btn.textContent = rsaOtp.generateLabel;
				} );
		} );

		// ── Display code + start timer ──────────────────────────────────
		function showOtp( otp, expiresIn ) {
			// Format as XXX‑XXX (non-breaking hyphen for readability)
			codeEl.textContent = otp.slice( 0, 3 ) + '\u2011' + otp.slice( 3 );
			copyBtn.style.display = '';
			box.style.display     = 'block';
			btn.disabled          = false;
			btn.textContent       = rsaOtp.regenerateLabel;

			var ends = Date.now() + expiresIn * 1000;

			if ( countdown ) { clearInterval( countdown ); }

			countdown = setInterval( function () {
				var remaining = Math.max( 0, Math.round( ( ends - Date.now() ) / 1000 ) );
				var m = Math.floor( remaining / 60 );
				var s = remaining % 60;
				timerEl.textContent = m + ':' + ( s < 10 ? '0' : '' ) + s;

				if ( remaining === 0 ) {
					clearInterval( countdown );
					countdown             = null;
					codeEl.textContent    = rsaOtp.expiredMsg;
					copyBtn.style.display = 'none';
					btn.textContent       = rsaOtp.generateLabel;
				}
			}, 1000 );
		}

		// ── Copy to clipboard ───────────────────────────────────────────
		copyBtn.addEventListener( 'click', function () {
			// Strip the non-breaking hyphen before copying
			var plain = codeEl.textContent.replace( /\u2011/g, '' );

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( plain ).then( function () {
					flashCopied();
				} ).catch( function () {
					legacyCopy( plain );
				} );
			} else {
				legacyCopy( plain );
			}
		} );

		function flashCopied() {
			copyBtn.textContent = rsaOtp.copiedMsg;
			setTimeout( function () {
				copyBtn.textContent = rsaOtp.copyLabel;
			}, 2000 );
		}

		function legacyCopy( text ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.focus();
			ta.select();
			try { document.execCommand( 'copy' ); flashCopied(); } catch ( e ) { /* silent */ }
			document.body.removeChild( ta );
		}
	} );
}() );
