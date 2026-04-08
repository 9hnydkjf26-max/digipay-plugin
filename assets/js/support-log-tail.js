/**
 * Live Log Tail — Digipay Support Page
 *
 * Polls GET /wp-json/digipay/v1/support/log-tail every 5 seconds while the
 * <details> panel is open and renders the last 50 lines per log source.
 *
 * Depends on wcpgLogTail global injected by wp_localize_script():
 *   { restUrl: string, restNonce: string }
 */
( function () {
	'use strict';

	var POLL_INTERVAL_MS = 5000;

	document.addEventListener( 'DOMContentLoaded', function () {
		var details = document.getElementById( 'wcpg-log-tail-details' );
		var output  = document.getElementById( 'wcpg-log-tail-output' );

		// Bail if either element is missing.
		if ( ! details || ! output ) {
			return;
		}

		/**
		 * Fetch log tail data and render it into the output div.
		 */
		function poll() {
			var url   = ( typeof wcpgLogTail !== 'undefined' && wcpgLogTail.restUrl )
				? wcpgLogTail.restUrl
				: '/wp-json/digipay/v1/support/log-tail';
			var nonce = ( typeof wcpgLogTail !== 'undefined' && wcpgLogTail.restNonce )
				? wcpgLogTail.restNonce
				: '';

			fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce },
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					renderData( data );
				} )
				.catch( function ( err ) {
					output.innerHTML =
						'<p style="color:#e06c75;">Fetch failed: ' + String( err ) + '</p>';
				} );
		}

		/**
		 * Render the API response into the output panel.
		 *
		 * @param {Object} data - { ts: string, sources: Array }
		 */
		function renderData( data ) {
			if ( ! data || ! Array.isArray( data.sources ) ) {
				output.innerHTML = '<p style="color:#e06c75;">Invalid response from server.</p>';
				return;
			}

			var html = '<p style="color:#888;margin:0 0 8px;">Updated at: ' +
				escapeHtml( data.ts || '' ) + '</p>';

			data.sources.forEach( function ( source ) {
				var name  = source.name || '(unknown)';
				var lines = Array.isArray( source.lines ) ? source.lines : [];

				html += '<div style="margin-bottom:16px;">';
				html += '<div style="color:#61afef;font-weight:bold;margin-bottom:4px;">[' +
					escapeHtml( name ) + ']</div>';

				if ( lines.length === 0 ) {
					html += '<span style="color:#5c6370;">— no log entries —</span>';
				} else {
					html += '<pre style="margin:0;white-space:pre-wrap;word-break:break-all;">' +
						escapeHtml( lines.join( '\n' ) ) + '</pre>';
				}

				html += '</div>';
			} );

			output.innerHTML = html;

			// Keep the output scrolled to the bottom.
			output.scrollTop = output.scrollHeight;
		}

		/**
		 * Minimal HTML escape to avoid XSS when rendering log content.
		 *
		 * @param {string} str
		 * @return {string}
		 */
		function escapeHtml( str ) {
			return String( str )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' );
		}

		/**
		 * Start polling and store the interval ID on the details element.
		 */
		function startPolling() {
			// Run immediately, then on interval.
			poll();
			details._wcpgLogTailInterval = setInterval( poll, POLL_INTERVAL_MS );
		}

		/**
		 * Stop polling and clear the stored interval.
		 */
		function stopPolling() {
			if ( details._wcpgLogTailInterval ) {
				clearInterval( details._wcpgLogTailInterval );
				details._wcpgLogTailInterval = null;
			}
		}

		// Toggle polling on open/close.
		details.addEventListener( 'toggle', function () {
			if ( details.open ) {
				startPolling();
			} else {
				stopPolling();
				output.innerHTML = '<p style="color:#888;">Opening panel to start polling...</p>';
			}
		} );
	} );
}() );
