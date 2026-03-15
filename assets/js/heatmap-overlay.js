/**
 * Rich Statistics — Heatmap Canvas Overlay
 *
 * Reads window.RSA_HEATMAP (array of {x, y, weight} objects injected by PHP)
 * and renders a radial-gradient heatmap on a canvas sitting above the iframe preview.
 *
 * No dependencies.
 */

( function () {
	'use strict';

	var data = window.RSA_HEATMAP;
	if ( ! Array.isArray( data ) || data.length === 0 ) {
		return;
	}

	var canvas    = document.getElementById( 'rsa-heatmap-canvas' );
	var container = document.getElementById( 'rsa-heatmap-container' );

	if ( ! canvas || ! container ) {
		return;
	}

	var ctx;
	var maxWeight;

	function init() {
		ctx = canvas.getContext( '2d' );
		resize();
		render();
		window.addEventListener( 'resize', function () {
			resize();
			render();
		} );
	}

	function resize() {
		var rect    = container.getBoundingClientRect();
		canvas.width  = rect.width  || container.offsetWidth;
		canvas.height = rect.height || container.offsetHeight;
	}

	function render() {
		var w = canvas.width;
		var h = canvas.height;

		ctx.clearRect( 0, 0, w, h );

		// Find max weight for normalisation
		maxWeight = 0;
		data.forEach( function ( p ) {
			if ( p.weight > maxWeight ) { maxWeight = p.weight; }
		} );

		if ( maxWeight === 0 ) { return; }

		// Radius scales with smallest dimension, minimum 30px
		var radius = Math.max( 30, Math.min( w, h ) * 0.04 );

		// Draw all points onto an offscreen canvas first, then colorise
		var offscreen = document.createElement( 'canvas' );
		offscreen.width  = w;
		offscreen.height = h;
		var off = offscreen.getContext( '2d' );

		data.forEach( function ( point ) {
			var px = ( point.x / 100 ) * w;
			var py = ( point.y / 100 ) * h;
			var alpha = Math.min( 1, point.weight / maxWeight );

			var grad = off.createRadialGradient( px, py, 0, px, py, radius );
			grad.addColorStop( 0,   'rgba(0,0,0,' + alpha + ')' );
			grad.addColorStop( 1,   'rgba(0,0,0,0)' );

			off.beginPath();
			off.arc( px, py, radius, 0, Math.PI * 2 );
			off.fillStyle = grad;
			off.fill();
		} );

		// Colorise: map grayscale intensity → thermal colour
		ctx.drawImage( offscreen, 0, 0 );
		var imageData = ctx.getImageData( 0, 0, w, h );
		var pixels    = imageData.data;

		for ( var i = 0; i < pixels.length; i += 4 ) {
			var intensity = pixels[ i + 3 ] / 255; // alpha channel = heat
			if ( intensity === 0 ) { continue; }

			var rgb = thermalColor( intensity );
			pixels[ i ]     = rgb[0];
			pixels[ i + 1 ] = rgb[1];
			pixels[ i + 2 ] = rgb[2];
			pixels[ i + 3 ] = Math.round( intensity * 200 ); // max 78% opacity
		}

		ctx.putImageData( imageData, 0, 0 );
	}

	/**
	 * Map 0–1 intensity to a thermal colour (blue → cyan → green → yellow → red).
	 * Returns [r, g, b].
	 */
	function thermalColor( t ) {
		// Colour stops: [t, r, g, b]
		var stops = [
			[ 0.00, 0,   0,   255 ],
			[ 0.25, 0,   255, 255 ],
			[ 0.50, 0,   255, 0   ],
			[ 0.75, 255, 255, 0   ],
			[ 1.00, 255, 0,   0   ],
		];

		for ( var i = 0; i < stops.length - 1; i++ ) {
			var lo = stops[ i ];
			var hi = stops[ i + 1 ];
			if ( t >= lo[0] && t <= hi[0] ) {
				var f = ( t - lo[0] ) / ( hi[0] - lo[0] );
				return [
					Math.round( lo[1] + ( hi[1] - lo[1] ) * f ),
					Math.round( lo[2] + ( hi[2] - lo[2] ) * f ),
					Math.round( lo[3] + ( hi[3] - lo[3] ) * f ),
				];
			}
		}
		return [ 255, 0, 0 ];
	}

	// Wait for DOM ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

}() );
