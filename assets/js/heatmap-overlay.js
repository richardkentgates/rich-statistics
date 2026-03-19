/* <fs_premium_only> */

/**
 * Rich Statistics — Admin Heatmap Renderer
 *
 * Reads window.RSA_HEATMAP ({x,y,weight,elements}[]) and window.RSA_CLICKS
 * injected by the PHP template and renders a dark canvas heatmap with:
 *   - scroll-depth guide lines + fold marker
 *   - radial-gradient heat dots
 *   - hotspot hover tooltip showing element breakdown
 *   - side-by-side click-element table
 */
/* global window, document */

( function () {
'use strict';

var heatData  = window.RSA_HEATMAP || [];
var clicks    = window.RSA_CLICKS  || [];
var canvas    = document.getElementById( 'rsa-heatmap-canvas' );
var container = document.getElementById( 'rsa-heatmap-container' );
var tipEl     = document.getElementById( 'rsa-hm-admin-tip' );
var tableWrap = document.getElementById( 'rsa-hm-admin-table' );

if ( ! canvas || ! container ) { return; }

// ── colour helper ─────────────────────────────────────────────────────
function heatColour( t, alpha ) {
var seg, r, g, b;
if ( t < 0.25 ) {
seg = t / 0.25;
r = 74;  g = Math.round( 144 + seg * ( 192 - 144 ) );  b = Math.round( 184 + seg * ( 255 - 184 ) );
} else if ( t < 0.5 ) {
seg = ( t - 0.25 ) / 0.25;
r = Math.round( 74 + seg * ( 144 - 74 ) );  g = Math.round( 192 + seg * ( 220 - 192 ) );  b = Math.round( 255 - seg * 255 );
} else if ( t < 0.75 ) {
seg = ( t - 0.5 ) / 0.25;
r = Math.round( 144 + seg * ( 245 - 144 ) );  g = Math.round( 220 - seg * ( 220 - 197 ) );  b = Math.round( seg * 24 );
} else {
seg = ( t - 0.75 ) / 0.25;
r = Math.round( 245 - seg * ( 245 - 232 ) );  g = Math.round( 197 - seg * ( 197 - 83 ) );  b = Math.round( 24 + seg * ( 42 - 24 ) );
}
return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

// ── draw dark canvas ──────────────────────────────────────────────────
function drawCanvas() {
var W = container.offsetWidth || 480;
var H = Math.round( W * ( 756 / 540 ) );
canvas.width  = W;
canvas.height = H;
canvas.style.height = H + 'px';

var ctx = canvas.getContext( '2d' );

ctx.fillStyle = '#111c2b';
ctx.fillRect( 0, 0, W, H );

// Horizontal grid lines at 25 % depth intervals
ctx.strokeStyle = 'rgba(255,255,255,0.06)';
ctx.lineWidth = 1;
[ 0.25, 0.5, 0.75 ].forEach( function ( pct ) {
var y = Math.round( pct * H ) + 0.5;
ctx.beginPath(); ctx.moveTo( 0, y ); ctx.lineTo( W, y ); ctx.stroke();
} );

// Fold line at ~30 %
ctx.save();
ctx.strokeStyle = 'rgba(74,144,184,0.4)';
ctx.lineWidth = 1;
ctx.setLineDash( [ 6, 4 ] );
var foldY = Math.round( 0.3 * H ) + 0.5;
ctx.beginPath(); ctx.moveTo( 0, foldY ); ctx.lineTo( W, foldY ); ctx.stroke();
ctx.restore();
ctx.font = '10px -apple-system,BlinkMacSystemFont,sans-serif';
ctx.fillStyle = 'rgba(74,144,184,0.65)';
ctx.fillText( 'above fold', 6, foldY - 4 );

// Y-axis depth labels
ctx.fillStyle = 'rgba(255,255,255,0.22)';
ctx.textAlign = 'right';
[ [ 0, '0%' ], [ 0.25, '25%' ], [ 0.5, '50%' ], [ 0.75, '75%' ], [ 1, '100%' ] ].forEach( function ( pair ) {
var yPos = Math.round( pair[0] * H );
ctx.fillText( pair[1], W - 4, Math.max( 11, yPos + ( pair[0] === 1 ? -3 : 11 ) ) );
} );
ctx.textAlign = 'left';

// Heat dots
if ( heatData.length ) {
var maxW = Math.max.apply( null, heatData.map( function ( p ) { return p.weight || 1; } ) );
heatData.forEach( function ( p ) {
var t    = ( p.weight || 1 ) / maxW;
var px   = ( p.x / 100 ) * W;
var py   = ( p.y / 100 ) * H;
var brad = Math.max( 18, Math.round( t * 64 ) );
if ( isNaN( px ) || isNaN( py ) ) { return; }
var grad = ctx.createRadialGradient( px, py, 0, px, py, brad );
grad.addColorStop( 0,   heatColour( t, 0.92 ) );
grad.addColorStop( 0.5, heatColour( t, 0.45 ) );
grad.addColorStop( 1,   heatColour( t, 0 ) );
ctx.fillStyle = grad;
ctx.beginPath();
ctx.arc( px, py, brad, 0, Math.PI * 2 );
ctx.fill();
} );
}
}

// ── tooltip ───────────────────────────────────────────────────────────
function esc( s ) {
return String( s )
.replace( /&/g, '&amp;' )
.replace( /</g, '&lt;'  )
.replace( />/g, '&gt;'  )
.replace( /"/g, '&quot;' );
}

function fmt( n ) {
return Number( n ).toLocaleString();
}

function buildTip( dot ) {
var head = '<div class="rsa-hm-tip-head">' +
fmt( dot.weight ) + ' click' + ( dot.weight !== 1 ? 's' : '' ) +
' &middot; (' + Math.round( dot.x ) + '%, ' + Math.round( dot.y ) + '%)' +
'</div>';
if ( ! dot.elements || ! dot.elements.length ) { return head; }
var rows = dot.elements.map( function ( e ) {
var label = ( e.text || '' ).trim() || '\u2014';
if ( label.length > 34 ) { label = label.slice( 0, 34 ) + '\u2026'; }
var tag = e.tag ? ' <span class="rsa-hm-tag">&lt;' + esc( e.tag ) + '&gt;</span>' : '';
return '<tr><td>' + esc( label ) + tag + '</td><td>' + fmt( e.count ) + '</td></tr>';
} ).join( '' );
return head + '<table class="rsa-hm-tip-tbl"><tbody>' + rows + '</tbody></table>';
}

function bindTooltip() {
if ( ! tipEl ) { return; }
canvas.style.cursor = 'crosshair';
canvas.addEventListener( 'mousemove', function ( ev ) {
var rect     = canvas.getBoundingClientRect();
var mx       = ( ( ev.clientX - rect.left ) / rect.width  ) * 100;
var my       = ( ( ev.clientY - rect.top  ) / rect.height ) * 100;
var wrapRect = container.getBoundingClientRect();

var best = null, bestDist = Infinity;
heatData.forEach( function ( d ) {
var dx = d.x - mx;
var dy = ( d.y - my ) * ( 540 / 756 );
var dist = Math.sqrt( dx * dx + dy * dy );
if ( dist < bestDist ) { bestDist = dist; best = d; }
} );

if ( best && bestDist < 5.5 ) {
tipEl.innerHTML = buildTip( best );
var tipW = 224;
var tipH = tipEl.offsetHeight || 100;
var tx   = ev.clientX - wrapRect.left + 6;
var ty   = ev.clientY - wrapRect.top  + 6;
if ( tx + tipW > wrapRect.width  - 4 ) { tx = ev.clientX - wrapRect.left - tipW - 6; }
if ( ty + tipH > wrapRect.height - 4 ) { ty = ev.clientY - wrapRect.top  - tipH - 6; }
tipEl.style.left = Math.max( 2, tx ) + 'px';
tipEl.style.top  = Math.max( 2, ty ) + 'px';
tipEl.hidden = false;
} else {
tipEl.hidden = true;
}
} );
canvas.addEventListener( 'mouseleave', function () { tipEl.hidden = true; } );
}

// ── click-element table ───────────────────────────────────────────────
function renderClickTable() {
if ( ! tableWrap ) { return; }
if ( ! clicks.length ) {
tableWrap.innerHTML = '<p style="color:#666;font-size:13px">No element click data for this page.</p>';
return;
}
var maxC = clicks[0].clicks || 1;
var rows = clicks.slice( 0, 25 ).map( function ( c ) {
var label = ( c.text || '' ).trim();
if ( ! label && c.href_value ) { label = c.href_value; }
if ( ! label ) { label = '\u2014'; }
if ( label.length > 42 ) { label = label.slice( 0, 42 ) + '\u2026'; }
var tag = c.tag ? '<span class="rsa-hm-tag">&lt;' + esc( c.tag ) + '&gt;</span>' : '';
var bar = Math.round( ( ( c.clicks || 0 ) / maxC ) * 100 );
return '<tr>' +
'<td class="rsa-hm-label">' + esc( label ) + tag + '</td>' +
'<td class="rsa-hm-bar-cell"><div class="rsa-hm-bar-bg"><div class="rsa-hm-bar-fill" style="width:' + bar + '%"></div></div></td>' +
'<td class="rsa-hm-count">' + fmt( c.clicks || 0 ) + '</td>' +
'</tr>';
} ).join( '' );
tableWrap.innerHTML =
'<p class="rsa-hm-admin-table-title">Top Clicked Elements</p>' +
'<table class="rsa-hm-table">' +
'<thead><tr><th>Element</th><th></th><th>Clicks</th></tr></thead>' +
'<tbody>' + rows + '</tbody>' +
'</table>';
}

function init() {
drawCanvas();
bindTooltip();
renderClickTable();
window.addEventListener( 'resize', drawCanvas );
}

if ( document.readyState === 'loading' ) {
document.addEventListener( 'DOMContentLoaded', init );
} else {
init();
}

}() );

/* </fs_premium_only> */
